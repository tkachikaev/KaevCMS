<?php

namespace Tests\Feature;

use App\Contracts\GameServerDatabaseGateway;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Services\GameWorld\GameStatistics;
use App\Services\GameWorld\GameWorldDriverResolver;
use App\Services\SiteSettings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Fakes\FailingGameServerDatabaseGateway;
use Tests\Fakes\FakeGameServerDatabaseGateway;
use Tests\TestCase;

class PublicGameStatisticsTest extends TestCase
{
    use RefreshDatabase;

    private string $databasePath;

    private FakeGameServerDatabaseGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->databasePath = database_path('statistics-test.sqlite');
        @unlink($this->databasePath);
        touch($this->databasePath);
        config()->set('database.connections.statistics_test', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('statistics_test');
        $this->createGameSchema();
        $this->seedGameData();
        $this->gateway = new FakeGameServerDatabaseGateway('statistics_test');
        $this->app->instance(GameServerDatabaseGateway::class, $this->gateway);
    }

    protected function tearDown(): void
    {
        DB::purge('statistics_test');
        @unlink($this->databasePath);

        parent::tearDown();
    }

    public function test_statistics_page_is_public_but_hides_servers_until_enabled(): void
    {
        GameServer::query()->update(['statistics_enabled' => false]);

        $this->get('/statistics')
            ->assertOk()
            ->assertSee('Статистика пока недоступна.')
            ->assertDontSee('Эльфачка');
    }

