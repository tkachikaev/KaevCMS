<?php

namespace Tests\Unit;

use App\Models\GameServer;
use App\Services\GameWorld\GameWorldDriverResolver;
use App\Services\GameWorld\MobiusGameWorldDriver;
use App\Services\Servers\ServerDriverRegistry;
use Tests\TestCase;

class ServerDriverRegistryTest extends TestCase
{
    public function test_modern_mobius_login_driver_keeps_its_identifier_and_schema_contract(): void
    {
        $driver = app(ServerDriverRegistry::class)->loginDriver('l2j_mobius');

        $this->assertNotNull($driver);
        $this->assertSame(__('L2J Mobius — Interlude and newer'), $driver['label']);
        $this->assertTrue($driver['ready']);
        $this->assertSame(2106, $driver['service_port']);
        $this->assertSame([
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
        ], $driver['requirements']);
    }

    public function test_legacy_mobius_login_driver_requires_only_the_accounts_table(): void
    {
        $driver = app(ServerDriverRegistry::class)->loginDriver('l2j_mobius_legacy');

        $this->assertNotNull($driver);
        $this->assertSame(__('L2J Mobius Legacy — C1/C4'), $driver['label']);
        $this->assertTrue($driver['ready']);
        $this->assertSame(2106, $driver['service_port']);
        $this->assertSame([
            [
                'table' => 'accounts',
                'columns' => ['login', 'password', 'email', 'created_time', 'lastactive', 'accessLevel', 'lastIP', 'lastServer'],
                'required' => true,
            ],
        ], $driver['requirements']);
    }

    public function test_both_mobius_login_driver_identifiers_are_registered(): void
    {
        $keys = app(ServerDriverRegistry::class)->loginDriverKeys();

        $this->assertContains('l2j_mobius', $keys);
        $this->assertContains('l2j_mobius_legacy', $keys);
    }

    public function test_mobius_game_driver_matches_shared_schema_contract(): void
    {
        $driver = app(ServerDriverRegistry::class)->gameDriver('l2j_mobius_ct0_interlude');

        $this->assertNotNull($driver);
        $this->assertSame(__('L2J Mobius — all chronicles'), $driver['label']);
        $this->assertTrue($driver['ready']);
        $this->assertSame(7777, $driver['service_port']);
        $this->assertSame('createDate', $driver['character_created_at_column']);
        $this->assertSame([
            'table' => 'characters',
            'column' => 'online',
            'value' => 1,
        ], $driver['online_count']);
        $this->assertSame(['level', 'pvp', 'pk', 'play_time', 'heroes', 'castles'], $driver['statistics']);
        $this->assertSame([
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
        ], $driver['requirements']);
    }

    public function test_existing_interlude_identifier_resolves_to_the_shared_mobius_driver(): void
    {
        $server = new GameServer(['driver' => 'l2j_mobius_ct0_interlude']);

        $this->assertInstanceOf(
            MobiusGameWorldDriver::class,
            app(GameWorldDriverResolver::class)->resolve($server),
        );
    }

    public function test_rusacis_drivers_are_registered_as_placeholders(): void
    {
        $registry = app(ServerDriverRegistry::class);
        $loginDriver = $registry->loginDriver('rusacis');
        $gameDriver = $registry->gameDriver('rusacis');

        $this->assertNotNull($loginDriver);
        $this->assertFalse($loginDriver['ready']);
        $this->assertSame([], $loginDriver['requirements']);

        $this->assertNotNull($gameDriver);
        $this->assertFalse($gameDriver['ready']);
        $this->assertSame([], $gameDriver['requirements']);
    }
}
