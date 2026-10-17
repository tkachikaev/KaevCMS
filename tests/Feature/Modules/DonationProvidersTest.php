<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Services\DonationProviderRegistry;
use KaevCMS\Modules\Donations\Services\DonationProviderSettings;
use KaevCMS\Modules\Donations\Services\DonationRates;
use RuntimeException;
use Tests\Fixtures\Donations\FakePaymentDriverAlpha;
use Tests\Fixtures\Donations\FakePaymentDriverBeta;
use Tests\TestCase;

final class DonationProvidersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('donations');
        app(ModuleRuntime::class)->bootModule($module);
    }

    private function fakeDrivers(): void
    {
        $this->app->instance(DonationProviderRegistry::class,
            new DonationProviderRegistry([FakePaymentDriverAlpha::class, FakePaymentDriverBeta::class]));
        $this->app->forgetInstance(DonationProviderSettings::class);
    }

    public function test_yoomoney_is_allowlisted_but_no_real_payment_or_webhook_routes_exist(): void
    {
        $this->assertSame(['yoomoney'], array_keys(app(DonationProviderRegistry::class)->all()));
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $this->actingAs($owner, 'admin')->get('/admin/extensions/donations/providers')
            ->assertOk()->assertSee('yoomoney');
        $this->assertDatabaseCount('module_donation_provider_settings', 0);
        $this->post('/modules/donations/payment/webhook')->assertNotFound();
        $this->post('/modules/donations/orders/00000000-0000-0000-0000-000000000000/checkout')->assertNotFound();
    }

    public function test_multiple_independent_provider_configs_encrypted_and_never_echoed(): void
    {
        $this->fakeDrivers();
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        app(DonationRates::class)->saveRate('RUB', '10', true, $owner);
        app(DonationRates::class)->saveRate('USD', '1', true, $owner);
        $service = app(DonationProviderSettings::class);
        $service->save($owner, 'fixture_alpha', true, 'live', ['RUB', 'USD'], ['api_secret' => 'alpha-top-secret']);
        $service->save($owner, 'fixture_beta', true, 'live', ['USD'], ['api_secret' => 'beta-top-secret']);
        $this->assertDatabaseCount('module_donation_provider_settings', 2);
        $raw = DB::table('module_donation_provider_settings')->where('provider_code', 'fixture_alpha')->value('credentials_ciphertext');
        $this->assertNotSame('alpha-top-secret', $raw);
        $this->assertStringNotContainsString('alpha-top-secret', $raw);
        $this->assertSame(['api_secret' => 'alpha-top-secret'], $service->credentialsForDriver('fixture_alpha'));
        $this->assertSame(['api_secret' => 'beta-top-secret'], $service->credentialsForDriver('fixture_beta'));
        $this->assertTrue($service->isAvailableForLivePayment('fixture_alpha', 'RUB'));
        $this->assertSame('local_ok', $service->localDiagnostic('fixture_alpha')['state']);
        $this->assertFalse($service->isAvailableForLivePayment('fixture_beta', 'RUB'));
        $this->assertTrue($service->isAvailableForLivePayment('fixture_beta', 'USD'));
        $this->actingAs($owner, 'admin')->get('/admin/extensions/donations/providers/fixture_alpha')
            ->assertOk()->assertDontSee('alpha-top-secret')->assertDontSee('beta-top-secret');
        $this->assertSame(2, AuditLog::query()->where('action', 'donations.provider_updated')->count());
        $this->assertStringNotContainsString('alpha-top-secret', json_encode(AuditLog::query()->where('action', 'donations.provider_updated')->first()->details ?? [], JSON_THROW_ON_ERROR));
    }

    public function test_test_mode_and_disabled_or_unpriced_currency_are_not_live_available(): void
    {
        $this->fakeDrivers();
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $service = app(DonationProviderSettings::class);
        $service->save($owner, 'fixture_alpha', true, 'test', ['RUB'], ['api_secret' => 'secret']);
        $this->assertFalse($service->isAvailableForLivePayment('fixture_alpha', 'RUB'));
        $service->save($owner, 'fixture_alpha', true, 'live', ['RUB']);
        $this->assertFalse($service->isAvailableForLivePayment('fixture_alpha', 'RUB'));
        app(DonationRates::class)->saveRate('RUB', '10', true, $owner);
        $this->assertTrue($service->isAvailableForLivePayment('fixture_alpha', 'RUB'));
        $this->assertSame('local_ok', $service->localDiagnostic('fixture_alpha')['state']);
        $service->save($owner, 'fixture_alpha', false, 'live', ['RUB']);
        $this->assertFalse($service->isAvailableForLivePayment('fixture_alpha', 'RUB'));
    }

    public function test_rejects_unregistered_driver_invalid_keys_currency_and_missing_secret(): void
    {
        $this->fakeDrivers();
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $service = app(DonationProviderSettings::class);
        foreach ([
            ['unknown', true, 'live', ['RUB'], ['api_secret' => 'x']],
            ['fixture_alpha', true, 'live', ['HKD'], ['api_secret' => 'x']],
            ['fixture_alpha', true, 'live', [['RUB']], ['api_secret' => 'x']],
            ['fixture_alpha', true, 'live', ['RUB', 'RUB'], ['api_secret' => 'x']],
            ['fixture_alpha', true, 'live', ['RUB'], ['api_secret' => ['nested-secret']]],
            ['fixture_alpha', true, 'live', ['RUB'], [0 => 'secret']],
            ['fixture_alpha', true, 'live', ['RUB'], ['api_key_class' => 'App\\Models\\User']],
            ['fixture_alpha', true, 'live', ['RUB'], []],
        ] as $args) {
            try {
                $service->save($owner, ...$args);
                $this->fail('Invalid provider configuration was accepted.');
            } catch (InvalidArgumentException) {
                // Expected.
            }
        }
        $this->assertDatabaseCount('module_donation_provider_settings', 0);
    }

    public function test_blank_credential_field_keeps_previous_encrypted_secret_in_http_form(): void
    {
        $this->fakeDrivers();
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        app(DonationRates::class)->saveRate('RUB', '10', true, $owner);
        app(DonationProviderSettings::class)->save($owner, 'fixture_alpha', true, 'live', ['RUB'], ['api_secret' => 'original-secret']);
        $this->actingAs($owner, 'admin')->put('/admin/extensions/donations/providers/fixture_alpha', [
            'enabled' => '1', 'mode' => 'live', 'currency_codes' => ['RUB'], 'credentials' => ['api_secret' => ''],
        ])->assertRedirect();
        $this->assertSame(['api_secret' => 'original-secret'], app(DonationProviderSettings::class)->credentialsForDriver('fixture_alpha'));
        $this->actingAs($owner, 'admin')->put('/admin/extensions/donations/providers/fixture_alpha', [
            'enabled' => '1', 'mode' => 'live', 'currency_codes' => ['EUR'], 'credentials' => ['api_secret' => 'unexpected-secret'],
        ])->assertSessionHasErrors('provider');
        $this->assertSame(['api_secret' => 'original-secret'], app(DonationProviderSettings::class)->credentialsForDriver('fixture_alpha'));
        $this->assertSame([], session('_old_input', []), 'Secret input must never be flashed on validation errors.');
    }

    public function test_permissions_and_direct_service_authorization(): void
    {
        $this->fakeDrivers();
        $auditor = Admin::factory()->create(['role' => AdminRole::Auditor]);
        $editor = Admin::factory()->create(['role' => AdminRole::Editor]);
        $this->actingAs($auditor, 'admin')->get('/admin/extensions/donations/providers')->assertOk();
        $this->actingAs($auditor, 'admin')->get('/admin/extensions/donations/providers/fixture_alpha')->assertOk();
        $this->actingAs($auditor, 'admin')->put('/admin/extensions/donations/providers/fixture_alpha', ['enabled' => '1', 'mode' => 'live'])->assertForbidden();
        $this->actingAs($editor, 'admin')->get('/admin/extensions/donations/providers')->assertForbidden();
        $this->actingAs($editor, 'admin')->put('/admin/extensions/donations/providers/fixture_alpha', ['enabled' => '1', 'mode' => 'live'])->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(DonationProviderSettings::class)->save($editor, 'fixture_alpha', true, 'live', ['RUB'], ['api_secret' => 'x']);
    }

    public function test_audit_failure_rolls_back_secret_rotation_and_mode(): void
    {
        $this->fakeDrivers();
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $service = app(DonationProviderSettings::class);
        $service->save($owner, 'fixture_alpha', true, 'live', ['RUB'], ['api_secret' => 'initial']);
        $audit = $this->mock(AuditLogger::class);
        $audit->shouldReceive('success')->once()->andReturn(null);
        $this->app->forgetInstance(DonationProviderSettings::class);
        $service = app(DonationProviderSettings::class);
        try {
            $service->save($owner, 'fixture_alpha', false, 'test', ['USD'], ['api_secret' => 'replacement']);
            $this->fail('A failed audit must roll back the provider settings.');
        } catch (RuntimeException) {
            $this->assertSame(['api_secret' => 'initial'], $service->credentialsForDriver('fixture_alpha'));
            $this->assertDatabaseHas('module_donation_provider_settings', ['provider_code' => 'fixture_alpha', 'mode' => 'live', 'enabled' => true]);
        }
    }

    public function test_broken_ciphertext_fails_closed_without_leaking_credentials(): void
    {
        $this->fakeDrivers();
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $service = app(DonationProviderSettings::class);
        $service->save($owner, 'fixture_alpha', true, 'live', ['RUB'], ['api_secret' => 'secret']);
        app(DonationRates::class)->saveRate('RUB', '10', true, $owner);
        DB::table('module_donation_provider_settings')->where('provider_code', 'fixture_alpha')->update(['credentials_ciphertext' => 'broken']);
        $this->assertFalse($service->isAvailableForLivePayment('fixture_alpha', 'RUB'));
        $this->assertSame([], $service->overview()[0]['configured_fields']);
        $this->assertSame('invalid', $service->localDiagnostic('fixture_alpha')['state']);
    }
}