    public function test_public_navigation_shows_statistics_only_when_a_connected_server_is_enabled(): void
    {
        $server = $this->statisticsServer(['statistics_enabled' => false]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('>Статистика</a>', false);

        $server->update(['statistics_enabled' => true]);

        $this->get('/')
            ->assertOk()
            ->assertSee('>Статистика</a>', false);
    }

    public function test_home_uses_configured_game_statistics_instead_of_mock_characters(): void
    {
        $this->statisticsServer();

        $this->get('/')
            ->assertOk()
            ->assertSee('Хрюшка')
            ->assertDontSee('TheGreatPlayer');
    }

    public function test_home_shows_honest_empty_ranking_without_configured_statistics(): void
    {
        GameServer::query()->update(['statistics_enabled' => false]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Данные рейтинга пока недоступны.')
            ->assertDontSee('TheGreatPlayer');
    }

    public function test_mobius_pvp_ranking_is_rendered_with_normalized_character_data(): void
    {
        $server = $this->statisticsServer();

        $this->get('/statistics/'.$server->id.'?section=pvp')
            ->assertOk()
            ->assertSee('Эльфачка')
            ->assertSee('Тёмный мистик')
            ->assertSee('Тёмный эльф')
            ->assertSee('Женский')
            ->assertSee('Васельки')
            ->assertSee('5')
            ->assertDontSee('test1');
    }

    public function test_statistics_are_available_through_localized_routes(): void
    {
        $server = $this->statisticsServer();

        $this->get('/en/statistics')
            ->assertOk()
            ->assertSee('Statistics');

        $this->get('/en/statistics/'.$server->id.'?section=pvp')
            ->assertOk()
            ->assertSee('Statistics')
            ->assertSee('Dark Mystic')
            ->assertSee('Dark Elf')
            ->assertSee('Female');
    }

    public function test_privileged_and_deleted_characters_are_excluded_from_rankings(): void
    {
        $server = $this->statisticsServer();
        DB::connection('statistics_test')->table('characters')->insert([
            'account_name' => 'deleted',
            'charId' => 268474699,
            'char_name' => 'DeletedChampion',
            'level' => 80,
            'exp' => 999999999,
            'classid' => 92,
            'race' => 0,
            'sex' => 0,
            'title' => '',
            'online' => 0,
            'lastAccess' => 1784320151700,
            'onlinetime' => 999999,
            'pvpkills' => 999,
            'pkkills' => 999,
            'karma' => 0,
            'nobless' => 1,
            'clanid' => 0,
            'accesslevel' => 0,
            'deletetime' => 1,
        ]);

        $this->get('/statistics/'.$server->id.'?section=pk')
            ->assertOk()
            ->assertSee('Эльфачка')
            ->assertDontSee('Timur')
            ->assertDontSee('DeletedChampion');
    }

    public function test_current_heroes_and_castle_owners_are_read_from_separate_tables(): void
    {
        $server = $this->statisticsServer();

        $this->get('/statistics/'.$server->id.'?section=heroes')
            ->assertOk()
            ->assertSee('Эльфачка')
            ->assertSee('Герой');

        $this->get('/statistics/'.$server->id.'?section=castles')
            ->assertOk()
            ->assertSee('Dion')
            ->assertSee('Васельки')
            ->assertSee('Timur');
    }

    public function test_each_ranking_uses_its_own_limit_and_heroes_are_not_limited(): void
    {
        $server = $this->statisticsServer([
            'statistics_level_limit' => 1,
            'statistics_pvp_limit' => 2,
            'statistics_pk_limit' => 1,
            'statistics_play_time_limit' => 3,
        ]);

        DB::connection('statistics_test')->table('characters')->insert([
            [
                'account_name' => 'rank-a',
                'charId' => 268474701,
                'char_name' => 'RankAlpha',
                'level' => 80,
                'exp' => 999999999,
                'classid' => 92,
                'race' => 0,
                'sex' => 0,
                'title' => '',
                'online' => 0,
                'lastAccess' => 1784320151700,
                'onlinetime' => 5000,
                'pvpkills' => 9,
                'pkkills' => 1,
                'karma' => 0,
                'nobless' => 1,
                'clanid' => 0,
                'accesslevel' => 0,
                'deletetime' => 0,
            ],
            [
                'account_name' => 'rank-b',
                'charId' => 268474702,
                'char_name' => 'RankBeta',
                'level' => 79,
                'exp' => 888888888,
                'classid' => 92,
                'race' => 0,
                'sex' => 1,
                'title' => '',
                'online' => 0,
                'lastAccess' => 1784320151700,
                'onlinetime' => 4000,
                'pvpkills' => 8,
                'pkkills' => 8,
                'karma' => 0,
                'nobless' => 1,
                'clanid' => 0,
                'accesslevel' => 0,
                'deletetime' => 0,
            ],
            [
                'account_name' => 'rank-c',
                'charId' => 268474703,
                'char_name' => 'RankGamma',
                'level' => 78,
                'exp' => 777777777,
                'classid' => 92,
                'race' => 0,
                'sex' => 0,
                'title' => '',
                'online' => 0,
                'lastAccess' => 1784320151700,
                'onlinetime' => 3000,
                'pvpkills' => 7,
                'pkkills' => 7,
                'karma' => 0,
                'nobless' => 0,
                'clanid' => 0,
                'accesslevel' => 0,
                'deletetime' => 0,
            ],
        ]);
        DB::connection('statistics_test')->table('heroes')->insert([
            [
                'charId' => 268474701,
                'class_id' => 92,
                'count' => 1,
                'played' => 1,
                'claimed' => 1,
                'message' => '',
            ],
            [
                'charId' => 268474702,
                'class_id' => 92,
                'count' => 1,
                'played' => 1,
                'claimed' => 1,
                'message' => '',
            ],
        ]);

        $this->get('/statistics/'.$server->id.'?section=level')
            ->assertOk()
            ->assertSee('RankAlpha')
            ->assertDontSee('RankBeta');

        $this->get('/statistics/'.$server->id.'?section=pvp')
            ->assertOk()
            ->assertSee('RankAlpha')
            ->assertSee('RankBeta')
            ->assertDontSee('RankGamma');

        $this->get('/statistics/'.$server->id.'?section=pk')
            ->assertOk()
            ->assertSee('RankBeta')
            ->assertDontSee('RankGamma');

        $this->get('/statistics/'.$server->id.'?section=heroes')
            ->assertOk()
            ->assertSee('Эльфачка')
            ->assertSee('RankAlpha')
            ->assertSee('RankBeta');
    }

    public function test_driver_returns_full_character_data_for_the_future_personal_account(): void
    {
        $server = $this->statisticsServer();
        $characters = app(GameWorldDriverResolver::class)
            ->resolve($server)
            ->charactersForAccount($server, 'test1');

        $this->assertCount(1, $characters);
        $this->assertSame('Эльфачка', $characters[0]['name']);
        $this->assertSame(38, $characters[0]['class_id']);
        $this->assertSame(2, $characters[0]['race']);
        $this->assertSame(1, $characters[0]['gender']);
        $this->assertSame('Васельки', $characters[0]['clan_name']);
        $this->assertSame(5, $characters[0]['pvp_kills']);
        $this->assertSame(1, $characters[0]['noble']);
        $this->assertArrayNotHasKey('account_name', $characters[0]);
    }

    public function test_modern_mobius_schema_uses_reputation_column_and_keeps_one_driver(): void
    {
        Schema::connection('statistics_test')->table('characters', function (Blueprint $table): void {
            $table->integer('reputation')->default(0);
        });
        DB::connection('statistics_test')->table('characters')
            ->where('account_name', 'test1')
            ->update(['reputation' => 777]);
        Schema::connection('statistics_test')->table('characters', function (Blueprint $table): void {
            $table->dropColumn('karma');
        });

        $server = $this->statisticsServer(['chronicle' => 'Interlude']);
        $characters = app(GameWorldDriverResolver::class)
            ->resolve($server)
            ->charactersForAccount($server, 'test1');

        $this->assertCount(1, $characters);
        $this->assertSame(777, $characters[0]['reputation']);
        $this->assertArrayNotHasKey('karma', $characters[0]);
    }

    public function test_legacy_mobius_schema_normalizes_karma_to_reputation(): void
    {
        DB::connection('statistics_test')->table('characters')
            ->where('account_name', 'test1')
            ->update(['karma' => 321]);

        $server = $this->statisticsServer(['chronicle' => 'Interlude']);
        $characters = app(GameWorldDriverResolver::class)
            ->resolve($server)
            ->charactersForAccount($server, 'test1');

        $this->assertSame(321, $characters[0]['reputation']);
        $this->assertArrayNotHasKey('karma', $characters[0]);
    }

    public function test_optional_heroes_and_castles_are_removed_from_available_sections(): void
    {
        Schema::connection('statistics_test')->drop('heroes');
        Schema::connection('statistics_test')->drop('castle');
        $server = $this->statisticsServer();

        $sections = app(GameStatistics::class)->sections($server);

        $this->assertArrayHasKey('level', $sections);
        $this->assertArrayNotHasKey('heroes', $sections);
        $this->assertArrayNotHasKey('castles', $sections);
    }

    public function test_incompatible_mobius_schema_without_reputation_field_is_rejected(): void
    {
        Schema::connection('statistics_test')->table('characters', function (Blueprint $table): void {
            $table->dropColumn('karma');
        });
        $server = $this->statisticsServer();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('either karma or reputation');

        app(GameWorldDriverResolver::class)->resolve($server)->capabilities($server);
    }

    public function test_disabled_statistics_section_falls_back_to_the_first_enabled_section(): void
    {
        $server = $this->statisticsServer([
            'statistics_pvp_enabled' => false,
            'statistics_level_enabled' => true,
        ]);

        $this->get('/statistics/'.$server->id.'?section=pvp')
            ->assertOk()
            ->assertSee('Хрюшка')
            ->assertSee('Уровень')
            ->assertDontSee('>PvP<', false);
    }

    public function test_successful_statistics_queries_are_cached(): void
    {
        $server = $this->statisticsServer();

        $this->get('/statistics/'.$server->id.'?section=pvp')->assertOk();
        $this->get('/statistics/'.$server->id.'?section=pvp')->assertOk();

        $this->assertSame(2, $this->gateway->calls);
    }

    public function test_failed_statistics_queries_use_a_short_cooldown(): void
    {
        $server = $this->statisticsServer();
        $gateway = new FailingGameServerDatabaseGateway;
        $this->app->instance(GameServerDatabaseGateway::class, $gateway);

        $this->get('/statistics/'.$server->id.'?section=pvp')
            ->assertOk()
            ->assertSee('Игровые данные временно недоступны.');
        $this->get('/statistics/'.$server->id.'?section=pvp')
            ->assertOk()
            ->assertSee('Игровые данные временно недоступны.');

        $this->assertSame(1, $gateway->calls);
    }

    public function test_level_ranking_respects_the_global_online_visibility_setting(): void
    {
        $server = $this->statisticsServer();
        app(SiteSettings::class)->setShowPublicOnline(false);

        $this->get('/statistics/'.$server->id.'?section=level')
            ->assertOk()
            ->assertSee('Эльфачка')
            ->assertDontSee('Онлайн')
            ->assertDontSee('Офлайн');
    }

    public function test_disabled_or_unconfigured_server_cannot_be_selected_directly(): void
    {
        $server = $this->statisticsServer(['statistics_enabled' => false]);

        $this->get('/statistics/'.$server->id)->assertNotFound();
    }

    /** @param array<string,mixed> $values */
    private function statisticsServer(array $values = []): GameServer
    {
        GameServer::query()->delete();
        LoginServer::query()->delete();
        $loginServer = LoginServer::factory()->create();

        return GameServer::factory()->for($loginServer)->create(array_merge([
            'name' => 'Interlude x5',
            'statistics_enabled' => true,
            'statistics_level_limit' => 10,
            'statistics_pvp_limit' => 10,
            'statistics_pk_limit' => 10,
            'statistics_play_time_limit' => 10,
        ], $values));
    }

    private function createGameSchema(): void
    {
        $schema = Schema::connection('statistics_test');
        $schema->create('characters', function (Blueprint $table): void {
            $table->string('account_name');
            $table->unsignedBigInteger('charId')->primary();
            $table->string('char_name');
            $table->unsignedInteger('level');
            $table->unsignedBigInteger('exp')->default(0);
            $table->integer('classid');
            $table->integer('race');
            $table->integer('sex');
            $table->string('title')->default('');
            $table->integer('online')->default(0);
            $table->unsignedBigInteger('lastAccess')->default(0);
            $table->unsignedBigInteger('onlinetime')->default(0);
            $table->unsignedInteger('pvpkills')->default(0);
            $table->unsignedInteger('pkkills')->default(0);
            $table->integer('karma')->default(0);
            $table->integer('nobless')->default(0);
            $table->unsignedBigInteger('clanid')->default(0);
            $table->integer('accesslevel')->default(0);
            $table->unsignedBigInteger('deletetime')->default(0);
        });
        $schema->create('clan_data', function (Blueprint $table): void {
            $table->unsignedBigInteger('clan_id')->primary();
            $table->string('clan_name');
            $table->integer('clan_level')->default(0);
            $table->integer('reputation_score')->default(0);
            $table->integer('hasCastle')->default(0);
            $table->unsignedBigInteger('leader_id');
        });
        $schema->create('heroes', function (Blueprint $table): void {
            $table->unsignedBigInteger('charId')->primary();
            $table->integer('class_id');
            $table->integer('count')->default(0);
            $table->integer('played')->default(0);
            $table->integer('claimed')->default(0);
            $table->string('message')->default('');
        });
        $schema->create('castle', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('name');
        });
    }

    private function seedGameData(): void
    {
        DB::connection('statistics_test')->table('characters')->insert([
            [
                'account_name' => 'test',
                'charId' => 268474651,
                'char_name' => 'Timur',
                'level' => 58,
                'exp' => 102265326,
                'classid' => 9,
                'race' => 0,
                'sex' => 0,
                'title' => '',
                'online' => 1,
                'lastAccess' => 1784319472139,
                'onlinetime' => 877,
                'pvpkills' => 0,
                'pkkills' => 3,
                'karma' => 3240,
                'nobless' => 0,
                'clanid' => 268474665,
                'accesslevel' => 100,
                'deletetime' => 0,
            ],
            [
                'account_name' => 'test1',
                'charId' => 268474666,
                'char_name' => 'Эльфачка',
                'level' => 58,
                'exp' => 102265326,
                'classid' => 38,
                'race' => 2,
                'sex' => 1,
                'title' => '',
                'online' => 1,
                'lastAccess' => 1784320151700,
                'onlinetime' => 1269,
                'pvpkills' => 5,
                'pkkills' => 1,
                'karma' => 0,
                'nobless' => 1,
                'clanid' => 268474665,
                'accesslevel' => 0,
                'deletetime' => 0,
            ],
            [
                'account_name' => 'riddle',
                'charId' => 268474655,
                'char_name' => 'Хрюшка',
                'level' => 2,
                'exp' => 203,
                'classid' => 0,
                'race' => 0,
                'sex' => 0,
                'title' => '',
                'online' => 0,
                'lastAccess' => 1784054483314,
                'onlinetime' => 156,
                'pvpkills' => 0,
                'pkkills' => 0,
                'karma' => 0,
                'nobless' => 0,
                'clanid' => 0,
                'accesslevel' => 0,
                'deletetime' => 0,
            ],
        ]);
        DB::connection('statistics_test')->table('clan_data')->insert([
            'clan_id' => 268474665,
            'clan_name' => 'Васельки',
            'clan_level' => 3,
            'reputation_score' => 0,
            'hasCastle' => 2,
            'leader_id' => 268474651,
        ]);
        DB::connection('statistics_test')->table('heroes')->insert([
            'charId' => 268474666,
            'class_id' => 38,
            'count' => 1,
            'played' => 1,
            'claimed' => 1,
            'message' => '',
        ]);
        DB::connection('statistics_test')->table('castle')->insert([
            'id' => 2,
            'name' => 'Dion',
        ]);
    }
}
