<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\GameServer;
use App\Models\User;
use App\Models\UserGameAccount;
use App\Services\Dashboard\AdminDashboardOverview;
use App\Services\Dashboard\DashboardTrends;
use App\Services\Servers\ServerOnlineHistory;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-22 15:30:00'));
    }

    public function test_registrations_are_counted_per_day_and_hour(): void
    {
        foreach (['2026-10-22 15:05:00', '2026-10-22 15:20:00', '2026-10-22 09:00:00', '2026-10-19 12:00:00', '2026-10-12 12:00:00', '2026-09-10 12:00:00'] as $createdAt) {
            User::factory()->create(['created_at' => $createdAt]);
        }
        $server = GameServer::factory()->create();
        $player = User::query()->firstOrFail();
        UserGameAccount::factory()->for($player)->registeredOn($server)->create(['created_at' => '2026-10-22 15:25:00']);
        UserGameAccount::factory()->for($player)->registeredOn($server)->create([
            'created_at' => '2026-10-22 15:26:00',
            'creation_status' => UserGameAccount::STATUS_FAILED,
        ]);

        $registrations = app(AdminDashboardOverview::class)->forAdmin(Admin::factory()->create())['registrations'];

        $this->assertNotNull($registrations);
        $this->assertCount(30, $registrations['days']);
        $this->assertSame('2026-10-22', $registrations['days'][29]['date']->toDateString());
        $this->assertSame(3, $registrations['days'][29]['count']);
        $this->assertSame(1, $registrations['days'][29]['game_accounts']);
        $this->assertSame(1, $registrations['days'][26]['count']);
        // The 40-day-old account is outside the 30-day window.
        $this->assertSame(5, $registrations['total']);
        $this->assertSame(4, $registrations['last_7_days']);
        $this->assertSame(1, $registrations['game_accounts_total']);
        $this->assertSame(3, $registrations['max']);
        $this->assertCount(24, $registrations['hours']);
        $this->assertSame(['masters' => 2, 'games' => 1], $registrations['hours'][23]);
        $this->assertSame(['masters' => 1, 'games' => 0], $registrations['hours'][17]);
        // Five-minute steps for the last 5 hours; the last step starts at 15:30.
        $this->assertCount(60, $registrations['minutes']);
        $this->assertSame(['masters' => 1, 'games' => 0], $registrations['minutes'][54]);
        $this->assertSame(['masters' => 1, 'games' => 0], $registrations['minutes'][57]);
        $this->assertSame(['masters' => 0, 'games' => 1], $registrations['minutes'][58]);
        $this->assertSame(['masters' => 0, 'games' => 0], $registrations['minutes'][59]);
        $this->assertSame(2, array_sum(array_column($registrations['minutes'], 'masters')));
    }

    public function test_online_chart_shows_the_total_and_each_game_server(): void
    {
        $history = app(ServerOnlineHistory::class);
        [$first, $second] = GameServer::factory()->count(2)->create()->all();
        $history->record($first, 100, CarbonImmutable::parse('2026-10-22 10:01:00'));
        $history->record($second, 50, CarbonImmutable::parse('2026-10-22 10:03:00'));
        $history->record($first, 120, CarbonImmutable::parse('2026-10-22 10:06:00'));
        $history->record($first, 30, CarbonImmutable::parse('2026-10-21 23:56:00'));
        $history->record($first, 40, CarbonImmutable::parse('2026-10-22 15:31:00'));
        $history->record($second, 2, CarbonImmutable::parse('2026-10-22 15:32:00'));
        $history->record($first, 35, CarbonImmutable::parse('2026-10-22 15:22:00'));

        $chart = $history->chart(30, 24, 60);
        $total = $chart['total'];
        $one = $chart['per_server'][$first->id];
        $two = $chart['per_server'][$second->id];

        $this->assertSame(GameServer::query()->orderBy('sort_order')->orderBy('id')->pluck('id')->all(), array_column($chart['servers'], 'id'));
        // Days: the highest online of the day; the total is the highest sum of one step
        // (100 + 50 at 10:00), not the sum of each server's own peak (120 + 50).
        $this->assertSame(150, $total['days'][29]);
        $this->assertSame(30, $total['days'][28]);
        $this->assertNull($total['days'][27]);
        $this->assertSame([120, 50], [$one['days'][29], $two['days'][29]]);
        $this->assertSame([30, null], [$one['days'][28], $two['days'][28]]);
        $this->assertSame(150, $chart['peak']);

        // Hours: the last 24 hours, the highest online of each hour.
        $this->assertCount(24, $total['hours']);
        $this->assertSame([150, 120, 50], [$total['hours'][18], $one['hours'][18], $two['hours'][18]]);
        $this->assertSame(30, $total['hours'][7]);
        $this->assertSame([42, 40, 2], [$total['hours'][23], $one['hours'][23], $two['hours'][23]]);

        // Five-minute steps for the last 5 hours: the online of each step.
        $this->assertCount(60, $total['minutes']);
        $this->assertSame([35, null, 42], array_slice($total['minutes'], 57));
        $this->assertSame([35, null, 40], array_slice($one['minutes'], 57));
        $this->assertSame([null, null, 2], array_slice($two['minutes'], 57));
        // 10:00 is 5.5 hours ago, outside the last 5 hours.
        $this->assertSame([], array_filter(array_slice($total['minutes'], 0, 57), static fn ($value) => $value !== null));

        // The widget: the total first, then one line per server, with a server picker.
        $widget = DashboardTrends::widgets(null, $chart, null)[0];
        $keys = array_column($widget['series'], 'key');
        $this->assertSame('total', $keys[0]);
        $this->assertContains('server-'.$first->id, $keys);
        $this->assertContains('server-'.$second->id, $keys);
        $this->assertSame('Общий онлайн', $widget['series'][0]['label']);
        $this->assertTrue($widget['picker']);
        $byKey = array_column($widget['series'], null, 'key');
        $this->assertSame([150, 120, 50], [$byKey['total']['days'][29], $byKey['server-'.$first->id]['days'][29], $byKey['server-'.$second->id]['days'][29]]);
    }

    public function test_a_single_game_server_has_one_online_line_without_a_picker(): void
    {
        // Installation creates one game server.
        $server = GameServer::query()->sole();
        app(ServerOnlineHistory::class)->record($server, 70, CarbonImmutable::parse('2026-10-22 12:00:00'));

        $widget = DashboardTrends::widgets(null, app(ServerOnlineHistory::class)->chart(), null)[0];

        $this->assertSame(['total'], array_column($widget['series'], 'key'));
        $this->assertFalse($widget['picker']);
        $this->assertSame(70, $widget['series'][0]['days'][29]);
    }

    public function test_chart_widgets_offer_five_minute_steps_for_the_last_hours(): void
    {
        $overview = app(AdminDashboardOverview::class)->forAdmin(Admin::factory()->create());

        $widgets = DashboardTrends::widgets($overview['registrations'], $overview['online'], $overview['donations']);

        $this->assertSame(['online', 'registrations'], array_column($widgets, 'key'));
        $this->assertCount(60, $widgets[0]['labels']['minutes']);
        $this->assertSame(['short' => '15:30', 'long' => '22 октября, 15:30'], $widgets[0]['labels']['minutes'][59]);
        $this->assertSame('10:35', $widgets[0]['labels']['minutes'][0]['short']);
        foreach ($widgets as $widget) {
            foreach ($widget['series'] as $series) {
                $this->assertCount(60, $series['minutes']);
            }
        }
    }

    public function test_donation_payment_count_belongs_to_the_main_currency(): void
    {
        $module = app(ModuleManager::class)->enable('donations');
        app(ModuleRuntime::class)->bootModule($module);
        $player = User::factory()->create(['name' => 'Donator']);

        $this->receipt($player, 'RUB', 10000, 'credited', '2026-10-22 12:00:00');
        $this->receipt($player, 'RUB', 20000, 'credited', '2026-10-15 12:00:00');
        $this->receipt($player, 'RUB', 30000, 'credited', '2026-10-01 12:00:00');
        $this->receipt($player, 'RUB', 5000, 'credited', '2026-09-10 12:00:00');
        $this->receipt($player, 'USD', 1000, 'credited', '2026-10-20 12:00:00');
        $this->receipt($player, 'RUB', 99900, 'review', null);

        $donations = app(AdminDashboardOverview::class)->forAdmin(Admin::factory()->create())['donations'];

        $this->assertNotNull($donations);
        $this->assertSame('RUB', $donations['currency']);
        $this->assertSame([
            ['currency' => 'RUB', 'amount' => 600.0, 'previous' => 50.0],
            ['currency' => 'USD', 'amount' => 10.0, 'previous' => 0.0],
        ], $donations['totals']);
        // The card shows the RUB total and average; the USD payment is not in its count.
        $this->assertSame(3, $donations['payments']);
        $this->assertSame(200.0, $donations['average']);
        $this->assertSame(100.0, $donations['days'][29]['amount']);
        $this->assertSame(100.0, $donations['hours'][20]);
        $this->assertSame(100.0, array_sum($donations['minutes']));
        $this->assertSame(100.0, $donations['minutes'][17]);
        $this->assertSame(1, $donations['review']);
        $this->assertCount(5, $donations['latest']);
        $this->assertSame('Donator', $donations['latest'][0]['player']);
    }

    public function test_top_ups_chart_is_shown_before_the_first_payment(): void
    {
        $module = app(ModuleManager::class)->enable('donations');
        app(ModuleRuntime::class)->bootModule($module);
        $owner = Admin::factory()->create();

        // No currency players can pay in yet: the chart is there and says there is no data.
        $overview = app(AdminDashboardOverview::class)->forAdmin($owner);
        $widget = collect(DashboardTrends::widgets(null, null, $overview['donations']))->firstWhere('key', 'donations');
        $this->assertNotNull($widget);
        $this->assertSame('number', $widget['format']);
        $this->assertSame([], array_filter($widget['series'][0]['days'], static fn ($value) => $value !== null));

        // With an enabled currency it is a zero line in that currency, and the card shows zero.
        DB::table('module_donation_currency_rates')->insert([
            ['currency_code' => 'USD', 'exponent' => 2, 'unit_price_minor' => 10, 'enabled' => false, 'created_at' => now(), 'updated_at' => now()],
            ['currency_code' => 'RUB', 'exponent' => 2, 'unit_price_minor' => 1000, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $overview = app(AdminDashboardOverview::class)->forAdmin($owner);
        $widget = collect(DashboardTrends::widgets(null, null, $overview['donations']))->firstWhere('key', 'donations');
        $this->assertSame('RUB', $overview['donations']['currency']);
        $this->assertSame('money', $widget['format']);
        $this->assertSame(array_fill(0, 30, 0.0), $widget['series'][0]['days']);
        $this->assertSame(0, $overview['donations']['payments']);
        $this->assertNull($overview['donations']['average']);

        $this->actingAs($owner, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('data-testid="dashboard-chart-donations"', false)
            ->assertSee(format_money(0, 'RUB'));
    }

    private function receipt(User $player, string $currency, int $amountMinor, string $status, ?string $creditedAt): void
    {
        $orderId = DB::table('module_donation_orders')->insertGetId([
            'order_uuid' => (string) Str::uuid(),
            'user_id' => $player->id,
            'idempotency_key' => (string) Str::uuid(),
            'coins' => 1,
            'currency_code' => $currency,
            'exponent' => 2,
            'unit_price_minor' => $amountMinor,
            'total_minor' => $amountMinor,
            'status' => 'paid',
            'expires_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $preparationId = DB::table('module_donation_payment_preparations')->insertGetId([
            'order_id' => $orderId,
            'provider_code' => 'test',
            'mode' => 'test',
            'checkout_method' => 'POST',
            'provider_label' => (string) Str::uuid(),
            'payer_amount_minor' => $amountMinor,
            'currency_code' => $currency,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('module_donation_payment_receipts')->insert([
            'order_id' => $orderId,
            'preparation_id' => $preparationId,
            'provider_code' => 'test',
            'provider_payment_id' => (string) Str::uuid(),
            'currency_code' => $currency,
            'payer_amount_minor' => $amountMinor,
            'received_amount_minor' => $amountMinor,
            'notification_sha256' => str_repeat('0', 64),
            'encrypted_notification' => 'x',
            'status' => $status,
            'credited_at' => $creditedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
