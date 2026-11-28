<?php

namespace Tests\Feature\GameServerFeatures;

use App\Models\GameServer;
use App\Services\GameServerFeatures\ExternalCharacterRescueGateway;
use App\Support\GameServerFeatures\CharacterRescueWriteResult;
use App\Support\UniversalDrivers\ConnectionProfileSnapshots;
use App\Support\UniversalDrivers\DriverProfileCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fakes\FakeGameServerDatabaseGateway;
use Tests\TestCase;

class ExternalCharacterRescueGatewayTest extends TestCase
{
    use RefreshDatabase;

    private string $databasePath;

    private ExternalCharacterRescueGateway $gateway;

    private GameServer $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = database_path('character-rescue-test.sqlite');
        @unlink($this->databasePath);
        touch($this->databasePath);
        config()->set('database.connections.character_rescue_test', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('character_rescue_test');

        Schema::connection('character_rescue_test')->create('characters', function (Blueprint $table): void {
            $table->unsignedBigInteger('charId')->primary();
            $table->string('char_name');
            $table->string('account_name');
            $table->integer('x');
            $table->integer('y');
            $table->integer('z');
            $table->integer('online')->default(0);
            $table->unsignedBigInteger('lastAccess')->default(0);
            $table->unsignedBigInteger('deletetime')->default(0);
            $table->integer('accesslevel')->default(0);
        });

        $this->gateway = new ExternalCharacterRescueGateway(
            new FakeGameServerDatabaseGateway('character_rescue_test'),
        );
        $this->server = GameServer::factory()->create();
    }

    protected function tearDown(): void
    {
        DB::purge('character_rescue_test');
        @unlink($this->databasePath);

        parent::tearDown();
    }

    public function test_capabilities_confirm_all_required_character_columns(): void
    {
        $capabilities = $this->gateway->capabilities($this->server);

        $this->assertTrue($capabilities->supported);
        $this->assertSame([], $capabilities->missingColumns);
    }

    public function test_capabilities_report_a_missing_coordinate_column(): void
    {
        Schema::connection('character_rescue_test')->dropIfExists('characters');
        Schema::connection('character_rescue_test')->create('characters', function (Blueprint $table): void {
            $table->unsignedBigInteger('charId')->primary();
            $table->string('char_name');
            $table->string('account_name');
            $table->integer('x');
            $table->integer('y');
            $table->integer('online')->default(0);
            $table->unsignedBigInteger('lastAccess')->default(0);
            $table->unsignedBigInteger('deletetime')->default(0);
            $table->integer('accesslevel')->default(0);
        });

        $capabilities = $this->gateway->capabilities($this->server);

        $this->assertFalse($capabilities->supported);
        $this->assertSame(['z'], $capabilities->missingColumns);
    }

    public function test_native_rescue_rejects_non_unique_character_identity_without_writing(): void
    {
        Schema::connection('character_rescue_test')->dropIfExists('characters');
        Schema::connection('character_rescue_test')->create('characters', static function (Blueprint $table): void {
            $table->unsignedBigInteger('charId');
            $table->string('char_name');
            $table->string('account_name');
            foreach (['x', 'y', 'z', 'online', 'lastAccess', 'deletetime', 'accesslevel'] as $name) {
                $table->bigInteger($name);
            }
        });
        $this->insertCharacter();
        $this->insertCharacter(['char_name' => 'Duplicate']);
        $this->assertFalse($this->gateway->capabilities($this->server)->supported);

        $result = $this->gateway->rescue(
            $this->server, 'PlayerOne', 100, ['x' => 1, 'y' => 2, 'z' => 3], CarbonImmutable::now(),
        );

        $this->assertSame(CharacterRescueWriteResult::UNSUPPORTED, $result->status);
        $this->assertSame([10, 10], DB::connection('character_rescue_test')->table('characters')->pluck('x')->all());
    }

    public function test_it_moves_an_owned_offline_character_and_returns_old_coordinates(): void
    {
        $this->insertCharacter();

        $result = $this->gateway->rescue(
            $this->server,
            'PlayerOne',
            100,
            ['x' => 83400, 'y' => 148600, 'z' => -3400],
            CarbonImmutable::now()->subMinutes(5),
        );

        $this->assertTrue($result->successful());
        $this->assertSame([10, 20, 30], [$result->oldX, $result->oldY, $result->oldZ]);
        $this->assertSame(
            ['x' => 83400, 'y' => 148600, 'z' => -3400],
            (array) DB::connection('character_rescue_test')
                ->table('characters')
                ->where('charId', 100)
                ->first(['x', 'y', 'z']),
        );
    }

