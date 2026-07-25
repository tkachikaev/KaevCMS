<?php

namespace Tests\Feature;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\User;
use App\Models\UserGameAccount;
use Database\Seeders\BrowserTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BrowserTestSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_test_administrator_is_created_from_configuration(): void
    {
        config()->set('browser_tests.admin.email', 'configured-browser-admin@example.test');
        config()->set('browser_tests.admin.password', 'ConfiguredBrowserPassword123!');
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

        $player = User::query()->where('email', 'configured-browser-player@example.test')->firstOrFail();
        $this->assertSame('browser-player', $player->name);
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
    }
}
