<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Modules\ModuleContext;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Models\DonationOrder;
use KaevCMS\Modules\Donations\Services\DonationOrders;
use KaevCMS\Modules\Donations\Services\DonationRates;
use KaevCMS\Modules\Donations\Services\DonationSettings;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class DonationOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('donations');
        app(ModuleRuntime::class)->bootModule($module);
    }

    private function configure(): Admin
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        app(DonationSettings::class)->updateVisibility(true, $owner);
        app(DonationRates::class)->saveRate('RUB', '10', true, $owner);

        return $owner;
    }

    public function test_schema_contains_provenance_and_starts_empty(): void
    {
        $this->assertSame('1.17.14', app(ModuleManager::class)->inspect('donations')['version']);
        $this->assertTrue(Schema::hasColumns('module_donation_orders', [
            'order_uuid', 'user_id', 'idempotency_key', 'coins', 'currency_code',
            'exponent', 'unit_price_minor', 'total_minor', 'provider_code',
            'provider_payment_id', 'checkout_total_minor', 'status', 'expires_at',
            'paid_at', 'credited_at',
        ]));
        $this->assertTrue(Schema::hasTable('module_donation_order_events'));
        $this->assertDatabaseCount('module_donation_orders', 0);
    }

    public function test_language_switch_does_not_consume_the_promo_activation_budget(): void
    {
        $this->configure();
        $module = app(ModuleManager::class)->enable('promo-codes');
        app(ModuleRuntime::class)->bootModule($module);
        $this->actingAs(User::factory()->create());
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->get('/language/ru?return=%2Fmodules%2Fdonations')->assertRedirect();
        }
        $this->from('/modules/promo-codes')->post('/modules/promo-codes/activate', [
            'code' => 'UNKNOWNQA2026', 'request_token' => (string) Str::uuid(),
        ])->assertRedirect('/modules/promo-codes')->assertSessionHasErrors('code');
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_checkout_reads_do_not_consume_the_order_creation_budget(): void
    {
        $this->configure();
        $user = User::factory()->create();
        $order = app(DonationOrders::class)->createDraft($user, 'RUB', 10, (string) Str::uuid());
        $this->actingAs($user);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->get('/modules/donations/orders/'.$order->order_uuid.'/checkout')->assertNotFound();
        }
        $this->post('/modules/donations/orders', [
            'coins' => 10, 'currency' => 'RUB', 'request_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->assertDatabaseCount('module_donation_orders', 2);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_order_creation_still_rejects_the_eleventh_request_per_minute(): void
    {
        $this->configure();
        $this->actingAs(User::factory()->create());
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->post('/modules/donations/orders', [
                'coins' => 10, 'currency' => 'RUB', 'request_key' => (string) Str::uuid(),
            ])->assertRedirect();
        }
        $this->post('/modules/donations/orders', [
            'coins' => 10, 'currency' => 'RUB', 'request_key' => (string) Str::uuid(),
        ])->assertTooManyRequests();
        // The independent pending-draft cap remains in force as well.
        $this->assertDatabaseCount('module_donation_orders', 5);
    }

    public function test_language_switch_still_rejects_the_twenty_first_request_per_minute(): void
    {
        $this->actingAs(User::factory()->create());
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->get('/language/ru?return=%2Fmodules%2Fdonations')->assertRedirect();
        }
        $this->get('/language/ru?return=%2Fmodules%2Fdonations')->assertTooManyRequests();
    }

    public function test_history_and_order_link_to_coin_selection_only_while_player_page_is_open(): void
    {
        $owner = $this->configure();
        $user = User::factory()->create();
        // HTTP tests reuse the application; refresh the boot-time navigation as a new request would.
        $module = app(ModuleManager::class)->inspect('donations');
        $bootstrap = require $module['bootstrap_path'];
        $context = new ModuleContext('donations', $module['path'], $module['manifest']);
        $bootstrap(app(), $context);
        $this->actingAs($user)->get('/modules/donations/orders')->assertRedirect('/account/wallet');
        $this->get('/account/wallet')->assertOk()->assertSee(__('Top up balance'));
        $order = app(DonationOrders::class)->createDraft($user, 'RUB', 25, (string) Str::uuid());
        $orderUrl = '/modules/donations/orders/'.$order->order_uuid;
        $this->get($orderUrl)->assertOk()->assertSee('data-testid="donations-choose-coins"', false);

        app(DonationSettings::class)->updateVisibility(false, $owner);
        $bootstrap(app(), $context);
        $this->get('/modules/donations/orders')->assertRedirect('/account/wallet');
        $this->get('/account/wallet')->assertOk()->assertDontSee(__('Top up balance'));
        $this->get($orderUrl)->assertOk()->assertDontSee('data-testid="donations-choose-coins"', false);
        $this->get('/modules/donations')->assertNotFound();
        $this->assertDatabaseCount('module_donation_orders', 1);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_order_snapshots_exact_fiat_price_without_charging_or_crediting(): void
    {
        $owner = $this->configure();
        $user = User::factory()->create();
        $order = app(DonationOrders::class)->createDraft($user, 'RUB', 100, (string) Str::uuid());
        $this->assertSame(100, $order->coins);
        $this->assertSame(1000, $order->unit_price_minor);
        $this->assertSame(100000, $order->total_minor);
        $this->assertSame(2, $order->exponent);
        $this->assertSame(DonationOrder::DRAFT, $order->status);
        $this->assertNull($order->provider_code);
        $this->assertNull($order->provider_payment_id);
        $this->assertNull($order->checkout_total_minor);
        $this->assertNull($order->paid_at);
        $this->assertNull($order->credited_at);
        $this->assertDatabaseCount('module_donation_order_events', 1);
        $this->assertDatabaseCount('wallet_entries', 0);

        app(DonationRates::class)->saveRate('RUB', '50', true, $owner);
        $order->refresh();
        $this->assertSame(1000, $order->unit_price_minor);
        $this->assertSame(100000, $order->total_minor);
    }

    public function test_same_key_replays_original_order_but_reuse_for_other_request_fails(): void
    {
        $owner = $this->configure();
        $user = User::factory()->create();
        $key = (string) Str::uuid();
        $service = app(DonationOrders::class);
        $first = $service->createDraft($user, 'RUB', 100, $key);
        app(DonationRates::class)->saveRate('RUB', '100', true, $owner);
        app(DonationSettings::class)->updateVisibility(false, $owner);
        $second = $service->createDraft($user, 'RUB', 100, $key);
        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->total_minor, $second->total_minor);
        $this->assertDatabaseCount('module_donation_orders', 1);
        $this->assertDatabaseCount('module_donation_order_events', 1);
        $this->expectException(InvalidArgumentException::class);
        $service->createDraft($user, 'RUB', 101, $key);
    }

    public function test_new_orders_close_when_visibility_is_disabled_and_invalid_currency_fails(): void
    {
        $owner = $this->configure();
        $user = User::factory()->create();
        $service = app(DonationOrders::class);
        foreach (['USD', 'FAK'] as $code) {
            try {
                $service->createDraft($user, $code, 100, (string) Str::uuid());
                $this->fail('Disabled currency must not create orders.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        app(DonationSettings::class)->updateVisibility(false, $owner);
        $this->expectException(LogicException::class);
        $service->createDraft($user, 'RUB', 100, (string) Str::uuid());
    }

    public function test_player_can_read_only_own_order_and_cancel_even_after_closed_to_new_orders(): void
    {
        $owner = $this->configure();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $service = app(DonationOrders::class);
        $order = $service->createDraft($user, 'RUB', 2, (string) Str::uuid());
        $this->assertNull($service->forUser($other, $order->order_uuid));
        $this->assertSame($order->id, $service->forUser($user, $order->order_uuid)?->id);
        app(DonationSettings::class)->updateVisibility(false, $owner);
        try {
            $service->cancelDraft($other, $order->order_uuid);
            $this->fail('Cannot cancel another player order.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
        $cancelled = $service->cancelDraft($user, $order->order_uuid);
        $this->assertSame(DonationOrder::CANCELLED, $cancelled->status);
        $service->cancelDraft($user, $order->order_uuid);
        $this->assertDatabaseCount('module_donation_order_events', 2);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_expired_draft_cannot_be_resurrected_and_requires_new_request_key(): void
    {
        $this->configure();
        $user = User::factory()->create();
        $service = app(DonationOrders::class);
        $key = (string) Str::uuid();
        $order = $service->createDraft($user, 'RUB', 2, $key);
        $this->travel(61)->minutes();
        $expired = $service->cancelDraft($user, $order->order_uuid);
        $this->assertSame(DonationOrder::EXPIRED, $expired->status);
        $this->assertSame($order->id, $service->createDraft($user, 'RUB', 2, $key)->id);
        $this->assertSame(DonationOrder::DRAFT,
            $service->createDraft($user, 'RUB', 2, (string) Str::uuid())->status);
        $this->assertDatabaseCount('module_donation_order_events', 3);
    }

    public function test_new_draft_expires_after_sixty_minutes_not_thirty(): void
    {
        $this->configure();
        $user = User::factory()->create();
        $order = app(DonationOrders::class)->createDraft($user, 'RUB', 2, (string) Str::uuid());
        $this->assertSame(now()->addHour()->format('Y-m-d H:i:s'), $order->expires_at->format('Y-m-d H:i:s'));
        $this->travel(31)->minutes();
        $this->assertSame('draft', $order->fresh()->displayStatus());
        $this->travel(30)->minutes();
        $this->assertSame('expired', $order->fresh()->displayStatus());
    }

    public function test_limit_of_five_active_drafts_does_not_block_cancelled_drafts(): void
    {
        $this->configure();
        $user = User::factory()->create();
        $service = app(DonationOrders::class);
        $orders = [];
        for ($i = 0; $i < 5; $i++) {
            $orders[] = $service->createDraft($user, 'RUB', 1, (string) Str::uuid());
        }
        try {
            $service->createDraft($user, 'RUB', 1, (string) Str::uuid());
            $this->fail('Active order cap must be enforced.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
        $service->cancelDraft($user, $orders[0]->order_uuid);
        $service->createDraft($user, 'RUB', 1, (string) Str::uuid());
        $this->assertDatabaseCount('module_donation_orders', 6);
    }

    public function test_snapshot_fields_cannot_be_mutated_and_order_cannot_be_deleted(): void
    {
        $this->configure();
        $user = User::factory()->create();
        $order = app(DonationOrders::class)->createDraft($user, 'RUB', 3, (string) Str::uuid());
        try {
            $order->forceFill(['total_minor' => 1])->save();
            $this->fail('Snapshot modification must be blocked.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
        $order->refresh();
        $this->assertSame(3000, $order->total_minor);
        $this->expectException(LogicException::class);
        $order->delete();
    }

    public function test_provider_payment_states_cannot_be_forged_before_integration(): void
    {
        $this->configure();
        $user = User::factory()->create();
        $order = app(DonationOrders::class)->createDraft($user, 'RUB', 3, (string) Str::uuid());
        foreach ([['status' => 'paid'], ['provider_code' => 'fake'], ['paid_at' => now()]] as $update) {
            try {
                $order->refresh()->forceFill($update)->save();
                $this->fail('B4 must reject provider payment or settlement fields.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
        $this->assertDatabaseHas('module_donation_orders', ['id' => $order->id, 'status' => 'draft']);
    }

    public function test_missing_audit_rolls_back_insert_and_history(): void
    {
        $this->configure();
        $user = User::factory()->create();
        $audit = $this->mock(AuditLogger::class);
        $audit->shouldReceive('success')->once()->andReturn(null);
        $this->expectException(RuntimeException::class);
        try {
            app(DonationOrders::class)->createDraft($user, 'RUB', 1, (string) Str::uuid());
        } finally {
            $this->assertDatabaseCount('module_donation_orders', 0);
            $this->assertDatabaseCount('module_donation_order_events', 0);
        }
    }

    public function test_failed_cancel_audit_leaves_draft_and_event_history_untouched(): void
    {
        $this->configure();
        $user = User::factory()->create();
        $order = app(DonationOrders::class)->createDraft($user, 'RUB', 1, (string) Str::uuid());
        $audit = $this->mock(AuditLogger::class);
        $audit->shouldReceive('success')->once()->andReturn(null);
        try {
            app(DonationOrders::class)->cancelDraft($user, $order->order_uuid);
            $this->fail('Cancelled status must roll back when audit fails.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('module_donation_orders', ['id' => $order->id, 'status' => 'draft']);
            $this->assertDatabaseCount('module_donation_order_events', 1);
        }
    }

    public function test_same_request_key_is_scoped_by_user_and_does_not_expose_another_order(): void
    {
        $this->configure();
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $key = (string) Str::uuid();
        $service = app(DonationOrders::class);
        $first = $service->createDraft($firstUser, 'RUB', 1, $key);
        $second = $service->createDraft($secondUser, 'RUB', 2, $key);
        $this->assertNotSame($first->id, $second->id);
        $this->assertNull($service->forUser($firstUser, $second->order_uuid));
        $this->assertNull($service->forUser($secondUser, $first->order_uuid));
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_order_and_events_cannot_be_destroyed_by_migration_rollback_with_data(): void
    {
        $this->configure();
        $user = User::factory()->create();
        app(DonationOrders::class)->createDraft($user, 'RUB', 1, (string) Str::uuid());
        $migration = require base_path('modules/donations/database/migrations/2026_10_16_010000_create_module_donation_orders_tables.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }
}
