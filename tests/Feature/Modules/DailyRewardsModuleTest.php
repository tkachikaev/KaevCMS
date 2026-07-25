<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\GameServer;
use App\Models\RewardInventoryGrant;
use App\Models\RewardInventoryItem;
use App\Models\User;
use App\Models\UserGameAccount;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleNavigationRegistry;
use App\Support\Modules\ModuleRuntime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use KaevCMS\Modules\DailyRewards\Models\DailyRewardCalendar;
use KaevCMS\Modules\DailyRewards\Models\DailyRewardClaim;
use KaevCMS\Modules\DailyRewards\Models\DailyRewardDay;
use Tests\TestCase;

class DailyRewardsModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $modules = app(ModuleManager::class);
        $module = $modules->enable('daily-rewards');
        app(ModuleRuntime::class)->bootModule($module);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_bundled_module_is_valid_migrates_and_registers_navigation(): void
    {
        $module = app(ModuleManager::class)->inspect('daily-rewards');

        $this->assertTrue($module['valid'], implode(PHP_EOL, $module['errors']));
        $this->assertTrue($module['compatible'], implode(PHP_EOL, $module['errors']));
        $this->assertTrue($module['enabled']);
        $this->assertSame([], $module['pending_migrations']);
        $this->assertTrue(Schema::hasTable('module_daily_reward_calendars'));
        $this->assertTrue(Schema::hasTable('module_daily_reward_days'));
        $this->assertTrue(Schema::hasTable('module_daily_reward_items'));
        $this->assertTrue(Schema::hasTable('module_daily_reward_claims'));
        $this->assertDatabaseHas('cms_modules', [
            'id' => 'daily-rewards',
            'version' => '1.0.2',
            'enabled' => true,
        ]);

        $this->assertSame(
            'modules.daily-rewards.index',
            app(ModuleNavigationRegistry::class)->accountLinks()[0]['route'] ?? null,
        );
        $this->assertSame(
            'admin.module-pages.daily-rewards.index',
            app(ModuleNavigationRegistry::class)->adminLinks()[0]['route'] ?? null,
        );
    }

    public function test_owner_sees_all_game_servers_and_calendar_uses_main_timezone(): void
    {
        config()->set('app.timezone', 'Europe/Berlin');

        $server = GameServer::factory()->create([
            'name' => 'Standalone Interlude',
            'login_server_id' => null,
        ]);
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);

        $this->actingAs($owner, 'admin')
            ->get('/admin/extensions/daily-rewards/create')
            ->assertOk()
            ->assertSee('Standalone Interlude')
            ->assertDontSee('name="timezone"', false);

        $this->actingAs($owner, 'admin')
            ->post('/admin/extensions/daily-rewards', [
                'game_server_id' => $server->id,
                'year' => 2028,
                'month' => 2,
                'enabled' => 1,
            ])
            ->assertRedirect();

        $calendar = DailyRewardCalendar::query()->firstOrFail();
        $this->assertFalse($calendar->enabled);
        $this->assertSame('Europe/Berlin', $calendar->timezone);
        $this->assertSame(29, $calendar->days()->count());
        $this->assertDatabaseHas('module_daily_reward_days', [
            'calendar_id' => $calendar->id,
            'day_number' => 29,
            'enabled' => false,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'category' => 'module',
            'action' => 'daily_reward_calendar.created',
            'target_id' => (string) $calendar->id,
        ]);
    }

    public function test_duplicate_period_is_rejected(): void
    {
        $server = GameServer::factory()->create();
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $this->createCalendar($server, 2026, 8);

        $this->actingAs($owner, 'admin')
            ->post('/admin/extensions/daily-rewards', [
                'game_server_id' => $server->id,
                'year' => 2026,
                'month' => 8,
                'enabled' => 0,
            ])
            ->assertSessionHasErrors('game_server_id');

        $this->assertSame(1, DailyRewardCalendar::query()->count());
    }

    public function test_owner_configures_multiple_items_and_administrator_is_read_only(): void
    {
        $server = GameServer::factory()->create();
        $calendar = $this->createCalendar($server, 2026, 8);
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $administrator = Admin::factory()->administrator()->create();
        $payload = $this->calendarPayload($calendar, [
            1 => [
                'enabled' => 1,
                'rewards' => [
                    ['item_id' => 57, 'amount' => 1000000],
                    ['item_id' => 4037, 'amount' => 5],
                ],
            ],
        ]);

        $this->actingAs($administrator, 'admin')
            ->put('/admin/extensions/daily-rewards/'.$calendar->id, $payload)
            ->assertForbidden();

        $this->actingAs($owner, 'admin')
            ->put('/admin/extensions/daily-rewards/'.$calendar->id, $payload)
            ->assertRedirect('/admin/extensions/daily-rewards/'.$calendar->id.'/edit');

        $day = $calendar->days()->where('day_number', 1)->with('items')->firstOrFail();
        $this->assertTrue($day->enabled);
        $this->assertCount(2, $day->items);
        $this->assertDatabaseHas('module_daily_reward_items', [
            'day_id' => $day->id,
            'item_id' => 57,
            'amount' => 1000000,
        ]);

        $this->actingAs($administrator, 'admin')
            ->get('/admin/extensions/daily-rewards/'.$calendar->id.'/edit')
            ->assertOk()
            ->assertDontSee('type="submit">'.__('module-daily-rewards::messages.save'), false);
    }

    public function test_enabled_day_requires_reward_and_item_ids_are_unique_after_normalization(): void
    {
        $server = GameServer::factory()->create();
        $calendar = $this->createCalendar($server, 2026, 8);
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);

        $payload = $this->calendarPayload($calendar, [
            1 => ['enabled' => 1, 'rewards' => []],
            2 => [
                'enabled' => 1,
                'rewards' => [
                    ['item_id' => 57, 'amount' => 1],
                    ['item_id' => '057', 'amount' => 2],
                ],
            ],
        ]);

        $this->actingAs($owner, 'admin')
            ->put('/admin/extensions/daily-rewards/'.$calendar->id, $payload)
            ->assertSessionHasErrors([
                'days.1.rewards',
                'days.2.rewards.1.item_id',
            ]);

        $this->assertDatabaseEmpty('module_daily_reward_items');
    }

    public function test_current_day_claim_is_idempotent_and_grants_all_items_to_server_inventory(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 12, 12, 0, 0, 'Europe/Moscow'));

        $server = GameServer::factory()->create(['name' => 'Interlude x5']);
        $calendar = $this->createCalendar($server, 2026, 8, true);
        $day = $this->configureDay($calendar, 12, [
            ['item_id' => 57, 'amount' => 1000000],
            ['item_id' => 4037, 'amount' => 5],
        ]);
        $user = User::factory()->create();
        $account = UserGameAccount::factory()->for($user)->registeredOn($server)->create();
        $token = (string) Str::uuid();

        $payload = [
            'calendar_id' => $calendar->id,
            'user_game_account_id' => $account->id,
            'request_token' => $token,
        ];

        $this->actingAs($user)->post('/modules/daily-rewards/claim', $payload)
            ->assertRedirect(route('modules.daily-rewards.index', [
                'calendar' => $calendar->id,
                'account' => $account->id,
            ], false))
            ->assertSessionHas('status');
        $this->actingAs($user)->post('/modules/daily-rewards/claim', $payload)
            ->assertSessionHas('status');

        $this->assertSame(1, DailyRewardClaim::query()->count());
        $this->assertSame(1, RewardInventoryGrant::query()->count());
        $this->assertSame(2, RewardInventoryItem::query()->count());
        $this->assertDatabaseHas('module_daily_reward_claims', [
            'calendar_id' => $calendar->id,
            'day_id' => $day->id,
            'user_id' => $user->id,
            'user_game_account_id' => $account->id,
            'game_server_id' => $server->id,
            'game_account_login' => $account->game_login,
        ]);
        $this->assertDatabaseHas('reward_inventory_items', [
            'user_id' => $user->id,
            'game_server_id' => $server->id,
            'item_id' => 57,
            'amount' => 1000000,
            'status' => 'available',
        ]);
    }

    public function test_same_account_cannot_claim_twice_but_another_account_can(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 12, 12, 0, 0, 'Europe/Moscow'));

        $server = GameServer::factory()->create();
        $calendar = $this->createCalendar($server, 2026, 8, true);
        $this->configureDay($calendar, 12, [['item_id' => 57, 'amount' => 100]]);
        $user = User::factory()->create();
        $first = UserGameAccount::factory()->for($user)->registeredOn($server)->create();
        $second = UserGameAccount::factory()->for($user)->registeredOn($server)->create();

        $this->claim($user, $calendar, $first, (string) Str::uuid())->assertSessionHas('status');
        $this->claim($user, $calendar, $first, (string) Str::uuid())->assertSessionHasErrors('reward');
        $this->claim($user, $calendar, $second, (string) Str::uuid())->assertSessionHas('status');

        $this->assertSame(2, DailyRewardClaim::query()->count());
        $this->assertSame(2, RewardInventoryGrant::query()->count());
    }

    public function test_wrong_user_or_login_server_account_cannot_claim(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 12, 12, 0, 0, 'Europe/Moscow'));

        $server = GameServer::factory()->create();
        $otherServer = GameServer::factory()->create();
        $calendar = $this->createCalendar($server, 2026, 8, true);
        $this->configureDay($calendar, 12, [['item_id' => 57, 'amount' => 1]]);
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $foreignAccount = UserGameAccount::factory()->for($otherUser)->registeredOn($server)->create();
        $otherLoginAccount = UserGameAccount::factory()->for($user)->registeredOn($otherServer)->create();

        $this->claim($user, $calendar, $foreignAccount, (string) Str::uuid())->assertSessionHasErrors('reward');
        $this->claim($user, $calendar, $otherLoginAccount, (string) Str::uuid())->assertSessionHasErrors('reward');

        $this->assertDatabaseEmpty('module_daily_reward_claims');
        $this->assertDatabaseEmpty('reward_inventory_items');
    }

    public function test_calendar_shows_missed_and_future_days_but_only_current_day_can_be_claimed(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 12, 12, 0, 0, 'Europe/Moscow'));

        $server = GameServer::factory()->create();
        $calendar = $this->createCalendar($server, 2026, 8, true);
        $this->configureDay($calendar, 11, [['item_id' => 57, 'amount' => 1]]);
        $this->configureDay($calendar, 13, [['item_id' => 57, 'amount' => 1]]);
        $user = User::factory()->create();
        $account = UserGameAccount::factory()->for($user)->registeredOn($server)->create();

        $this->actingAs($user)
            ->get('/modules/daily-rewards?calendar='.$calendar->id.'&account='.$account->id)
            ->assertOk()
            ->assertSee(__('module-daily-rewards::messages.status_missed'))
            ->assertSee(__('module-daily-rewards::messages.status_future'));

        $this->claim($user, $calendar, $account, (string) Str::uuid())
            ->assertSessionHasErrors('reward');

        $calendar->update(['year' => 2027]);
        $this->claim($user, $calendar, $account, (string) Str::uuid())
            ->assertSessionHasErrors('reward');

        $this->assertDatabaseEmpty('module_daily_reward_claims');
    }

    public function test_claimed_day_is_immutable_and_runtime_uses_main_timezone(): void
    {
        config()->set('app.timezone', 'Europe/Moscow');
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 12, 0, 30, 0, 'Europe/Moscow'));

        $server = GameServer::factory()->create();
        $calendar = $this->createCalendar($server, 2026, 8, true);
        $calendar->update(['timezone' => 'UTC']);
        $this->configureDay($calendar, 12, [['item_id' => 57, 'amount' => 100]]);
        $user = User::factory()->create();
        $account = UserGameAccount::factory()->for($user)->registeredOn($server)->create();
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $this->claim($user, $calendar, $account, (string) Str::uuid())->assertSessionHas('status');

        $payload = $this->calendarPayload($calendar->fresh('days.items'), [
            12 => ['enabled' => 1, 'rewards' => [['item_id' => 57, 'amount' => 999]]],
        ]);

        $this->actingAs($owner, 'admin')
            ->put('/admin/extensions/daily-rewards/'.$calendar->id, $payload)
            ->assertSessionHasErrors('days.12');

        $this->assertDatabaseHas('module_daily_reward_items', [
            'day_id' => $calendar->days()->where('day_number', 12)->value('id'),
            'item_id' => 57,
            'amount' => 100,
        ]);
    }

    public function test_disabling_module_removes_runtime_routes_but_preserves_data(): void
    {
        $server = GameServer::factory()->create();
        $calendar = $this->createCalendar($server, 2026, 8);
        $modules = app(ModuleManager::class);

        $modules->disable('daily-rewards');
        $modules->refresh();

        $user = User::factory()->create();
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);

        $this->assertDatabaseHas('module_daily_reward_calendars', ['id' => $calendar->id]);
        $this->actingAs($user)
            ->get('/modules/daily-rewards')
            ->assertNotFound();
        $this->actingAs($owner, 'admin')
            ->get('/admin/extensions/daily-rewards')
            ->assertNotFound();
    }

    public function test_module_translation_keys_have_full_ru_en_parity(): void
    {
        $english = require base_path('modules/daily-rewards/lang/en/messages.php');
        $russian = require base_path('modules/daily-rewards/lang/ru/messages.php');

        $this->assertSame(array_keys($english), array_keys($russian));
        $this->assertCount(count($english), array_unique(array_map('mb_strtolower', array_keys($english))));
        $this->assertCount(count($russian), array_unique(array_map('mb_strtolower', array_keys($russian))));
    }

    private function createCalendar(
        GameServer $server,
        int $year,
        int $month,
        bool $enabled = false,
    ): DailyRewardCalendar {
        $calendar = DailyRewardCalendar::query()->create([
            'game_server_id' => $server->id,
            'year' => $year,
            'month' => $month,
            'timezone' => 'Europe/Moscow',
            'enabled' => $enabled,
        ]);

        foreach (range(1, $calendar->daysInMonth()) as $dayNumber) {
            $calendar->days()->create(['day_number' => $dayNumber, 'enabled' => false]);
        }

        return $calendar->load(['gameServer.translations', 'days.items']);
    }

    /** @param list<array{item_id:int,amount:int}> $items */
    private function configureDay(DailyRewardCalendar $calendar, int $dayNumber, array $items): DailyRewardDay
    {
        $day = $calendar->days()->where('day_number', $dayNumber)->firstOrFail();
        $day->update(['enabled' => true]);
        foreach ($items as $index => $item) {
            $day->items()->create([
                'item_id' => $item['item_id'],
                'amount' => $item['amount'],
                'sort_order' => $index,
            ]);
        }

        return $day->load('items');
    }

    /**
     * @param  array<int, array{enabled:int|bool,rewards:list<array{item_id:int|string,amount:int|string}>}>  $overrides
     * @return array<string, mixed>
     */
    private function calendarPayload(DailyRewardCalendar $calendar, array $overrides = []): array
    {
        $calendar->loadMissing('days.items');
        $days = [];

        foreach ($calendar->days as $day) {
            $days[$day->day_number] = [
                'enabled' => $day->enabled ? 1 : 0,
                'rewards' => $day->items
                    ->map(static fn ($item): array => [
                        'item_id' => $item->item_id,
                        'amount' => $item->amount,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        foreach ($overrides as $dayNumber => $override) {
            $days[$dayNumber] = $override;
        }

        return [
            'enabled' => $calendar->enabled ? 1 : 0,
            'days' => $days,
        ];
    }

    private function claim(
        User $user,
        DailyRewardCalendar $calendar,
        UserGameAccount $account,
        string $token,
    ): TestResponse {
        return $this->actingAs($user)
            ->from('/modules/daily-rewards')
            ->post('/modules/daily-rewards/claim', [
                'calendar_id' => $calendar->id,
                'user_game_account_id' => $account->id,
                'request_token' => $token,
            ]);
    }
}
