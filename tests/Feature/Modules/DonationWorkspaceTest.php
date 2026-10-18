<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use KaevCMS\Modules\Donations\Services\DonationOrders;
use KaevCMS\Modules\Donations\Services\DonationRates;
use KaevCMS\Modules\Donations\Services\DonationSettings;
use KaevCMS\Modules\Donations\Services\DonationWorkspace;
use RuntimeException;
use Tests\TestCase;

class DonationWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Admin $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleRuntime::class)->bootModule(app(ModuleManager::class)->enable('donations'));
        $this->owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $this->actingAs($this->owner, 'admin');
    }

    public function test_one_save_applies_visibility_limits_and_multiple_exact_currency_prices(): void
    {
        $this->put('/admin/extensions/donations/workspace', $this->payload([
            'show_in_account' => '1', 'min_coins' => '10', 'max_coins' => '500',
            'rates' => ['RUB' => $this->rate('3.50'), 'USD' => $this->rate('0,25', false)],
        ]))->assertRedirect('/admin/extensions/donations')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('module_donation_settings', ['id' => 1, 'show_in_account' => 1, 'min_coins' => 10, 'max_coins' => 500]);
        $this->assertDatabaseHas('module_donation_currency_rates', ['currency_code' => 'RUB', 'unit_price_minor' => 350, 'enabled' => 1]);
        $this->assertDatabaseHas('module_donation_currency_rates', ['currency_code' => 'USD', 'unit_price_minor' => 25, 'enabled' => 0]);
        $this->assertSame(1, AuditLog::query()->where('action', 'donations.workspace_updated')->count());
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_invalid_price_preserves_all_pending_fields_and_never_partially_deletes_or_saves(): void
    {
        app(DonationRates::class)->saveRate('RUB', '1', false, $this->owner);
        app(DonationRates::class)->saveRate('USD', '2', true, $this->owner);
        $auditCount = AuditLog::query()->count();
        $this->from('/admin/extensions/donations')->put('/admin/extensions/donations/workspace', $this->payload([
            'show_in_account' => '1', 'min_coins' => '20', 'max_coins' => '200',
            'rates' => ['RUB' => $this->rate('0.001'), 'USD' => ['remove' => '1']],
        ]))->assertRedirect('/admin/extensions/donations')->assertSessionHasErrors('rates.RUB.unit_price');
        $response = $this->get('/admin/extensions/donations')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame('0.001', $xpath->evaluate('string(//input[@name="rates[RUB][unit_price]"]/@value)'));
        $this->assertSame(1.0, $xpath->evaluate('count(//input[@name="rates[RUB][enabled]" and @checked])'));
        $this->assertSame('1', $xpath->evaluate('string(//input[@name="rates[USD][remove]"]/@value)'));
        $this->assertSame('20', $xpath->evaluate('string(//input[@name="min_coins"]/@value)'));
        $this->assertDatabaseHas('module_donation_settings', ['id' => 1, 'show_in_account' => 0, 'min_coins' => 1, 'max_coins' => 1000000]);
        $this->assertDatabaseHas('module_donation_currency_rates', ['currency_code' => 'RUB', 'unit_price_minor' => 100, 'enabled' => 0]);
        $this->assertDatabaseHas('module_donation_currency_rates', ['currency_code' => 'USD', 'enabled' => 1]);
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_removing_last_currency_preserves_order_history_and_original_retries(): void
    {
        app(DonationRates::class)->saveRate('RUB', '2.50', true, $this->owner);
        app(DonationSettings::class)->updateVisibility(true, $this->owner);
        $user = User::factory()->create();
        $key = (string) Str::uuid();
        $orders = app(DonationOrders::class);
        $order = $orders->createDraft($user, 'RUB', 100, $key);
        $original = $order->fresh()->getAttributes();

        $this->put('/admin/extensions/donations/workspace', $this->payload(['rates' => ['RUB' => ['remove' => '1']]]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('module_donation_currency_rates', 0);
        $this->assertSame($original, $order->fresh()->getAttributes());
        $this->assertDatabaseCount('module_donation_order_events', 1);
        $this->assertSame($order->id, $orders->createDraft($user, 'RUB', 100, $key)->id);
        $this->assertSame([], app(DonationRates::class)->availableForPlayers('ru'));
        $this->actingAs($user)->get('/modules/donations/orders/'.$order->order_uuid)->assertOk()->assertSee('250.00');
        $this->assertDatabaseCount('wallet_entries', 0);
        $this->expectException(\InvalidArgumentException::class);
        $orders->createDraft($user, 'RUB', 100, (string) Str::uuid());
    }

    public function test_audit_failure_rolls_back_deleted_added_and_general_settings_together(): void
    {
        app(DonationRates::class)->saveRate('RUB', '1', true, $this->owner);
        $payload = $this->payload(['show_in_account' => '1', 'min_coins' => '10', 'rates' => [
            'RUB' => ['remove' => '1'], 'EUR' => $this->rate('2'),
        ]]);
        $audit = $this->mock(AuditLogger::class);
        $audit->shouldReceive('success')->once()->andReturn(null);
        try {
            app(DonationWorkspace::class)->save($payload, $this->owner);
            $this->fail('A failed audit must roll back the entire settings form.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to audit Donations settings.', $exception->getMessage());
        }
        $this->assertDatabaseHas('module_donation_currency_rates', ['currency_code' => 'RUB', 'unit_price_minor' => 100]);
        $this->assertDatabaseMissing('module_donation_currency_rates', ['currency_code' => 'EUR']);
        $this->assertDatabaseHas('module_donation_settings', ['id' => 1, 'min_coins' => 1, 'show_in_account' => 0]);
    }

    public function test_stale_tabs_cannot_overwrite_another_save_or_a_legacy_rate_change(): void
    {
        $first = $this->payload(['rates' => ['RUB' => $this->rate('1')]]);
        $second = $this->payload(['rates' => ['EUR' => $this->rate('2')]]);
        $this->put('/admin/extensions/donations/workspace', $first)->assertSessionHasNoErrors();
        $this->put('/admin/extensions/donations/workspace', $second)->assertSessionHasErrors('snapshot');
        $this->assertDatabaseCount('module_donation_currency_rates', 1);
        $stale = $this->payload(['rates' => ['RUB' => $this->rate('5')]]);
        app(DonationRates::class)->saveRate('RUB', '3', true, $this->owner);
        $this->put('/admin/extensions/donations/workspace', $stale)->assertSessionHasErrors('snapshot');
        $this->assertDatabaseHas('module_donation_currency_rates', ['currency_code' => 'RUB', 'unit_price_minor' => 300]);
    }

    public function test_omitted_currencies_are_not_deleted_and_unchanged_save_does_not_add_audit(): void
    {
        app(DonationRates::class)->saveRate('RUB', '1', true, $this->owner);
        $count = AuditLog::query()->count();
        $this->put('/admin/extensions/donations/workspace', $this->payload())
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('module_donation_currency_rates', ['currency_code' => 'RUB']);
        $this->assertSame($count, AuditLog::query()->count());
    }

    public function test_roles_and_unknown_currency_cannot_change_settings(): void
    {
        $payload = $this->payload(['rates' => ['FAK' => $this->rate('1')]]);
        $this->put('/admin/extensions/donations/workspace', $payload)->assertSessionHasErrors('rates');
        $payload = $this->payload(['rates' => ['RUB' => $this->rate('1')]]);
        foreach ([AdminRole::Auditor, AdminRole::Editor] as $role) {
            $this->actingAs(Admin::factory()->create(['role' => $role]), 'admin')
                ->put('/admin/extensions/donations/workspace', $payload)->assertForbidden();
        }
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Auditor]), 'admin')
            ->get('/admin/extensions/donations')->assertOk()->assertDontSee('data-donations-delete', false)
            ->assertDontSee('data-donations-add', false)->assertDontSee(__('module-donations::messages.save_all'));
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Administrator]), 'admin')
            ->put('/admin/extensions/donations/workspace', $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('module_donation_currency_rates', ['currency_code' => 'RUB']);
    }

    /** @param array<string,mixed> $override
     * @return array{snapshot:string,show_in_account:string,min_coins:string,max_coins:string,rates:array<string,array{remove:string,unit_price?:string,enabled?:string}>}
     */
    private function payload(array $override = []): array
    {
        $state = app(DonationWorkspace::class)->read();

        return array_replace([
            'snapshot' => $state['snapshot'], 'show_in_account' => $state['show_in_account'] ? '1' : '0',
            'min_coins' => (string) $state['min_coins'], 'max_coins' => (string) $state['max_coins'], 'rates' => [],
        ], $override);
    }

    /** @return array{unit_price:string,enabled:string,remove:string} */
    private function rate(string $price, bool $enabled = true): array
    {
        return ['unit_price' => $price, 'enabled' => $enabled ? '1' : '0', 'remove' => '0'];
    }
}
