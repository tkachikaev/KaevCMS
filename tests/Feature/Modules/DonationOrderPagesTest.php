<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\User;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use App\Support\Themes\AccountThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use KaevCMS\Modules\Donations\Models\DonationOrder;
use KaevCMS\Modules\Donations\Services\DonationOrders;
use KaevCMS\Modules\Donations\Services\DonationRates;
use KaevCMS\Modules\Donations\Services\DonationSettings;
use Tests\TestCase;

class DonationOrderPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('donations');
        app(ModuleRuntime::class)->bootModule($module);
    }

    private function configure(): Admin
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        app(DonationSettings::class)->updateVisibility(true, $owner);
        app(DonationRates::class)->saveRate('RUB', '10', true, $owner);

        return $owner;
    }

    public function test_donation_pages_receive_the_authenticated_player_in_each_account_theme(): void
    {
        $this->configure();
        $player = User::factory()->create();
        $order = app(DonationOrders::class)->createDraft($player, 'RUB', 100, (string) Str::uuid());

        foreach (['luxury', 'kaev-aurelia'] as $theme) {
            app(AccountThemeManager::class)->activate($theme);
            foreach (['/modules/donations', '/account/wallet', '/modules/donations/orders/'.$order->order_uuid] as $path) {
                $this->actingAs($player)->get($path)
                    ->assertOk()
                    ->assertViewHas('user', fn (User $user): bool => $user->is($player))
                    ->assertSee($player->name);
            }
        }
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_player_can_create_unpaid_draft_from_valid_quote_and_view_own_history(): void
    {
        $this->configure();
        $player = User::factory()->create();
        $this->actingAs($player)->get('/modules/donations?coins=100&currency=RUB')
            ->assertOk()->assertDontSee('data-testid="donations-create-order"', false);
        $key = (string) Str::uuid();
        $response = $this->actingAs($player)->post('/modules/donations/orders', [
            'coins' => 100, 'currency' => 'RUB', 'request_key' => $key,
            'total_minor' => 1, // forged amount must be ignored
        ]);
        $order = DonationOrder::query()->sole();
        $response->assertRedirect(route('modules.donations.orders.show', ['orderUuid' => $order->order_uuid]));
        $this->assertSame(100000, (int) $order->total_minor);
        $this->assertSame('draft', $order->status);
        $this->assertNull($order->paid_at);
        $this->assertNull($order->provider_code);
        $this->actingAs($player)->get('/modules/donations/orders')->assertRedirect('/account/wallet');
        $this->get('/account/wallet')->assertOk()
            ->assertSee('1000.00')->assertSee($order->order_uuid);
        $this->actingAs($player)->get('/modules/donations/orders/'.$order->order_uuid)
            ->assertOk()->assertSee('1000.00')->assertSee('data-testid="donations-order-detail"', false);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_idempotent_http_retry_and_different_payload_rejected(): void
    {
        $this->configure();
        $player = User::factory()->create();
        $key = (string) Str::uuid();
        $form = ['coins' => 20, 'currency' => 'RUB', 'request_key' => $key];
        $this->actingAs($player)->post('/modules/donations/orders', $form)->assertRedirect();
        $this->actingAs($player)->post('/modules/donations/orders', $form)->assertRedirect();
        $this->assertDatabaseCount('module_donation_orders', 1);
        $this->assertDatabaseCount('module_donation_order_events', 1);
        $this->actingAs($player)->post('/modules/donations/orders', array_merge($form, ['coins' => 21]))
            ->assertSessionHasErrors('order');
        $this->assertDatabaseCount('module_donation_orders', 1);
    }

    public function test_other_player_cannot_see_or_cancel_any_order(): void
    {
        $this->configure();
        $first = User::factory()->create();
        $other = User::factory()->create();
        $order = app(DonationOrders::class)->createDraft($first, 'RUB', 3, (string) Str::uuid());
        $this->actingAs($other)->get('/modules/donations/orders')->assertRedirect('/account/wallet');
        $this->get('/account/wallet')->assertOk()->assertDontSee($order->order_uuid);
        $this->actingAs($other)->get('/modules/donations/orders/'.$order->order_uuid)->assertNotFound();
        $this->actingAs($other)->post('/modules/donations/orders/'.$order->order_uuid.'/cancel')->assertNotFound();
        $this->assertSame('draft', $order->fresh()->status);
    }

    public function test_closing_new_orders_preserves_history_and_cancellation(): void
    {
        $owner = $this->configure();
        $player = User::factory()->create();
        $order = app(DonationOrders::class)->createDraft($player, 'RUB', 30, (string) Str::uuid());
        app(DonationSettings::class)->updateVisibility(false, $owner);
        $this->actingAs($player)->get('/modules/donations')->assertNotFound();
        $this->actingAs($player)->get('/modules/donations/orders')->assertRedirect('/account/wallet');
        $this->get('/account/wallet')->assertOk()->assertSee($order->order_uuid);
        $this->actingAs($player)->get('/modules/donations/orders/'.$order->order_uuid)->assertOk();
        $this->actingAs($player)->post('/modules/donations/orders', [
            'coins' => 30, 'currency' => 'RUB', 'request_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('order');
        $this->actingAs($player)->post('/modules/donations/orders/'.$order->order_uuid.'/cancel')->assertRedirect();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_expired_order_is_displayed_as_expired_and_cannot_be_paid_or_reopened(): void
    {
        $this->configure();
        $player = User::factory()->create();
        $order = app(DonationOrders::class)->createDraft($player, 'RUB', 30, (string) Str::uuid());
        DonationOrder::query()->whereKey($order->id)->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($player)->get('/modules/donations/orders/'.$order->order_uuid)
            ->assertOk()->assertDontSee('name="payment_amount"', false)
            ->assertDontSee('data-testid="donations-create-test-draft"', false);
        $this->assertSame('expired', $order->fresh()->displayStatus());
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin')
            ->get('/admin/extensions/donations/orders?status=expired')->assertOk()->assertSee('#'.$order->id);
        $this->actingAs($player)->post('/modules/donations/orders/'.$order->order_uuid.'/cancel')->assertRedirect();
        $this->assertSame('expired', $order->fresh()->status);
    }

    public function test_admin_journal_filters_and_permissions_are_enforced(): void
    {
        $owner = $this->configure();
        $player = User::factory()->create();
        $order = app(DonationOrders::class)->createDraft($player, 'RUB', 3, (string) Str::uuid());
        $auditor = Admin::factory()->create(['role' => AdminRole::Auditor]);
        $editor = Admin::factory()->create(['role' => AdminRole::Editor]);
        $this->actingAs($owner, 'admin')->get('/admin/extensions/donations/orders?status=draft&currency=RUB&user_id='.$player->id)
            ->assertOk()->assertSee('data-testid="donations-admin-orders"', false);
        $this->actingAs($owner, 'admin')->get('/admin/extensions/donations/orders/'.$order->order_uuid)
            ->assertOk()->assertSee($order->order_uuid);
        $this->actingAs($owner, 'admin')->get('/admin/extensions/donations/orders?status=paid')
            ->assertSessionHasErrors('status');
        $this->actingAs($auditor, 'admin')->get('/admin/extensions/donations/orders')->assertOk();
        $this->actingAs($editor, 'admin')->get('/admin/extensions/donations/orders')->assertForbidden();
        $this->actingAs($editor, 'admin')->get('/admin/extensions/donations/orders/'.$order->order_uuid)->assertForbidden();
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_module_disable_hides_http_routes_without_touching_wallet_or_orders(): void
    {
        $this->configure();
        $player = User::factory()->create();
        $order = app(DonationOrders::class)->createDraft($player, 'RUB', 3, (string) Str::uuid());
        app(ModuleManager::class)->disable('donations');
        $this->actingAs($player)->get('/modules/donations/orders')->assertNotFound();
        $this->assertDatabaseHas('module_donation_orders', ['id' => $order->id]);
        $this->assertDatabaseCount('wallet_entries', 0);
    }
}
