<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Contracts\WalletGateway;
use App\Models\Admin;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use KaevCMS\Modules\Donations\Models\DonationOrder;
use KaevCMS\Modules\Donations\Services\DonationOrders;
use KaevCMS\Modules\Donations\Services\DonationPaymentPreparations;
use KaevCMS\Modules\Donations\Services\DonationProviderSettings;
use KaevCMS\Modules\Donations\Services\DonationRates;
use KaevCMS\Modules\Donations\Services\DonationSettings;
use KaevCMS\Modules\Donations\Services\DonationYooMoneyReconciliation;
use RuntimeException;
use Tests\TestCase;

final class DonationYooMoneySettlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('donations');
        app(ModuleRuntime::class)->bootModule($module);
    }

    /** @return array{0:User,1:DonationOrder} */
    private function prepared(string $mode = 'live'): array
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        app(DonationSettings::class)->updateVisibility(true, $owner);
        app(DonationRates::class)->saveRate('RUB', '10', true, $owner);
        app(DonationProviderSettings::class)->save($owner, 'yoomoney', true, $mode, ['RUB'], [
            'receiver' => '410012345678901', 'notification_secret' => 'private-secret',
            'history_token' => str_repeat('s', 36),
        ]);
        $player = User::factory()->create();
        $order = app(DonationOrders::class)->createDraft($player, 'RUB', 12, (string) Str::uuid());
        app(DonationPaymentPreparations::class)->prepare($player, $order->order_uuid, 'yoomoney');

        return [$player, $order];
    }

    /** RFC3986 signed notification, gross payer 120 RUB, net receiver 117.60 RUB. */
    private function signed(DonationOrder $order, string $operation = '44012345', array $overrides = []): string
    {
        $fields = array_merge([
            'notification_type' => 'p2p-incoming', 'operation_id' => $operation,
            'amount' => '117.60', 'withdraw_amount' => '120.00',
            'currency' => '643', 'datetime' => '2026-10-17T13:00:00Z',
            'sender' => '410012345000000', 'codepro' => 'false',
            'label' => 'kaevcms-'.$order->order_uuid, 'unaccepted' => 'false',
            'test_notification' => 'false',
        ], $overrides);
        ksort($fields, SORT_STRING);
        $signature = hash_hmac('sha256', http_build_query($fields, '', '&', PHP_QUERY_RFC3986), 'private-secret');
        $fields['sign'] = $signature;

        return http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
    }

    private function notify(string $body)
    {
        return $this->call('POST', '/payments/yoomoney/notify', [], [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], $body);
    }

    private function openCredits(): void
    {
        config()->set('donation-payments.callbacks_enabled', true);
        config()->set('donation-payments.credits_enabled', true);
        config()->set('wallet.enabled_sources.donations', true);
    }

    public function test_release_defaults_reject_callbacks_and_never_change_wallet(): void
    {
        [$player, $order] = $this->prepared();
        $this->notify($this->signed($order))->assertStatus(503);
        $this->assertDatabaseCount('module_donation_payment_receipts', 0);
        $this->assertDatabaseCount('wallet_entries', 0);
        $this->assertSame('draft', $order->fresh()->status);
    }

    public function test_authenticated_notifications_are_durable_but_cannot_credit_without_both_gates(): void
    {
        [, $order] = $this->prepared();
        config()->set('donation-payments.callbacks_enabled', true);
        $body = $this->signed($order);
        $this->notify($body)->assertOk();
        $this->notify($body)->assertOk();
        $this->assertDatabaseCount('module_donation_payment_receipts', 1);
        $this->assertDatabaseHas('module_donation_payment_receipts', [
            'status' => 'pending', 'payer_amount_minor' => 12000, 'received_amount_minor' => 11760,
        ]);
        $this->assertDatabaseCount('wallet_entries', 0);
        $this->assertSame('draft', $order->fresh()->status);
        $raw = DB::table('module_donation_payment_receipts')->value('encrypted_notification');
        $this->assertStringNotContainsString('private-secret', (string) $raw);
        $this->assertStringNotContainsString('44012345', (string) $raw);
    }

    public function test_signed_gross_amount_mismatch_and_test_notifications_are_never_credited(): void
    {
        [, $order] = $this->prepared();
        $this->openCredits();
        $this->notify($this->signed($order, '44012346', ['withdraw_amount' => '119.99']))->assertOk();
        $this->notify($this->signed($order, '44012347', ['test_notification' => 'true']))->assertOk();
        $this->assertDatabaseHas('module_donation_payment_receipts', [
            'provider_payment_id' => '44012346', 'status' => 'review', 'review_reason' => 'amount_or_currency_mismatch',
        ]);
        $this->assertDatabaseHas('module_donation_payment_receipts', [
            'provider_payment_id' => '44012347', 'status' => 'test', 'review_reason' => 'test_notification',
        ]);
        $this->assertSame('draft', $order->fresh()->status);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_test_preparation_cannot_credit_even_when_all_gates_are_enabled(): void
    {
        [, $order] = $this->prepared('test');
        $this->openCredits();
        $this->notify($this->signed($order))->assertOk();
        $this->assertDatabaseHas('module_donation_payment_receipts', ['status' => 'test']);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_exactly_once_same_signature_and_two_distinct_valid_operations(): void
    {
        [, $order] = $this->prepared();
        $this->openCredits();
        $body = $this->signed($order);
        $this->notify($body)->assertOk();
        $this->notify($body)->assertOk();
        $this->notify($this->signed($order, '44099999'))->assertOk();
        $this->assertDatabaseCount('module_donation_payment_settlements', 1);
        $this->assertDatabaseCount('wallet_entries', 1);
        $this->assertDatabaseHas('wallet_entries', ['source' => 'donations', 'source_key' => 'donation:'.$order->order_uuid, 'amount' => 12]);
        $this->assertDatabaseHas('module_donation_payment_receipts', [
            'provider_payment_id' => '44099999', 'status' => 'review', 'review_reason' => 'order_not_payable',
        ]);
        $this->assertSame('credited', $order->fresh()->status);
    }

    public function test_fake_signature_duplicate_keys_query_string_and_browser_checkout_are_rejected(): void
    {
        [, $order] = $this->prepared();
        $this->openCredits();
        $body = $this->signed($order);
        $this->notify(str_replace('120.00', '1.00', $body))->assertStatus(400);
        $this->notify($body.'&amount=120.00')->assertStatus(400);
        $this->post('/payments/yoomoney/notify?amount=120.00', ['sign' => 'invalid'])->assertStatus(400);
        $this->get('/payments/yoomoney/notify')->assertStatus(405);
        $this->assertDatabaseCount('module_donation_payment_receipts', 0);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_verified_pending_transfer_prevents_cancellation_after_webhook(): void
    {
        [$player, $order] = $this->prepared();
        config()->set('donation-payments.callbacks_enabled', true);
        $this->notify($this->signed($order))->assertOk();
        $this->expectException(\LogicException::class);
        app(DonationOrders::class)->cancelDraft($player, $order->order_uuid);
    }

    public function test_cancelled_order_stays_uncredited_even_with_signed_transfer(): void
    {
        [$player, $order] = $this->prepared();
        app(DonationOrders::class)->cancelDraft($player, $order->order_uuid);
        $this->openCredits();
        $this->notify($this->signed($order))->assertOk();
        $this->assertDatabaseHas('module_donation_payment_receipts', [
            'status' => 'review', 'review_reason' => 'order_not_payable',
        ]);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_db_and_wallet_failures_must_not_acknowledge_a_payment(): void
    {
        [, $order] = $this->prepared();
        $this->openCredits();
        $gateway = $this->mock(WalletGateway::class);
        $gateway->shouldReceive('creditFromModule')->once()->andThrow(new RuntimeException('wallet refused'));
        $this->notify($this->signed($order))->assertStatus(503);
        $this->assertDatabaseCount('module_donation_payment_receipts', 0);
        $this->assertDatabaseCount('module_donation_payment_settlements', 0);
        $this->assertSame('draft', $order->fresh()->status);
    }

    public function test_reused_provider_id_with_changed_signed_amount_is_not_reassigned(): void
    {
        [, $order] = $this->prepared();
        $this->openCredits();
        $this->notify($this->signed($order, '99888', ['withdraw_amount' => '119.99']))->assertOk();
        $this->notify($this->signed($order, '99888'))->assertStatus(400);
        $this->assertDatabaseCount('module_donation_payment_receipts', 1);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_audit_failure_rolls_back_signed_receipt_without_acknowledging(): void
    {
        [, $order] = $this->prepared();
        $this->openCredits();
        $audit = $this->mock(AuditLogger::class);
        $audit->shouldReceive('system')->once()->andReturn(null);
        $this->notify($this->signed($order))->assertStatus(503);
        $this->assertDatabaseCount('module_donation_payment_receipts', 0);
        $this->assertDatabaseCount('wallet_entries', 0);
        $this->assertSame('draft', $order->fresh()->status);
    }

    public function test_verified_pending_receipt_can_be_reverified_and_settled_later(): void
    {
        [, $order] = $this->prepared();
        config()->set('donation-payments.callbacks_enabled', true);
        $this->notify($this->signed($order))->assertOk();
        $this->openCredits();
        $this->assertSame(1, app(\KaevCMS\Modules\Donations\Services\DonationPaymentSettlements::class)->retryPending());
        $this->assertSame(0, app(\KaevCMS\Modules\Donations\Services\DonationPaymentSettlements::class)->retryPending());
        $this->assertDatabaseCount('wallet_entries', 1);
        $this->assertSame('credited', $order->fresh()->status);
    }

    public function test_read_only_recovery_only_records_review_candidates_and_never_changes_wallet(): void
    {
        [, $order] = $this->prepared();
        Http::fake(['https://yoomoney.ru/api/operation-history' => Http::response([
            'operations' => [[
                'operation_id' => '900111', 'status' => 'success', 'direction' => 'in',
                'label' => 'kaevcms-'.$order->order_uuid,
            ]],
        ], 200)]);
        $probe = app(DonationYooMoneyReconciliation::class);
        $this->assertSame(['scanned' => 1, 'candidates' => 1, 'errors' => 0], $probe->scan());
        $this->assertSame(['scanned' => 1, 'candidates' => 0, 'errors' => 0], $probe->scan());
        $this->assertDatabaseCount('module_donation_recovery_candidates', 1);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_core_callback_remains_registered_when_module_is_disabled(): void
    {
        [, $order] = $this->prepared();
        config()->set('donation-payments.callbacks_enabled', true);
        app(ModuleManager::class)->disable('donations');
        $this->notify($this->signed($order))->assertOk();
        $this->assertDatabaseCount('module_donation_payment_receipts', 1);
        $this->assertDatabaseCount('wallet_entries', 0);
    }
}
