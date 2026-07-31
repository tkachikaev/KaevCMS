<?php

namespace Database\Seeders;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\GameServer;
use App\Models\GameServerFeature;
use App\Models\LoginServer;
use App\Models\RewardDelivery;
use App\Models\RewardInventoryItem;
use App\Models\User;
use App\Models\UserGameAccount;
use App\Services\GameServerFeatures\GameServerFeatureSettings;
use App\Services\Rewards\RewardInventoryService;
use App\Support\Modules\ModuleManager;
use App\Support\Rewards\RewardGrantItem;
use App\Support\Rewards\RewardQueueDiagnostic;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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

        $auditorEmail = (string) config('browser_tests.auditor.email');
        $auditorPassword = (string) config('browser_tests.auditor.password');

        Admin::query()->updateOrCreate(
            ['email' => $auditorEmail],
            [
                'name' => 'Browser Test Auditor',
                'password' => Hash::make($auditorPassword),
                'is_active' => true,
                'role' => AdminRole::Auditor,
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
                'service_host' => '127.0.0.1',
                'service_port' => 2106,
                'database_status' => 'configured',
                'database_error' => null,
                'database_checked_at' => now(),
                'database_last_success_at' => now(),
                'database_last_error_class' => null,
                'database_last_error_at' => null,
                'database_latency_ms' => 17,
                'database_schema_profile' => 'mobius_interlude_plus',
                'database_capabilities' => [
                    'account_lookup',
                    'account_creation',
                    'password_change',
                    'account_data',
                ],
                'database_table_checks' => [
                    [
                        'table' => 'accounts',
                        'required' => true,
                        'table_exists' => true,
                        'missing_columns' => [],
                    ],
                    [
                        'table' => 'account_data',
                        'required' => false,
                        'table_exists' => true,
                        'missing_columns' => [],
                    ],
                    [
                        'table' => 'accounts_ipauth',
                        'required' => false,
                        'table_exists' => false,
                        'missing_columns' => [],
                    ],
                ],
                'monitor_status' => 'online',
                'monitor_checked_at' => now(),
                'monitor_last_online_at' => now(),
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
                'service_host' => '127.0.0.1',
                'service_port' => 7777,
                'database_status' => 'configured',
                'database_error' => null,
                'database_checked_at' => now(),
                'database_last_success_at' => now(),
                'database_last_error_class' => null,
                'database_last_error_at' => null,
                'database_latency_ms' => 23,
                'database_schema_profile' => 'mobius_legacy',
                'database_capabilities' => [
                    'level',
                    'pvp',
                    'pk',
                    'play_time',
                    'heroes',
                ],
                'database_table_checks' => [
                    [
                        'table' => 'characters',
                        'required' => true,
                        'table_exists' => true,
                        'missing_columns' => [],
                    ],
                    [
                        'table' => 'clan_data',
                        'required' => true,
                        'table_exists' => true,
                        'missing_columns' => [],
                    ],
                    [
                        'table' => 'heroes',
                        'required' => false,
                        'table_exists' => true,
                        'missing_columns' => [],
                    ],
                    [
                        'table' => 'castle',
                        'required' => false,
                        'table_exists' => false,
                        'missing_columns' => [],
                    ],
                    [
                        'table' => 'optional_character_services_with_extended_identifier',
                        'required' => false,
                        'table_exists' => false,
                        'missing_columns' => [],
                    ],
                ],
                'monitor_status' => 'online',
                'monitor_checked_at' => now(),
                'monitor_last_online_at' => now(),
            ],
        );

        // The initial schema migration creates a legacy game server without a
        // monitor timestamp. Keep every browser fixture fresh so the first
        // dashboard visit cannot run external probes and replace the seeded
        // deterministic database diagnostics.
        $monitorCheckedAt = now();
        LoginServer::query()->update(['monitor_checked_at' => $monitorCheckedAt]);
        GameServer::query()->update(['monitor_checked_at' => $monitorCheckedAt]);

        GameServerFeature::query()->updateOrCreate(
            [
                'game_server_id' => $gameServer->id,
                'feature_key' => GameServerFeatureSettings::CHARACTER_RESCUE,
            ],
            [
                'enabled' => true,
                'settings' => [
                    'location_name' => 'Giran',
                    'x' => 83400,
                    'y' => 148600,
                    'z' => -3400,
                    'offline_delay_minutes' => 5,
                    'cooldown_hours' => 12,
                ],
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

        app(ModuleManager::class)->enable('daily-rewards');

        $today = CarbonImmutable::now((string) config('app.timezone', 'UTC'));
        $calendarId = DB::table('module_daily_reward_calendars')->insertGetId([
            'game_server_id' => $gameServer->id,
            'year' => $today->year,
            'month' => $today->month,
            'timezone' => (string) config('app.timezone', 'UTC'),
            'enabled' => true,
            'created_by_admin_id' => $admin->id,
            'updated_by_admin_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $dayRows = [];
        for ($dayNumber = 1; $dayNumber <= $today->daysInMonth; $dayNumber++) {
            $dayRows[] = [
                'calendar_id' => $calendarId,
                'day_number' => $dayNumber,
                'enabled' => $dayNumber === $today->day,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('module_daily_reward_days')->insert($dayRows);

        $todayDayId = DB::table('module_daily_reward_days')
            ->where('calendar_id', $calendarId)
            ->where('day_number', $today->day)
            ->value('id');
        DB::table('module_daily_reward_items')->insert([
            [
                'day_id' => $todayDayId,
                'item_id' => 57,
                'amount' => 250000,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'day_id' => $todayDayId,
                'item_id' => 4037,
                'amount' => 2,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        app(ModuleManager::class)->enable('support-tickets');

        $supportTicketId = DB::table('module_support_tickets')->insertGetId([
            'user_id' => $player->id,
            'user_name_snapshot' => $player->name,
            'user_email_snapshot' => $player->email,
            'category' => 'donations_and_bonuses',
            'status' => 'new',
            'subject' => 'Browser seeded support ticket',
            'assigned_admin_id' => null,
            'closed_by_admin_id' => null,
            'closed_by_user_id' => null,
            'last_message_at' => now(),
            'last_player_message_at' => now(),
            'last_staff_message_at' => null,
            'closed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('module_support_ticket_messages')->insert([
            'ticket_id' => $supportTicketId,
            'author_type' => 'player',
            'user_id' => $player->id,
            'admin_id' => null,
            'author_name_snapshot' => $player->name,
            'admin_role_snapshot' => null,
            'is_internal' => false,
            'body' => 'Browser seeded ticket message.',
            'edited_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $queueGrant = app(RewardInventoryService::class)->grant(
            user: $player,
            server: $gameServer,
            grantKey: 'browser.reward-queue.review',
            sourceType: 'browser-test',
            items: [new RewardGrantItem(57, 5000)],
            sourceReference: 'browser-review',
            sourceLabel: 'Browser reward queue review',
            actor: $admin,
        );
        $queueItem = $queueGrant->items->firstOrFail();
        $delivery = RewardDelivery::query()->create([
            'operation_uuid' => (string) Str::uuid(),
            'request_token' => (string) Str::uuid(),
            'user_id' => $player->id,
            'game_server_id' => $gameServer->id,
            'user_game_account_id' => UserGameAccount::query()
                ->where('user_id', $player->id)
                ->where('registration_game_server_id', $gameServer->id)
                ->value('id'),
            'character_id' => 9001,
            'character_name' => 'Browser Hero',
            'account_login' => 'BrowserGame',
            'status' => RewardDelivery::STATUS_REVIEW,
            'failure_code' => RewardQueueDiagnostic::WriteUnknown->value,
            'requested_at' => now(),
        ]);
        $delivery->items()->create([
            'reward_inventory_item_id' => $queueItem->id,
            'item_id' => $queueItem->item_id,
            'item_name' => $queueItem->item_name,
            'amount' => $queueItem->amount,
        ]);
        $queueItem->update(['status' => RewardInventoryItem::STATUS_RESERVED]);
    }
}
