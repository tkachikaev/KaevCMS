<?php

namespace Tests\Feature\Rewards;

use App\Models\GameServer;
use App\Services\Rewards\DatabaseGameRewardQueueGateway;
use App\Support\Rewards\RewardQueuePayload;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithServerFixtures;
use Tests\Fakes\FakeGameServerDatabaseGateway;
use Tests\TestCase;

class GameRewardQueueGatewayTest extends TestCase
{
    use InteractsWithServerFixtures;
    use RefreshDatabase;

    private string $databasePath;

    private DatabaseGameRewardQueueGateway $gateway;

    private GameServer $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = database_path('reward-queue-test.sqlite');
        @unlink($this->databasePath);
        touch($this->databasePath);
        config()->set('database.connections.reward_queue_test', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('reward_queue_test');

        [, $this->server] = $this->freshMobiusServerPair();
        $this->gateway = new DatabaseGameRewardQueueGateway(
            new FakeGameServerDatabaseGateway('reward_queue_test'),
        );
    }

    protected function tearDown(): void
    {
        DB::purge('reward_queue_test');
        @unlink($this->databasePath);

        parent::tearDown();
    }

    public function test_capabilities_require_only_the_neutral_queue_table(): void
    {
        $missing = $this->gateway->capabilities($this->server);
        $this->assertFalse($missing->supported);
        $this->assertSame('reward_queue_not_installed', $missing->reasonCode);

        Schema::connection('reward_queue_test')->create('kaev_reward_queue', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_uuid');
        });

        $invalid = $this->gateway->capabilities($this->server);
        $this->assertFalse($invalid->supported);
        $this->assertSame('reward_queue_schema_invalid', $invalid->reasonCode);

        Schema::connection('reward_queue_test')->drop('kaev_reward_queue');
        $this->createQueueSchema();

        $supported = $this->gateway->capabilities($this->server);
        $this->assertTrue($supported->supported);
        $this->assertNull($supported->reasonCode);
    }

    public function test_enqueue_is_idempotent_and_stores_complete_neutral_rows(): void
    {
        $this->createQueueSchema();
        $payload = $this->payload();

        $first = $this->gateway->enqueue($this->server, $payload);
        $second = $this->gateway->enqueue($this->server, $payload);

        $this->assertTrue($first->isQueued());
        $this->assertTrue($second->isQueued());
        $this->assertSame(2, DB::connection('reward_queue_test')->table('kaev_reward_queue')->count());
        $this->assertDatabaseHas('kaev_reward_queue', [
            'request_uuid' => $payload->requestUuid,
            'line_number' => 1,
            'game_server_id' => $this->server->id,
            'source' => 'web_inventory',
            'cms_user_id' => 91,
            'account_name' => 'RewardPlayer',
            'character_id' => 500,
            'character_name' => 'DeliveryCharacter',
            'item_id' => 57,
            'amount' => 1000000,
            'status' => 'pending',
            'attempts' => 0,
        ], 'reward_queue_test');
    }

    public function test_reusing_request_uuid_with_different_payload_is_rejected(): void
    {
        $this->createQueueSchema();
        $payload = $this->payload();
        $this->gateway->enqueue($this->server, $payload);

        $conflict = $this->gateway->enqueue($this->server, new RewardQueuePayload(
            requestUuid: $payload->requestUuid,
            gameServerId: $payload->gameServerId,
            cmsUserId: $payload->cmsUserId,
            accountName: $payload->accountName,
            characterId: $payload->characterId,
            characterName: $payload->characterName,
            items: [['item_id' => 57, 'amount' => 999]],
        ));

        $this->assertTrue($conflict->isFailed());
        $this->assertSame('reward_queue_payload_conflict', $conflict->failureCode);
        $this->assertSame(2, DB::connection('reward_queue_test')->table('kaev_reward_queue')->count());
    }

    private function createQueueSchema(): void
    {
        Schema::connection('reward_queue_test')->create('kaev_reward_queue', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_uuid');
            $table->unsignedSmallInteger('line_number');
            $table->unsignedBigInteger('game_server_id');
            $table->string('source', 64);
            $table->unsignedBigInteger('cms_user_id');
            $table->string('account_name', 45);
            $table->unsignedBigInteger('character_id');
            $table->string('character_name', 190);
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('amount');
            $table->string('status', 32);
            $table->unsignedInteger('attempts');
            $table->string('error_message', 500)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('processing_started_at')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->unique(['request_uuid', 'line_number']);
        });
    }

    private function payload(): RewardQueuePayload
    {
        return new RewardQueuePayload(
            requestUuid: '7c622f5c-928a-4ee1-9b92-9d6f32a347b8',
            gameServerId: $this->server->id,
            cmsUserId: 91,
            accountName: 'RewardPlayer',
            characterId: 500,
            characterName: 'DeliveryCharacter',
            items: [
                ['item_id' => 57, 'amount' => 1000000],
                ['item_id' => 4037, 'amount' => 10],
            ],
        );
    }
}
