<?php

namespace Tests\Feature\Admin;

use App\Contracts\CharacterRescueGateway;
use App\Livewire\Admin\GameServerManager;
use App\Models\Admin;
use App\Models\GameServer;
use App\Models\GameServerFeature;
use App\Services\GameServerFeatures\GameServerFeatureSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Fakes\FakeCharacterRescueGateway;
use Tests\TestCase;

class GameServerFeatureSettingsTest extends TestCase
{
    use RefreshDatabase;

    private FakeCharacterRescueGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeCharacterRescueGateway;
        $this->app->instance(CharacterRescueGateway::class, $this->gateway);
    }

    public function test_character_rescue_is_configured_inside_the_game_server_features_tab(): void
    {
        $server = GameServer::factory()->create(['name' => 'Interlude x10']);
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(GameServerManager::class)
            ->call('edit', $server->id)
            ->assertSee('Возможности')
            ->assertDontSee('live_game_rescue_location', false)
            ->call('setActiveTab', 'features')
            ->assertSet('activeTab', 'features')
            ->assertSee('Возврат персонажа в город')
            ->assertSee('character-rescue-settings', false)
            ->call('setCharacterRescueEnabled', true)
            ->assertSet('characterRescueEnabled', true)
            ->assertSee('live_game_rescue_location', false)
            ->assertSee('character-rescue-fields', false)
            ->assertSee('server-form-grid server-form-grid-three', false)
            ->assertSee('form-group', false);
    }

    public function test_old_separate_game_server_features_page_is_removed(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin')
            ->get('/admin/settings/game-server-features')
            ->assertNotFound();
    }

    public function test_owner_can_enable_character_rescue_and_save_coordinates_from_game_server_drawer(): void
    {
        $server = GameServer::factory()->create();
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(GameServerManager::class)
            ->call('edit', $server->id)
            ->call('setActiveTab', 'features')
            ->call('setCharacterRescueEnabled', true)
            ->set('characterRescueLocationName', 'Giran')
            ->set('characterRescueX', '83400')
            ->set('characterRescueY', '148600')
            ->set('characterRescueZ', '-3400')
            ->set('characterRescueOfflineDelayMinutes', '5')
            ->set('characterRescueCooldownHours', '12')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Настройки игрового сервера сохранены.');

        $feature = GameServerFeature::query()->firstOrFail();
        $this->assertSame(GameServerFeatureSettings::CHARACTER_RESCUE, $feature->feature_key);
        $this->assertTrue($feature->enabled);
        $this->assertSame('Giran', $feature->settings['location_name']);
        $this->assertSame(83400, $feature->settings['x']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'game_server.character_rescue_settings_updated',
            'result' => 'success',
        ]);
    }

    public function test_character_rescue_validation_opens_the_features_tab(): void
    {
        $server = GameServer::factory()->create();
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(GameServerManager::class)
            ->call('edit', $server->id)
            ->call('setCharacterRescueEnabled', true)
            ->set('characterRescueLocationName', '')
            ->set('characterRescueX', 'not-a-number')
            ->set('characterRescueOfflineDelayMinutes', '-1')
            ->set('characterRescueCooldownHours', '9999')
            ->call('save')
            ->assertSet('activeTab', 'features')
            ->assertHasErrors([
                'characterRescueLocationName',
                'characterRescueX',
                'characterRescueOfflineDelayMinutes',
                'characterRescueCooldownHours',
            ]);
    }

    public function test_character_rescue_cannot_be_enabled_when_driver_capability_is_unavailable(): void
    {
        $this->gateway->capabilitySupported = false;
        $server = GameServer::factory()->create();
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(GameServerManager::class)
            ->call('edit', $server->id)
            ->assertSet('characterRescueCapabilityState', 'unavailable')
            ->call('setCharacterRescueEnabled', true)
            ->assertSet('characterRescueEnabled', false)
            ->assertHasErrors(['characterRescueEnabled']);
    }
}
