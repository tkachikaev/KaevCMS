<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\User;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Support\Str;
use KaevCMS\Modules\Donations\Models\DonationOrder;
use KaevCMS\Modules\Donations\Services\DonationOrders;
use KaevCMS\Modules\Donations\Services\DonationPaymentPreparations;
use KaevCMS\Modules\Donations\Services\DonationProviderSettings;
use KaevCMS\Modules\Donations\Services\DonationRates;
use KaevCMS\Modules\Donations\Services\DonationSettings;
use Tests\Concerns\RefreshDonationDatabase;
use Tests\TestCase;

/** The "Test" button on the YooMoney settings page and the notification address while it waits. */
final class DonationYooMoneyNotificationTestButtonTest extends TestCase
{
    use RefreshDonationDatabase;

    private Admin $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('donations');
        app(ModuleRuntime::class)->bootModule($module);
        config()->set('donation-payments.callbacks_enabled', true);
        $this->owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        app(DonationProviderSettings::class)->save($this->owner, 'yoomoney', true, 'live', ['RUB'], [
            'receiver' => '410012345678901', 'notification_secret' => 'private-secret',
        ]);
    }

    /** What YooMoney sends from "Test" in its HTTP notification settings: no order label. */
    private function yooMoneyTestBody(string $secret = 'private-secret', bool $sha1 = false): string
    {
        $fields = [
            'notification_type' => 'p2p-incoming', 'operation_id' => 'test-notification',
            'amount' => '196.53', 'withdraw_amount' => '197.52', 'currency' => '643',
            'datetime' => '2026-10-23T12:00:00Z', 'sender' => '41001000040', 'codepro' => 'false',
            'label' => '', 'unaccepted' => 'false', 'test_notification' => 'true',
        ];
        if ($sha1) {
            $fields['sha1_hash'] = sha1(implode('&', [
                $fields['notification_type'], $fields['operation_id'], $fields['amount'], $fields['currency'],
                $fields['datetime'], $fields['sender'], $fields['codepro'], $secret, $fields['label'],
            ]));

            return http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        }
        ksort($fields, SORT_STRING);
        $fields['sign'] = hash_hmac('sha256', http_build_query($fields, '', '&', PHP_QUERY_RFC3986), $secret);

        return http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
    }

    private function notify(string $body)
    {
        return $this->call('POST', '/payments/yoomoney/notify', [], [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], $body);
    }

    private function start(array $payload = [], ?Admin $admin = null)
    {
        return $this->actingAs($admin ?? $this->owner, 'admin')
            ->postJson('/admin/extensions/donations/providers/yoomoney/notification-test', $payload);
    }

    private function dialogStatus(string $token): string
    {
        return (string) $this->actingAs($this->owner, 'admin')
            ->getJson('/admin/extensions/donations/providers/yoomoney/notification-test/'.$token)
            ->assertOk()->json('status');
    }

    public function test_signed_test_notification_is_confirmed_while_the_owner_waits(): void
    {
        $token = (string) $this->start()->assertOk()->json('token');
        $this->assertSame('waiting', $this->dialogStatus($token));

        $this->notify($this->yooMoneyTestBody())->assertOk();

        $this->assertSame('received', $this->dialogStatus($token));
        $this->assertDatabaseCount('module_donation_payment_receipts', 0);
        $this->assertDatabaseCount('wallet_entries', 0);

        // Once answered, the window no longer intercepts: the address behaves as before.
        $this->notify($this->yooMoneyTestBody())->assertStatus(400);
        $this->assertSame('received', $this->dialogStatus($token));
    }

    public function test_documented_sha1_signature_is_accepted_for_the_test(): void
    {
        $token = (string) $this->start()->assertOk()->json('token');
        $this->notify($this->yooMoneyTestBody(sha1: true))->assertOk();
        $this->assertSame('received', $this->dialogStatus($token));
    }

    public function test_a_new_secret_typed_in_the_form_is_the_one_tested(): void
    {
        $token = (string) $this->start(['secret' => 'new-secret-from-form'])->assertOk()->json('token');
        $this->notify($this->yooMoneyTestBody('new-secret-from-form'))->assertOk();
        $this->assertSame('received', $this->dialogStatus($token));
    }

    public function test_wrong_secret_is_reported_and_answered_as_before(): void
    {
        $token = (string) $this->start()->assertOk()->json('token');
        $this->notify($this->yooMoneyTestBody('another-secret'))->assertStatus(400);
        $this->assertSame('signature_mismatch', $this->dialogStatus($token));
    }

    public function test_without_a_waiting_owner_the_address_behaves_as_before(): void
    {
        $this->notify($this->yooMoneyTestBody())->assertStatus(400);
        $this->assertSame('expired', $this->dialogStatus(str_repeat('a', 40)));
    }

    public function test_real_payment_keeps_its_usual_path_while_the_owner_waits(): void
    {
        app(DonationSettings::class)->updateVisibility(true, $this->owner);
        app(DonationRates::class)->saveRate('RUB', '10', true, $this->owner);
        $player = User::factory()->create();
        $order = app(DonationOrders::class)->createDraft($player, 'RUB', 12, (string) Str::uuid());
        app(DonationPaymentPreparations::class)->prepare($player, $order->order_uuid, 'yoomoney');
        $token = (string) $this->start()->assertOk()->json('token');

        $this->notify($this->paymentNotification($order, 'false'))->assertOk();
        // A test notification for one of our orders is still recorded, never credited.
        $this->notify($this->paymentNotification($order, 'true', '44012399'))->assertOk();

        $this->assertSame('waiting', $this->dialogStatus($token));
        $this->assertDatabaseHas('module_donation_payment_receipts', ['provider_payment_id' => '44012345', 'payer_amount_minor' => 12000]);
        $this->assertDatabaseHas('module_donation_payment_receipts', ['provider_payment_id' => '44012399', 'status' => 'test']);
    }

    public function test_only_payment_managers_can_start_and_a_secret_is_required(): void
    {
        $auditor = Admin::factory()->create(['role' => AdminRole::Auditor]);
        $editor = Admin::factory()->create(['role' => AdminRole::Editor]);
        $this->start([], $auditor)->assertForbidden();
        $this->start([], $editor)->assertForbidden();
        $this->start(['secret' => 'short'])->assertStatus(422);

        app(DonationProviderSettings::class)->save($this->owner, 'yoomoney', false, 'live', ['RUB'], [
            'receiver' => '410012345678901', 'notification_secret' => 'private-secret',
        ]);
        $this->start(['secret' => ''])->assertOk();
    }

    public function test_settings_page_offers_the_test_button(): void
    {
        $this->actingAs($this->owner, 'admin')
            ->get('https://pay.example.com/admin/extensions/donations/providers/yoomoney')
            ->assertOk()
            ->assertSee('data-yoomoney-test-open', false)
            ->assertSee('data-yoomoney-test-dialog', false);
    }

    private function paymentNotification(DonationOrder $order, string $test, string $operation = '44012345'): string
    {
        $fields = [
            'notification_type' => 'p2p-incoming', 'operation_id' => $operation,
            'amount' => '117.60', 'withdraw_amount' => '120.00', 'currency' => '643',
            'datetime' => '2026-10-17T13:00:00Z', 'sender' => '410012345000000', 'codepro' => 'false',
            'label' => 'kaevcms-'.$order->order_uuid, 'unaccepted' => 'false', 'test_notification' => $test,
        ];
        ksort($fields, SORT_STRING);
        $fields['sign'] = hash_hmac('sha256', http_build_query($fields, '', '&', PHP_QUERY_RFC3986), 'private-secret');

        return http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
    }
}