    public function test_it_rejects_an_online_character_without_changing_coordinates(): void
    {
        $this->insertCharacter(['online' => 1]);

        $result = $this->gateway->rescue(
            $this->server,
            'PlayerOne',
            100,
            ['x' => 83400, 'y' => 148600, 'z' => -3400],
            CarbonImmutable::now()->subMinutes(5),
        );

        $this->assertSame(CharacterRescueWriteResult::ONLINE, $result->status);
        $this->assertSame(
            ['x' => 10, 'y' => 20, 'z' => 30],
            (array) DB::connection('character_rescue_test')
                ->table('characters')
                ->where('charId', 100)
                ->first(['x', 'y', 'z']),
        );
    }

    public function test_it_rejects_a_character_that_has_not_been_offline_long_enough(): void
    {
        $this->insertCharacter([
            'lastAccess' => CarbonImmutable::now()->subMinute()->getTimestamp() * 1000,
        ]);

        $result = $this->gateway->rescue(
            $this->server,
            'PlayerOne',
            100,
            ['x' => 83400, 'y' => 148600, 'z' => -3400],
            CarbonImmutable::now()->subMinutes(5),
        );

        $this->assertSame(CharacterRescueWriteResult::OFFLINE_DELAY, $result->status);
    }

    public function test_same_target_coordinates_are_an_idempotent_success(): void
    {
        $this->insertCharacter(['x' => 83400, 'y' => 148600, 'z' => -3400]);

        $result = $this->gateway->rescue(
            $this->server,
            'PlayerOne',
            100,
            ['x' => 83400, 'y' => 148600, 'z' => -3400],
            CarbonImmutable::now()->subMinutes(5),
        );

        $this->assertSame(CharacterRescueWriteResult::SUCCESS, $result->status);
        $this->assertSame([83400, 148600, -3400], [$result->oldX, $result->oldY, $result->oldZ]);
    }

    public function test_character_from_another_game_account_is_not_found(): void
    {
        $this->insertCharacter();

        $result = $this->gateway->rescue(
            $this->server,
            'OtherAccount',
            100,
            ['x' => 83400, 'y' => 148600, 'z' => -3400],
            CarbonImmutable::now()->subMinutes(5),
        );

        $this->assertSame(CharacterRescueWriteResult::NOT_FOUND, $result->status);
    }

