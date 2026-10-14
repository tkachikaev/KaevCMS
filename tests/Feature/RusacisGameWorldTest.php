<?php

namespace Tests\Feature;

use App\Contracts\GameServerDatabaseGateway;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Services\GameAccounts\ExternalGameAccountGateway;
use App\Services\GameWorld\GameWorldDriverResolver;
use App\Services\GameWorld\RusacisGameSchemaInspector;
use App\Services\GameWorld\RusacisGameWorldDriver;
use App\Services\Servers\MySqlGameServerOnlineCounter;
use App\Services\Servers\ServerDriverRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Fakes\FakeGameServerDatabaseGateway;
use Tests\TestCase;

class RusacisGameWorldTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    private GameServer $server;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->file = database_path('rusacis-world-test.sqlite');
        @unlink($this->file);
        touch($this->file);
        config()->set('database.connections.rusacis_world_test', [
            'driver' => 'sqlite',
            'database' => $this->file,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('rusacis_world_test');
        $this->createSchema();
        $this->seedCharacters();

        $this->app->instance(GameServerDatabaseGateway::class, new FakeGameServerDatabaseGateway('rusacis_world_test'));
        $login = LoginServer::factory()->create(['driver' => ServerDriverRegistry::RUSACIS_DRIVER]);
        $this->server = GameServer::factory()->for($login)->create([
            'driver' => ServerDriverRegistry::RUSACIS_DRIVER,
            'statistics_enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('rusacis_world_test');
        @unlink($this->file);
        parent::tearDown();
    }

    public function test_rusacis_character_account_mapping_and_nullable_deletion_filter(): void
    {
        $driver = app(GameWorldDriverResolver::class)->resolve($this->server);
        $this->assertInstanceOf(RusacisGameWorldDriver::class, $driver);

        $characters = $driver->charactersForAccount($this->server, 'player1');
        $this->assertCount(2, $characters);
        $this->assertSame([100, 101], array_column($characters, 'id'));
        $this->assertSame('Test Clan', $characters[0]['clan_name']);
        $this->assertSame(12, $characters[0]['pvp_kills']);
        $this->assertSame(15, $characters[0]['reputation']);
        $this->assertArrayNotHasKey('account_name', $characters[0]);

        $cabinet = app(ExternalGameAccountGateway::class)->characters($this->server, 'player1');
        $this->assertCount(2, $cabinet);
        $this->assertSame(100, $cabinet[0]['id']);
        $this->assertTrue($cabinet[0]['hero']);
        $this->assertSame('Test Clan', $cabinet[0]['clan']);
        $this->assertNull($cabinet[0]['created_at']);
    }

    public function test_rankings_online_count_heroes_and_castle_owners_use_rusacis_columns(): void
    {
        $driver = app(GameWorldDriverResolver::class)->resolve($this->server);
        $this->assertSame(['level', 'pvp', 'pk', 'play_time', 'heroes', 'castles'], $driver->capabilities($this->server));
        $this->assertSame(['ActiveHero', 'NormalCharacter', 'OtherAccount'], array_column($driver->ranking($this->server, 'level', 5), 'name'));
        $this->assertSame(['ActiveHero'], array_column($driver->ranking($this->server, 'pvp', 10), 'name'));
        $this->assertSame('NormalCharacter', $driver->ranking($this->server, 'pk', 10)[0]['name']);
        $this->assertSame('ActiveHero', $driver->ranking($this->server, 'play_time', 10)[0]['name']);
        $this->assertCount(1, $driver->ranking($this->server, 'level', 1));
        $this->assertSame(['ActiveHero'], array_column($driver->heroes($this->server), 'name'));
        $this->assertSame('Test Clan', $driver->castleOwners($this->server)[0]['clan_name']);
        $this->assertSame('ActiveHero', $driver->castleOwners($this->server)[0]['leader_name']);
        $this->assertSame(1, app(MySqlGameServerOnlineCounter::class)->count($this->server));
        $this->assertNotContains('character_rescue', $driver->capabilities($this->server));
    }

    public function test_public_statistics_page_uses_rusacis_character_rankings(): void
    {
        $this->get('/statistics/'.$this->server->id.'?section=pvp')
            ->assertOk()
            ->assertSee('ActiveHero')
            ->assertDontSee('DeletedCharacter')
            ->assertDontSee('GM');
    }

    public function test_optional_tables_disable_optional_capabilities_without_blocking_rankings(): void
    {
        Schema::connection('rusacis_world_test')->drop('heroes');
        Schema::connection('rusacis_world_test')->drop('castle');
        $driver = app(GameWorldDriverResolver::class)->resolve($this->server);

        $this->assertSame(['level', 'pvp', 'pk', 'play_time'], $driver->capabilities($this->server));
        $this->assertSame([], $driver->heroes($this->server));
        $this->assertSame([], $driver->castleOwners($this->server));
        $this->assertCount(3, $driver->ranking($this->server, 'level', 10));
    }

    public function test_missing_required_column_fails_closed_instead_of_running_mobius_queries(): void
    {
        Schema::connection('rusacis_world_test')->table('characters', static function (Blueprint $table): void {
            $table->dropColumn('karma');
        });
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('karma');
        app(GameWorldDriverResolver::class)->resolve($this->server)->capabilities($this->server);
    }

    public function test_invalid_ranking_section_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(GameWorldDriverResolver::class)->resolve($this->server)->ranking($this->server, 'items', 10);
    }

    private function createSchema(): void
    {
        Schema::connection('rusacis_world_test')->create('characters', static function (Blueprint $t): void {
            $t->string('account_name')->nullable();
            $t->unsignedInteger('obj_Id')->primary();
            $t->string('char_name');
            $t->unsignedInteger('level');
            $t->unsignedBigInteger('exp')->default(0);
            $t->integer('classid');
            $t->integer('race');
            $t->integer('sex');
            $t->string('title')->nullable();
            $t->integer('online')->default(0);
            $t->unsignedBigInteger('lastAccess')->default(0);
            $t->unsignedInteger('onlinetime')->default(0);
            $t->integer('pvpkills')->default(0);
            $t->integer('pkkills')->default(0);
            $t->integer('karma')->default(0);
            $t->integer('nobless')->default(0);
            $t->integer('hero')->default(0);
            $t->integer('clanid')->nullable();
            $t->integer('accesslevel')->default(0);
            $t->unsignedBigInteger('deletetime')->nullable();
        });
        Schema::connection('rusacis_world_test')->create('clan_data', static function (Blueprint $t): void {
            $t->integer('clan_id')->primary();
            $t->string('clan_name');
            $t->integer('clan_level')->default(0);
            $t->integer('reputation_score')->default(0);
            $t->integer('hasCastle')->default(0);
            $t->integer('leader_id')->default(0);
        });
        Schema::connection('rusacis_world_test')->create('heroes', static function (Blueprint $t): void {
            $t->integer('char_id')->primary();
            $t->integer('class_id');
            $t->integer('count');
            $t->integer('played');
            $t->integer('active');
        });
        Schema::connection('rusacis_world_test')->create('castle', static function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->string('name');
        });
    }

    private function seedCharacters(): void
    {
        $db = DB::connection('rusacis_world_test');
        $db->table('clan_data')->insert(['clan_id' => 30, 'clan_name' => 'Test Clan', 'clan_level' => 6, 'reputation_score' => 55, 'hasCastle' => 1, 'leader_id' => 100]);
        $db->table('castle')->insert(['id' => 1, 'name' => 'Gludio']);
        $db->table('heroes')->insert([
            ['char_id' => 100, 'class_id' => 88, 'count' => 2, 'played' => 1, 'active' => 1],
            ['char_id' => 101, 'class_id' => 88, 'count' => 1, 'played' => 1, 'active' => 0],
        ]);
        $base = ['account_name' => 'player1', 'level' => 60, 'exp' => 10000, 'classid' => 88, 'race' => 0, 'sex' => 1, 'title' => null, 'online' => 0, 'lastAccess' => 1700000000000, 'onlinetime' => 3600, 'pvpkills' => 0, 'pkkills' => 0, 'karma' => 0, 'nobless' => 0, 'hero' => 0, 'clanid' => null, 'accesslevel' => 0, 'deletetime' => null];
        $db->table('characters')->insert([
            array_merge($base, ['obj_Id' => 100, 'char_name' => 'ActiveHero', 'level' => 80, 'exp' => 100000, 'online' => 1, 'onlinetime' => 7200, 'pvpkills' => 12, 'karma' => 15, 'nobless' => 1, 'hero' => 1, 'clanid' => 30]),
            array_merge($base, ['obj_Id' => 101, 'char_name' => 'NormalCharacter', 'deletetime' => 0, 'pkkills' => 3]),
            array_merge($base, ['obj_Id' => 102, 'char_name' => 'DeletedCharacter', 'deletetime' => 1700000000000]),
            array_merge($base, ['obj_Id' => 103, 'char_name' => 'GM', 'accesslevel' => 100]),
            array_merge($base, ['obj_Id' => 104, 'char_name' => 'OtherAccount', 'account_name' => 'someone_else', 'level' => 40]),
        ]);
    }
}
