<?php

namespace Database\Seeders;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Models\User;
use App\Models\UserGameAccount;
use App\Support\Modules\ModuleManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class BrowserTestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('BrowserTestSeeder may run only in the testing environment.');
        }

        $adminEmail = (string) config('browser_tests.admin.email');
        $adminPassword = (string) config('browser_tests.admin.password');

        $admin = Admin::query()->updateOrCreate(
            ['email' => $adminEmail],
            [
                'name' => 'Browser Test Admin',
                'password' => Hash::make($adminPassword),
                'is_active' => true,
                'role' => AdminRole::Owner,
                'locale' => 'ru',
            ],
        );

        $playerEmail = (string) config('browser_tests.player.email');
        $playerPassword = (string) config('browser_tests.player.password');
        $player = User::query()->updateOrCreate(
            ['email' => $playerEmail],
            [
                'name' => 'browser-player',
                'email_verified_at' => now(),
                'password' => Hash::make($playerPassword),
                'is_active' => true,
                'locale' => 'ru',
            ],
        );

        $loginServer = LoginServer::query()->updateOrCreate(
            ['name' => 'Browser LoginServer'],
            [
                'driver' => 'browser_test_unsupported',
                'database_host' => '127.0.0.1',
                'database_port' => 3306,
                'database_name' => 'browser_test',
                'database_username' => 'browser_test',
                'database_password' => null,
                'database_charset' => 'utf8mb4',
            ],
        );

        $gameServer = GameServer::query()->updateOrCreate(
            ['name' => 'Browser World'],
            [
                'rates' => 'x1',
                'chronicle' => 'Interlude',
                'mode' => 'PvE',
                'sort_order' => 100,
                'login_server_id' => $loginServer->id,
                'driver' => 'browser_test_unsupported',
                'use_login_server_connection' => true,
            ],
        );

        UserGameAccount::query()->updateOrCreate(
            [
                'login_server_id' => $loginServer->id,
                'normalized_login' => 'browsergame',
            ],
            [
                'user_id' => $player->id,
                'registration_game_server_id' => $gameServer->id,
                'game_login' => 'BrowserGame',
                'created_via_cms' => true,
            ],
        );

        app(ModuleManager::class)->enable('promo-codes');

        $promoCodeId = DB::table('module_promo_codes')->insertGetId([
            'game_server_id' => $gameServer->id,
            'code' => 'BROWSER2026',
            'starts_at' => null,
            'ends_at' => null,
            'total_limit' => 0,
            'per_user_limit' => 1,
            'activations_count' => 0,
            'enabled' => true,
            'created_by_admin_id' => $admin->id,
            'updated_by_admin_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('module_promo_code_rewards')->insert([
            [
                'promo_code_id' => $promoCodeId,
                'item_id' => 57,
                'amount' => 1000000,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'promo_code_id' => $promoCodeId,
                'item_id' => 4037,
                'amount' => 10,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
