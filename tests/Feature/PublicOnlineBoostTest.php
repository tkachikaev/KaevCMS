<?php

namespace Tests\Feature;

use App\Contracts\ExternalDatabaseConnectionTester;
use App\Models\Admin;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Services\GameServerFeatures\GameServerFeatureSettings;
use App\Services\Servers\PublicOnlineBoost;
use App\Services\Servers\ServerStatusOverview;
use App\Support\Themes\ThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithServerFixtures;
use Tests\Fakes\FakeExternalDatabaseConnectionTester;
use Tests\TestCase;

class PublicOnlineBoostTest extends TestCase
{
    use InteractsWithServerFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ExternalDatabaseConnectionTester::class, new FakeExternalDatabaseConnectionTester);
    }

    public function test_range_boost_stays_within_bounds_and_is_stable_inside_a_window(): void
    {
        $boost = app(PublicOnlineBoost::class);
        $settings = ['enabled' => true, 'mode' => 'range', 'min' => 50, 'max' => 80, 'percent' => 0];

        $values = [];
        foreach (range(0, 40) as $window) {
            $value = $boost->apply(100, $settings, 7, $window * 300);
            $this->assertGreaterThanOrEqual(150, $value);
            $this->assertLessThanOrEqual(180, $value);
            $values[] = $value;
        }

        $this->assertGreaterThan(1, count(array_unique($values)));
        $this->assertSame($boost->apply(100, $settings, 7, 1000), $boost->apply(100, $settings, 7, 1100));
    }

    public function test_percent_and_disabled_boost(): void
    {
        $boost = app(PublicOnlineBoost::class);

        $this->assertSame(150, $boost->apply(100, ['enabled' => true, 'mode' => 'percent', 'min' => 0, 'max' => 0, 'percent' => 50], 1));
        $this->assertSame(0, $boost->apply(0, ['enabled' => true, 'mode' => 'percent', 'min' => 0, 'max' => 0, 'percent' => 50], 1));
        $this->assertSame(100, $boost->apply(100, ['enabled' => false, 'mode' => 'range', 'min' => 5, 'max' => 9, 'percent' => 0], 1));
    }

    public function test_website_shows_the_boosted_online_while_administration_keeps_the_real_one(): void
    {
        app(ThemeManager::class)->activate('default');
        $this->seed();
        GameServer::query()->delete();
        LoginServer::query()->delete();
        [, $gameServer] = $this->freshMobiusServerPair(
            ['name' => 'Login', 'database_host' => 'db.example.test', 'database_name' => 'login', 'database_username' => 'l2', 'database_charset' => 'utf8mb4'],
            ['name' => 'Interlude', 'rates' => 'x1', 'sort_order' => 0],
        );
        $gameServer->loginServer?->update([
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
            'online_players' => 40,
            'online_checked_at' => now(),
            'database_status' => 'configured',
            'database_checked_at' => now(),
        ]);
        app(GameServerFeatureSettings::class)->updateOnlineBoost($gameServer, [
            'enabled' => true, 'mode' => 'percent', 'min' => 0, 'max' => 0, 'percent' => 50,
        ]);

        $status = collect(app(ServerStatusOverview::class)->get()['game_servers'])->firstWhere('id', $gameServer->id);
        $this->assertSame(40, $status['players']);
        $this->assertSame(60, $status['public_players']);

        $this->get('/')->assertOk()->assertSee('Онлайн: 60');

        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin')->get('/admin')->assertOk()->assertSee('40 онлайн')->assertDontSee('60 онлайн');
    }
}
