<?php

namespace App\Services\Servers;

use App\Support\Database\TableExistence;
use App\Support\UniversalDrivers\BundledProfileCatalog;
use App\Support\UniversalDrivers\DriverProfileCatalog;
use Illuminate\Support\Facades\DB;

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
        $profiles = (new BundledProfileCatalog)->all();
        $legacy = [
            self::MOBIUS_DRIVER => [
                'label' => __('L2J Mobius — Interlude and newer'),
                'description' => __('L2J Mobius LoginServer for Interlude and newer builds.'),
                'ready' => true,
                'service_port' => 2106,
                'schema_profile' => 'mobius_interlude_plus',
                'capabilities' => ['account_lookup', 'account_creation', 'password_change'],
                'optional_capabilities' => [
                    'account_data' => 'account_data',
                    'accounts_ipauth' => 'ip_authorization',
                ],
                'requirements' => $profiles['login:l2j_mobius']['config']['requirements'],
            ],
            self::MOBIUS_LEGACY_LOGIN_DRIVER => [
                'label' => __('L2J Mobius Legacy — C1/C4'),
                'description' => __('L2J Mobius legacy LoginServer for C1 and C4 builds.'),
                'ready' => true,
                'service_port' => 2106,
                'schema_profile' => 'mobius_legacy',
                'capabilities' => ['account_lookup', 'account_creation', 'password_change'],
                'optional_capabilities' => [],
                'requirements' => $profiles['login:l2j_mobius_legacy']['config']['requirements'],
            ],
            self::RUSACIS_DRIVER => [
                'label' => 'RUSaCis',
                'description' => __('RUSaCis LoginServer with BCrypt password hashing.'),
                'ready' => true,
                'service_port' => 2106,
                'schema_profile' => 'rusacis_interlude',
                'capabilities' => ['account_lookup', 'account_creation', 'password_change'],
                'optional_capabilities' => [],
                'requirements' => $profiles['login:rusacis']['config']['requirements'],
            ],
        ];

        // A bundled JSON profile hidden in the catalog must also disappear
        // from the connection selector; keeping the release-owned descriptor
        // visible would silently undo the administrator's delete action.
        return $this->selectableLegacy('login', $legacy) + $this->customDescriptors('login', array_keys($legacy));
    }

    /**
     * @return array<string, array{
     *     label:string,
     *     description:string,
     *     ready:bool,
     *     service_port:int,
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
        $profiles = (new BundledProfileCatalog)->all();
        $legacy = [
            self::MOBIUS_DRIVER => [
                'label' => 'L2J Mobius',
                'description' => __('One L2J Mobius GameServer driver with automatic legacy and modern schema detection.'),
                'ready' => true,
                'service_port' => 7777,
                'character_created_at_column' => 'createDate',
                'online_count' => [
                    'table' => 'characters',
                    'column' => 'online',
                    'value' => 1,
                ],
                'statistics' => ['level', 'pvp', 'pk', 'play_time', 'heroes', 'castles'],
                'capabilities' => ['level', 'pvp', 'pk', 'play_time'],
                'optional_capabilities' => [
                    'heroes' => 'heroes',
                    'castle' => 'castles',
                ],
                'requirements' => $profiles['game:l2j_mobius']['config']['requirements'],
            ],
            self::RUSACIS_DRIVER => [
                'label' => 'RUSaCis',
                'description' => __('RUSaCis GameServer with separate Interlude schema and character statistics.'),
                'ready' => true,
                'service_port' => 7777,
                'online_count' => [
                    'table' => 'characters',
                    'column' => 'online',
                    'value' => 1,
                ],
                'statistics' => ['level', 'pvp', 'pk', 'play_time', 'heroes', 'castles'],
                'capabilities' => ['level', 'pvp', 'pk', 'play_time'],
                'optional_capabilities' => [
                    'heroes' => 'heroes',
                    'castle' => 'castles',
                ],
                'requirements' => $profiles['game:rusacis']['config']['requirements'],
            ],
        ];

        return $this->selectableLegacy('game', $legacy) + $this->customDescriptors('game', array_keys($legacy));
    }

    /**
     * Preserve the bundled profiles for existing connections, but
     * respect tombstones in selectors. Deleting a profile is already refused
     * while any server uses its identifier, so hiding cannot orphan a server.
     *
     * @param  array<string,array<string,mixed>>  $legacy
     * @return array<string,array<string,mixed>>
     */
    private function selectableLegacy(string $type, array $legacy): array
    {
        if (! app(TableExistence::class)->has('universal_driver_profiles')) {
            return $legacy;
        }

        $hidden = DB::table('universal_driver_profiles')
            ->where('type', $type)
            ->where('is_deleted', true)
            ->pluck('profile_id')
            ->all();
        foreach ($hidden as $profileId) {
            unset($legacy[$profileId]);
        }

        return $legacy;
    }

    /**
     * Discover user-installed profiles from the existing catalog. Release-owned
     * IDs retain their release-owned descriptors until an
     * administrator explicitly applies a JSON snapshot to a connection.
     *
     * @param  list<string>  $reserved
     * @return array<string,array<string,mixed>>
     */
    private function customDescriptors(string $type, array $reserved): array
    {
        if (! app(TableExistence::class)->has('universal_driver_profiles')) {
            return [];
        }
        $drivers = [];
        foreach (app(DriverProfileCatalog::class)->all() as $entry) {
            $profile = $entry['profile'];
            if ($profile['type'] !== $type || in_array($profile['id'], $reserved, true)) {
                continue;
            }
            $config = $profile['config'];
            // No custom PHP / SQL can be registered here: only validated JSON.
            $drivers[$profile['id']] = [
                'label' => $profile['name'],
                'description' => __('User-defined JSON driver'),
                'ready' => true,
                'service_port' => $type === 'login' ? 2106 : 7777,
                'schema_profile' => 'universal:'.$profile['id'],
                'capabilities' => $config['capabilities'],
                'optional_capabilities' => [],
                'requirements' => $config['requirements'],
                'statistics' => $type === 'game' ? array_values(array_intersect(
                    ['level', 'pvp', 'pk', 'play_time', 'heroes', 'castles'], $config['capabilities'],
                )) : [],
                ...($type === 'game' && ($config['characters']['fields']['online']['kind'] ?? null) === 'column'
                    ? ['online_count' => [
                        'table' => $config['characters']['table'],
                        'column' => $config['characters']['fields']['online']['column'],
                        'value' => 1,
                    ]] : []),
            ];
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

    /** @return array{label:string,description:string,ready:bool,service_port:int,character_created_at_column?:string|null,online_count?:array{table:string,column:string,value:int|string},statistics?:list<string>,capabilities:list<string>,optional_capabilities:array<string,string>,requirements:list<array{table:string,columns:list<string>,any_columns?:list<string>,optional_columns?:list<string>,required:bool}>}|null */
    public function gameDriver(string $key): ?array
    {
        return $this->gameDrivers()[$key] ?? null;
    }
}
