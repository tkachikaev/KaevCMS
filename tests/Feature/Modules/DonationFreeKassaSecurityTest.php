<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Services\DonationProviderSettings;
use KaevCMS\Modules\Donations\Services\FreeKassaCallbackSecurity;
use Tests\TestCase;

final class DonationFreeKassaSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('donations');
        app(ModuleRuntime::class)->bootModule($module);
    }

    public function test_admin_can_replace_free_kassa_sender_addresses_and_secrets_remain_encrypted(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $this->actingAs($owner, 'admin')->get('/admin/extensions/donations/providers/freekassa')
            ->assertOk()->assertSee('168.119.157.136')->assertDontSee('reset-ip');
        $this->actingAs($owner, 'admin')->put('/admin/extensions/donations/providers/freekassa', [
            'enabled' => '0', 'mode' => 'test', 'currency_codes' => [],
            'credentials' => ['merchant_id' => '12345', 'secret_1' => 'checkout-super-secret', 'secret_2' => 'callback-super-secret'],
            'callback_sender_ips' => "198.51.100.19\n198.51.100.20",
            'callback_proxy_ips' => "127.0.0.1\n203.0.113.0/24",
        ])->assertRedirect();
        $policy = app(FreeKassaCallbackSecurity::class)->current();
        $this->assertTrue($policy['customized']);
        $this->assertSame(['198.51.100.19', '198.51.100.20'], $policy['senders']);
        $this->assertSame(['127.0.0.1', '203.0.113.0/24'], $policy['proxies']);
        $this->assertSame('12345', app(DonationProviderSettings::class)->credentialsForDriver('freekassa')['merchant_id']);
        $ciphertext = (string) DB::table('module_donation_provider_settings')
            ->where('provider_code', 'freekassa')->value('credentials_ciphertext');
        $this->assertStringNotContainsString('callback-super-secret', $ciphertext);
        $this->assertStringNotContainsString('198.51.100.19', $ciphertext);
        $this->actingAs($owner, 'admin')->get('/admin/extensions/donations/providers/freekassa')
            ->assertOk()->assertSee('198.51.100.19')->assertDontSee('callback-super-secret');
    }

    public function test_deleted_addresses_stay_deleted_even_after_reopening_and_other_provider_settings(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $service = app(DonationProviderSettings::class);
        $service->save($owner, 'freekassa', false, 'test', [], [], [], []);
        $this->assertSame([], app(FreeKassaCallbackSecurity::class)->current()['senders']);
        $service->save($owner, 'freekassa', false, 'test', [], ['merchant_id' => '12345']);
        $this->assertSame([], app(FreeKassaCallbackSecurity::class)->current()['senders']);
        $this->assertSame([], app(FreeKassaCallbackSecurity::class)->current()['proxies']);
    }

    public function test_invalid_sender_addresses_and_proxy_wildcards_are_rejected_without_changing_existing_policy(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $service = app(DonationProviderSettings::class);
        $service->save($owner, 'freekassa', false, 'test', [], [], ['198.51.100.19'], []);
        $this->actingAs($owner, 'admin')->put('/admin/extensions/donations/providers/freekassa', [
            'enabled' => '0', 'mode' => 'test', 'currency_codes' => [], 'credentials' => [],
            'callback_sender_ips' => '0.0.0.0/0', 'callback_proxy_ips' => '*',
        ])->assertSessionHasErrors('provider');
        $this->assertSame(['198.51.100.19'], app(FreeKassaCallbackSecurity::class)->current()['senders']);
        $this->assertSame([], session('_old_input', []));
    }

    public function test_auditor_can_read_but_cannot_change_callback_allowlist(): void
    {
        $auditor = Admin::factory()->create(['role' => AdminRole::Auditor]);
        $this->actingAs($auditor, 'admin')->get('/admin/extensions/donations/providers/freekassa')->assertOk();
        $this->actingAs($auditor, 'admin')->put('/admin/extensions/donations/providers/freekassa', [
            'enabled' => '0', 'mode' => 'test', 'callback_sender_ips' => '198.51.100.19',
            'callback_proxy_ips' => '', 'credentials' => [],
        ])->assertForbidden();
    }

    public function test_raw_spoofed_sender_rejected_before_payment_processing(): void
    {
        // Even with the same fake callback fields, the trust policy must reject
        // an unlisted TCP peer before verification, transaction or Wallet credit.
        $this->call('POST', '/payments/freekassa/notify', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.90',
            'HTTP_X_FORWARDED_FOR' => '168.119.157.136',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTPS' => 'on',
        ], 'MERCHANT_ID=123&AMOUNT=100')->assertStatus(403);
    }

    public function test_trusted_sender_proceeds_to_regular_signature_validation(): void
    {
        // IP match is not enough to settle: the notification remains invalid.
        $this->call('POST', '/payments/freekassa/notify', [], [], [], [
            'REMOTE_ADDR' => '168.119.157.136',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTPS' => 'on',
        ], 'MERCHANT_ID=123&AMOUNT=100')->assertStatus(400);
    }
}
