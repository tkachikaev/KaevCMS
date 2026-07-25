<?php

namespace App\Services;

use App\Exceptions\GameServerDeletionConfirmationRequired;
use App\Exceptions\GameServerHasRewardData;
use App\Models\GameServer;
use App\Models\GameServerTranslation;
use App\Models\UserGameAccount;
use App\Services\Localization\LanguageManager;
use App\Support\Modules\ModuleGameServerDependencyRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class GameServerSettings
{
    public function __construct(
        private readonly LanguageManager $languages,
        private readonly GameServerDeletionImpact $deletionImpact,
        private readonly ModuleGameServerDependencyRegistry $moduleDependencies,
    ) {}

    /**
     * @return array<int, array{
     *     id: int,
     *     name: string,
     *     rates: string,
     *     chronicle: string,
     *     mode: string,
     *     show_rates: bool,
     *     show_chronicle: bool,
     *     show_mode: bool,
     *     translations: array<string,string>,
     *     maintenance_enabled: bool,
     *     maintenance_message: string,
     *     maintenance_messages: array<string,string>,
     *     statistics_enabled:bool,
     *     statistics_level_enabled:bool,
     *     statistics_pvp_enabled:bool,
     *     statistics_pk_enabled:bool,
     *     statistics_play_time_enabled:bool,
     *     statistics_heroes_enabled:bool,
     *     statistics_castles_enabled:bool,
     *     statistics_level_limit:int,
     *     statistics_pvp_limit:int,
     *     statistics_pk_limit:int,
     *     statistics_play_time_limit:int,
     *     login_server_id:int|null,
     *     login_server_name:string|null,
     *     driver:string|null,
     *     use_login_server_connection:bool,
     *     database_host:string,
     *     database_port:int|null,
     *     database_name:string,
     *     database_username:string,
     *     database_charset:string,
     *     database_password_saved:bool,
     *     connection_configured:bool,
     *     database_status:string,
     *     database_error:string|null,
     *     database_checked_at:mixed,
     *     service_status:string,
     *     service_checked_at:mixed
     * }>
     */
    public function all(?string $locale = null): array
    {
        if (! $this->tableExists()) {
            return [];
        }

        try {
            return GameServer::query()
                ->with(['translations', 'loginServer'])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (GameServer $server): array => $this->normalize($server, $locale))
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    public function primary(?string $locale = null): ?array
    {
        return $this->all($locale)[0] ?? null;
    }

    /** @param array{name: string, rates?: string|null, chronicle?: string|null, mode?: string|null, translations?:array<string,string>, maintenance_enabled?: bool, maintenance_messages?:array<string,string>, statistics_enabled?: bool, statistics_level_enabled?: bool, statistics_pvp_enabled?: bool, statistics_pk_enabled?: bool, statistics_play_time_enabled?: bool, statistics_heroes_enabled?: bool, statistics_castles_enabled?: bool, statistics_level_limit?: int, statistics_pvp_limit?: int, statistics_pk_limit?: int, statistics_play_time_limit?: int} $values */
    public function create(array $values): GameServer
    {
        $this->ensureTableExists();

        return DB::transaction(function () use ($values): GameServer {
            $nextSortOrder = ((int) GameServer::query()->max('sort_order')) + 1;
            $defaultName = $this->defaultName($values);

            $server = GameServer::query()->create([
                'name' => $defaultName,
                'rates' => $this->nullableString($values['rates'] ?? null),
                'chronicle' => $this->nullableString($values['chronicle'] ?? null),
                'mode' => $this->nullableString($values['mode'] ?? null),
                'maintenance_enabled' => (bool) ($values['maintenance_enabled'] ?? false),
                'statistics_enabled' => (bool) ($values['statistics_enabled'] ?? false),
                'statistics_level_enabled' => (bool) ($values['statistics_level_enabled'] ?? true),
                'statistics_pvp_enabled' => (bool) ($values['statistics_pvp_enabled'] ?? true),
                'statistics_pk_enabled' => (bool) ($values['statistics_pk_enabled'] ?? true),
                'statistics_play_time_enabled' => (bool) ($values['statistics_play_time_enabled'] ?? true),
                'statistics_heroes_enabled' => (bool) ($values['statistics_heroes_enabled'] ?? true),
                'statistics_castles_enabled' => (bool) ($values['statistics_castles_enabled'] ?? true),
                'statistics_level_limit' => $this->statisticsLimit($values['statistics_level_limit'] ?? 10),
                'statistics_pvp_limit' => $this->statisticsLimit($values['statistics_pvp_limit'] ?? 10),
                'statistics_pk_limit' => $this->statisticsLimit($values['statistics_pk_limit'] ?? 10),
                'statistics_play_time_limit' => $this->statisticsLimit($values['statistics_play_time_limit'] ?? 10),
                'sort_order' => $nextSortOrder,
            ]);

            $this->saveTranslations(
                $server,
                (array) ($values['translations'] ?? []),
                (array) ($values['maintenance_messages'] ?? []),
                $defaultName,
            );

            return $server;
        });
    }

    /** @param array{name: string, rates?: string|null, chronicle?: string|null, mode?: string|null, translations?:array<string,string>, maintenance_enabled?: bool, maintenance_messages?:array<string,string>, statistics_enabled?: bool, statistics_level_enabled?: bool, statistics_pvp_enabled?: bool, statistics_pk_enabled?: bool, statistics_play_time_enabled?: bool, statistics_heroes_enabled?: bool, statistics_castles_enabled?: bool, statistics_level_limit?: int, statistics_pvp_limit?: int, statistics_pk_limit?: int, statistics_play_time_limit?: int} $values */
    public function update(GameServer $server, array $values): void
    {
        DB::transaction(function () use ($server, $values): void {
            $defaultName = $this->defaultName($values);

            $server->update([
                'name' => $defaultName,
                'rates' => $this->nullableString($values['rates'] ?? null),
                'chronicle' => $this->nullableString($values['chronicle'] ?? null),
                'mode' => $this->nullableString($values['mode'] ?? null),
                'maintenance_enabled' => (bool) ($values['maintenance_enabled'] ?? false),
                'statistics_enabled' => (bool) ($values['statistics_enabled'] ?? $server->statistics_enabled),
                'statistics_level_enabled' => (bool) ($values['statistics_level_enabled'] ?? $server->statistics_level_enabled),
                'statistics_pvp_enabled' => (bool) ($values['statistics_pvp_enabled'] ?? $server->statistics_pvp_enabled),
                'statistics_pk_enabled' => (bool) ($values['statistics_pk_enabled'] ?? $server->statistics_pk_enabled),
                'statistics_play_time_enabled' => (bool) ($values['statistics_play_time_enabled'] ?? $server->statistics_play_time_enabled),
                'statistics_heroes_enabled' => (bool) ($values['statistics_heroes_enabled'] ?? $server->statistics_heroes_enabled),
                'statistics_castles_enabled' => (bool) ($values['statistics_castles_enabled'] ?? $server->statistics_castles_enabled),
                'statistics_level_limit' => $this->statisticsLimit($values['statistics_level_limit'] ?? $server->statistics_level_limit),
                'statistics_pvp_limit' => $this->statisticsLimit($values['statistics_pvp_limit'] ?? $server->statistics_pvp_limit),
                'statistics_pk_limit' => $this->statisticsLimit($values['statistics_pk_limit'] ?? $server->statistics_pk_limit),
                'statistics_play_time_limit' => $this->statisticsLimit($values['statistics_play_time_limit'] ?? $server->statistics_play_time_limit),
            ]);

            $this->saveTranslations(
                $server,
                (array) ($values['translations'] ?? []),
                (array) ($values['maintenance_messages'] ?? []),
                $defaultName,
            );
        });
    }

    /**
     * @return array{
     *     game_server_id:int,
     *     login_server_id:int|null,
     *     login_server_name:string|null,
     *     replacement_game_server_id:int|null,
     *     login_server_account_count:int,
     *     accounts_becoming_unavailable:int,
     *     unavailable_after_deletion:int,
     *     requires_confirmation:bool,
     *     fingerprint:string
     * }
     */
    public function delete(GameServer $server, ?string $confirmedImpactFingerprint = null): array
    {
        return DB::transaction(function () use ($server, $confirmedImpactFingerprint): array {
            $expectedLoginServerId = $server->login_server_id;
            if ($expectedLoginServerId !== null) {
                $server->loginServer()->lockForUpdate()->first();
            }

            $lockedServer = GameServer::query()
                ->with('loginServer')
                ->lockForUpdate()
                ->findOrFail($server->id);
            $impact = $this->deletionImpact->analyze($lockedServer);

            if ($lockedServer->login_server_id !== $expectedLoginServerId) {
                throw new GameServerDeletionConfirmationRequired($impact);
            }
            if ($impact['requires_confirmation']
                && (! is_string($confirmedImpactFingerprint)
                    || ! hash_equals($impact['fingerprint'], $confirmedImpactFingerprint))) {
                throw new GameServerDeletionConfirmationRequired($impact);
            }

            if ($lockedServer->rewardInventoryGrants()->exists()
                || $lockedServer->rewardInventoryItems()->exists()
                || $lockedServer->rewardDeliveries()->exists()
                || $this->moduleDependencies->blocksDeletion($lockedServer)) {
                throw new GameServerHasRewardData;
            }

            $this->reassignLinkedAccountsBeforeDisconnect($lockedServer);
            $lockedServer->delete();

            return $impact;
        });
    }

    public function reassignLinkedAccountsBeforeDisconnect(GameServer $server, ?int $nextLoginServerId = null): int
    {
        if ($server->login_server_id === null || $server->login_server_id === $nextLoginServerId) {
            return 0;
        }

        $replacementId = GameServer::query()
            ->where('login_server_id', $server->login_server_id)
            ->whereKeyNot($server->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('id');

        return UserGameAccount::query()
            ->where('login_server_id', $server->login_server_id)
            ->where(function ($query) use ($server): void {
                $query->where('registration_game_server_id', $server->id)
                    ->orWhereNull('registration_game_server_id');
            })
            ->update(['registration_game_server_id' => $replacementId]);
    }

    public function restoreOrphanedAccountLinks(GameServer $server): int
    {
        if ($server->login_server_id === null) {
            return 0;
        }

        return UserGameAccount::query()
            ->where('login_server_id', $server->login_server_id)
            ->whereNull('registration_game_server_id')
            ->update(['registration_game_server_id' => $server->id]);
    }

    private function normalize(GameServer $server, ?string $locale): array
    {
        $rates = trim((string) $server->rates);
        $chronicle = trim((string) $server->chronicle);
        $mode = trim((string) $server->mode);
        $translations = [];
        $maintenanceMessages = [];

        foreach ($this->languages->enabledCodes() as $code) {
            $translations[$code] = $server->nameFor($code, false);
            $own = $server->translations->firstWhere('locale', $code);
            if ($translations[$code] === trim((string) $server->name) && $code !== $this->languages->default()) {
                $translations[$code] = $own instanceof GameServerTranslation ? trim((string) $own->name) : '';
            }

            $maintenanceMessages[$code] = $own instanceof GameServerTranslation
                ? trim((string) $own->maintenance_message)
                : '';
        }

        return [
            'id' => (int) $server->id,
            'name' => $server->nameFor($locale),
            'rates' => $rates,
            'chronicle' => $chronicle,
            'mode' => $mode,
            'show_rates' => $rates !== '',
            'show_chronicle' => $chronicle !== '',
            'show_mode' => $this->modeIsVisible($mode),
            'translations' => $translations,
            'maintenance_enabled' => (bool) $server->maintenance_enabled,
            'maintenance_message' => $server->maintenanceMessageFor($locale),
            'maintenance_messages' => $maintenanceMessages,
            'statistics_enabled' => (bool) $server->statistics_enabled,
            'statistics_level_enabled' => (bool) $server->statistics_level_enabled,
            'statistics_pvp_enabled' => (bool) $server->statistics_pvp_enabled,
            'statistics_pk_enabled' => (bool) $server->statistics_pk_enabled,
            'statistics_play_time_enabled' => (bool) $server->statistics_play_time_enabled,
            'statistics_heroes_enabled' => (bool) $server->statistics_heroes_enabled,
            'statistics_castles_enabled' => (bool) $server->statistics_castles_enabled,
            'statistics_level_limit' => (int) $server->statistics_level_limit,
            'statistics_pvp_limit' => (int) $server->statistics_pvp_limit,
            'statistics_pk_limit' => (int) $server->statistics_pk_limit,
            'statistics_play_time_limit' => (int) $server->statistics_play_time_limit,
            'login_server_id' => $server->login_server_id,
            'login_server_name' => $server->loginServer?->name,
            'driver' => $server->driver,
            'use_login_server_connection' => (bool) $server->use_login_server_connection,
            'database_host' => trim((string) $server->database_host),
            'database_port' => $server->database_port,
            'database_name' => trim((string) $server->database_name),
            'database_username' => trim((string) $server->database_username),
            'database_charset' => trim((string) $server->database_charset),
            'database_password_saved' => $server->hasDatabasePassword(),
            'connection_configured' => $server->connectionConfigured(),
            'database_status' => (string) $server->database_status,
            'database_error' => $server->database_error,
            'database_checked_at' => $server->database_checked_at,
            'service_status' => (string) $server->monitor_status,
            'service_checked_at' => $server->monitor_checked_at,
        ];
    }

    /** @param array<string,mixed> $values */
    private function defaultName(array $values): string
    {
        $translations = (array) ($values['translations'] ?? []);
        $default = trim((string) ($translations[$this->languages->default()] ?? $values['name'] ?? ''));

        if ($default === '') {
            $default = trim((string) ($values['name'] ?? ''));
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $translations
     * @param  array<string, mixed>  $maintenanceMessages
     */
    private function saveTranslations(
        GameServer $server,
        array $translations,
        array $maintenanceMessages,
        string $defaultName,
    ): void {
        if (! Schema::hasTable('game_server_translations')) {
            return;
        }

        if ($translations === []) {
            $translations[$this->languages->default()] = $defaultName;
        }

        foreach ($this->languages->enabledCodes() as $locale) {
            $name = trim((string) ($translations[$locale] ?? ''));
            $maintenanceMessage = $this->nullableString($maintenanceMessages[$locale] ?? null);

            if ($name === '' && $maintenanceMessage === null) {
                $server->translations()->where('locale', $locale)->delete();

                continue;
            }

            $server->translations()->updateOrCreate(
                ['locale' => $locale],
                [
                    'name' => $name !== '' ? $name : $defaultName,
                    'maintenance_message' => $maintenanceMessage,
                ],
            );
        }
    }

    private function modeIsVisible(string $mode): bool
    {
        return $mode !== '' && mb_strtolower($mode) !== 'none';
    }

    private function statisticsLimit(mixed $value): int
    {
        return min(max((int) $value, 1), 100);
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function ensureTableExists(): void
    {
        if (! $this->tableExists()) {
            throw new RuntimeException('Game servers table is not available. Run database migrations first.');
        }
    }

    private function tableExists(): bool
    {
        try {
            return Schema::hasTable('game_servers');
        } catch (Throwable) {
            return false;
        }
    }
}
