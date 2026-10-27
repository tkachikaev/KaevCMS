<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use KaevCMS\Modules\Donations\Services\DonationPaymentPreparations;
use KaevCMS\Modules\Donations\Services\DonationProviderSettings;
use Tests\TestCase;

final class DonationFreeKassaAdminTestPurchaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('donations');
        app(ModuleRuntime::class)->bootModule($module);
    }

    private function configuredOwner(): Admin
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        app(DonationProviderSettings::class)->save(
            $owner, 'freekassa', true, 'test', ['RUB'],
            ['merchant_id' => '12345', 'secret_1' => 'test-checkout-secret', 'secret_2' => 'test-callback-secret'],
            ['198.51.100.19'], [],
        );

        return $owner;
    }

    public function test_admin_can_start_external_test_and_verified_callback_never_credits_wallet(): void
    {
        $owner = $this->configuredOwner();
        $path = '/admin/extensions/donations/providers/freekassa/test-purchase';
        $response = $this->actingAs($owner, 'admin')->post($path, [
            'currency_code' => 'RUB', 'amount' => '1.00',
        ]);
        $response->assertStatus(303);
        $response->assertRedirect('/admin/extensions/donations/providers/freekassa');
        $checkoutUrl = session('freekassa_test_checkout_url');
        $this->assertIsString($checkoutUrl);
        $this->assertStringStartsWith('https://pay.fk.money/?', $checkoutUrl);
        $page = $this->get('/admin/extensions/donations/providers/freekassa')->assertOk();
        $page->assertSee('data-testid="freekassa-test-continue"', false);
        $this->get('/admin/extensions/donations/providers/freekassa')->assertOk()
            ->assertDontSee('data-testid="freekassa-test-continue"', false);
        $this->assertDatabaseCount('module_donation_freekassa_admin_tests', 1);
        $row = DB::table('module_donation_freekassa_admin_tests')->first();
        $this->assertNotNull($row);
        $this->assertSame('pending', $row->status);
        $this->assertSame(100, (int) $row->amount_minor);
        $this->assertSame(0, DB::table('module_donation_orders')->count());
        $this->assertSame(0, DB::table('module_donation_payment_receipts')->count());
        $this->assertSame([], array_filter(app(DonationPaymentPreparations::class)->available('RUB'),
            static fn (array $provider): bool => $provider['code'] === 'freekassa'));

        $payload = [
            'MERCHANT_ID' => '12345', 'AMOUNT' => '1.00', 'intid' => '9876543',
            'MERCHANT_ORDER_ID' => $row->reference, 'CUR_ID' => '4',
            'SIGN' => md5('12345:1.00:test-callback-secret:'.$row->reference),
        ];
        $body = http_build_query($payload);
        $sender = ['REMOTE_ADDR' => '198.51.100.19', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTPS' => 'on'];
        $this->call('POST', '/payments/freekassa/notify', [], [], [], $sender, $body)->assertOk()->assertSeeText('YES');
        $this->call('POST', '/payments/freekassa/notify', [], [], [], $sender, $body)->assertOk()->assertSeeText('YES');
        $this->assertSame('verified', DB::table('module_donation_freekassa_admin_tests')->value('status'));
        $this->assertSame(0, DB::table('module_donation_orders')->count());
        $this->assertSame(0, DB::table('module_donation_payment_receipts')->count());
        $this->assertSame(0, DB::table('module_donation_payment_settlements')->count());
    }

    public function test_mode_change_or_provider_disable_does_not_reclassify_existing_test_as_live(): void
    {
        $owner = $this->configuredOwner();
        $this->actingAs($owner, 'admin')->post('/admin/extensions/donations/providers/freekassa/test-purchase',
            ['currency_code' => 'RUB', 'amount' => '1.00'])->assertStatus(303);
        app(DonationProviderSettings::class)->save($owner, 'freekassa', false, 'test', ['RUB']);
        $row = DB::table('module_donation_freekassa_admin_tests')->first();
        $payload = [
            'MERCHANT_ID' => '12345', 'AMOUNT' => '1.0', 'intid' => '9876545',
            'MERCHANT_ORDER_ID' => $row->reference, 'CUR_ID' => '4',
            'SIGN' => md5('12345:1.0:test-callback-secret:'.$row->reference),
        ];
        $this->call('POST', '/payments/freekassa/notify', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.19', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTPS' => 'on',
        ], http_build_query($payload))->assertOk()->assertSeeText('YES');
        $this->assertSame('verified', DB::table('module_donation_freekassa_admin_tests')->value('status'));
        $this->assertSame(0, DB::table('module_donation_payment_settlements')->count());
    }

    public function test_invalid_signature_does_not_mark_test_verified(): void
    {
        $owner = $this->configuredOwner();
        $this->actingAs($owner, 'admin')->post('/admin/extensions/donations/providers/freekassa/test-purchase',
            ['currency_code' => 'RUB', 'amount' => '1.00'])->assertStatus(303);
        $uuid = DB::table('module_donation_freekassa_admin_tests')->value('reference');
        $this->call('POST', '/payments/freekassa/notify', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.19', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTPS' => 'on',
        ], http_build_query(['MERCHANT_ID' => '12345', 'AMOUNT' => '1.00', 'intid' => '6789',
            'MERCHANT_ORDER_ID' => $uuid, 'CUR_ID' => '4', 'SIGN' => str_repeat('0', 32)]))->assertStatus(400);
        $this->assertSame('pending', DB::table('module_donation_freekassa_admin_tests')->value('status'));
    }

    public function test_admin_test_checkout_policy_allows_only_the_trusted_provider_destination(): void
    {
        $owner = $this->configuredOwner();
        $response = $this->actingAs($owner, 'admin')->get('/admin/extensions/donations/providers/freekassa');
        $response->assertOk();
        $this->assertStringContainsString("form-action 'self' https://pay.fk.money;",
            $response->headers->get('Content-Security-Policy'));

        $other = $this->get('/admin/extensions/donations/providers/yoomoney');
        $other->assertOk();
        $this->assertStringContainsString("form-action 'self';", $other->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('https://pay.fk.money', $other->headers->get('Content-Security-Policy'));
    }

    public function test_php_parsed_multipart_callback_preserves_empty_fields_and_never_credits_wallet(): void
    {
        $owner = $this->configuredOwner();
        $this->actingAs($owner, 'admin')->post('/admin/extensions/donations/providers/freekassa/test-purchase',
            ['currency_code' => 'RUB', 'amount' => '1.00'])->assertStatus(303);
        $reference = DB::table('module_donation_freekassa_admin_tests')->value('reference');
        $fields = ['MERCHANT_ID' => '12345', 'AMOUNT' => '1.00', 'intid' => '9876543',
            'MERCHANT_ORDER_ID' => $reference, 'CUR_ID' => '4', 'P_EMAIL' => '',
            'SIGN' => md5('12345:1.00:test-callback-secret:'.$reference)];
        $server = ['REMOTE_ADDR' => '198.51.100.19', 'HTTPS' => 'on',
            'CONTENT_TYPE' => 'multipart/form-data; boundary=KaevMultipart', 'CONTENT_LENGTH' => '1024'];
        $this->call('POST', '/payments/freekassa/notify', $fields, [], [], $server, '')
            ->assertOk()->assertContent('YES');
        $this->assertSame('verified', DB::table('module_donation_freekassa_admin_tests')->value('status'));
        $this->assertDatabaseCount('wallet_entries', 0);
        $this->assertDatabaseCount('module_donation_payment_settlements', 0);
    }

    public function test_auditor_and_guests_cannot_start_test_or_receive_secrets(): void
    {
        $this->post('/admin/extensions/donations/providers/freekassa/test-purchase', [
            'currency_code' => 'RUB', 'amount' => '1.00',
        ])->assertRedirect();
        $auditor = Admin::factory()->create(['role' => AdminRole::Auditor]);
        $this->actingAs($auditor, 'admin')->post('/admin/extensions/donations/providers/freekassa/test-purchase', [
            'currency_code' => 'RUB', 'amount' => '1.00',
        ])->assertForbidden();
    }
}
