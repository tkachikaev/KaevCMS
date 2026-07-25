<?php

namespace App\Services\GameWorld;

use App\Models\GameServer;
use App\Services\GameAssets\CharacterAppearanceResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class GameStatistics
{
    private const CACHE_MINUTES = 5;

    private const FAILURE_COOLDOWN_SECONDS = 30;

    /** @var array<string,array{label:string,flag:string,capability:string}> */
    private const SECTIONS = [
        'level' => ['label' => 'Level', 'flag' => 'statistics_level_enabled', 'capability' => 'level'],
        'pvp' => ['label' => 'PvP', 'flag' => 'statistics_pvp_enabled', 'capability' => 'pvp'],
        'pk' => ['label' => 'PK', 'flag' => 'statistics_pk_enabled', 'capability' => 'pk'],
        'play_time' => ['label' => 'Play time', 'flag' => 'statistics_play_time_enabled', 'capability' => 'play_time'],
        'heroes' => ['label' => 'Heroes', 'flag' => 'statistics_heroes_enabled', 'capability' => 'heroes'],
        'castles' => ['label' => 'Castles', 'flag' => 'statistics_castles_enabled', 'capability' => 'castles'],
    ];

    public function __construct(
        private readonly GameWorldDriverResolver $drivers,
        private readonly MobiusCharacterLabels $labels,
        private readonly CharacterAppearanceResolver $appearances,
    ) {}

    public function navigationAvailable(): bool
    {
        try {
            return GameServer::query()
                ->where('statistics_enabled', true)
                ->get()
                ->contains(function (GameServer $server): bool {
                    if (! $server->connectionConfigured()) {
                        return false;
                    }

                    $state = $this->sectionState($server);

                    return $state['available'] && $state['sections'] !== [];
                });
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string,string> */
    public function sections(GameServer $server): array
    {
        return $this->sectionState($server)['sections'];
    }

    /** @return array{available:bool,sections:array<string,string>} */
    public function sectionState(GameServer $server): array
    {
        if (! $server->statistics_enabled || ! $server->connectionConfigured()) {
            return ['available' => true, 'sections' => []];
        }

        $failureKey = implode(':', [
            'game-statistics-capabilities-v1',
            $server->id,
            $server->updated_at?->getTimestamp() ?? 0,
            'unavailable',
        ]);
        if (Cache::has($failureKey)) {
            return ['available' => false, 'sections' => []];
        }

        try {
            $capabilities = $this->drivers->resolve($server)->capabilities($server);
        } catch (Throwable $exception) {
            Cache::put($failureKey, true, now()->addSeconds(self::FAILURE_COOLDOWN_SECONDS));
            Log::warning('Public game statistics capabilities could not be read.', [
                'game_server_id' => $server->id,
                'driver' => $server->driver,
                'exception' => $exception::class,
            ]);

            return ['available' => false, 'sections' => []];
        }

        $sections = [];
        foreach (self::SECTIONS as $key => $definition) {
            if ((bool) $server->getAttribute($definition['flag']) && in_array($definition['capability'], $capabilities, true)) {
                $sections[$key] = __($definition['label']);
            }
        }

        return ['available' => true, 'sections' => $sections];
    }

    /** @return array{available:bool,rows:list<array<string,mixed>>} */
    public function load(GameServer $server, string $section): array
    {
        $sections = $this->sections($server);
        if (! array_key_exists($section, $sections)) {
            return ['available' => false, 'rows' => []];
        }

        $limit = $this->sectionLimit($server, $section);

        $cacheKey = implode(':', [
            'game-statistics-v2',
            $server->id,
            $server->updated_at?->getTimestamp() ?? 0,
            $section,
            $limit ?? 'all',
            app()->getLocale(),
        ]);

        $failureKey = $cacheKey.':unavailable';
        if (Cache::has($failureKey)) {
            return ['available' => false, 'rows' => []];
        }

        try {
            /** @var list<array<string,mixed>> $rows */
            $rows = Cache::remember($cacheKey, now()->addMinutes(self::CACHE_MINUTES), function () use ($server, $section, $limit): array {
                $driver = $this->drivers->resolve($server);
                $driverRows = match ($section) {
                    'heroes' => $driver->heroes($server),
                    'castles' => $driver->castleOwners($server),
                    default => $driver->ranking($server, $section, $limit ?? 10),
                };

                return $section === 'castles'
                    ? array_map(fn (array $row): array => $this->normalizeCastle($row), $driverRows)
                    : array_map(fn (array $row): array => $this->normalizeCharacter($row, $server), $driverRows);
            });

            return ['available' => true, 'rows' => $rows];
        } catch (Throwable $exception) {
            Cache::put($failureKey, true, now()->addSeconds(self::FAILURE_COOLDOWN_SECONDS));
            Log::warning('Public game statistics query failed.', [
                'game_server_id' => $server->id,
                'driver' => $server->driver,
                'section' => $section,
                'exception' => $exception::class,
            ]);

            return ['available' => false, 'rows' => []];
        }
    }

    private function sectionLimit(GameServer $server, string $section): ?int
    {
        return match ($section) {
            'level' => $this->rankingLimit($server->statistics_level_limit),
            'pvp' => $this->rankingLimit($server->statistics_pvp_limit),
            'pk' => $this->rankingLimit($server->statistics_pk_limit),
            'play_time' => $this->rankingLimit($server->statistics_play_time_limit),
            default => null,
        };
    }

    private function rankingLimit(mixed $value): int
    {
        return min(max((int) $value, 1), 100);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeCharacter(array $row, GameServer $server): array
    {
        $seconds = max(0, (int) ($row['play_time_seconds'] ?? 0));
        $classId = (int) ($row['class_id'] ?? -1);
        $race = (int) ($row['race'] ?? -1);
        $gender = (int) ($row['gender'] ?? -1);
        $appearance = $this->appearances->resolve($server, $race, $gender, $classId);

        return array_merge($row, $appearance, [
            'class_name' => $this->labels->className($classId),
            'race_name' => $this->labels->raceName($race),
            'gender_name' => $this->labels->genderName($gender),
            'online' => (bool) ($row['online'] ?? false),
            'noble' => (bool) ($row['noble'] ?? false),
            'play_time_hours' => (int) floor($seconds / 3600),
        ]);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeCastle(array $row): array
    {
        return [
            'castle_id' => (int) ($row['castle_id'] ?? 0),
            'castle_name' => trim((string) ($row['castle_name'] ?? '')),
            'clan_id' => (int) ($row['clan_id'] ?? 0),
            'clan_name' => trim((string) ($row['clan_name'] ?? '')),
            'clan_level' => (int) ($row['clan_level'] ?? 0),
            'reputation_score' => (int) ($row['reputation_score'] ?? 0),
            'leader_name' => trim((string) ($row['leader_name'] ?? '')),
        ];
    }
}
