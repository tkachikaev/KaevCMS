<?php

namespace Tests\Feature;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\User;
use App\Models\UserGameAccount;
use Database\Seeders\BrowserTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BrowserTestSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_test_administrator_is_created_from_configuration(): void
    {
        config()->set('browser_tests.admin.email', 'configured-browser-admin@example.test');
        config()->set('browser_tests.admin.password', 'ConfiguredBrowserPassword123!');
        config()->set('browser_tests.auditor.email', 'configured-browser-auditor@example.test');
        config()->set('browser_tests.auditor.password', 'ConfiguredBrowserAuditorPassword123!');
        config()->set('browser_tests.player.email', 'configured-browser-player@example.test');
        config()->set('browser_tests.player.password', 'ConfiguredBrowserPlayerPassword123!');

        $this->seed(BrowserTestSeeder::class);

        $admin = Admin::query()
            ->where('email', 'configured-browser-admin@example.test')
            ->firstOrFail();

        $this->assertSame('Browser Test Admin', $admin->name);
        $this->assertTrue($admin->is_active);
        $this->assertSame(AdminRole::Owner, $admin->role);
        $this->assertSame('ru', $admin->locale);
        $this->assertTrue(Hash::check('ConfiguredBrowserPassword123!', $admin->password));

        $auditor = Admin::query()
            ->where('email', 'configured-browser-auditor@example.test')
            ->firstOrFail();

        $this->assertSame('Browser Test Auditor', $auditor->name);
        $this->assertSame(AdminRole::Auditor, $auditor->role);
        $this->assertTrue(Hash::check('ConfiguredBrowserAuditorPassword123!', $auditor->password));

        $player = User::query()->where('email', 'configured-browser-player@example.test')->firstOrFail();
        $this->assertSame('browser-player', $player->name);
        $this->assertDatabaseHas('cms_modules', ['id' => 'daily-rewards', 'enabled' => true]);
        $this->assertSame(1, DB::table('module_daily_reward_calendars')->where('enabled', true)->count());
        $this->assertSame(2, DB::table('module_daily_reward_items')->count());
        $this->assertTrue($player->is_active);
        $this->assertNotNull($player->email_verified_at);
        $this->assertTrue(Hash::check('ConfiguredBrowserPlayerPassword123!', $player->password));
        $this->assertTrue(UserGameAccount::query()->where('user_id', $player->id)->where('game_login', 'BrowserGame')->exists());
        $this->assertDatabaseHas('cms_modules', [
            'id' => 'promo-codes',
            'enabled' => true,
        ]);
        $this->assertDatabaseHas('module_promo_codes', [
            'code' => 'BROWSER2026',
            'total_limit' => 0,
            'per_user_limit' => 1,
            'enabled' => true,
        ]);
        $this->assertDatabaseHas('module_promo_code_rewards', [
            'item_id' => 57,
            'amount' => 1000000,
        ]);
        $this->assertDatabaseHas('cms_modules', [
            'id' => 'support-tickets',
            'enabled' => true,
        ]);
        $this->assertDatabaseHas('module_support_tickets', [
            'user_id' => $player->id,
            'category' => 'donations_and_bonuses',
            'status' => 'new',
            'subject' => 'Browser seeded support ticket',
        ]);
        $this->assertDatabaseHas('module_support_ticket_messages', [
            'user_id' => $player->id,
            'author_type' => 'player',
            'is_internal' => false,
            'body' => 'Browser seeded ticket message.',
        ]);
    }
}
