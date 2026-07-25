<?php

namespace App\Services\Servers;

final class ServerDriverRegistry
{
    /**
     * @return array<string, array{
     *     label:string,
     *     description:string,
     *     ready:bool,
     *     service_port:int,
     *     requirements:list<array{table:string,columns:list<string>,any_columns?:list<string>,required:bool}>
     * }>
     */
    public function loginDrivers(): array
    {
        return [
            'l2j_mobius' => [
                'label' => __('L2J Mobius — Interlude and newer'),
                'description' => __('L2J Mobius LoginServer for Interlude and newer builds.'),
                'ready' => true,
                'service_port' => 2106,
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
            'l2j_mobius_legacy' => [
                'label' => __('L2J Mobius Legacy — C1/C4'),
                'description' => __('L2J Mobius legacy LoginServer for C1 and C4 builds.'),
                'ready' => true,
                'service_port' => 2106,
                'requirements' => [
                    [
                        'table' => 'accounts',
                        'columns' => ['login', 'password', 'email', 'created_time', 'lastactive', 'accessLevel', 'lastIP', 'lastServer'],
                        'required' => true,
                    ],
                ],
            ],
            'rusacis' => [
                'label' => 'RUSaCis',
                'description' => __('RUSaCis driver placeholder. Schema support will be added later.'),
                'ready' => false,
                'service_port' => 2106,
                'requirements' => [],
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
     *     requirements:list<array{table:string,columns:list<string>,any_columns?:list<string>,required:bool}>
     * }>
     */
    public function gameDrivers(): array
    {
        return [
            'l2j_mobius_ct0_interlude' => [
                'label' => __('L2J Mobius — all chronicles'),
                'description' => __('One L2J Mobius GameServer driver for legacy, mainline, Classic and Essence schemas.'),
                'ready' => true,
                'service_port' => 7777,
                'character_created_at_column' => 'createDate',
                'online_count' => [
                    'table' => 'characters',
                    'column' => 'online',
                    'value' => 1,
                ],
                'statistics' => ['level', 'pvp', 'pk', 'play_time', 'heroes', 'castles'],
                'requirements' => [
                    [
                        'table' => 'characters',
                        'columns' => [
                            'account_name',
                            'charId',
                            'char_name',
                            'level',
                            'exp',
                            'classid',
                            'race',
                            'sex',
                            'title',
                            'online',
                            'onlinetime',
                            'accesslevel',
                            'deletetime',
                            'pvpkills',
                            'pkkills',
                            'nobless',
                            'clanid',
                            'lastAccess',
                            'createDate',
                        ],
                        'any_columns' => ['karma', 'reputation'],
                        'required' => true,
                    ],
                    [
                        'table' => 'clan_data',
                        'columns' => ['clan_id', 'clan_name', 'clan_level', 'reputation_score', 'hasCastle', 'leader_id'],
                        'required' => true,
                    ],
                    [
                        'table' => 'heroes',
                        'columns' => ['charId', 'class_id', 'count', 'played', 'claimed'],
                        'required' => false,
                    ],
                    [
                        'table' => 'castle',
                        'columns' => ['id', 'name'],
                        'required' => false,
                    ],
                ],
            ],
            'rusacis' => [
                'label' => 'RUSaCis',
                'description' => __('RUSaCis driver placeholder. Schema support will be added later.'),
                'ready' => false,
                'service_port' => 7777,
                'requirements' => [],
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

    /** @return array{label:string,description:string,ready:bool,service_port:int,requirements:list<array{table:string,columns:list<string>,any_columns?:list<string>,required:bool}>}|null */
    public function loginDriver(string $key): ?array
    {
        return $this->loginDrivers()[$key] ?? null;
    }

    /** @return array{label:string,description:string,ready:bool,service_port:int,character_created_at_column?:string|null,online_count?:array{table:string,column:string,value:int|string},statistics?:list<string>,requirements:list<array{table:string,columns:list<string>,any_columns?:list<string>,required:bool}>}|null */
    public function gameDriver(string $key): ?array
    {
        return $this->gameDrivers()[$key] ?? null;
    }
}
