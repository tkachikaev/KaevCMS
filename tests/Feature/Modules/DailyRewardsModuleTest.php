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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use KaevCMS\Modules\DailyRewards\Models\DailyRewardCalendar;
use KaevCMS\Modules\DailyRewards\Models\DailyRewardClaim;
use KaevCMS\Modules\DailyRewards\Models\DailyRewardDay;
use KaevCMS\Modules\DailyRewards\Models\DailyRewardItem;
use RuntimeException;
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
            'version' => '1.4.0',
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

    public function test_auditor_can_preview_daily_rewards_and_its_journal_without_changing_data(): void
    {
        $auditor = Admin::factory()->auditor()->create();

        $this->actingAs($auditor, 'admin')
            ->get('/admin/extensions/daily-rewards')
            ->assertOk()
            ->assertSee(__('Read-only mode'))
            ->assertSee('/admin/extensions/daily-rewards/claims', false);

        $this->actingAs($auditor, 'admin')
            ->get('/admin/extensions/daily-rewards/claims')
            ->assertOk()
            ->assertSee(__('Read-only mode'));

        $this->actingAs($auditor, 'admin')
            ->post('/admin/extensions/daily-rewards', [])
            ->assertForbidden();
    }

    public function test_language_switch_from_selected_calendar_returns_to_localized_account_overview(): void
    {
        $user = User::factory()->create(['locale' => 'en']);

        $this->actingAs($user)
            ->get(route('language.switch', [
                'locale' => 'ru',
                'return' => '/modules/daily-rewards?calendar=1&account=2',
            ]))
            ->assertRedirect(url('/ru/account'))
            ->assertSessionHas('locale', 'ru');

        $this->assertSame('ru', $user->fresh()->locale);
    }

    public function test_daily_reward_uses_the_interlude_catalog_item_name(): void
    {
        $server = GameServer::factory()->create(['chronicle' => 'Interlude']);
        $item = new DailyRewardItem(['item_id' => 907, 'amount' => 1]);

        $this->assertSame('Necklace of Anguish', $item->displayName($server));
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

    public function test_admin_editor_uses_a_visual_calendar_day_dialog_and_item_preview_endpoint(): void
    {
        $server = GameServer::factory()->create(['chronicle' => 'Interlude']);
        $calendar = $this->createCalendar($server, 2026, 8);
        $this->configureDay($calendar, 1, [['item_id' => 57, 'amount' => 1000000]]);
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);

        $this->actingAs($owner, 'admin')
            ->get('/admin/extensions/daily-rewards/'.$calendar->id.'/edit')
            ->assertOk()
            ->assertSee('daily-reward-admin-calendar-grid', false)
            ->assertSee('data-daily-day-open=', false)
            ->assertSee('daily-reward-admin-dialog', false)
            ->assertSee('data-daily-item-preview', false)
            ->assertSee('Адена')
            ->assertSee('assets/admin/js/daily-rewards.js', false)
            ->assertSee('data-daily-unsaved', false)
            ->assertSee(__('module-daily-rewards::messages.apply_day'));

        $this->actingAs($owner, 'admin')
            ->get('/admin/extensions/daily-rewards/'.$calendar->id.'/items/57')
            ->assertOk()
            ->assertJsonPath('item_id', 57)
            ->assertJsonPath('name', 'Адена');
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

    public function test_account_page_filters_current_period_in_sql_before_eager_loading(): void
    {
        config()->set('app.timezone', 'Europe/Moscow');
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 12, 12, 0, 0, 'Europe/Moscow'));

        $currentServer = GameServer::factory()->create(['name' => 'Current Rewards World']);
        $historicalServer = GameServer::factory()->create(['name' => 'Historical Rewards World']);
        $current = $this->createCalendar($currentServer, 2026, 8, true);
        $historical = $this->createCalendar($historicalServer, 2025, 8, true);
        $this->configureDay($current, 12, [['item_id' => 57, 'amount' => 1]]);
        $this->configureDay($historical, 12, [['item_id' => 57, 'amount' => 999]]);
        $user = User::factory()->create();
        UserGameAccount::factory()->for($user)->registeredOn($currentServer)->create();

        /** @var list<mixed>|null $periodQueryBindings */
        $periodQueryBindings = null;
        DB::listen(static function (QueryExecuted $query) use (&$periodQueryBindings): void {
            $sql = strtolower($query->sql);
            if (
                str_contains($sql, 'module_daily_reward_calendars')
                && str_contains($sql, 'year')
                && str_contains($sql, 'month')
            ) {
                $periodQueryBindings = $query->bindings;
            }
        });

        $response = $this->actingAs($user)
            ->get('/modules/daily-rewards')
            ->assertOk()
            ->assertSee('Current Rewards World');
        // Classic's sidebar legitimately lists every game server. Restrict the
        // calendar assertion to the module content rather than the entire shell.
        $document = new \DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $sections = (new \DOMXPath($document))->query('//*[contains(concat(" ", normalize-space(@class), " "), " daily-reward-account-section ")]');
        $this->assertNotFalse($sections);
        $this->assertCount(1, $sections);
        $this->assertStringContainsString('Current Rewards World', $sections->item(0)->textContent);
        $this->assertStringNotContainsString('Historical Rewards World', $sections->item(0)->textContent);

        $this->assertNotNull($periodQueryBindings, 'The account calendar query must filter year and month in SQL.');
        $this->assertContains(2026, $periodQueryBindings);
        $this->assertContains(8, $periodQueryBindings);
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

        $claim = DailyRewardClaim::query()->with('rewardGrant.items')->firstOrFail();
        $snapshot = collect($claim->items_snapshot)
            ->mapWithKeys(static fn (array $item): array => [(int) $item['item_id'] => [
                'amount' => (int) $item['amount'],
                'name' => $item['name'],
            ]])
            ->all();
        $granted = $claim->rewardGrant->items
            ->mapWithKeys(static fn (RewardInventoryItem $item): array => [$item->item_id => [
                'amount' => $item->amount,
                'name' => $item->item_name,
            ]])
            ->all();
        $this->assertSame($snapshot, $granted);
    }

    public function test_claim_journal_shows_operation_uuid_item_icon_name_and_server(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 12, 12, 0, 0, 'Europe/Moscow'));

        $server = GameServer::factory()->create(['name' => 'Daily Journal World']);
        $calendar = $this->createCalendar($server, 2026, 8, true);
        $this->configureDay($calendar, 12, [['item_id' => 57, 'amount' => 250000]]);
        $user = User::factory()->create();
        $account = UserGameAccount::factory()->for($user)->registeredOn($server)->create();
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $root = storage_path('framework/testing/game-assets-'.Str::uuid());
        File::ensureDirectoryExists($root.'/items/common');
        File::put($root.'/items/common/57.webp', 'icon');
        config()->set('cms.game_assets.uploads_path', $root);

        try {
            $this->claim($user, $calendar, $account, (string) Str::uuid())->assertSessionHas('status');
            $claim = DailyRewardClaim::query()->with('rewardGrant')->firstOrFail();
            $operationUuid = $claim->rewardGrant?->operation_uuid;
            $this->assertNotNull($operationUuid);

            $this->actingAs($owner, 'admin')
                ->get('/admin/extensions/daily-rewards/claims')
                ->assertOk()
                ->assertSee('data-testid="daily-reward-claim-row"', false)
                ->assertSee((string) $operationUuid)
                ->assertSee('Daily Journal World')
                ->assertSee(__('module-daily-rewards::messages.server_id', ['id' => $server->id]))
                ->assertSee('Адена')
                ->assertSee('/uploads/game-assets/items/common/57.webp', false);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_successful_and_failed_claims_render_the_shared_operation_modal(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 12, 12, 0, 0, 'Europe/Moscow'));

        $server = GameServer::factory()->create(['name' => 'Interlude x5']);
        $calendar = $this->createCalendar($server, 2026, 8, true);
        $this->configureDay($calendar, 12, [['item_id' => 57, 'amount' => 250000]]);
        $user = User::factory()->create();
        $account = UserGameAccount::factory()->for($user)->registeredOn($server)->create();

        $success = $this->claim($user, $calendar, $account, (string) Str::uuid())
            ->assertSessionHas(
                'account_operation',
                static fn (array $operation): bool => $operation['type'] === 'success'
                    && $operation['title'] === __('module-daily-rewards::messages.claim_success_title')
                    && ($operation['items'][0]['item_id'] ?? null) === 57
                    && ($operation['items'][0]['amount'] ?? null) === 250000,
            );

        $this->actingAs($user)
            ->get((string) $success->headers->get('Location'))
            ->assertOk()
            ->assertSee('data-account-operation-modal', false)
            ->assertSee(__('module-daily-rewards::messages.claim_success_title'))
            ->assertSee('× 250 000');

        $this->claim($user, $calendar, $account, (string) Str::uuid())
            ->assertSessionHasErrors('reward')
            ->assertSessionHas(
                'account_operation',
                static fn (array $operation): bool => $operation['type'] === 'error'
                    && $operation['title'] === __('module-daily-rewards::messages.claim_failed_title'),
            );
    }

    public function test_same_account_cannot_claim_twice_but_another_account_can(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 12, 12, 0, 0, 'Europe/Moscow'));

        $server = GameServer::factory()->create();
        $calendar = $this->createCalendar($server, 2026, 8, true, DailyRewardCalendar::CLAIM_LIMIT_GAME_ACCOUNT);
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

    public function test_account_filters_submit_through_the_shared_runtime_instead_of_inline_handlers(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 12, 12, 0, 0, 'Europe/Moscow'));

        $server = GameServer::factory()->create();
        $calendar = $this->createCalendar($server, 2026, 8, true);
        $user = User::factory()->create();
        $account = UserGameAccount::factory()->for($user)->registeredOn($server)->create();

        $html = $this->actingAs($user)
            ->get('/modules/daily-rewards?calendar='.$calendar->id.'&account='.$account->id)
            ->assertOk()
            ->assertDontSee('onchange=', false)
            ->getContent();

        $this->assertSame(1, preg_match_all('/<select name="calendar" data-account-auto-submit\b/', (string) $html));
        $this->assertSame(1, preg_match_all('/<select name="account" data-account-auto-submit\b/', (string) $html));
        $this->assertStringContainsString('data-account-auto-submit', (string) file_get_contents(public_path('assets/account/js/navigation.js')));
    }

    public function test_module_translation_keys_have_full_ru_en_parity(): void
    {
        $english = require base_path('modules/daily-rewards/lang/en/messages.php');
        $russian = require base_path('modules/daily-rewards/lang/ru/messages.php');

        $this->assertSame(array_keys($english), array_keys($russian));
        $this->assertCount(count($english), array_unique(array_map('mb_strtolower', array_keys($english))));
        $this->assertCount(count($russian), array_unique(array_map('mb_strtolower', array_keys($russian))));
    }

    public function test_game_account_limit_lets_each_of_nine_accounts_claim_once(): void
    {
        // Nine claims in a row exceed the route's per-minute throttle, which is not under test here.
        $this->withoutMiddleware(ThrottleRequests::class);
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 12, 12, 0, 0, 'Europe/Moscow'));

        $server = GameServer::factory()->create();
        $calendar = $this->createCalendar($server, 2026, 8, true, DailyRewardCalendar::CLAIM_LIMIT_GAME_ACCOUNT);
        $this->configureDay($calendar, 12, [['item_id' => 57, 'amount' => 100]]);
        $user = User::factory()->create();
        $accounts = UserGameAccount::factory()->count(9)->for($user)->registeredOn($server)->create();

        foreach ($accounts as $account) {
            $this->claim($user, $calendar, $account, (string) Str::uuid())->assertSessionHas('status');
        }

        $this->assertSame(9, DailyRewardClaim::query()->count());
        $this->assertSame(9, RewardInventoryGrant::query()->count());
        $this->assertSame(0, DailyRewardClaim::query()->whereNotNull('site_user_limit_id')->count());
    }

    public function test_site_user_limit_allows_one_claim_for_nine_accounts_without_extra_inventory(): void
    {
        // Nine claims in a row exceed the route's per-minute throttle, which is not under test here.
        $this->withoutMiddleware(ThrottleRequests::class);
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 12, 12, 0, 0, 'Europe/Moscow'));

        $server = GameServer::factory()->create();
        $calendar = $this->createCalendar($server, 2026, 8, true, DailyRewardCalendar::CLAIM_LIMIT_SITE_USER);
        $this->configureDay($calendar, 12, [
            ['item_id' => 57, 'amount' => 100],
            ['item_id' => 4037, 'amount' => 2],
        ]);
        $user = User::factory()->create();
        $accounts = UserGameAccount::factory()->count(9)->for($user)->registeredOn($server)->create();

        $this->claim($user, $calendar, $accounts[0], (string) Str::uuid())->assertSessionHas('status');
        foreach ($accounts->slice(1) as $account) {
            $this->claim($user, $calendar, $account, (string) Str::uuid())
                ->assertSessionHasErrors(['reward' => __('module-daily-rewards::messages.claim_already_claimed_by_site_user')]);
        }

        $this->assertSame(1, DailyRewardClaim::query()->count());
        $this->assertSame(1, RewardInventoryGrant::query()->count());
        $this->assertSame(2, RewardInventoryItem::query()->count());
        $this->assertDatabaseHas('module_daily_reward_claims', [
            'user_game_account_id' => $accounts[0]->id,
            'site_user_limit_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->get('/modules/daily-rewards?calendar='.$calendar->id.'&account='.$accounts[3]->id)
            ->assertOk()
            ->assertSee(__('module-daily-rewards::messages.account_help_site_user'))
            ->assertSee(__('module-daily-rewards::messages.claimed_with_account', ['account' => $accounts[0]->game_login]))
            ->assertDontSee('name="request_token"', false);
    }

    public function test_site_user_limit_is_separate_for_each_user_and_each_server(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 12, 12, 0, 0, 'Europe/Moscow'));

        $server = GameServer::factory()->create();
        $otherServer = GameServer::factory()->create();
        $calendar = $this->createCalendar($server, 2026, 8, true);
        $otherCalendar = $this->createCalendar($otherServer, 2026, 8, true);
        $this->configureDay($calendar, 12, [['item_id' => 57, 'amount' => 1]]);
        $this->configureDay($otherCalendar, 12, [['item_id' => 57, 'amount' => 1]]);

        $first = User::factory()->create();
        $second = User::factory()->create();
        $firstAccount = UserGameAccount::factory()->for($first)->registeredOn($server)->create();
        $secondAccount = UserGameAccount::factory()->for($second)->registeredOn($server)->create();
        $firstOtherAccount = UserGameAccount::factory()->for($first)->registeredOn($otherServer)->create();

        $this->claim($first, $calendar, $firstAccount, (string) Str::uuid())->assertSessionHas('status');
        $this->claim($second, $calendar, $secondAccount, (string) Str::uuid())->assertSessionHas('status');
        $this->claim($first, $otherCalendar, $firstOtherAccount, (string) Str::uuid())->assertSessionHas('status');
        $this->claim($first, $otherCalendar, $firstOtherAccount, (string) Str::uuid())->assertSessionHasErrors('reward');

        $this->assertSame(2, DailyRewardClaim::query()->where('calendar_id', $calendar->id)->count());
        $this->assertSame(1, DailyRewardClaim::query()->where('calendar_id', $otherCalendar->id)->count());
        $this->assertSame(3, RewardInventoryGrant::query()->count());
    }

    public function test_shared_login_server_does_not_merge_site_user_limits_of_two_game_servers(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 12, 12, 0, 0, 'Europe/Moscow'));

        $server = GameServer::factory()->create();
        $sibling = GameServer::factory()->create(['login_server_id' => $server->login_server_id]);
        $calendar = $this->createCalendar($server, 2026, 8, true);
        $siblingCalendar = $this->createCalendar($sibling, 2026, 8, true);
        $this->configureDay($calendar, 12, [['item_id' => 57, 'amount' => 1]]);
        $this->configureDay($siblingCalendar, 12, [['item_id' => 57, 'amount' => 1]]);
        $user = User::factory()->create();
        $account = UserGameAccount::factory()->for($user)->registeredOn($server)->create();
        $secondAccount = UserGameAccount::factory()->for($user)->registeredOn($sibling)->create();

        $this->claim($user, $calendar, $account, (string) Str::uuid())->assertSessionHas('status');
        $this->claim($user, $siblingCalendar, $account, (string) Str::uuid())->assertSessionHas('status');
        $this->claim($user, $calendar, $secondAccount, (string) Str::uuid())->assertSessionHasErrors('reward');
        $this->claim($user, $siblingCalendar, $secondAccount, (string) Str::uuid())->assertSessionHasErrors('reward');

        $this->assertSame(1, DailyRewardClaim::query()->where('calendar_id', $calendar->id)->count());
        $this->assertSame(1, DailyRewardClaim::query()->where('calendar_id', $siblingCalendar->id)->count());
        $this->assertSame(2, RewardInventoryGrant::query()->count());
        $this->assertSame(1, RewardInventoryGrant::query()->where('game_server_id', $sibling->id)->count());
    }

    public function test_repeating_the_same_request_token_never_duplicates_a_site_user_claim(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 12, 12, 0, 0, 'Europe/Moscow'));

        $server = GameServer::factory()->create();
        $calendar = $this->createCalendar($server, 2026, 8, true);
        $this->configureDay($calendar, 12, [['item_id' => 57, 'amount' => 1]]);
        $user = User::factory()->create();
        $account = UserGameAccount::factory()->for($user)->registeredOn($server)->create();
        $otherAccount = UserGameAccount::factory()->for($user)->registeredOn($server)->create();
        $token = (string) Str::uuid();

        $this->claim($user, $calendar, $account, $token)->assertSessionHas('status');
        $this->claim($user, $calendar, $account, $token)->assertSessionHas('status');
        $this->claim($user, $calendar, $otherAccount, $token)->assertSessionHasErrors('reward');

        $this->assertSame(1, DailyRewardClaim::query()->count());
        $this->assertSame(1, RewardInventoryGrant::query()->count());
        $this->assertSame(1, RewardInventoryItem::query()->count());
    }

    public function test_database_rejects_a_second_site_user_claim_but_keeps_per_account_history(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 12, 12, 0, 0, 'Europe/Moscow'));

        $server = GameServer::factory()->create();
        $calendar = $this->createCalendar($server, 2026, 8, true);
        $day = $this->configureDay($calendar, 12, [['item_id' => 57, 'amount' => 1]]);
        $user = User::factory()->create();
        [$first, $second, $third] = UserGameAccount::factory()->count(3)->for($user)->registeredOn($server)->create()->all();
        $row = fn (UserGameAccount $account, ?int $limitId): array => [
            'request_token' => (string) Str::uuid(),
            'calendar_id' => $calendar->id,
            'day_id' => $day->id,
            'user_id' => $user->id,
            'user_game_account_id' => $account->id,
            'site_user_limit_id' => $limitId,
            'game_server_id' => $server->id,
            'reward_date' => '2026-08-12',
            'user_email' => $user->email,
            'game_account_login' => $account->game_login,
            'items_snapshot' => '[]',
            'claimed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // Per-account rows keep NULL, so several accounts of one user never collide.
        DB::table('module_daily_reward_claims')->insert($row($first, null));
        DB::table('module_daily_reward_claims')->insert($row($second, null));
        DB::table('module_daily_reward_claims')->insert($row($third, $user->id));

        // A request that slipped past the application check is still stopped by the unique index.
        $this->expectException(QueryException::class);
        DB::table('module_daily_reward_claims')->insert(array_merge(
            $row($first, $user->id),
            ['user_game_account_id' => $second->id],
        ));
    }

    public function test_claim_limit_change_is_blocked_after_a_claim_today_and_allowed_the_next_day(): void
    {
        config()->set('app.timezone', 'Europe/Moscow');
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 12, 12, 0, 0, 'Europe/Moscow'));

        $server = GameServer::factory()->create();
        $calendar = $this->createCalendar($server, 2026, 8, true, DailyRewardCalendar::CLAIM_LIMIT_GAME_ACCOUNT);
        $this->configureDay($calendar, 12, [['item_id' => 57, 'amount' => 1]]);
        $user = User::factory()->create();
        $account = UserGameAccount::factory()->for($user)->registeredOn($server)->create();
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $url = '/admin/extensions/daily-rewards/'.$calendar->id;

        $this->claim($user, $calendar, $account, (string) Str::uuid())->assertSessionHas('status');

        $this->actingAs($owner, 'admin')
            ->put($url, [...$this->calendarPayload($calendar->fresh('days.items')), 'claim_limit' => 'site_user'])
            ->assertSessionHasErrors(['claim_limit' => __('module-daily-rewards::messages.claim_limit_locked_today')]);
        $this->assertSame(DailyRewardCalendar::CLAIM_LIMIT_GAME_ACCOUNT, $calendar->refresh()->claim_limit);

        // Saving without changing the mode is still allowed on a day with claims.
        $this->actingAs($owner, 'admin')
            ->put($url, [...$this->calendarPayload($calendar->fresh('days.items')), 'claim_limit' => 'game_account'])
            ->assertSessionHasNoErrors();

        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 13, 0, 5, 0, 'Europe/Moscow'));

        $this->actingAs($owner, 'admin')
            ->put($url, [...$this->calendarPayload($calendar->fresh('days.items')), 'claim_limit' => 'site_user'])
            ->assertSessionHasNoErrors();
        $this->assertSame(DailyRewardCalendar::CLAIM_LIMIT_SITE_USER, $calendar->refresh()->claim_limit);
        $this->assertSame(1, DailyRewardClaim::query()->count());
    }

    public function test_new_calendars_default_to_site_user_and_admin_can_pick_the_limit(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 12, 12, 0, 0, 'Europe/Moscow'));

        $server = GameServer::factory()->create();
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);

        $this->actingAs($owner, 'admin')
            ->get('/admin/extensions/daily-rewards/create')
            ->assertOk()
            ->assertSee(__('module-daily-rewards::messages.claim_limit'))
            ->assertSee('<option value="site_user" selected', false);

        $this->actingAs($owner, 'admin')
            ->post('/admin/extensions/daily-rewards', ['game_server_id' => $server->id, 'year' => 2026, 'month' => 9])
            ->assertSessionHasNoErrors();
        $this->actingAs($owner, 'admin')
            ->post('/admin/extensions/daily-rewards', ['game_server_id' => $server->id, 'year' => 2026, 'month' => 10, 'claim_limit' => 'game_account'])
            ->assertSessionHasNoErrors();
        $this->actingAs($owner, 'admin')
            ->post('/admin/extensions/daily-rewards', ['game_server_id' => $server->id, 'year' => 2026, 'month' => 11, 'claim_limit' => 'everyone'])
            ->assertSessionHasErrors('claim_limit');

        $this->assertSame('site_user', DailyRewardCalendar::query()->where('month', 9)->value('claim_limit'));
        $this->assertSame('game_account', DailyRewardCalendar::query()->where('month', 10)->value('claim_limit'));
        $this->assertFalse(DailyRewardCalendar::query()->where('month', 11)->exists());
    }

    public function test_migration_keeps_existing_calendars_on_the_per_account_limit_and_preserves_history(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 12, 12, 0, 0, 'Europe/Moscow'));

        $migration = require base_path('modules/daily-rewards/database/migrations/2026_11_23_000400_add_claim_limit_to_module_daily_rewards.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('module_daily_reward_calendars', 'claim_limit'));

        $server = GameServer::factory()->create();
        $user = User::factory()->create();
        [$first, $second] = UserGameAccount::factory()->count(2)->for($user)->registeredOn($server)->create()->all();
        $calendarId = DB::table('module_daily_reward_calendars')->insertGetId([
            'game_server_id' => $server->id, 'year' => 2026, 'month' => 8, 'timezone' => 'Europe/Moscow',
            'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $dayId = DB::table('module_daily_reward_days')->insertGetId([
            'calendar_id' => $calendarId, 'day_number' => 12, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([$first, $second] as $account) {
            DB::table('module_daily_reward_claims')->insert([
                'request_token' => (string) Str::uuid(), 'calendar_id' => $calendarId, 'day_id' => $dayId,
                'user_id' => $user->id, 'user_game_account_id' => $account->id, 'game_server_id' => $server->id,
                'reward_date' => '2026-08-12', 'user_email' => $user->email, 'game_account_login' => $account->game_login,
                'items_snapshot' => '[]', 'claimed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $migration->up();

        $this->assertSame('game_account', DB::table('module_daily_reward_calendars')->where('id', $calendarId)->value('claim_limit'));
        $this->assertSame(2, DB::table('module_daily_reward_claims')->where('calendar_id', $calendarId)->whereNull('site_user_limit_id')->count());
    }

    public function test_pending_and_failed_game_accounts_cannot_claim(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 12, 12, 0, 0, 'Europe/Moscow'));

        $server = GameServer::factory()->create();
        $calendar = $this->createCalendar($server, 2026, 8, true, DailyRewardCalendar::CLAIM_LIMIT_GAME_ACCOUNT);
        $this->configureDay($calendar, 12, [['item_id' => 57, 'amount' => 1]]);
        $user = User::factory()->create();
        $pending = UserGameAccount::factory()->for($user)->registeredOn($server)->create(['creation_status' => UserGameAccount::STATUS_PENDING]);
        $failed = UserGameAccount::factory()->for($user)->registeredOn($server)->create(['creation_status' => UserGameAccount::STATUS_FAILED]);

        $this->claim($user, $calendar, $pending, (string) Str::uuid())
            ->assertSessionHasErrors(['reward' => __('module-daily-rewards::messages.claim_account_unavailable')]);
        $this->claim($user, $calendar, $failed, (string) Str::uuid())
            ->assertSessionHasErrors(['reward' => __('module-daily-rewards::messages.claim_account_unavailable')]);

        $this->assertDatabaseEmpty('module_daily_reward_claims');
        $this->assertDatabaseEmpty('reward_inventory_grants');
        $this->assertDatabaseEmpty('reward_inventory_items');
    }

    public function test_failed_inventory_grant_rolls_back_the_site_user_claim(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 12, 12, 0, 0, 'Europe/Moscow'));

        $server = GameServer::factory()->create();
        $calendar = $this->createCalendar($server, 2026, 8, true);
        $this->configureDay($calendar, 12, [['item_id' => 57, 'amount' => 1]]);
        $user = User::factory()->create();
        $first = UserGameAccount::factory()->for($user)->registeredOn($server)->create();

        DB::listen(static function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'insert into "reward_inventory_items"')) {
                throw new RuntimeException('inventory unavailable');
            }
        });

        try {
            $this->withoutExceptionHandling();
            $this->claim($user, $calendar, $first, (string) Str::uuid());
            $this->fail('The claim should fail when the inventory grant fails.');
        } catch (RuntimeException $exception) {
            $this->assertSame('inventory unavailable', $exception->getMessage());
        }

        $this->assertDatabaseEmpty('module_daily_reward_claims');
        $this->assertDatabaseEmpty('reward_inventory_grants');
        $this->assertSame(0, DB::table('reward_inventory_items')->count());
    }

    private function createCalendar(
        GameServer $server,
        int $year,
        int $month,
        bool $enabled = false,
        string $claimLimit = DailyRewardCalendar::CLAIM_LIMIT_SITE_USER,
    ): DailyRewardCalendar {
        $calendar = DailyRewardCalendar::query()->create([
            'game_server_id' => $server->id,
            'year' => $year,
            'month' => $month,
            'timezone' => 'Europe/Moscow',
            'enabled' => $enabled,
            'claim_limit' => $claimLimit,
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
