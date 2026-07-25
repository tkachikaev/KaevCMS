<?php

namespace Tests\Feature\Admin;

use App\Auth\AdminPermission;
use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\GameServer;
use App\Models\RewardDelivery;
use App\Models\User;
use App\Services\Rewards\RewardInventoryService;
use App\Support\Rewards\RewardGrantItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Tests\TestCase;

class RewardDeliveryJournalTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_and_administrator_can_view_reward_journal_but_editor_cannot(): void
    {
        foreach ([AdminRole::Owner, AdminRole::Administrator] as $role) {
            $admin = Admin::factory()->create(['role' => $role]);
            $this->actingAs($admin, 'admin')
                ->get(route('admin.rewards.index'))
                ->assertOk()
                ->assertSee(__('Reward queue'));
        }

        $editor = Admin::factory()->create(['role' => AdminRole::Editor]);
        $this->actingAs($editor, 'admin')
            ->get(route('admin.rewards.index'))
            ->assertForbidden();
    }

    public function test_reward_journal_uses_standard_audit_layout_and_localized_item_names(): void
    {
        App::setLocale('ru');
        $admin = Admin::factory()->create(['role' => AdminRole::Owner]);
        $user = User::factory()->create([
            'name' => 'tkachikaev',
            'email' => 'tkachikaev@gmail.com',
        ]);
        $server = GameServer::factory()->create(['name' => 'Эллада']);
        $grant = app(RewardInventoryService::class)->grant(
            user: $user,
            server: $server,
            grantKey: 'journal-layout-test',
            sourceType: 'promo-code',
            items: [new RewardGrantItem(57, 10000000)],
        );
        $inventoryItem = $grant->items->firstOrFail();

        $delivery = RewardDelivery::query()->create([
            'operation_uuid' => (string) Str::uuid(),
            'request_token' => (string) Str::uuid(),
            'user_id' => $user->id,
            'game_server_id' => $server->id,
            'user_game_account_id' => null,
            'character_id' => 100,
            'character_name' => 'Booogz',
            'account_login' => 'booogz',
            'status' => RewardDelivery::STATUS_QUEUED,
            'requested_at' => now(),
            'queued_at' => now(),
        ]);
        $delivery->items()->create([
            'reward_inventory_item_id' => $inventoryItem->id,
            'item_id' => 57,
            'item_name' => null,
            'amount' => 10000000,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.rewards.index'))
            ->assertOk()
            ->assertSee('audit-table-wrap', false)
            ->assertSee('audit-table reward-queue-table', false)
            ->assertSee('audit-date', false)
            ->assertSee('tkachikaev@gmail.com')
            ->assertSee('Booogz')
            ->assertSee('booogz')
            ->assertSee('Адена')
            ->assertSee('ID 57')
            ->assertSee('10 000 000')
            ->assertDontSee('Предмет №57');
    }

    public function test_only_owner_and_administrator_can_manage_uncertain_reward_transfers(): void
    {
        $this->assertTrue(AdminRole::Owner->allows(AdminPermission::RewardsManage));
        $this->assertTrue(AdminRole::Administrator->allows(AdminPermission::RewardsManage));
        $this->assertFalse(AdminRole::Editor->allows(AdminPermission::RewardsManage));
    }
}
