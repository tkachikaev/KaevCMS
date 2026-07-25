<?php

namespace App\Services\GameAccounts;

use App\Contracts\GameAccountGateway;
use App\Models\GameServer;
use App\Models\User;
use App\Models\UserGameAccount;
use App\Services\GameAssets\CharacterAppearanceResolver;
use App\Services\GameWorld\MobiusCharacterLabels;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @phpstan-type CharacterRow array{
 *     id:int,
 *     name:string,
 *     level:int,
 *     class_id:int,
 *     class_name:string,
 *     race:int,
 *     race_name:string,
 *     gender:int,
 *     gender_name:string,
 *     race_key:string,
 *     gender_key:string,
 *     archetype:string,
 *     avatar_key:string,
 *     avatar_url:?string,
 *     title:?string,
 *     clan:?string,
 *     online:bool,
 *     play_time_seconds:int,
 *     play_time_label:string,
 *     pvp_kills:int,
 *     pk_kills:int,
 *     reputation:int,
 *     noble:bool,
 *     hero:bool,
 *     last_seen_label:?string,
 *     created_at_label:?string,
 *     server_id:int,
 *     server_name:string,
 *     account_id:int,
 *     account_login:string
 * }
 * @phpstan-type AccountRow array{id:int,login:string,available:bool,characters:list<CharacterRow>}
 * @phpstan-type ServerRow array{id:int,name:string,chronicle:string,rates:string,sort_order:int,accounts:list<AccountRow>}
 */
final class AccountCharacterDirectory
{
    private const CACHE_MINUTES = 2;

    private const FAILURE_COOLDOWN_SECONDS = 30;

    public function __construct(
        private readonly GameAccountGateway $gateway,
        private readonly MobiusCharacterLabels $labels,
        private readonly CharacterAppearanceResolver $appearances,
    ) {}

    /**
     * @return array{
     *     servers:list<ServerRow>,
     *     characters:list<CharacterRow>,
     *     counts:array{servers:int,accounts:int,characters:int,online:int}
     * }
     */
    public function for(User $user): array
    {
        $accounts = $user->availableGameAccounts()
            ->with(['loginServer.gameServers.translations', 'registrationGameServer.translations'])
            ->orderBy('game_login')
            ->orderBy('id')
            ->get();

        /** @var array<int,ServerRow> $servers */
        $servers = [];
        /** @var list<CharacterRow> $allCharacters */
        $allCharacters = [];

        foreach ($accounts as $account) {
            $this->appendAccount($servers, $allCharacters, $account);
        }

        $serverRows = array_values($servers);
        usort(
            $serverRows,
            static fn (array $left, array $right): int => [$left['sort_order'], $left['id']] <=> [$right['sort_order'], $right['id']],
        );

        foreach ($serverRows as &$server) {
            usort(
                $server['accounts'],
                static fn (array $left, array $right): int => strnatcasecmp($left['login'], $right['login']),
            );
        }
        unset($server);

        usort($allCharacters, static function (array $left, array $right): int {
            return [! $left['online'], -$left['level'], mb_strtolower($left['name'])]
                <=> [! $right['online'], -$right['level'], mb_strtolower($right['name'])];
        });

        return [
            'servers' => $serverRows,
            'characters' => $allCharacters,
            'counts' => [
                'servers' => count($serverRows),
                'accounts' => $accounts->count(),
                'characters' => count($allCharacters),
                'online' => count(array_filter(
                    $allCharacters,
                    static fn (array $character): bool => $character['online'],
                )),
            ],
        ];
    }

    /**
     * @param  array<int,ServerRow>  $servers
     * @param  list<CharacterRow>  $allCharacters
     */
    private function appendAccount(array &$servers, array &$allCharacters, UserGameAccount $account): void
    {
        $gameServers = $account->loginServer->gameServers
            ->filter(fn (GameServer $server): bool => $server->connectionConfigured() && $this->gateway->supportsGameServer($server))
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']]);

        foreach ($gameServers as $gameServer) {
            $serverId = (int) $gameServer->id;
            $servers[$serverId] ??= [
                'id' => $serverId,
                'name' => $gameServer->nameFor(),
                'chronicle' => trim((string) $gameServer->chronicle),
                'rates' => trim((string) $gameServer->rates),
                'sort_order' => (int) $gameServer->sort_order,
                'accounts' => [],
            ];

            $characterResult = $this->characters($gameServer, $account);
            $characters = $characterResult['characters'];

            $servers[$serverId]['accounts'][] = [
                'id' => (int) $account->id,
                'login' => $account->game_login,
                'available' => $characterResult['available'],
                'characters' => $characters,
            ];

            foreach ($characters as $character) {
                $allCharacters[] = $character;
            }
        }
    }

