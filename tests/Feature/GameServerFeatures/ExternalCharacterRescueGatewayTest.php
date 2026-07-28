<?php

namespace Tests\Feature\GameServerFeatures;

use App\Models\GameServer;
use App\Services\GameServerFeatures\ExternalCharacterRescueGateway;
use App\Support\GameServerFeatures\CharacterRescueWriteResult;
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
