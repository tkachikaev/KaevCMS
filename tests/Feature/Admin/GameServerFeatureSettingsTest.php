<?php

namespace Tests\Feature\Admin;

use App\Contracts\CharacterRescueGateway;
use App\Models\Admin;
use App\Models\GameServer;
use App\Models\GameServerFeature;
use App\Services\GameServerFeatures\GameServerFeatureSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_owner_can_open_game_server_features_without_overloading_server_cards(): void
    {
        $server = GameServer::factory()->create(['name' => 'Interlude x10']);

        $this->actingAs(Admin::factory()->create(), 'admin')
            ->get(route('admin.settings.game-server-features.index'))
            ->assertOk()
            ->assertSee('Возможности игровых серверов')
            ->assertSee('Interlude x10')
            ->assertSee('Настроить');

        $this->actingAs(Admin::query()->firstOrFail(), 'admin')
            ->get(route('admin.settings.game-server-features.edit', $server))
            ->assertOk()
            ->assertSee('Возврат персонажа в город')
            ->assertSee('Возврат персонажа поддерживается');
    }

    public function test_owner_can_enable_character_rescue_and_save_coordinates(): void
    {
        $server = GameServer::factory()->create();

        $this->actingAs(Admin::factory()->create(), 'admin')
            ->put(route('admin.settings.game-server-features.update', $server), [
                'enabled' => '1',
                'location_name' => 'Giran',
                'x' => 83400,
                'y' => 148600,
                'z' => -3400,
                'offline_delay_minutes' => 5,
                'cooldown_hours' => 12,
            ])
            ->assertRedirect(route('admin.settings.game-server-features.edit', $server))
            ->assertSessionHas('status', 'Возможности игрового сервера сохранены.');

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

    public function test_character_rescue_coordinates_are_validated(): void
    {
        $server = GameServer::factory()->create();

        $this->actingAs(Admin::factory()->create(), 'admin')
            ->from(route('admin.settings.game-server-features.edit', $server))
            ->put(route('admin.settings.game-server-features.update', $server), [
                'enabled' => '1',
                'location_name' => '',
                'x' => 'not-a-number',
                'y' => 148600,
                'z' => -3400,
                'offline_delay_minutes' => -1,
                'cooldown_hours' => 9999,
            ])
            ->assertRedirect(route('admin.settings.game-server-features.edit', $server))
            ->assertSessionHasErrors(['location_name', 'x', 'offline_delay_minutes', 'cooldown_hours']);
    }
}