    /** @return array{available:bool,characters:list<CharacterRow>} */
    private function characters(GameServer $gameServer, UserGameAccount $account): array
    {
        $cacheKey = implode(':', [
            'account-character-directory-v2',
            $gameServer->id,
            $gameServer->updated_at?->getTimestamp() ?? 0,
            $account->id,
            $account->updated_at?->getTimestamp() ?? 0,
        ]);

        $failureKey = $cacheKey.':unavailable';
        if (Cache::has($failureKey)) {
            return ['available' => false, 'characters' => []];
        }

        try {
            /** @var list<array<string,mixed>> $rows */
            $rows = Cache::remember(
                $cacheKey,
                now()->addMinutes(self::CACHE_MINUTES),
                fn (): array => $this->gateway->characters($gameServer, $account->game_login),
            );

            /** @var list<CharacterRow> $characters */
            $characters = array_map(
                fn (array $character): array => $this->normalizeCharacter($character, $gameServer, $account),
                $rows,
            );

            return ['available' => true, 'characters' => $characters];
        } catch (Throwable $exception) {
            Cache::put($failureKey, true, now()->addSeconds(self::FAILURE_COOLDOWN_SECONDS));
            Log::warning('Player character directory loading failed.', [
                'exception' => $exception::class,
                'game_server_id' => $gameServer->id,
                'game_account_id' => $account->id,
            ]);

            return ['available' => false, 'characters' => []];
        }
    }

    /** @param array<string,mixed> $character @return CharacterRow */
    private function normalizeCharacter(array $character, GameServer $server, UserGameAccount $account): array
    {
        $playTimeSeconds = max(0, (int) ($character['play_time_seconds'] ?? 0));
        $lastAccess = max(0, (int) ($character['last_access'] ?? 0));
        $clan = trim((string) ($character['clan'] ?? $character['clan_name'] ?? ''));
        $title = trim((string) ($character['title'] ?? ''));

        $lastSeenAt = $this->lastSeenAt($lastAccess);
        $createdAtValue = $character['created_at'] ?? null;
        $createdAt = $createdAtValue instanceof CarbonImmutable ? $createdAtValue : null;
        $classId = (int) ($character['class_id'] ?? -1);
        $race = (int) ($character['race'] ?? -1);
        $gender = (int) ($character['gender'] ?? -1);
        $appearance = $this->appearances->resolve($server, $race, $gender, $classId);

        return [
            'id' => (int) ($character['id'] ?? 0),
            'name' => trim((string) ($character['name'] ?? '')),
            'level' => max(0, (int) ($character['level'] ?? 0)),
            'class_id' => $classId,
            'class_name' => $this->labels->className($classId),
            'race' => $race,
            'race_name' => $this->labels->raceName($race),
            'gender' => $gender,
            'gender_name' => $this->labels->genderName($gender),
            'race_key' => $appearance['race_key'],
            'gender_key' => $appearance['gender_key'],
            'archetype' => $appearance['archetype'],
            'avatar_key' => $appearance['avatar_key'],
            'avatar_url' => $appearance['avatar_url'],
            'title' => $title !== '' ? $title : null,
            'clan' => $clan !== '' ? $clan : null,
            'online' => (bool) ($character['online'] ?? false),
            'play_time_seconds' => $playTimeSeconds,
            'play_time_label' => $this->playTimeLabel($playTimeSeconds),
            'pvp_kills' => max(0, (int) ($character['pvp_kills'] ?? 0)),
            'pk_kills' => max(0, (int) ($character['pk_kills'] ?? 0)),
            'reputation' => (int) ($character['reputation'] ?? 0),
            'noble' => (bool) ($character['noble'] ?? false),
            'hero' => (bool) ($character['hero'] ?? false),
            'last_seen_label' => $lastSeenAt?->format('d.m.Y H:i'),
            'created_at_label' => $createdAt?->format('d.m.Y'),
            'server_id' => (int) $server->id,
            'server_name' => $server->nameFor(),
            'account_id' => (int) $account->id,
            'account_login' => $account->game_login,
        ];
    }

    private function playTimeLabel(int $seconds): string
    {
        if ($seconds < 3600) {
            return __(':count min.', ['count' => max(0, (int) floor($seconds / 60))]);
        }

        $hours = (int) floor($seconds / 3600);
        if ($hours < 24) {
            return __(':count h.', ['count' => $hours]);
        }

        $days = (int) floor($hours / 24);
        $remainingHours = $hours % 24;

        return $remainingHours > 0
            ? __(':days d. :hours h.', ['days' => $days, 'hours' => $remainingHours])
            : __(':count d.', ['count' => $days]);
    }

    private function lastSeenAt(int $milliseconds): ?CarbonImmutable
    {
        if ($milliseconds <= 0) {
            return null;
        }

        return CarbonImmutable::createFromTimestampUTC((int) floor($milliseconds / 1000))
            ->setTimezone((string) config('app.timezone'));
    }
}
