<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use KaevCMS\Modules\Donations\Services\DonationProviderRegistry;
use KaevCMS\Modules\Donations\Services\PayPalAdminSettings;
use Tests\Concerns\RefreshDonationIntegrationDatabase;
use Tests\TestCase;

/** Admin-only Sandbox flow with mock HTTP; never a Wallet or DonationOrder. */
final class DonationPayPalAdminSandboxTest extends TestCase
{
    use RefreshDonationIntegrationDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('donations');
        app(ModuleRuntime::class)->bootModule($module);
        URL::forceScheme('https');
        Http::preventStrayRequests();
    }

    private function configure(Admin $admin): void
    {
        app(PayPalAdminSettings::class)->save($admin, ['USD', 'JPY'], [
            'sandbox_client_id' => 'sandboxClientTest', 'sandbox_client_secret' => 'sandboxPrivateSecret',
            'sandbox_webhook_id' => 'sandboxWebhookId',
            'live_client_id' => 'liveClientTest', 'live_client_secret' => 'livePrivateSecret',
        ]);
    }

    public function test_admin_settings_are_encrypted_redacted_and_not_player_accessible(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $this->configure($owner);
        $row = DB::table('module_donation_provider_settings')->where('provider_code', 'paypal')->first();
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row->enabled);
        $this->assertSame('test', $row->mode);
        $this->assertStringNotContainsString('sandboxPrivateSecret', $row->credentials_ciphertext);
        $this->assertSame('sandboxWebhookId', json_decode(Crypt::decryptString($row->credentials_ciphertext), true)['sandbox_webhook_id']);
        $page = $this->actingAs($owner, 'admin')->get('/admin/extensions/donations/providers/paypal');
        $page->assertOk()->assertSee('data-testid="donations-paypal-settings"', false)->assertDontSee('sandboxPrivateSecret')
            ->assertDontSee('sandboxWebhookId')->assertDontSee('livePrivateSecret');
        $this->assertSame(['freekassa', 'paypal', 'yoomoney'], array_keys(app(DonationProviderRegistry::class)->all()));
        $this->assertSame(['USD', 'JPY'], app(PayPalAdminSettings::class)->state()['currency_codes']);
        $this->assertNotContains('HUF', app(PayPalAdminSettings::class)->supportedCurrencies());
        $this->assertNotContains('TWD', app(PayPalAdminSettings::class)->supportedCurrencies());
    }

    public function test_secret_blank_preserves_old_value_and_invalid_currency_cannot_be_saved(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $this->configure($owner);
        $this->actingAs($owner, 'admin')->put('/admin/extensions/donations/providers/paypal', [
            'currency_codes' => ['USD'], 'credentials' => ['sandbox_client_secret' => ''],
        ])->assertRedirect();
        $this->assertSame('sandboxPrivateSecret', app(PayPalAdminSettings::class)->credentials('sandbox')->clientSecret);
        $this->put('/admin/extensions/donations/providers/paypal', [
            'currency_codes' => ['HUF'], 'credentials' => ['sandbox_client_secret' => 'changed'],
        ])->assertSessionHasErrors('paypal');
        $this->assertSame('sandboxPrivateSecret', app(PayPalAdminSettings::class)->credentials('sandbox')->clientSecret);
    }

    public function test_auditor_can_view_but_cannot_write_test_or_check_api(): void
    {
        $auditor = Admin::factory()->create(['role' => AdminRole::Auditor]);
        $editor = Admin::factory()->create(['role' => AdminRole::Editor]);
        $this->actingAs($auditor, 'admin')->get('/admin/extensions/donations/providers/paypal')->assertOk();
        $this->put('/admin/extensions/donations/providers/paypal', ['currency_codes' => ['USD']])->assertForbidden();
        $this->post('/admin/extensions/donations/providers/paypal/check', ['environment' => 'sandbox'])->assertForbidden();
        $this->post('/admin/extensions/donations/providers/paypal/test-purchase', ['currency_code' => 'USD', 'amount' => '1.00'])->assertForbidden();
        $this->actingAs($editor, 'admin')->get('/admin/extensions/donations/providers/paypal')->assertForbidden();
    }

    public function test_admin_can_check_sandbox_api_token_without_leaking_it(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $this->configure($owner);
        Http::fake(['https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'privateSandboxBearer', 'expires_in' => 3600,
        ])]);
        $result = $this->actingAs($owner, 'admin')->post('/admin/extensions/donations/providers/paypal/check', ['environment' => 'sandbox']);
        $result->assertRedirect()->assertSessionHas('status');
        $this->assertStringNotContainsString('privateSandboxBearer', (string) $result->headers->get('Location'));
        Http::assertSentCount(1);
    }

    public function test_sandbox_purchase_requires_remote_approval_and_capture_and_never_credits_wallet(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $this->configure($owner);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/v1/oauth2/token')) {
                return Http::response(['access_token' => 'sandboxBearer123', 'expires_in' => 3600]);
            }
            if (str_ends_with($request->url(), '/v2/checkout/orders')) {
                return Http::response(['id' => 'SBXORDER1', 'status' => 'PAYER_ACTION_REQUIRED',
                    'links' => [['rel' => 'payer-action', 'method' => 'GET',
                        'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=SBXORDER1']]], 201);
            }
            $attempt = DB::table('module_donation_paypal_attempts')->first();
            $unit = ['reference_id' => $attempt->invoice_id, 'invoice_id' => $attempt->invoice_id,
                'amount' => ['currency_code' => 'USD', 'value' => '1.00']];
            if (str_ends_with($request->url(), '/v2/checkout/orders/SBXORDER1')) {
                return Http::response(['id' => 'SBXORDER1', 'status' => 'APPROVED', 'purchase_units' => [$unit]]);
            }
            if (str_ends_with($request->url(), '/v2/checkout/orders/SBXORDER1/capture')) {
                return Http::response(['id' => 'SBXORDER1', 'status' => 'COMPLETED', 'purchase_units' => [[
                    ...$unit, 'payments' => ['captures' => [['id' => 'SBXCAPTURE1', 'status' => 'COMPLETED',
                        'amount' => ['currency_code' => 'USD', 'value' => '1.00']]]],
                ]]], 201);
            }

            return Http::response(['error' => 'unexpected'], 500);
        });
        $this->actingAs($owner, 'admin')->post('/admin/extensions/donations/providers/paypal/test-purchase', [
            'currency_code' => 'USD', 'amount' => '1.00',
        ])->assertStatus(303)->assertRedirect('https://www.sandbox.paypal.com/checkoutnow?token=SBXORDER1');
        $attempt = DB::table('module_donation_paypal_attempts')->first();
        $this->assertSame('admin_test', $attempt->purpose);
        $this->assertNull($attempt->order_id);
        $this->assertNull($attempt->preparation_id);
        $this->assertSame('sandbox', $attempt->environment);
        $this->actingAs($owner, 'admin')->get('/admin/extensions/donations/providers/paypal/test/'.$attempt->id.'/return?token=WRONG')
            ->assertOk()->assertSee('UNKNOWN')->assertDontSee(__('module-donations::messages.paypal_test_capture'));
        $this->get('/admin/extensions/donations/providers/paypal/test/'.$attempt->id.'/return?token=SBXORDER1')
            ->assertOk()->assertSee('APPROVED')->assertSee(__('module-donations::messages.paypal_test_capture'));
        $this->post('/admin/extensions/donations/providers/paypal/test/'.$attempt->id.'/capture')->assertRedirect();
        $this->assertSame('sandbox_verified', DB::table('module_donation_paypal_attempts')->value('status'));
        $this->assertSame(0, DB::table('module_donation_orders')->count());
        $this->assertSame(0, DB::table('module_donation_payment_receipts')->count());
        $this->assertSame(0, DB::table('module_donation_payment_settlements')->count());
        $capturePosts = Http::recorded()->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/SBXORDER1/capture'));
        $this->assertCount(1, $capturePosts);
        $this->post('/admin/extensions/donations/providers/paypal/test/'.$attempt->id.'/capture')->assertRedirect();
        $capturePosts = Http::recorded()->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/SBXORDER1/capture'));
        $this->assertCount(1, $capturePosts);
    }

    public function test_webhook_never_accepts_unverified_events(): void
    {
        config(['donation-payments.callbacks_enabled' => true]);
        $this->post('/payments/paypal/notify', ['event_type' => 'PAYMENT.CAPTURE.COMPLETED'])->assertStatus(415);
        $this->call('POST', '/payments/paypal/notify', [], [], [], ['CONTENT_TYPE' => 'application/json'],
            str_repeat('a', 65537))->assertStatus(413);
    }
}