    public function test_applied_custom_json_rescue_uses_mapped_columns_and_preserves_eligibility(): void
    {
        $profile = [
            'id' => 'custom-rescue', 'name' => 'Custom rescue', 'type' => 'game', 'format_version' => 1,
            'config' => [
                'database' => ['dialect' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
                'requirements' => [['table' => 'players', 'required' => true, 'columns' => ['pid', 'nickname', 'owner_login', 'in_game', 'seen_ms', 'staff', 'removed', 'pos_x', 'pos_y', 'pos_z']]],
                'capabilities' => ['characters', 'character_rescue'],
                'characters' => [
                    'table' => 'players', 'owner' => ['identity' => 'login', 'column' => 'owner_login'],
                    'fields' => array_map(static fn (string $column): array => ['kind' => 'column', 'column' => $column], [
                        'id' => 'pid', 'name' => 'nickname', 'online' => 'in_game', 'last_seen_at_ms' => 'seen_ms',
                        'access_level' => 'staff', 'deletion_time' => 'removed', 'x' => 'pos_x', 'y' => 'pos_y', 'z' => 'pos_z',
                    ]),
                ],
                'relations' => [], 'rankings' => [], 'features' => [],
                'filters' => [['column' => 'staff', 'op' => 'eq', 'value' => 0], ['column' => 'removed', 'op' => 'zero_or_null']],
            ],
        ];
        app(DriverProfileCatalog::class)->create($profile);
        $this->server->update(['driver' => 'custom-rescue']);
        $this->assertFalse($this->gateway->supports($this->server));
        app(ConnectionProfileSnapshots::class)->verifyAndRecord($this->server, 'custom-rescue', static fn (): bool => true);

        Schema::connection('character_rescue_test')->create('players', static function (Blueprint $table): void {
            $table->unsignedBigInteger('pid')->primary();
            $table->string('nickname');
            $table->string('owner_login');
            foreach (['pos_x', 'pos_y', 'pos_z', 'in_game', 'staff', 'removed', 'seen_ms'] as $name) {
                $table->bigInteger($name)->nullable();
            }
        });
        $db = DB::connection('character_rescue_test');
        $original = ['pos_x' => 10, 'pos_y' => 20, 'pos_z' => 30];
        $db->table('players')->insert($original + [
            'pid' => 101, 'nickname' => 'Test', 'owner_login' => 'PlayerOne', 'in_game' => 0,
            'seen_ms' => CarbonImmutable::now()->subHour()->getTimestamp() * 1000, 'staff' => 0, 'removed' => null,
        ]);
        $target = ['x' => 100, 'y' => 200, 'z' => -300];
        $before = CarbonImmutable::now()->subMinutes(5);
        $this->assertTrue($this->gateway->capabilities($this->server)->supported);
        $this->assertSame(CharacterRescueWriteResult::NOT_FOUND, $this->gateway->rescue($this->server, 'OtherAccount', 101, $target, $before)->status);
        foreach ([
            [['in_game' => 1], CharacterRescueWriteResult::ONLINE],
            [['in_game' => 0, 'staff' => 1], CharacterRescueWriteResult::NOT_FOUND],
            [['staff' => 0, 'removed' => 1], CharacterRescueWriteResult::NOT_FOUND],
            [['removed' => null, 'seen_ms' => null], CharacterRescueWriteResult::OFFLINE_DELAY],
            [['seen_ms' => CarbonImmutable::now()->getTimestamp() * 1000], CharacterRescueWriteResult::OFFLINE_DELAY],
        ] as [$values, $status]) {
            $db->table('players')->where('pid', 101)->update($values);
            $this->assertSame($status, $this->gateway->rescue($this->server, 'PlayerOne', 101, $target, $before)->status);
            $this->assertSame($original, (array) $db->table('players')->where('pid', 101)->first(['pos_x', 'pos_y', 'pos_z']));
        }
        $db->table('players')->where('pid', 101)->update(['seen_ms' => $before->subHour()->getTimestamp() * 1000]);
        $result = $this->gateway->rescue($this->server, 'PlayerOne', 101, $target, $before);
        $this->assertTrue($result->successful());
        $this->assertSame([10, 20, 30], [$result->oldX, $result->oldY, $result->oldZ]);
        $this->assertSame(['pos_x' => 100, 'pos_y' => 200, 'pos_z' => -300], (array) $db->table('players')->where('pid', 101)->first(['pos_x', 'pos_y', 'pos_z']));
    }

    public function test_old_applied_mobius_profile_preserves_rescue(): void
    {
        $catalog = app(DriverProfileCatalog::class);
        $profile = $catalog->find('game', 'l2j_mobius')['profile'];
        unset($profile['config']['characters']['fields']['access_level'], $profile['config']['characters']['fields']['deletion_time']);
        $catalog->save($profile);
        app(ConnectionProfileSnapshots::class)->verifyAndRecord($this->server, 'l2j_mobius', static fn (): bool => true);
        $this->insertCharacter();

        $this->assertTrue($this->gateway->capabilities($this->server)->supported);
        $this->assertTrue($this->gateway->rescue($this->server, 'PlayerOne', 100, ['x' => 1, 'y' => 2, 'z' => 3], CarbonImmutable::now())->successful());
    }

    /** @param array<string,int|string> $overrides */
    private function insertCharacter(array $overrides = []): void
    {
        DB::connection('character_rescue_test')->table('characters')->insert(array_merge([
            'charId' => 100,
            'char_name' => 'Bubi',
            'account_name' => 'PlayerOne',
            'x' => 10,
            'y' => 20,
            'z' => 30,
            'online' => 0,
            'lastAccess' => CarbonImmutable::now()->subHour()->getTimestamp() * 1000,
            'deletetime' => 0,
            'accesslevel' => 0,
        ], $overrides));
    }
}
