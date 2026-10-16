<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\User;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use KaevCMS\Modules\Donations\Models\DonationCurrencyRate;
use KaevCMS\Modules\Donations\Services\DonationRates;
use KaevCMS\Modules\Donations\Services\DonationSettings;
use Tests\TestCase;

class DonationPlayerQuoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('donations');
        app(ModuleRuntime::class)->bootModule($module);
    }

    private function expose(): Admin
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        app(DonationSettings::class)->updateVisibility(true, $owner);

        return $owner;
    }

    public function test_player_page_fails_closed_until_enabled_and_does_not_offer_checkout(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/modules/donations')->assertNotFound();
        $this->expose();
        $this->actingAs($user)->get('/modules/donations')->assertOk()
            ->assertSee('data-testid="donations-no-currencies"', false)
            ->assertDontSee('data-testid="donations-quote-form"', false)
            ->assertDontSee('name="payment_amount"', false);
        $this->actingAs($user)->post('/modules/donations')->assertStatus(405);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_quote_uses_exact_configured_rate_for_one_selected_currency(): void
    {
        $owner = $this->expose();
        $rates = app(DonationRates::class);
        $rates->saveRate('RUB', '10', true, $owner);
        $rates->saveRate('USD', '1', true, $owner);
        $rates->saveRate('EUR', '0.50', true, $owner);
        $rates->saveRate('JPY', '100', true, $owner);
        $rates->saveRate('KWD', '0.500', true, $owner);
        $user = User::factory()->create();

        foreach (['RUB' => '1000.00', 'USD' => '100.00', 'EUR' => '50.00', 'JPY' => '10000', 'KWD' => '50.000'] as $code => $amount) {
            $this->actingAs($user)->get('/modules/donations?coins=100&currency='.$code)
                ->assertOk()->assertSee('data-testid="donations-quote-result"', false)
                ->assertSee($amount.' '.$code)
                ->assertDontSee('data-testid="donations-quote-error"', false);
        }
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_disabled_currency_and_forged_query_are_not_quotable(): void
    {
        $owner = $this->expose();
        $rates = app(DonationRates::class);
        $rates->saveRate('USD', '1', true, $owner);
        $rates->saveRate('EUR', '1', false, $owner);
        $user = User::factory()->create();
        foreach (['coins=100&currency=EUR', 'coins=100&currency=FAK',
            'coins=100&currency=usd', 'coins=100&currency[]=USD',
            'coins=100&currency=USD&total_minor=1', 'coins[]=100&currency=USD',
        ] as $query) {
            $res = $this->actingAs($user)->get('/modules/donations?'.$query)->assertOk();
            if (! str_contains($query, 'total_minor=1')) {
                $res->assertSee('data-testid="donations-quote-error"', false)
                    ->assertDontSee('data-testid="donations-quote-result"', false);
            } else {
                // Injected total must not change the server-calculated price.
                $res->assertSee('100.00 USD')->assertDontSee('0.01 USD');
            }
        }
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_min_max_and_non_integer_quantities_are_rejected(): void
    {
        $owner = $this->expose();
        $rates = app(DonationRates::class);
        $rates->saveLimits(10, 200, $owner);
        $rates->saveRate('USD', '100', true, $owner);
        $user = User::factory()->create();
        foreach (['0', '9', '201', '-1', '1.5', '1e2', '9999999', 'invalid'] as $quantity) {
            $this->actingAs($user)->get('/modules/donations?currency=USD&coins='.$quantity)
                ->assertOk()->assertSee('data-testid="donations-quote-error"', false)
                ->assertDontSee('data-testid="donations-quote-result"', false);
        }
        foreach (['10', '200'] as $quantity) {
            $this->actingAs($user)->get('/modules/donations?currency=USD&coins='.$quantity)
                ->assertOk()->assertSee('data-testid="donations-quote-result"', false);
        }
    }

    public function test_disabling_or_damaging_a_rate_revokes_player_quotes_without_writing_balances(): void
    {
        $owner = $this->expose();
        $rates = app(DonationRates::class);
        $rates->saveRate('USD', '1', true, $owner);
        $user = User::factory()->create();
        $this->actingAs($user)->get('/modules/donations?coins=100&currency=USD')->assertSee('100.00 USD');
        $rates->saveRate('USD', '1', false, $owner);
        $this->actingAs($user)->get('/modules/donations')->assertSee('data-testid="donations-no-currencies"', false);
        $rates->saveRate('USD', '1', true, $owner);
        DonationCurrencyRate::query()->whereKey('USD')->update(['exponent' => 3]);
        $this->actingAs($user)->get('/modules/donations')->assertSee('data-testid="donations-no-currencies"', false);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_admin_rates_sections_remain_inside_blade_layout_content(): void
    {
        $blade = file_get_contents(base_path('modules/donations/resources/views/admin/index.blade.php'));
        $this->assertIsString($blade);
        $this->assertSame(1, substr_count($blade, '@endsection'));
        $this->assertGreaterThan(strpos($blade, 'data-testid="donations-currency-rates"'), strpos($blade, '@endsection'));
    }
}
