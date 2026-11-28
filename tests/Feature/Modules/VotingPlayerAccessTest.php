<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\GameServer;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use KaevCMS\Modules\Voting\Models\VotingServerReward;
use KaevCMS\Modules\Voting\Models\VotingSetting;
use Tests\TestCase;

final class VotingPlayerAccessTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/extensions/voting/providers/ktreon';

    private const PROJECT = '11111111-2222-4333-8444-555555555555';

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('voting');
        app(ModuleRuntime::class)->bootModule($module);
        Http::preventStrayRequests();
    }

    public function test_activation_requires_valid_configuration_check_and_explicit_confirmation(): void
    {
        $owner = Admin::factory()->create();
        $server = GameServer::factory()->create();
        $this->actingAs($owner, 'admin')->put(self::URL.'/player-access', ['player_access_enabled' => 1, 'confirm' => 1])
            ->assertSessionHasErrors('player_access_enabled');
        $this->put(self::URL, [
            'enabled' => 1, 'project_id' => self::PROJECT, 'password' => 'kti_'.str_repeat('a', 43), 'pending_policy' => 'wait',
        ])->assertRedirect();
        (new VotingServerReward)->forceFill([
            'game_server_id' => $server->id, 'enabled' => true, 'items' => [['item_id' => 57, 'amount' => 1]],
        ])->save();
        $this->put(self::URL.'/player-access', ['player_access_enabled' => 1, 'confirm' => 1])->assertSessionHasErrors('player_access_enabled');
        Http::fake(['https://ktreon.com/api/v1/projects/'.self::PROJECT.'/vote-events*' => Http::response(['events' => [], 'next_cursor' => '0', 'has_more' => false])]);
        $this->post(self::URL.'/check')->assertSessionHas('status');
        $this->put(self::URL.'/player-access', ['player_access_enabled' => 1])->assertSessionHasErrors('player_access_enabled');
        $this->put(self::URL.'/player-access', ['player_access_enabled' => 1, 'confirm' => 1])->assertRedirect();
        $this->assertDatabaseHas('module_voting_settings', ['player_access_enabled' => true]);
        $this->put(self::URL, [
            'enabled' => 1, 'project_id' => self::PROJECT, 'password' => 'kti_'.str_repeat('b', 43), 'pending_policy' => 'wait',
        ])->assertRedirect();
        $this->assertDatabaseHas('module_voting_settings', ['player_access_enabled' => false, 'last_check_result' => null]);
    }

    public function test_failed_authorization_check_closes_access_and_success_does_not_reopen_it(): void
    {
        $server = GameServer::factory()->create();
        $owner = Admin::factory()->create();
        $this->actingAs($owner, 'admin')->put(self::URL, [
            'enabled' => 1, 'project_id' => self::PROJECT, 'password' => 'kti_'.str_repeat('a', 43), 'pending_policy' => 'wait',
        ])->assertRedirect();
        (new VotingServerReward)->forceFill([
            'game_server_id' => $server->id, 'enabled' => true, 'items' => [['item_id' => 57, 'amount' => 1]],
        ])->save();
        VotingSetting::query()->update(['player_access_enabled' => true, 'last_check_result' => 'ok']);
        Http::fakeSequence('https://ktreon.com/api/v1/projects/'.self::PROJECT.'/vote-events*')
            ->push(['error' => 'unauthorized'], 401)
            ->push(['events' => [], 'next_cursor' => '0', 'has_more' => false]);
        $this->post(self::URL.'/check')->assertSessionHasErrors('connection');
        $this->assertDatabaseHas('module_voting_settings', ['player_access_enabled' => false, 'last_check_result' => 'unauthorized']);
        $this->post(self::URL.'/check')->assertSessionHas('status');
        $this->assertDatabaseHas('module_voting_settings', ['player_access_enabled' => false, 'last_check_result' => 'ok']);
    }

    public function test_auditor_cannot_open_or_close_player_access(): void
    {
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Auditor]), 'admin')
            ->put(self::URL.'/player-access', ['player_access_enabled' => 1, 'confirm' => 1])->assertForbidden();
        $this->put(self::URL.'/player-access', ['player_access_enabled' => 0])->assertForbidden();
        $this->assertDatabaseHas('module_voting_settings', ['player_access_enabled' => false]);
    }
}
