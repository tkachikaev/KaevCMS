<?php

namespace Tests\Feature\Wallet;

use App\Auth\AdminRole;
use App\Contracts\WalletGateway;
use App\Exceptions\WalletOperationException;
use App\Models\Admin;
use App\Models\User;
use App\Services\Wallet\WalletSource;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class WalletGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_uninstalled_module_sources_are_denied_without_writes(): void
    {
        $user = User::factory()->create();
        $gateway = app(WalletGateway::class);
        foreach ([WalletSource::PromoCodes, WalletSource::DailyRewards, WalletSource::ShopRefund] as $source) {
            try {
                $gateway->creditFromModule($source, $user, 100, 'payment-001', 'verified-reference');
                $this->fail('An uninstalled module must not be able to write to wallets.');
            } catch (AuthorizationException) {
                // Non-installed module sources remain disabled.
            }
        }
        $this->assertDatabaseCount('wallet_entries', 0);
        $this->assertDatabaseCount('wallets', 0);
    }

    public function test_registered_trusted_source_can_credit_once_but_cannot_debit_or_impersonate_another_source(): void
    {
        $user = User::factory()->create();
        config()->set('wallet.enabled_sources.donations', true);
        $gateway = app(WalletGateway::class);
        $first = $gateway->creditFromModule(WalletSource::Donations, $user, 100, 'order-123', 'verified-order-123');
        $again = $gateway->creditFromModule(WalletSource::Donations, $user, 100, 'order-123', 'verified-order-123');
        $this->assertSame($first->id, $again->id);
        $this->assertSame(100, $user->fresh()->wallet->balance);
        $this->assertDatabaseCount('wallet_entries', 1);

        foreach ([WalletSource::Manual, WalletSource::Shop] as $disallowed) {
            try {
                $gateway->creditFromModule($disallowed, $user, 100, 'order-456', 'attempt');
                $this->fail('Forbidden credit direction must be rejected.');
            } catch (AuthorizationException) {
                // Expected.
            }
        }
    }

    public function test_shop_debit_requires_authenticated_owner_and_enabled_shop(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin');
        app(WalletGateway::class)->manualCredit($admin, $owner, 100, 'gift-a', 'Partner credit');
        $this->actingAs($other, 'web');
        config()->set('wallet.enabled_sources.shop', true);
        try {
            app(WalletGateway::class)->debitForOwner($owner, 10, 'order1', 'shop-order-1');
            $this->fail('One user must not debit another user.');
        } catch (AuthorizationException) {
            // Expected.
        }

        $this->actingAs($owner, 'web');
        config()->set('wallet.enabled_sources.shop', false);
        try {
            app(WalletGateway::class)->debitForOwner($owner, 10, 'order1', 'shop-order-1');
            $this->fail('Uninstalled shop must not spend coins.');
        } catch (AuthorizationException) {
            // Expected.
        }
        config()->set('wallet.enabled_sources.shop', true);
        $entry = app(WalletGateway::class)->debitForOwner($owner, 10, 'order1', 'shop-order-1');
        $this->assertSame(90, $entry->balance_after);
        $this->assertSame($owner->id, $entry->actor_user_id);
        $this->assertSame(90, $owner->fresh()->wallet->balance);
    }

    public function test_only_signed_in_active_owner_or_administrator_may_adjust_balances(): void
    {
        $recipient = User::factory()->create();
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $administrator = Admin::factory()->create(['role' => AdminRole::Administrator]);
        $auditor = Admin::factory()->create(['role' => AdminRole::Auditor]);
        $editor = Admin::factory()->create(['role' => AdminRole::Editor]);
        $gateway = app(WalletGateway::class);

        $this->actingAs($owner, 'admin');
        $gateway->manualCredit($owner, $recipient, 100, 'owner-1', 'Initial reward');
        $this->actingAs($administrator, 'admin');
        $gateway->manualDebit($administrator, $recipient, 25, 'admin-1', 'Manual adjustment');
        $this->assertSame(75, $recipient->fresh()->wallet->balance);

        foreach ([$auditor, $editor] as $staff) {
            $this->actingAs($staff, 'admin');
            try {
                $gateway->manualCredit($staff, $recipient, 1, 'attempt-'.$staff->id, 'Should fail');
                $this->fail('Read-only or editor account cannot grant coins.');
            } catch (AuthorizationException) {
                // Expected.
            }
        }
        $this->actingAs($owner, 'admin');
        try {
            $gateway->manualCredit($administrator, $recipient, 1, 'forged-admin', 'Forged actor');
            $this->fail('An administrator cannot impersonate a different signed-in admin.');
        } catch (AuthorizationException) {
            // Expected.
        }
        DB::table('admins')->where('id', $owner->id)->update(['is_active' => false]);
        try {
            $gateway->manualCredit($owner, $recipient, 1, 'inactive-admin', 'Inactive actor');
            $this->fail('The stored administrator must be active.');
        } catch (AuthorizationException) {
            // Expected.
        }
        $this->assertDatabaseCount('wallet_entries', 2);
    }

    public function test_manual_correction_does_not_allow_shop_spending_from_a_blocked_wallet(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create(['role' => AdminRole::Owner]);
        $this->actingAs($admin, 'admin');
        $gateway = app(WalletGateway::class);
        $gateway->manualCredit($admin, $user, 100, 'initial', 'Initial grant');
        $gateway->changeBlockStatus($admin, $user, true, 'dispute', 'Payment dispute');
        $gateway->manualDebit($admin, $user, 40, 'reversal', 'Confirmed payment reversal');
        $this->actingAs($user, 'web');
        config()->set('wallet.enabled_sources.shop', true);

        try {
            $gateway->debitForOwner($user, 1, 'shop-order', 'Shop order');
            $this->fail('A manual correction must never enable player spending.');
        } catch (WalletOperationException $exception) {
            $this->assertSame('wallet_blocked', $exception->reason);
        }
        $this->assertTrue($user->fresh()->wallet->is_blocked);
        $this->assertSame(60, $user->fresh()->wallet->balance);
        $this->assertDatabaseCount('wallet_entries', 2);
    }

    public function test_blocked_manual_debit_still_requires_current_active_wallet_manager(): void
    {
        $user = User::factory()->create();
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $this->actingAs($owner, 'admin');
        $gateway = app(WalletGateway::class);
        $gateway->manualCredit($owner, $user, 100, 'initial', 'Initial grant');
        $gateway->changeBlockStatus($owner, $user, true, 'dispute', 'Payment dispute');

        foreach ([AdminRole::Auditor, AdminRole::Editor] as $role) {
            $staff = Admin::factory()->create(['role' => $role]);
            $this->actingAs($staff, 'admin');
            try {
                $gateway->manualDebit($staff, $user, 10, 'forbidden-'.$staff->id, 'Unauthorized correction');
                $this->fail('A blocked wallet does not relax administrator permissions.');
            } catch (AuthorizationException) {
                // Expected.
            }
        }
        $this->actingAs($owner, 'admin');
        DB::table('admins')->where('id', $owner->id)->update(['is_active' => false]);
        try {
            $gateway->manualDebit($owner, $user, 10, 'disabled-admin', 'Unauthorized correction');
            $this->fail('The stored administrator must still be active.');
        } catch (AuthorizationException) {
            // Expected.
        }
        $this->assertSame(100, $user->fresh()->wallet->balance);
        $this->assertTrue($user->fresh()->wallet->is_blocked);
        $this->assertDatabaseCount('wallet_entries', 1);
    }

    public function test_manual_adjustments_require_nonempty_reason_and_cannot_accept_unbounded_text(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin');
        foreach ([' ', str_repeat('a', 191)] as $reason) {
            try {
                app(WalletGateway::class)->manualCredit($admin, $user, 1, 'manual-key', $reason);
                $this->fail('Missing or excessive reason must be rejected.');
            } catch (InvalidArgumentException) {
                // Expected.
            }
        }
        $this->assertDatabaseCount('wallet_entries', 0);
    }
}
