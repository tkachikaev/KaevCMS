<?php

namespace Tests\Feature\Rewards;

use App\Models\RewardDelivery;
use App\Models\RewardInventoryItem;
use App\Models\User;
use App\Services\Rewards\RewardInventoryService;
use App\Support\Rewards\RewardGrantItem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithServerFixtures;
use Tests\TestCase;

class RewardQueueMigrationTest extends TestCase
{
    use InteractsWithServerFixtures;
    use RefreshDatabase;

    public function test_legacy_bridge_state_is_converted_without_requeueing_uncertain_operations(): void
    {
        $user = User::factory()->create();
        [, $server] = $this->freshMobiusServerPair();
        $grant = app(RewardInventoryService::class)->grant(
            user: $user,
            server: $server,
            grantKey: 'migration:legacy-reward',
            sourceType: 'admin_gift',
            items: [new RewardGrantItem(57, 1000, 'Adena')],
        );
        $inventoryItem = $grant->items->firstOrFail();

        $delivered = $this->createDelivery($user, $server->id, 1001, 'delivered');
        $pending = $this->createDelivery($user, $server->id, 1002, 'pending');
        $processing = $this->createDelivery($user, $server->id, 1003, 'processing');

        Schema::table('reward_inventory_items', function (Blueprint $table): void {
            $table->renameColumn('transferred_at', 'delivered_at');
        });
        Schema::table('reward_deliveries', function (Blueprint $table): void {
            $table->renameColumn('queued_at', 'completed_at');
        });
        Schema::table('reward_deliveries', function (Blueprint $table): void {
            $table->timestamp('started_at')->nullable();
        });

        DB::table('reward_inventory_items')
            ->where('id', $inventoryItem->id)
            ->update([
                'status' => 'delivered',
                'delivered_at' => now(),
            ]);
        DB::table('jobs')->insert([
            [
                'queue' => 'rewards',
                'payload' => '{}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ],
            [
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ],
        ]);

        $migration = require database_path('migrations/2026_07_22_000000_simplify_reward_queue_delivery.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('reward_inventory_items', 'transferred_at'));
        $this->assertFalse(Schema::hasColumn('reward_inventory_items', 'delivered_at'));
        $this->assertTrue(Schema::hasColumn('reward_deliveries', 'queued_at'));
        $this->assertFalse(Schema::hasColumn('reward_deliveries', 'completed_at'));
        $this->assertFalse(Schema::hasColumn('reward_deliveries', 'started_at'));
        $this->assertDatabaseHas('reward_inventory_items', [
            'id' => $inventoryItem->id,
            'status' => RewardInventoryItem::STATUS_TRANSFERRED,
        ]);
        $this->assertDatabaseHas('reward_deliveries', [
            'id' => $delivered->id,
            'status' => RewardDelivery::STATUS_QUEUED,
        ]);
        foreach ([$pending, $processing] as $delivery) {
            $this->assertDatabaseHas('reward_deliveries', [
                'id' => $delivery->id,
                'status' => RewardDelivery::STATUS_REVIEW,
                'failure_code' => 'legacy_bridge_operation_requires_review',
            ]);
        }
        $this->assertDatabaseMissing('jobs', ['queue' => 'rewards']);
        $this->assertDatabaseHas('jobs', ['queue' => 'default']);
    }

    private function createDelivery(User $user, int $serverId, int $characterId, string $status): RewardDelivery
    {
        return RewardDelivery::query()->create([
            'operation_uuid' => (string) Str::uuid(),
            'request_token' => (string) Str::uuid(),
            'user_id' => $user->id,
            'game_server_id' => $serverId,
            'user_game_account_id' => null,
            'character_id' => $characterId,
            'character_name' => 'LegacyCharacter',
            'account_login' => 'LegacyAccount',
            'status' => $status,
            'requested_at' => now(),
            'queued_at' => $status === 'delivered' ? now() : null,
        ]);
    }
}
