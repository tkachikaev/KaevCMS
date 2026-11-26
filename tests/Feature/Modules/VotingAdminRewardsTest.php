<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\GameServer;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use KaevCMS\Modules\Voting\Models\VotingServerReward;
use Tests\TestCase;

final class VotingAdminRewardsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/extensions/voting/rewards';

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('voting');
        app(ModuleRuntime::class)->bootModule($module);
    }

    public function test_rewards_are_saved_per_server_and_do_not_open_player_access(): void
    {
        $admin = Admin::factory()->create();
        $first = GameServer::factory()->create();
        $second = GameServer::factory()->create();
        $items = [['item_id' => 57, 'amount' => 100], ['item_id' => 4037, 'amount' => 5]];
        $this->actingAs($admin, 'admin')->get(self::URL.'?server='.$first->id)->assertOk();
        $this->put(self::URL.'/'.$first->id, ['enabled' => 1, 'rewards' => $items])->assertRedirect();
        $this->put(self::URL.'/'.$second->id, ['enabled' => 0, 'rewards' => [['item_id' => 57, 'amount' => 1]]])->assertRedirect();
        $this->assertSame($items, VotingServerReward::query()->where('game_server_id', $first->id)->firstOrFail()->configuredItems());
        $disabled = VotingServerReward::query()->where('game_server_id', $second->id)->firstOrFail();
        $this->assertSame([], $disabled->configuredItems());
        $this->assertSame([['item_id' => 57, 'amount' => 1]], $disabled->validatedItems());
        $this->assertDatabaseHas('audit_logs', ['action' => 'voting.rewards_updated', 'actor_id' => $admin->id]);
        $this->assertDatabaseHas('module_voting_settings', ['player_access_enabled' => false, 'event_cursor' => '0']);
    }

    public function test_invalid_bundle_cannot_replace_saved_reward(): void
    {
        $server = GameServer::factory()->create();
        $url = self::URL.'/'.$server->id;
        $valid = [['item_id' => 57, 'amount' => 100]];
        $this->actingAs(Admin::factory()->create(), 'admin')->put($url, ['enabled' => 1, 'rewards' => $valid])->assertRedirect();
        foreach ([
            [],
            [['item_id' => 57, 'amount' => 1], ['item_id' => '57', 'amount' => 2]],
            [['item_id' => 57]],
            [['item_id' => 57, 'amount' => 2000000001]],
            array_fill(0, 101, ['item_id' => 57, 'amount' => 1]),
        ] as $invalid) {
            $this->put($url, ['enabled' => 1, 'rewards' => $invalid])->assertSessionHasErrors();
            $this->assertSame($valid, VotingServerReward::query()->where('game_server_id', $server->id)->firstOrFail()->configuredItems());
        }
    }

    public function test_auditor_reads_reward_configuration_and_history_but_cannot_write(): void
    {
        $server = GameServer::factory()->create();
        $auditor = Admin::factory()->create(['role' => AdminRole::Auditor]);
        $editor = Admin::factory()->create(['role' => AdminRole::Editor]);
        $this->actingAs($auditor, 'admin')->get(self::URL.'?server='.$server->id)->assertOk();
        $this->get('/admin/extensions/voting/history')->assertOk();
        $this->put(self::URL.'/'.$server->id, ['enabled' => 1, 'rewards' => [['item_id' => 57, 'amount' => 1]]])->assertForbidden();
        $this->actingAs($editor, 'admin')->get(self::URL)->assertForbidden();
        $this->get('/admin/extensions/voting/history')->assertForbidden();
        $this->assertDatabaseCount('module_voting_server_rewards', 0);
    }

    public function test_item_preview_uses_core_catalog_for_selected_server(): void
    {
        $server = GameServer::factory()->create();
        $this->actingAs(Admin::factory()->create(), 'admin')
            ->getJson(self::URL.'/'.$server->id.'/items/57')
            ->assertOk()->assertJsonPath('item_id', 57)->assertJsonStructure(['name', 'icon_url']);
    }
}
