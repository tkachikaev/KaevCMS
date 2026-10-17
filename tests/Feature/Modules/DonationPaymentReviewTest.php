<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Services\DonationOrders;
use KaevCMS\Modules\Donations\Services\DonationPaymentPreparations;
use KaevCMS\Modules\Donations\Services\DonationPaymentReviews;
use KaevCMS\Modules\Donations\Services\DonationProviderSettings;
use KaevCMS\Modules\Donations\Services\DonationRates;
use KaevCMS\Modules\Donations\Services\DonationSettings;
use LogicException;
use RuntimeException;
use Tests\TestCase;

final class DonationPaymentReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('donations');
        app(ModuleRuntime::class)->bootModule($module);
    }

    /** @return array{Admin,string,int} */
    private function fixture(string $status = 'credited'): array
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Owner]);
        app(DonationSettings::class)->updateVisibility(true, $admin);
        app(DonationRates::class)->saveRate('RUB', '10', true, $admin);
        app(DonationProviderSettings::class)->save($admin, 'yoomoney', true, 'live', ['RUB'], [
            'receiver' => '410012345678901', 'notification_secret' => 'fixture-secret',
        ]);
        $user = User::factory()->create();
        $order = app(DonationOrders::class)->createDraft($user, 'RUB', 12, (string) Str::uuid());
        $preparation = app(DonationPaymentPreparations::class)->prepare($user, $order->order_uuid, 'yoomoney');
        $receipt = DB::table('module_donation_payment_receipts')->insertGetId([
            'order_id' => $order->id, 'preparation_id' => $preparation->id,
            'provider_code' => 'yoomoney', 'provider_payment_id' => (string) random_int(1000000000, 9999999999),
            'currency_code' => 'RUB', 'payer_amount_minor' => 12000,
            'received_amount_minor' => 11760, 'notification_sha256' => str_repeat('a', 64),
            'encrypted_notification' => 'fixture-no-credentials', 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$admin, $order->order_uuid, $receipt];
    }

    public function test_manual_refund_report_is_audited_idempotent_and_does_not_change_wallet_or_order(): void
    {
        [$admin, $uuid, $receipt] = $this->fixture();
        $request = (string) Str::uuid();
        $service = app(DonationPaymentReviews::class);
        $event = $service->record($admin, $uuid, $receipt, 'refund_reported', 'Support ticket TEST-427.', $request);
        $this->assertSame($event, $service->record($admin, $uuid, $receipt, 'refund_reported', 'Support ticket TEST-427.', $request));
        $this->assertDatabaseCount('module_donation_payment_review_events', 1);
        $this->assertDatabaseHas('module_donation_payment_review_events', ['id' => $event, 'source' => 'manual', 'kind' => 'refund_reported']);
        $this->assertDatabaseCount('wallet_entries', 0);
        $this->assertDatabaseHas('module_donation_orders', ['order_uuid' => $uuid, 'status' => 'draft']);
    }

    public function test_same_key_with_changed_report_is_rejected(): void
    {
        [$admin, $uuid, $receipt] = $this->fixture();
        $request = (string) Str::uuid();
        $service = app(DonationPaymentReviews::class);
        $service->record($admin, $uuid, $receipt, 'review_note', 'First review report.', $request);
        $this->expectException(LogicException::class);
        $service->record($admin, $uuid, $receipt, 'review_note', 'Different review report.', $request);
    }

    public function test_editor_and_auditor_cannot_write_reports_directly(): void
    {
        [, $uuid, $receipt] = $this->fixture();
        foreach ([AdminRole::Editor, AdminRole::Auditor] as $role) {
            $admin = Admin::factory()->create(['role' => $role]);
            try {
                app(DonationPaymentReviews::class)->record($admin, $uuid, $receipt,
                    'review_note', 'Should never be recorded.', (string) Str::uuid());
                $this->fail('Read-only role was permitted to create financial evidence.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertDatabaseCount('module_donation_payment_review_events', 0);
    }

    public function test_other_order_receipt_and_test_receipt_are_rejected(): void
    {
        [$admin, $uuid, $receipt] = $this->fixture();
        [, $otherUuid, $otherReceipt] = $this->fixture('test');
        foreach ([[$uuid, $otherReceipt], [$otherUuid, $otherReceipt]] as [$target, $id]) {
            try {
                app(DonationPaymentReviews::class)->record($admin, $target, $id,
                    'review_note', 'Untrusted receipt reference.', (string) Str::uuid());
                $this->fail('Invalid receipt was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->assertDatabaseCount('module_donation_payment_review_events', 0);
    }

    public function test_uncredited_receipts_cannot_be_described_as_refunded_or_charged_back(): void
    {
        [$admin, $uuid, $receipt] = $this->fixture('pending');
        foreach (['refund_reported', 'chargeback_reported'] as $kind) {
            try {
                app(DonationPaymentReviews::class)->record($admin, $uuid, $receipt, $kind,
                    'Unverified transfer report.', (string) Str::uuid());
                $this->fail('A pending transfer was marked as a refund.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_audit_failure_rolls_back_the_report(): void
    {
        [$admin, $uuid, $receipt] = $this->fixture();
        $logger = $this->mock(AuditLogger::class);
        $logger->shouldReceive('success')->once()->andReturn(null);
        $this->expectException(RuntimeException::class);
        try {
            app(DonationPaymentReviews::class)->record($admin, $uuid, $receipt,
                'dispute_reported', 'Received a customer dispute.', (string) Str::uuid());
        } finally {
            $this->assertDatabaseCount('module_donation_payment_review_events', 0);
        }
    }

    public function test_admin_routes_restrict_writes_but_auditor_can_read(): void
    {
        [$owner, $uuid, $receipt] = $this->fixture();
        $url = '/admin/extensions/donations/orders/'.$uuid;
        $payload = ['receipt_id' => $receipt, 'kind' => 'review_note',
            'reason' => 'Manual payment review item.', 'request_uuid' => (string) Str::uuid()];
        $auditor = Admin::factory()->create(['role' => AdminRole::Auditor]);
        $this->actingAs($auditor, 'admin')->get($url)->assertOk()
            ->assertDontSee('donations-review-form');
        $this->actingAs($auditor, 'admin')->post($url.'/reviews', $payload)->assertForbidden();
        $editor = Admin::factory()->create(['role' => AdminRole::Editor]);
        $this->actingAs($editor, 'admin')->post($url.'/reviews', $payload)->assertForbidden();
        $this->actingAs($owner, 'admin')->get($url)->assertOk()
            ->assertSee('donations-review-form')->assertSee('2.40');
        $this->actingAs($owner, 'admin')->post($url.'/reviews', $payload)->assertRedirect();
        $this->actingAs($auditor, 'admin')->get($url)->assertOk()->assertSee('Manual payment review item.');
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_resolution_requires_a_report_and_cannot_be_replayed_as_a_new_resolution(): void
    {
        [$admin, $uuid, $receipt] = $this->fixture();
        $service = app(DonationPaymentReviews::class);
        try {
            $service->record($admin, $uuid, $receipt, 'review_resolved',
                'No original dispute exists.', (string) Str::uuid());
            $this->fail('Cannot resolve without an existing report.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
        $service->record($admin, $uuid, $receipt, 'dispute_reported',
            'Customer opened an external dispute.', (string) Str::uuid());
        $service->record($admin, $uuid, $receipt, 'review_resolved',
            'Resolved manually after checking.', (string) Str::uuid());
        $this->assertDatabaseCount('module_donation_payment_review_events', 2);
        $this->expectException(LogicException::class);
        $service->record($admin, $uuid, $receipt, 'review_resolved',
            'Duplicate resolution attempt.', (string) Str::uuid());
    }

    public function test_test_preparation_with_non_test_receipt_cannot_enter_live_review(): void
    {
        [$admin, $uuid, $receipt] = $this->fixture();
        DB::table('module_donation_payment_preparations')->update(['mode' => 'test']);
        $this->expectException(InvalidArgumentException::class);
        try {
            app(DonationPaymentReviews::class)->record($admin, $uuid, $receipt,
                'review_note', 'Pretend this is a real receipt.', (string) Str::uuid());
        } finally {
            $this->assertDatabaseCount('module_donation_payment_review_events', 0);
            $this->assertDatabaseCount('wallet_entries', 0);
        }
    }
}
