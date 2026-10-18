<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\User;
use App\Services\Wallet\WalletTransactionService;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use App\Support\Themes\AccountThemeManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use KaevCMS\Modules\Donations\Models\DonationOrder;
use KaevCMS\Modules\Donations\Services\DonationOrders;
use KaevCMS\Modules\Donations\Services\DonationPaymentPreparations;
use KaevCMS\Modules\Donations\Services\DonationProviderSettings;
use KaevCMS\Modules\Donations\Services\DonationRates;
use KaevCMS\Modules\Donations\Services\DonationSettings;
use Tests\Concerns\RefreshDonationDatabase;
use Tests\TestCase;

final class DonationPlayerHistoryTest extends TestCase
{
    use RefreshDonationDatabase;

    private User $player;

    private Admin $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleRuntime::class)->bootModule(app(ModuleManager::class)->enable('donations'));
        $this->player = User::factory()->create();
        $this->owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        app(DonationSettings::class)->updateVisibility(true, $this->owner);
        app(DonationRates::class)->saveRate('RUB', '1.25', true, $this->owner);
        app(DonationProviderSettings::class)->save($this->owner, 'yoomoney', true, 'live', ['RUB'], [
            'receiver' => '410012345678901', 'notification_secret' => 'history-fixture-secret',
        ]);
        config(['donation-payments.checkout_enabled' => true, 'donation-payments.callbacks_enabled' => true,
            'donation-payments.credits_enabled' => true, 'wallet.enabled_sources.donations' => true]);
        DB::table('module_donation_live_settings')->where('id', 1)->update(['mode' => 'live', 'pilot_user_id' => null]);
        Http::preventStrayRequests();
    }

    private function order(int $coins = 100, ?User $user = null): DonationOrder
    {
        return app(DonationOrders::class)->createDraft($user ?? $this->player, 'RUB', $coins, (string) Str::uuid());
    }

    public function test_purchase_and_ledger_credit_are_one_row_and_pending_topups_come_first(): void
    {
        $purchase = $this->order();
        $engine = app(WalletTransactionService::class);
        // Build a settled ledger fixture through the engine; no external payment is performed.
        $entry = $engine->credit($this->player, 100, 'donations', 'donation:'.$purchase->order_uuid);
        $engine->credit($this->player, 10, 'manual', 'history-gift');
        $engine->debit($this->player, 3, 'shop', 'history-spending');
        $pending = $this->order(20);
        $other = $this->order(777, User::factory()->create());
        $before = DB::table('audit_logs')->count();

        foreach (['kaev-aurelia', 'luxury'] as $theme) {
            app(AccountThemeManager::class)->activate($theme);
            $response = $this->actingAs($this->player)->get('/account/wallet')->assertOk()
                ->assertSee('Покупка монет')->assertSee('125.00 RUB')->assertSee('+100 монет')
                ->assertSee('−3 монеты')->assertSee('Монеты начислены')->assertSee('Ожидает оплаты')
                ->assertDontSee($other->order_uuid)->assertDontSee('history-fixture-secret');
            $rows = $response->viewData('entries');
            $this->assertSame(4, $rows->total());
            $this->assertSame('topup-'.$pending->id, $rows->items()[0]['key']);
            $this->assertCount(1, collect($rows->items())->where('key', 'wallet-'.$entry->id));
        }
        $this->assertSame($before, DB::table('audit_logs')->count());
        $this->assertDatabaseCount('wallet_entries', 3);
        $this->assertDatabaseHas('wallets', ['user_id' => $this->player->id, 'balance' => 107]);
        Http::assertNothingSent();
    }

    public function test_inactive_orders_are_optional_and_expiry_reads_do_not_mutate_orders(): void
    {
        $cancelled = $this->order(11);
        app(DonationOrders::class)->cancelDraft($this->player, $cancelled->order_uuid);
        $expired = $this->order(12);
        DB::table('module_donation_orders')->where('id', $expired->id)->update(['expires_at' => now()->subSecond()]);
        $pending = $this->order(13);
        $this->actingAs($this->player)->get('/account/wallet')->assertOk()
            ->assertSee($pending->order_uuid)->assertDontSee($cancelled->order_uuid)->assertDontSee($expired->order_uuid);
        $response = $this->get('/account/wallet?inactive=1')->assertOk()
            ->assertSee($cancelled->order_uuid)->assertSee($expired->order_uuid)
            ->assertSee('Пополнение отменено')->assertSee('Время оплаты истекло');
        $this->assertSame(3, $response->viewData('entries')->total());
        $this->assertSame('draft', $expired->fresh()->status);
        $this->assertDatabaseCount('wallets', 0);
        $this->assertDatabaseCount('module_donation_payment_preparations', 0);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_signed_payment_under_review_is_not_hidden_with_inactive_orders(): void
    {
        $order = $this->order();
        $preparation = app(DonationPaymentPreparations::class)->prepare($this->player, $order->order_uuid, 'yoomoney');
        app(DonationOrders::class)->cancelDraft($this->player, $order->order_uuid);
        DB::table('module_donation_payment_receipts')->insert([
            'order_id' => $order->id, 'preparation_id' => $preparation->id, 'provider_code' => 'yoomoney',
            'provider_payment_id' => 'history-review-fixture', 'currency_code' => 'RUB',
            'payer_amount_minor' => 12500, 'received_amount_minor' => 12250,
            'notification_sha256' => str_repeat('a', 64), 'encrypted_notification' => 'fixture-only',
            'status' => 'review', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->player)->get('/account/wallet')->assertOk()->assertSee($order->order_uuid)
            ->assertSee('Проверяем оплату')->assertDontSee('data-wallet-pay', false)->assertDontSee('data-wallet-cancel-form', false);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_inline_actions_reuse_guarded_endpoints_and_cancel_returns_to_history(): void
    {
        $order = $this->order();
        $this->actingAs($this->player)->get('/account/wallet')->assertOk()
            ->assertSee('data-wallet-pay-form', false)->assertSee('data-wallet-cancel-form', false);
        $this->post('/modules/donations/orders/'.$order->order_uuid.'/prepare', ['provider' => 'yoomoney'])
            ->assertRedirect(route('modules.donations.orders.checkout', ['orderUuid' => $order->order_uuid]));
        $this->get('/account/wallet')->assertOk()->assertSee('data-wallet-pay ', false);
        $this->post('/modules/donations/orders/'.$order->order_uuid.'/cancel', ['return_to' => 'history'])
            ->assertRedirect('/account/wallet');
        $this->get('/account/wallet')->assertOk()->assertDontSee($order->order_uuid);
        $this->get('/account/wallet?inactive=1')->assertOk()->assertSee($order->order_uuid);
        $this->assertDatabaseCount('wallet_entries', 0);
        Http::assertNothingSent();
    }

    public function test_checkout_guards_hide_payment_actions_and_foreign_ids_do_not_change_history(): void
    {
        $order = $this->order();
        config(['donation-payments.checkout_enabled' => false]);
        $this->actingAs($this->player)->get('/account/wallet')->assertOk()
            ->assertDontSee('data-wallet-pay', false)->assertSee('data-wallet-cancel-form', false);
        $other = User::factory()->create();
        $this->actingAs($other)->get('/account/wallet?user_id='.$this->player->id)->assertOk()->assertDontSee($order->order_uuid);
        $this->post('/modules/donations/orders/'.$order->order_uuid.'/cancel', ['return_to' => 'history'])->assertNotFound();
        $this->assertSame('draft', $order->fresh()->status);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_mixed_history_paginates_in_sql_without_duplicates_or_missing_rows(): void
    {
        foreach (range(1, 24) as $amount) {
            app(WalletTransactionService::class)->credit($this->player, $amount, 'manual', 'page-'.$amount);
        }
        $this->order(20);
        $this->order(30);
        $first = $this->actingAs($this->player)->get('/account/wallet')->assertOk()->viewData('entries');
        $second = $this->get('/account/wallet?page=2')->assertOk()->viewData('entries');
        $this->assertSame(26, $first->total());
        $this->assertCount(20, $first->items());
        $this->assertCount(6, $second->items());
        $keys = array_merge(array_column($first->items(), 'key'), array_column($second->items(), 'key'));
        $this->assertCount(26, array_unique($keys));
        $this->assertStringStartsWith('topup-', $keys[0]);
        $this->assertStringStartsWith('topup-', $keys[1]);
    }
}
