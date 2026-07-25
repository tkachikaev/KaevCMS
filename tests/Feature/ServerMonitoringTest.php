<?php

namespace Tests\Feature;

use App\Contracts\ExternalDatabaseConnectionTester;
use App\Contracts\GameServerOnlineCounter;
use App\Contracts\ServicePortProbe;
use App\Models\Admin;
use App\Models\GameServer;
use App\Models\GameServerTranslation;
use App\Models\LoginServer;
use App\Services\CmsSettings;
use App\Services\Servers\ServerMonitor;
use App\Services\Servers\ServerMonitorCoordinator;
use App\Services\Servers\ServerMonitorSettings;
use App\Services\Servers\ServerStatusOverview;
use App\Services\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\InteractsWithServerFixtures;
use Tests\Fakes\FakeExternalDatabaseConnectionTester;
use Tests\Fakes\FakeGameServerOnlineCounter;
use Tests\Fakes\FakeServicePortProbe;
use Tests\TestCase;

class ServerMonitoringTest extends TestCase
{
    use InteractsWithServerFixtures, RefreshDatabase;

    private FakeExternalDatabaseConnectionTester $databases;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databases = new FakeExternalDatabaseConnectionTester;
        $this->app->instance(ExternalDatabaseConnectionTester::class, $this->databases);
    }

    public function test_monitor_records_live_services_and_online_players(): void
    {
        [$loginServer, $gameServer] = $this->servers();
        $ports = new FakeServicePortProbe;
        $ports->responses = [
            'login.example.test:2106' => true,
            'game.example.test:7777' => true,
        ];
        $online = new FakeGameServerOnlineCounter;
        $online->counts[$gameServer->id] = 73;
        $this->app->instance(ServicePortProbe::class, $ports);
        $this->app->instance(GameServerOnlineCounter::class, $online);

        app(ServerMonitor::class)->run();

        $this->assertDatabaseHas('login_servers', [
            'id' => $loginServer->id,
            'monitor_status' => 'online',
            'monitor_failures' => 0,
            'database_status' => 'configured',
        ]);
        $this->assertDatabaseHas('game_servers', [
            'id' => $gameServer->id,
            'monitor_status' => 'online',
            'monitor_failures' => 0,
            'database_status' => 'configured',
            'online_players' => 73,
        ]);
        $this->assertNotNull($loginServer->fresh()?->monitor_checked_at);
        $this->assertNotNull($gameServer->fresh()?->online_checked_at);
    }

    public function test_service_becomes_offline_only_after_three_consecutive_failures(): void
    {
        [$loginServer] = $this->servers();
        $ports = new FakeServicePortProbe;
        $ports->responses = [
            'login.example.test:2106' => [false, false, false],
            'game.example.test:7777' => [false, false, false],
        ];
        $this->app->instance(ServicePortProbe::class, $ports);
        $this->app->instance(GameServerOnlineCounter::class, new FakeGameServerOnlineCounter);
        $monitor = app(ServerMonitor::class);

        $monitor->run();
        $this->assertSame('unknown', $loginServer->fresh()?->monitor_status);
        $this->assertSame(1, $loginServer->fresh()?->monitor_failures);

        $monitor->run();
        $this->assertSame('unknown', $loginServer->fresh()?->monitor_status);
        $this->assertSame(2, $loginServer->fresh()?->monitor_failures);

        $monitor->run();
        $this->assertSame('offline', $loginServer->fresh()?->monitor_status);
        $this->assertSame(3, $loginServer->fresh()?->monitor_failures);
    }

    public function test_dashboard_and_public_home_show_compact_real_server_statuses(): void
    {
        $this->seed();
        GameServer::query()->delete();
        LoginServer::query()->delete();
        [, $gameServer] = $this->servers();
        $loginServer = $gameServer->loginServer;
        $this->assertInstanceOf(LoginServer::class, $loginServer);
        $loginServer->update([
            'monitor_status' => 'online',
            'monitor_failures' => 0,
            'monitor_checked_at' => now(),
            'monitor_last_online_at' => now(),
            'database_status' => 'configured',
            'database_checked_at' => now(),
        ]);
        $gameServer->update([
            'monitor_status' => 'online',
            'monitor_failures' => 0,
            'monitor_checked_at' => now(),
            'monitor_last_online_at' => now(),
            'online_players' => 37,
            'online_checked_at' => now(),
            'database_status' => 'configured',
            'database_checked_at' => now(),
        ]);
        $admin = Admin::factory()->create([
            'name' => 'Monitor Admin',
            'email' => 'monitor@example.com',
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('Всего онлайн')
            ->assertSee('37')
            ->assertSee('Interlude')
            ->assertSee('Основной LoginServer')
            ->assertSee('Работает');

        $this->get('/')
            ->assertOk()
            ->assertSee('Interlude')
            ->assertSee('Доступен')
            ->assertSee('Онлайн: 37')
            ->assertDontSee('/ 5 000')
            ->assertDontSee('class="progress"', false);
    }

    public function test_admin_shows_each_process_state_while_public_status_requires_login_and_game_servers(): void
    {
        $this->seed();
        GameServer::query()->delete();
        LoginServer::query()->delete();
        [$loginServer, $gameServer] = $this->servers();
        $loginServer->update([
            'monitor_status' => 'offline',
            'monitor_failures' => 3,
            'monitor_checked_at' => now(),
            'database_status' => 'configured',
            'database_checked_at' => now(),
        ]);
        $gameServer->update([
            'monitor_status' => 'online',
            'monitor_failures' => 0,
            'monitor_checked_at' => now(),
            'monitor_last_online_at' => now(),
            'online_players' => 12,
            'online_checked_at' => now(),
            'database_status' => 'configured',
            'database_checked_at' => now(),
        ]);
        $admin = Admin::factory()->create([
            'name' => 'Monitor Admin',
            'email' => 'monitor-state@example.com',
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('Interlude')
            ->assertDontSee('12 онлайн')
            ->assertSee('Основной LoginServer');

        $this->get('/')
            ->assertOk()
            ->assertSee('Interlude')
            ->assertSee('Недоступно')
            ->assertSee('Онлайн временно недоступен')
            ->assertDontSee('Онлайн: 12');
    }

    public function test_maintenance_overrides_public_status_but_keeps_admin_online_count(): void
    {
        $this->seed();
        GameServer::query()->delete();
        LoginServer::query()->delete();
        [$loginServer, $gameServer] = $this->servers();
        $loginServer->update([
            'monitor_status' => 'online',
            'monitor_checked_at' => now(),
            'database_status' => 'configured',
            'database_checked_at' => now(),
        ]);
        $gameServer->update([
            'monitor_status' => 'online',
            'monitor_checked_at' => now(),
            'online_players' => 24,
            'online_checked_at' => now(),
            'database_status' => 'configured',
            'database_checked_at' => now(),
            'maintenance_enabled' => true,
        ]);
        GameServerTranslation::query()->updateOrCreate(
            ['game_server_id' => $gameServer->id, 'locale' => 'ru'],
            ['name' => 'Interlude', 'maintenance_message' => 'Установка обновления'],
        );
        GameServerTranslation::query()->updateOrCreate(
            ['game_server_id' => $gameServer->id, 'locale' => 'en'],
            ['name' => 'Interlude', 'maintenance_message' => 'Installing an update'],
        );

        $overview = app(ServerStatusOverview::class)->get('ru');

        $this->assertSame('maintenance', $overview['game_servers'][0]['state']);
        $this->assertSame('maintenance', $overview['game_servers'][0]['availability_state']);
        $this->assertSame(24, $overview['game_servers'][0]['players']);
        $this->assertNull($overview['game_servers'][0]['public_players']);
        $this->assertSame('Установка обновления', $overview['game_servers'][0]['maintenance_message']);
        $this->assertSame(
            'Installing an update',
            app(ServerStatusOverview::class)->get('en')['game_servers'][0]['maintenance_message'],
        );

        $this->postJson('/server-status/refresh')
            ->assertOk()
            ->assertJsonPath('monitor.total_online', 0)
            ->assertJsonPath('monitor.game_servers.0.availability_state', 'maintenance')
            ->assertJsonPath('monitor.game_servers.0.public_players', null)
            ->assertJsonMissingPath('monitor.game_servers.0.players');

        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin')
            ->postJson('/admin/server-monitor/status')
            ->assertOk()
            ->assertJsonPath('monitor.total_online', 24)
            ->assertJsonPath('monitor.game_servers.0.players', 24);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('На обслуживании')
            ->assertSee('24 онлайн');

        $this->get('/')
            ->assertOk()
            ->assertSee('На обслуживании')
            ->assertSee('Установка обновления')
            ->assertDontSee('Онлайн: 24');
    }

    public function test_public_online_count_can_be_hidden_globally(): void
    {
        $this->seed();
        GameServer::query()->delete();
        LoginServer::query()->delete();
        [$loginServer, $gameServer] = $this->servers();
        $loginServer->update([
            'monitor_status' => 'online',
            'monitor_checked_at' => now(),
            'database_status' => 'configured',
            'database_checked_at' => now(),
        ]);
        $gameServer->update([
            'monitor_status' => 'online',
            'monitor_checked_at' => now(),
            'online_players' => 37,
            'online_checked_at' => now(),
            'database_status' => 'configured',
            'database_checked_at' => now(),
        ]);
        app(CmsSettings::class)->set(SiteSettings::KEY_SHOW_PUBLIC_ONLINE, '0');

        $overview = app(ServerStatusOverview::class)->get();
        $this->assertFalse($overview['public_online_visible']);
        $this->assertSame(37, $overview['game_servers'][0]['players']);
        $this->assertNull($overview['game_servers'][0]['public_players']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Доступен')
            ->assertDontSee('Онлайн: 37')
            ->assertDontSee('Онлайн временно недоступен')
            ->assertDontSee('data-monitor-public-online', false);

        $this->postJson('/server-status/refresh')
            ->assertOk()
            ->assertJsonPath('monitor.public_online_visible', false)
            ->assertJsonPath('monitor.total_online', null)
            ->assertJsonPath('monitor.game_servers.0.public_players', null)
            ->assertJsonPath('monitor.game_servers.0.public_online_label', null)
            ->assertJsonMissingPath('monitor.game_servers.0.players')
            ->assertJsonMissingPath('monitor.game_servers.0.database_state');

        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin')
            ->postJson('/admin/server-monitor/status')
            ->assertOk()
            ->assertJsonPath('monitor.total_online', 37)
            ->assertJsonPath('monitor.game_servers.0.players', 37);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('37 онлайн');
    }

    public function test_public_request_refreshes_stale_monitoring_without_system_scheduler(): void
    {
        $this->seed();
        GameServer::query()->delete();
        LoginServer::query()->delete();
        [, $gameServer] = $this->servers();
        $ports = new FakeServicePortProbe;
        $ports->responses = [
            'login.example.test:2106' => true,
            'game.example.test:7777' => true,
        ];
        $online = new FakeGameServerOnlineCounter;
        $online->counts[$gameServer->id] = 48;
        $this->app->instance(ServicePortProbe::class, $ports);
        $this->app->instance(GameServerOnlineCounter::class, $online);

        $this->postJson('/server-status/refresh')
            ->assertOk()
            ->assertJsonPath('refreshing', false)
            ->assertJsonPath('fresh', true)
            ->assertJsonPath('monitor.total_online', 48)
            ->assertJsonPath('monitor.game_servers.0.availability_state', 'online')
            ->assertJsonPath('monitor.game_servers.0.public_state_label', 'Доступен')
            ->assertJsonPath('monitor.game_servers.0.public_online_label', 'Онлайн: 48');

        $this->assertSame('online', $gameServer->fresh()?->monitor_status);
        $this->assertSame(48, $gameServer->fresh()?->online_players);
    }

    public function test_open_service_ports_do_not_make_servers_online_when_database_connection_fails(): void
    {
        $this->seed();
        GameServer::query()->delete();
        LoginServer::query()->delete();
        [$loginServer, $gameServer] = $this->servers();
        $ports = new FakeServicePortProbe;
        $ports->responses = [
            'login.example.test:2106' => true,
            'game.example.test:7777' => true,
        ];
        $this->databases->report = [
            'connected' => false,
            'compatible' => null,
            'server_version' => null,
            'error' => 'Connection refused',
            'checks' => [],
        ];
        $this->app->instance(ServicePortProbe::class, $ports);
        $this->app->instance(GameServerOnlineCounter::class, new FakeGameServerOnlineCounter);

        app(ServerMonitor::class)->run();

        $overview = app(ServerStatusOverview::class)->get();
        $this->assertSame('not_configured', $overview['login_servers'][0]['state']);
        $this->assertSame('not_configured', $overview['game_servers'][0]['state']);
        $this->assertSame('offline', $overview['game_servers'][0]['availability_state']);
        $this->assertNull($overview['game_servers'][0]['players']);
        $this->assertSame('not_configured', $loginServer->fresh()?->database_status);
        $this->assertSame('not_configured', $gameServer->fresh()?->database_status);
        $this->assertSame('online', $loginServer->fresh()?->monitor_status);
        $this->assertSame('online', $gameServer->fresh()?->monitor_status);

        $this->get('/')
            ->assertOk()
            ->assertSee('Недоступно')
            ->assertDontSee('Доступен');
    }

    public function test_home_marks_old_snapshot_for_automatic_refresh_and_does_not_show_old_offline_as_current(): void
    {
        $this->seed();
        GameServer::query()->delete();
        LoginServer::query()->delete();
        [$loginServer, $gameServer] = $this->servers();
        $old = now()->subHours(4);
        $loginServer->update([
            'monitor_status' => 'offline',
            'monitor_failures' => 3,
            'monitor_checked_at' => $old,
            'database_status' => 'configured',
            'database_checked_at' => $old,
        ]);
        $gameServer->update([
            'monitor_status' => 'offline',
            'monitor_failures' => 3,
            'monitor_checked_at' => $old,
            'online_players' => 0,
            'online_checked_at' => $old,
            'database_status' => 'configured',
            'database_checked_at' => $old,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('data-auto-refresh="1"', false)
            ->assertSee('Статус уточняется')
            ->assertDontSee('Недоступно');
    }

    public function test_fresh_snapshot_does_not_trigger_another_monitoring_run(): void
    {
        $this->seed();
        GameServer::query()->delete();
        LoginServer::query()->delete();
        [$loginServer, $gameServer] = $this->servers();
        $loginServer->update([
            'monitor_status' => 'online',
            'monitor_failures' => 0,
            'monitor_checked_at' => now(),
            'database_status' => 'configured',
            'database_checked_at' => now(),
        ]);
        $gameServer->update([
            'monitor_status' => 'online',
            'monitor_failures' => 0,
            'monitor_checked_at' => now(),
            'online_players' => 17,
            'online_checked_at' => now(),
            'database_status' => 'configured',
            'database_checked_at' => now(),
        ]);
        $ports = new FakeServicePortProbe;
        $ports->default = false;
        $this->app->instance(ServicePortProbe::class, $ports);
        $this->app->instance(GameServerOnlineCounter::class, new FakeGameServerOnlineCounter);

        $this->postJson('/server-status/refresh')
            ->assertOk()
            ->assertJsonPath('refreshing', false)
            ->assertJsonPath('fresh', true)
            ->assertJsonPath('monitor.total_online', 17)
            ->assertJsonPath('monitor.game_servers.0.availability_state', 'online');

        $this->assertSame('online', $gameServer->fresh()?->monitor_status);
        $this->assertSame(0, $gameServer->fresh()?->monitor_failures);
    }

    public function test_saved_refresh_interval_controls_when_monitoring_is_due(): void
    {
        config()->set('cms.server_monitor.refresh_interval_seconds', 60);
        [$loginServer, $gameServer] = $this->servers();
        $checkedAt = now()->subSeconds(45);
        $loginServer->update(['monitor_checked_at' => $checkedAt]);
        $gameServer->update(['monitor_checked_at' => $checkedAt]);

        $coordinator = app(ServerMonitorCoordinator::class);
        $this->assertFalse($coordinator->isDue());

        app(ServerMonitorSettings::class)->update(30);

        $this->assertSame(30, config('cms.server_monitor.refresh_interval_seconds'));
        $this->assertTrue($coordinator->isDue());
    }

    public function test_long_refresh_interval_does_not_make_snapshot_stale_before_next_check(): void
    {
        [$loginServer, $gameServer] = $this->servers();
        app(ServerMonitorSettings::class)->update(300);
        $this->assertSame(300, app(ServerMonitorSettings::class)->refreshIntervalSeconds());
        $checkedAt = now()->subSeconds(240);
        $loginServer->update([
            'monitor_status' => 'online',
            'monitor_checked_at' => $checkedAt,
            'database_status' => 'configured',
            'database_checked_at' => $checkedAt,
        ]);
        $gameServer->update([
            'monitor_status' => 'online',
            'monitor_checked_at' => $checkedAt,
            'online_players' => 42,
            'online_checked_at' => $checkedAt,
            'database_status' => 'configured',
            'database_checked_at' => $checkedAt,
        ]);

        $overview = app(ServerStatusOverview::class)->get();

        $this->assertSame('online', $overview['game_servers'][0]['state']);
        $this->assertSame('online', $overview['game_servers'][0]['availability_state']);
        $this->assertSame(42, $overview['game_servers'][0]['players']);
        $this->assertFalse(app(ServerMonitorCoordinator::class)->isDue());
    }

    public function test_monitor_command_respects_saved_interval_unless_forced(): void
    {
        [$loginServer, $gameServer] = $this->servers();
        app(ServerMonitorSettings::class)->update(300);
        $this->assertSame(300, app(ServerMonitorSettings::class)->refreshIntervalSeconds());
        $loginServer->update([
            'monitor_status' => 'online',
            'monitor_failures' => 0,
            'monitor_checked_at' => now(),
        ]);
        $gameServer->update([
            'monitor_status' => 'online',
            'monitor_failures' => 0,
            'monitor_checked_at' => now(),
        ]);
        $ports = new FakeServicePortProbe;
        $ports->default = false;
        $this->app->instance(ServicePortProbe::class, $ports);
        $this->app->instance(GameServerOnlineCounter::class, new FakeGameServerOnlineCounter);

        $this->assertSame(0, Artisan::call('kaevcms:servers-monitor'));
        $this->assertStringContainsString('still fresh', Artisan::output());
        $this->assertSame(0, $loginServer->fresh()?->monitor_failures);

        $this->assertSame(0, Artisan::call('kaevcms:servers-monitor', ['--force' => true]));
        $this->assertSame(1, $loginServer->fresh()?->monitor_failures);
        $this->assertSame(1, $gameServer->fresh()?->monitor_failures);
    }

    /** @return array{LoginServer,GameServer} */
    private function servers(): array
    {
        return $this->freshMobiusServerPair(
            [
                'name' => 'Основной LoginServer',
                'database_host' => 'db.example.test',
                'database_name' => 'login',
                'database_username' => 'l2',
                'database_charset' => 'utf8mb4',
            ],
            [
                'name' => 'Interlude',
                'rates' => 'x1',
                'sort_order' => 0,
            ],
        );
    }
}
