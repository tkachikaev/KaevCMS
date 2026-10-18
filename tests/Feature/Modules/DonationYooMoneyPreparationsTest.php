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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Models\DonationOrder;
use KaevCMS\Modules\Donations\Services\DonationOrders;
use KaevCMS\Modules\Donations\Services\DonationPaymentPreparations;
use KaevCMS\Modules\Donations\Services\DonationProviderSettings;
use KaevCMS\Modules\Donations\Services\DonationRates;
use KaevCMS\Modules\Donations\Services\DonationSettings;
use KaevCMS\Modules\Donations\Services\YooMoneyRecoveryProbe;
use RuntimeException;
use Tests\TestCase;

final class DonationYooMoneyPreparationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('donations');
        app(ModuleRuntime::class)->bootModule($module);
    }

    private function configure(string $mode = 'test'): Admin
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        app(DonationSettings::class)->updateVisibility(true, $owner);
        app(DonationRates::class)->saveRate('RUB', '10', true, $owner);
        app(DonationRates::class)->saveRate('USD', '2', true, $owner);
        app(DonationProviderSettings::class)->save($owner, 'yoomoney', true, $mode, ['RUB'], [
            'receiver' => '410012345678901', 'notification_secret' => 'private-secret',
        ]);

        return $owner;
    }

    private function draft(User $user, string $currency = 'RUB'): DonationOrder
    {
        return app(DonationOrders::class)->createDraft($user, $currency, 12, (string) Str::uuid());
    }

    public function test_prepare_is_audit_atomic_and_is_not_a_payment_even_when_live_configured(): void
    {
        $this->configure('live');
        $player = User::factory()->create();
        $order = $this->draft($player);
        $this->actingAs($player)->get('/modules/donations/orders/'.$order->order_uuid)
            ->assertOk()->assertDontSee('data-testid="donations-prepare-provider"', false)
            ->assertDontSee('quickpay/confirm');
        $request = ['provider' => 'yoomoney', 'amount' => '0.01', 'coins' => 1000000, 'receiver' => 'fake'];
        $url = '/modules/donations/orders/'.$order->order_uuid.'/prepare';
        $this->actingAs($player)->post($url, $request)->assertRedirect();
        $this->actingAs($player)->post($url, $request)->assertRedirect();
        $this->assertDatabaseCount('module_donation_payment_preparations', 1);
        $record = DB::table('module_donation_payment_preparations')->sole();
        $this->assertSame((int) $order->total_minor, (int) $record->payer_amount_minor);
        $this->assertSame('POST', $record->checkout_method);
        $this->assertSame('live', $record->mode);
        $this->assertSame('RUB', $record->currency_code);
        $this->assertSame('kaevcms-'.$order->order_uuid, $record->provider_label);
        $this->assertSame('draft', $order->fresh()->status);
        $this->assertNull($order->fresh()->provider_code);
        $this->assertDatabaseCount('wallet_entries', 0);
        $this->assertSame(1, AuditLog::query()->where('action', 'donations.payment_prepared')->count());
        $this->actingAs($player)->get('/modules/donations/orders/'.$order->order_uuid)
            ->assertOk()->assertDontSee('data-testid="donations-payment-prepared"', false)
            ->assertDontSee('quickpay/confirm');
    }

    public function test_test_mode_local_only_and_cannot_send_payment_request(): void
    {
        $this->configure('test');
        $user = User::factory()->create();
        $order = $this->draft($user);
        $this->actingAs($user)->post('/modules/donations/orders/'.$order->order_uuid.'/prepare', ['provider' => 'yoomoney'])->assertRedirect();
        $this->assertDatabaseHas('module_donation_payment_preparations', [
            'provider_code' => 'yoomoney', 'mode' => 'test', 'checkout_method' => 'PREVIEW',
        ]);
        $this->assertDatabaseCount('wallet_entries', 0);
        app(DonationOrders::class)->cancelDraft($user, $order->order_uuid);
        $this->assertDatabaseHas('module_donation_payment_preparations', ['order_id' => $order->id]);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_different_currency_disabled_or_expired_draft_and_foreign_user_are_denied(): void
    {
        $owner = $this->configure();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $order = $this->draft($user);
        $usd = $this->draft($user, 'USD');
        $this->actingAs($other)->post('/modules/donations/orders/'.$order->order_uuid.'/prepare', ['provider' => 'yoomoney'])->assertNotFound();
        $this->actingAs($user)->post('/modules/donations/orders/'.$usd->order_uuid.'/prepare', ['provider' => 'yoomoney'])->assertSessionHasErrors('order');
        $this->actingAs($user)->post('/modules/donations/orders/'.$order->order_uuid.'/prepare', ['provider' => 'evil'])->assertSessionHasErrors('order');
        app(DonationSettings::class)->updateVisibility(false, $owner);
        $this->actingAs($user)->post('/modules/donations/orders/'.$order->order_uuid.'/prepare', ['provider' => 'yoomoney'])->assertSessionHasErrors('order');
        $this->assertDatabaseCount('module_donation_payment_preparations', 0);
        app(DonationSettings::class)->updateVisibility(true, $owner);
        DB::table('module_donation_orders')->where('order_uuid', $order->order_uuid)->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($user)->post('/modules/donations/orders/'.$order->order_uuid.'/prepare', ['provider' => 'yoomoney'])->assertSessionHasErrors('order');
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_provider_disabled_preparation_is_rejected_but_existing_preparation_is_never_repriced(): void
    {
        $owner = $this->configure('live');
        $user = User::factory()->create();
        $order = $this->draft($user);
        $service = app(DonationPaymentPreparations::class);
        $first = $service->prepare($user, $order->order_uuid, 'yoomoney');
        app(DonationRates::class)->saveRate('RUB', '19', true, $owner);
        app(DonationProviderSettings::class)->save($owner, 'yoomoney', false, 'live', ['RUB']);
        $second = $service->prepare($user, $order->order_uuid, 'yoomoney');
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame((int) $order->total_minor, (int) $second->payer_amount_minor);
        $another = $this->draft($user);
        $this->actingAs($user)->post('/modules/donations/orders/'.$another->order_uuid.'/prepare', ['provider' => 'yoomoney'])
            ->assertSessionHasErrors('order');
        $this->assertDatabaseCount('module_donation_payment_preparations', 1);
        $this->expectException(InvalidArgumentException::class);
        $service->prepare($user, $order->order_uuid, 'not-installed');
    }

    public function test_audit_failure_rolls_back_preparation_without_changing_draft_or_wallet(): void
    {
        $this->configure();
        $user = User::factory()->create();
        $order = $this->draft($user);
        $audit = $this->mock(AuditLogger::class);
        $audit->shouldReceive('success')->once()->andReturn(null);
        $this->app->forgetInstance(DonationPaymentPreparations::class);
        try {
            app(DonationPaymentPreparations::class)->prepare($user, $order->order_uuid, 'yoomoney');
            $this->fail('Preparation without audit must roll back.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('module_donation_payment_preparations', 0);
            $this->assertSame('draft', $order->fresh()->status);
            $this->assertDatabaseCount('wallet_entries', 0);
        }
    }

    public function test_read_only_recovery_finds_only_matching_incoming_operations_without_credit(): void
    {
        $uuid = (string) Str::uuid();
        $label = 'kaevcms-'.$uuid;
        Http::fake(['https://yoomoney.ru/api/operation-history' => Http::response([
            'operations' => [
                ['operation_id' => '10001', 'status' => 'success', 'direction' => 'in', 'label' => $label],
                ['operation_id' => '10002', 'status' => 'success', 'direction' => 'out', 'label' => $label],
                ['operation_id' => '10003', 'status' => 'success', 'direction' => 'in', 'label' => 'other'],
            ],
        ], 200)]);
        $found = app(YooMoneyRecoveryProbe::class)->candidates($uuid, ['history_token' => str_repeat('s', 32)]);
        $this->assertSame([['operation_id' => '10001', 'status' => 'success']], $found);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer '.str_repeat('s', 32))
            && $request['label'] === $label);
        $this->assertDatabaseCount('wallet_entries', 0);
    }
}
