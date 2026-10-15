<?php

namespace App\Services\Servers;

use App\Services\GameWorld\MobiusGameSchemaInspector;
use App\Services\GameWorld\RusacisGameSchemaInspector;

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
        return [
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
                'requirements' => [
                    [
                        'table' => 'accounts',
                        'columns' => ['login', 'password', 'email', 'created_time', 'lastactive', 'accessLevel', 'lastIP', 'lastServer'],
                        'required' => true,
                    ],
                    [
                        'table' => 'account_data',
                        'columns' => ['account_name', 'var', 'value'],
                        'required' => false,
                    ],
                    [
                        'table' => 'accounts_ipauth',
                        'columns' => ['login', 'ip', 'type'],
                        'required' => false,
                    ],
                ],
            ],
            self::MOBIUS_LEGACY_LOGIN_DRIVER => [
                'label' => __('L2J Mobius Legacy — C1/C4'),
                'description' => __('L2J Mobius legacy LoginServer for C1 and C4 builds.'),
                'ready' => true,
                'service_port' => 2106,
                'schema_profile' => 'mobius_legacy',
                'capabilities' => ['account_lookup', 'account_creation', 'password_change'],
                'optional_capabilities' => [],
                'requirements' => [
                    [
                        'table' => 'accounts',
                        'columns' => ['login', 'password', 'email', 'created_time', 'lastactive', 'accessLevel', 'lastIP', 'lastServer'],
                        'required' => true,
                    ],
                ],
            ],
            self::RUSACIS_DRIVER => [
                'label' => 'RUSaCis',
                'description' => __('RUSaCis LoginServer with BCrypt password hashing.'),
                'ready' => true,
                'service_port' => 2106,
                'schema_profile' => 'rusacis_interlude',
                'capabilities' => ['account_lookup', 'account_creation', 'password_change'],
                'optional_capabilities' => [],
                'requirements' => [
                    [
                        'table' => 'accounts',
                        'columns' => ['login', 'password', 'last_active', 'access_level', 'last_server'],
                        'required' => true,
                    ],
                ],
            ],
        ];
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
        return [
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
                'requirements' => MobiusGameSchemaInspector::requirements(),
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
                'requirements' => RusacisGameSchemaInspector::requirements(),
            ],
        ];
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
