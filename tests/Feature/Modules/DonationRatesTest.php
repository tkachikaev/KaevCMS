<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Services\DonationCurrencyRegistry;
use KaevCMS\Modules\Donations\Services\DonationMoney;
use KaevCMS\Modules\Donations\Services\DonationRates;
use RuntimeException;
use Tests\TestCase;

class DonationRatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('donations');
        app(ModuleRuntime::class)->bootModule($module);
    }

    public function test_additive_migration_keeps_b1_visibility_and_initially_has_no_prices(): void
    {
        $this->assertTrue(Schema::hasTable('module_donation_currency_rates'));
        $this->assertTrue(Schema::hasColumns('module_donation_settings', ['min_coins', 'max_coins']));
        $this->assertDatabaseHas('module_donation_settings', [
            'id' => 1, 'show_in_account' => false, 'min_coins' => 1, 'max_coins' => 1000000,
        ]);
        $this->assertDatabaseCount('module_donation_currency_rates', 0);
        $this->assertSame('1.17.13', app(ModuleManager::class)->inspect('donations')['version']);
    }

    public function test_iso_currency_catalog_has_correct_fraction_digits_and_many_choices(): void
    {
        $registry = app(DonationCurrencyRegistry::class);
        $this->assertGreaterThan(100, count($registry->all()));
        foreach (['RUB' => 2, 'USD' => 2, 'EUR' => 2, 'KZT' => 2, 'JPY' => 0, 'KWD' => 3] as $code => $digits) {
            $this->assertSame($digits, $registry->find($code)['exponent']);
        }
        $this->assertNull($registry->find('FAK'));
        $this->assertNull($registry->find('../../'));
    }

    public function test_prices_are_exact_and_allow_independent_currency_values(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $rates = app(DonationRates::class);
        $rates->saveRate('RUB', '10', true, $owner);
        $rates->saveRate('USD', '100', true, $owner);
        $rates->saveRate('EUR', '0,50', true, $owner);
        $rates->saveRate('JPY', '100', true, $owner);
        $rates->saveRate('KWD', '0.500', true, $owner);

        foreach (['RUB' => [1000, 100000], 'USD' => [10000, 1000000], 'EUR' => [50, 5000], 'JPY' => [100, 10000], 'KWD' => [500, 50000]] as $code => [$unit, $total]) {
            $quote = $rates->quote($code, 100);
            $this->assertSame($unit, $quote['unit_price_minor']);
            $this->assertSame($total, $quote['total_minor']);
        }
        $this->assertDatabaseCount('module_donation_currency_rates', 5);
        $this->assertSame(5, AuditLog::query()->where('action', 'donations.rate_updated')->count());
    }

    public function test_exact_parser_rejects_invalid_digits_overflow_and_free_coins(): void
    {
        $money = app(DonationMoney::class);
        $this->assertSame(50, $money->parseUnitPrice('0,50', 2));
        $this->assertSame(100, $money->parseUnitPrice('100', 0));
        $this->assertSame(500, $money->parseUnitPrice('0.500', 3));
        $this->assertSame('10000.00', $money->format($money->totalMinor(1000000, 1), 2));
        foreach ([['0', 2], ['-1', 2], ['1e3', 2], ['0.001', 2], ['1.1', 0], ['1.1234', 3], ['9999999999', 2], ['0,00', 2]] as [$price, $exponent]) {
            try {
                $money->parseUnitPrice($price, $exponent);
                $this->fail("Price should be rejected: $price ($exponent)");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $money->totalMinor(1, 1000001);
    }

    public function test_currency_rate_validation_and_role_separation(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $editor = Admin::factory()->create(['role' => AdminRole::Editor]);
        $auditor = Admin::factory()->create(['role' => AdminRole::Auditor]);
        $rates = app(DonationRates::class);

        $this->actingAs($editor, 'admin')->post('/admin/extensions/donations/rates', [
            'currency_code' => 'USD', 'unit_price' => '10', 'enabled' => '1',
        ])->assertForbidden();
        $this->actingAs($auditor, 'admin')->get('/admin/extensions/donations')->assertOk();
        $this->actingAs($auditor, 'admin')->post('/admin/extensions/donations/rates', [
            'currency_code' => 'USD', 'unit_price' => '10', 'enabled' => '1',
        ])->assertForbidden();
        $this->actingAs($owner, 'admin')->post('/admin/extensions/donations/rates', [
            'currency_code' => 'USD', 'unit_price' => '0.001', 'enabled' => '1',
        ])->assertSessionHasErrors('unit_price');
        $this->actingAs($owner, 'admin')->post('/admin/extensions/donations/rates', [
            'currency_code' => 'FAK', 'unit_price' => '10', 'enabled' => '1',
        ])->assertSessionHasErrors('currency_code');
        $this->assertDatabaseCount('module_donation_currency_rates', 0);
        try {
            $rates->saveRate('EUR', '1', true, $editor);
            $this->fail('Editor must not change rates through the service.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->actingAs($owner, 'admin')->post('/admin/extensions/donations/rates', [
            'currency_code' => 'USD', 'unit_price' => '1', 'enabled' => '1',
        ])->assertRedirect();
        $this->assertDatabaseHas('module_donation_currency_rates', ['currency_code' => 'USD', 'unit_price_minor' => 100, 'enabled' => 1]);
    }

    public function test_invalid_legacy_price_does_not_save_availability(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $rates = app(DonationRates::class);
        $rates->saveRate('USD', '2', true, $owner);
        $this->actingAs($owner, 'admin');

        foreach ([true, false] as $pendingEnabled) {
            $rates->saveRate('RUB', '1', ! $pendingEnabled, $owner);
            $auditCount = AuditLog::query()->count();
            $this->from('/admin/extensions/donations')
                ->post('/admin/extensions/donations/rates', [
                    'currency_code' => 'RUB', 'unit_price' => '0.001',
                    'enabled' => $pendingEnabled ? '1' : '0',
                ])->assertRedirect('/admin/extensions/donations')->assertSessionHasErrors('unit_price');

            $this->assertDatabaseHas('module_donation_currency_rates', [
                'currency_code' => 'RUB', 'unit_price_minor' => 100, 'enabled' => ! $pendingEnabled,
            ]);
            $this->assertSame($auditCount, AuditLog::query()->count());
        }
    }

    public function test_disabled_or_unconfigured_rates_cannot_be_quoted(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $rates = app(DonationRates::class);
        $rates->saveRate('USD', '1.25', false, $owner);
        $this->expectException(InvalidArgumentException::class);
        $rates->quote('USD', 100);
    }

    public function test_invalid_min_max_and_out_of_range_quantities_are_rejected(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $rates = app(DonationRates::class);
        $this->actingAs($owner, 'admin')->put('/admin/extensions/donations/limits', [
            'min_coins' => 500, 'max_coins' => 100,
        ])->assertSessionHasErrors('max_coins');
        $this->assertDatabaseHas('module_donation_settings', ['id' => 1, 'min_coins' => 1, 'max_coins' => 1000000]);
        $rates->saveLimits(10, 100, $owner);
        $rates->saveRate('USD', '1', true, $owner);
        $this->assertSame(1000, $rates->quote('USD', 10)['total_minor']);
        try {
            $rates->quote('USD', 9);
            $this->fail('Quantity below min should fail.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
        $this->expectException(InvalidArgumentException::class);
        $rates->quote('USD', 101);
    }

    public function test_currency_precision_mismatch_fails_closed(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $rates = app(DonationRates::class);
        $rates->saveRate('USD', '1.00', true, $owner);
        DB::table('module_donation_currency_rates')->where('currency_code', 'USD')->update(['exponent' => 3]);
        $this->expectException(InvalidArgumentException::class);
        $rates->quote('USD', 10);
    }

    public function test_audit_failure_rolls_back_rate_and_limits(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $audit = $this->mock(AuditLogger::class);
        $audit->shouldReceive('success')->twice()->andReturn(null);
        $rates = app(DonationRates::class);
        try {
            $rates->saveRate('USD', '1.00', true, $owner);
            $this->fail('Audit failure should roll back the tariff.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('module_donation_currency_rates', 0);
        }
        try {
            $rates->saveLimits(10, 500, $owner);
            $this->fail('Audit failure should roll back limits.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('module_donation_settings', ['id' => 1, 'min_coins' => 1, 'max_coins' => 1000000]);
        }
    }

    public function test_rates_do_not_affect_core_wallet_or_enable_player_checkout(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $user = User::factory()->create();
        $walletId = $user->wallet()->value('id');
        app(DonationRates::class)->saveRate('USD', '100', true, $owner);
        $this->assertSame($walletId, $user->wallet()->value('id'));
        $this->assertDatabaseCount('wallet_entries', 0);
        $this->actingAs($user)->get('/modules/donations')->assertNotFound();
        $this->actingAs($user)->post('/modules/donations')->assertStatus(405);
    }
}
