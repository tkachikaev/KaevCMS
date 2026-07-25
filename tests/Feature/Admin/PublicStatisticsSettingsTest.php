<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\GameServerManager;
use App\Models\Admin;
use App\Models\GameServer;
use App\Services\GameServerSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PublicStatisticsSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_game_server_uses_ten_as_the_default_for_each_character_ranking(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(GameServerManager::class)
            ->call('create')
            ->assertSet('statisticsLevelLimit', '10')
            ->assertSet('statisticsPvpLimit', '10')
            ->assertSet('statisticsPkLimit', '10')
            ->assertSet('statisticsPlayTimeLimit', '10');
    }

    public function test_admin_can_enable_selected_public_statistics_sections_with_individual_limits(): void
    {
        $server = GameServer::query()->firstOrFail();
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(GameServerManager::class)
            ->call('edit', $server->id)
            ->set('statisticsEnabled', true)
            ->set('statisticsLevelEnabled', true)
            ->set('statisticsPvpEnabled', true)
            ->set('statisticsPkEnabled', false)
            ->set('statisticsPlayTimeEnabled', true)
            ->set('statisticsHeroesEnabled', true)
            ->set('statisticsCastlesEnabled', true)
            ->set('statisticsLevelLimit', '10')
            ->set('statisticsPvpLimit', '20')
            ->set('statisticsPkLimit', '20')
            ->set('statisticsPlayTimeLimit', '30')
            ->call('save')
            ->assertHasNoErrors();

        $server->refresh();
        $this->assertTrue($server->statistics_enabled);
        $this->assertTrue($server->statistics_pvp_enabled);
        $this->assertFalse($server->statistics_pk_enabled);
        $this->assertSame(10, $server->statistics_level_limit);
        $this->assertSame(20, $server->statistics_pvp_limit);
        $this->assertSame(20, $server->statistics_pk_limit);
        $this->assertSame(30, $server->statistics_play_time_limit);
    }

    public function test_game_server_settings_are_grouped_into_three_tabs(): void
    {
        $server = GameServer::query()->firstOrFail();
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(GameServerManager::class)
            ->call('edit', $server->id)
            ->assertSet('activeTab', 'general')
            ->assertSee('Основное')
            ->assertSee('Статистика')
            ->assertSee('Разное')
            ->call('setActiveTab', 'statistics')
            ->assertSet('activeTab', 'statistics')
            ->assertSee('Рейтинги персонажей')
            ->assertSee('Все текущие герои')
            ->assertSee('Владельцы крепостей')
            ->call('setActiveTab', 'miscellaneous')
            ->assertSet('activeTab', 'miscellaneous')
            ->assertSee('Режим обслуживания')
            ->assertSee('Дополнительные сетевые настройки');
    }

    public function test_unsupported_driver_cannot_publish_statistics(): void
    {
        $server = GameServer::query()->firstOrFail();
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(GameServerManager::class)
            ->call('edit', $server->id)
            ->set('driver', 'rusacis')
            ->set('statisticsEnabled', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($server->fresh()->statistics_enabled);
    }

    public function test_unrelated_profile_update_preserves_statistics_settings(): void
    {
        $server = GameServer::query()->firstOrFail();
        $server->update([
            'statistics_enabled' => true,
            'statistics_pk_enabled' => false,
            'statistics_level_limit' => 12,
            'statistics_pvp_limit' => 22,
            'statistics_pk_limit' => 32,
            'statistics_play_time_limit' => 42,
        ]);

        app(GameServerSettings::class)->update($server, [
            'name' => 'Renamed server',
        ]);

        $server->refresh();
        $this->assertTrue($server->statistics_enabled);
        $this->assertFalse($server->statistics_pk_enabled);
        $this->assertSame(12, $server->statistics_level_limit);
        $this->assertSame(22, $server->statistics_pvp_limit);
        $this->assertSame(32, $server->statistics_pk_limit);
        $this->assertSame(42, $server->statistics_play_time_limit);
    }

    public function test_public_statistics_requires_at_least_one_section(): void
    {
        $server = GameServer::query()->firstOrFail();
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(GameServerManager::class)
            ->call('edit', $server->id)
            ->set('statisticsEnabled', true)
            ->set('statisticsLevelEnabled', false)
            ->set('statisticsPvpEnabled', false)
            ->set('statisticsPkEnabled', false)
            ->set('statisticsPlayTimeEnabled', false)
            ->set('statisticsHeroesEnabled', false)
            ->set('statisticsCastlesEnabled', false)
            ->call('save')
            ->assertHasErrors(['statisticsEnabled']);
    }

    public function test_each_character_ranking_limit_accepts_only_one_to_one_hundred(): void
    {
        $server = GameServer::query()->firstOrFail();
        $this->actingAs(Admin::factory()->create(), 'admin');

        Livewire::test(GameServerManager::class)
            ->call('edit', $server->id)
            ->set('statisticsEnabled', true)
            ->set('statisticsLevelLimit', '0')
            ->set('statisticsPvpLimit', '101')
            ->set('statisticsPkLimit', '0')
            ->set('statisticsPlayTimeLimit', '101')
            ->call('save')
            ->assertSet('activeTab', 'statistics')
            ->assertHasErrors([
                'statisticsLevelLimit',
                'statisticsPvpLimit',
                'statisticsPkLimit',
                'statisticsPlayTimeLimit',
            ]);
    }
}
