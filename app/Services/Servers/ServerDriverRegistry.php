<?php

namespace App\Services\Servers;

use App\Support\Database\TableExistence;
use App\Support\UniversalDrivers\BundledProfileCatalog;
use App\Support\UniversalDrivers\DriverProfileCatalog;

final class ServerDriverRegistry
{
    public const MOBIUS_DRIVER = 'l2j_mobius';

    public const MOBIUS_LEGACY_LOGIN_DRIVER = 'l2j_mobius_legacy';

    public const RUSACIS_DRIVER = 'rusacis';

    /**
     * @return array<string, array{
     *     label:string,
     *     description:string,
     *     ready:bool,
     *     service_port:int,
     *     schema_profile:string,
     *     capabilities:list<string>,
     *     optional_capabilities:array<string,string>,
     *     requirements:list<array{table:string,columns:list<string>,any_columns?:list<string>,optional_columns?:list<string>,required:bool}>
     * }>
     */
    public function loginDrivers(): array
    {
        return $this->descriptors('login');
    }

    /**
     * @return array<string, array{
     *     label:string,
     *     description:string,
     *     ready:bool,
     *     service_port:int,
     *     schema_profile:string,
     *     character_created_at_column?:string|null,
     *     online_count?:array{table:string,column:string,value:int|string},
     *     statistics?:list<string>,
     *     capabilities:list<string>,
     *     optional_capabilities:array<string,string>,
     *     requirements:list<array{table:string,columns:list<string>,any_columns?:list<string>,optional_columns?:list<string>,required:bool}>
     * }>
     */
    public function gameDrivers(): array
    {
        return $this->descriptors('game');
    }

    /**
     * Catalog visibility is shared by all profiles. Built-in mappings stay
     * release-owned until a verified override is applied to a connection.
     *
     * @return array<string,array{label:string,description:string,ready:bool,service_port:int,schema_profile:string,capabilities:list<string>,optional_capabilities:array<string,string>,requirements:list<array{table:string,columns:list<string>,any_columns?:list<string>,optional_columns?:list<string>,required:bool}>,statistics?:list<string>,online_count?:array{table:string,column:string,value:int|string},character_created_at_column?:string|null}>
     */
    private function descriptors(string $type): array
    {
        $bundled = (new BundledProfileCatalog)->all();
        $entries = app(TableExistence::class)->has('universal_driver_profiles')
            ? app(DriverProfileCatalog::class)->all()
            : array_map(static fn (array $profile): array => ['profile' => $profile], $bundled);
        $drivers = [];
        foreach ($entries as $key => $entry) {
            $profile = $bundled[$key] ?? $entry['profile'];
            if ($profile['type'] !== $type) {
                continue;
            }

            // Preserve existing diagnostic labels and identifiers, not a
            // separate implementation of the underlying database mappings.
            [$label, $description, $schemaProfile, $optional] = match ($key) {
                'login:l2j_mobius' => [__('L2J Mobius — Interlude and newer'), __('L2J Mobius LoginServer for Interlude and newer builds.'), 'mobius_interlude_plus', ['account_data' => 'account_data', 'accounts_ipauth' => 'ip_authorization']],
                'login:l2j_mobius_legacy' => [__('L2J Mobius Legacy — C1/C4'), __('L2J Mobius legacy LoginServer for C1 and C4 builds.'), 'mobius_legacy', []],
                'login:rusacis' => ['RUSaCis', __('RUSaCis LoginServer with BCrypt password hashing.'), 'rusacis_interlude', []],
                'game:l2j_mobius' => ['L2J Mobius', __('One L2J Mobius GameServer driver with automatic legacy and modern schema detection.'), 'universal:'.$profile['id'], ['heroes' => 'heroes', 'castle' => 'castles']],
                'game:rusacis' => ['RUSaCis', __('RUSaCis GameServer with separate Interlude schema and character statistics.'), 'rusacis_interlude', ['heroes' => 'heroes', 'castle' => 'castles']],
                'login:lucera2' => [$profile['name'], __('Lucera 2 game accounts with Whirlpool2 password hashing.'), 'universal:lucera2', []],
                'game:lucera2' => [$profile['name'], __('Lucera 2 characters and active subclasses.'), 'universal:lucera2', []],
                default => [$profile['name'], __('User-defined JSON driver'), 'universal:'.$profile['id'], []],
            };
            $config = $profile['config'];
            $capabilities = $config['capabilities'];
            $descriptor = [
                'label' => $label,
                'description' => $description,
                'ready' => true,
                'service_port' => $type === 'login' ? 2106 : 7777,
                'schema_profile' => $schemaProfile,
                'capabilities' => $capabilities,
                'optional_capabilities' => $optional,
                'requirements' => $config['requirements'],
            ];
            if ($type === 'game') {
                $descriptor['statistics'] = array_values(array_intersect(['level', 'pvp', 'pk', 'play_time', 'heroes', 'castles'], $capabilities));
                if ($optional !== []) {
                    $descriptor['capabilities'] = array_values(array_diff($capabilities, ['characters', 'character_rescue', ...array_values($optional)]));
                }
                $online = $config['characters']['fields']['online'] ?? null;
                if (($online['kind'] ?? null) === 'column') {
                    $descriptor['online_count'] = ['table' => $config['characters']['table'], 'column' => $online['column'], 'value' => 1];
                }
                $created = $config['characters']['fields']['created_at'] ?? null;
                if (($created['kind'] ?? null) === 'column') {
                    $descriptor['character_created_at_column'] = $created['column'];
                }
            } elseif (! isset($bundled[$key])) {
                $descriptor['statistics'] = [];
            }
            if ($type === 'login' && in_array($key, ['login:l2j_mobius', 'login:l2j_mobius_legacy', 'login:rusacis'], true)) {
                $descriptor['capabilities'] = array_values(array_intersect(['account_lookup', 'account_creation', 'password_change'], $capabilities));
            }
            $drivers[$profile['id']] = $descriptor;
        }

        return $drivers;
    }

    /** @return list<string> */
    public function loginDriverKeys(): array
    {
        return array_keys($this->loginDrivers());
    }

    /** @return list<string> */
    public function gameDriverKeys(): array
    {
        return array_keys($this->gameDrivers());
    }

    /** @return array{label:string,description:string,ready:bool,service_port:int,schema_profile:string,capabilities:list<string>,optional_capabilities:array<string,string>,requirements:list<array{table:string,columns:list<string>,any_columns?:list<string>,optional_columns?:list<string>,required:bool}>}|null */
    public function loginDriver(string $key): ?array
    {
        return $this->loginDrivers()[$key] ?? null;
    }

    /** @return array{label:string,description:string,ready:bool,service_port:int,schema_profile:string,character_created_at_column?:string|null,online_count?:array{table:string,column:string,value:int|string},statistics?:list<string>,capabilities:list<string>,optional_capabilities:array<string,string>,requirements:list<array{table:string,columns:list<string>,any_columns?:list<string>,optional_columns?:list<string>,required:bool}>}|null */
    public function gameDriver(string $key): ?array
    {
        return $this->gameDrivers()[$key] ?? null;
    }
}
