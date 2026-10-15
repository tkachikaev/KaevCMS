<?php

namespace Tests\Feature\Admin;

use App\Auth\AdminRole;
use App\Contracts\WalletGateway;
use App\Models\Admin;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Wallet\WalletLedgerReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class WalletAdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_editor_and_auditor_cannot_change_a_wallet(): void
    {
        $user = User::factory()->create();
        $url = route('admin.wallets.adjust', $user);
        $fields = $this->validAdjustment();

        $this->get(route('admin.wallets.index'))->assertRedirect(route('admin.login'));
        $this->post($url, $fields)->assertRedirect(route('admin.login'));

        $editor = Admin::factory()->create(['role' => AdminRole::Editor]);
        $this->actingAs($editor, 'admin')->get(route('admin.wallets.index'))->assertForbidden();
        $this->post($url, $fields)->assertForbidden();

        $auditor = Admin::factory()->create(['role' => AdminRole::Auditor]);
        $this->actingAs($auditor, 'admin')->get(route('admin.wallets.index'))->assertOk();
        $this->get(route('admin.wallets.show', $user))->assertOk()
            ->assertDontSee('admin-wallet-adjustment-form');
        $this->post($url, $fields)->assertForbidden();
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_owner_credits_then_administrator_debits_with_ledger_and_audit(): void
    {
        $user = User::factory()->create(['name' => 'Wallet Recipient']);
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $this->actingAs($owner, 'admin');
        $this->get(route('admin.wallets.index', ['q' => $user->email]))->assertOk()->assertSee('Wallet Recipient');
        $this->get(route('admin.wallets.show', $user))->assertOk()->assertSee('admin-wallet-adjustment-form');
        $this->post(route('admin.wallets.adjust', $user), $this->validAdjustment())
            ->assertRedirect(route('admin.wallets.show', $user))->assertSessionHas('status');

        $administrator = Admin::factory()->create(['role' => AdminRole::Administrator]);
        $this->actingAs($administrator, 'admin');
        $this->post(route('admin.wallets.adjust', $user), $this->validAdjustment([
            'operation' => 'debit', 'amount' => '20', 'reason' => 'Corrected grant',
        ]))->assertRedirect(route('admin.wallets.show', $user));

        $this->assertSame(80, $user->fresh()->wallet->balance);
        $this->assertDatabaseCount('wallet_entries', 2);
        $this->assertDatabaseHas('wallet_entries', [
            'source' => 'manual', 'direction' => 'credit', 'amount' => 100,
            'actor_admin_id' => $owner->id, 'reason' => 'Partner reward',
        ]);
        $this->assertDatabaseHas('wallet_entries', [
            'direction' => 'debit', 'amount' => 20, 'actor_admin_id' => $administrator->id,
        ]);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->get(route('admin.wallets.show', $user))->assertOk()->assertSee('Corrected grant');
    }

    public function test_double_submission_with_identical_operation_key_creates_one_entry(): void
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Owner]);
        $user = User::factory()->create();
        $this->actingAs($admin, 'admin');
        $fields = $this->validAdjustment();
        $url = route('admin.wallets.adjust', $user);

        $this->post($url, $fields)->assertRedirect();
        $this->post($url, $fields)->assertRedirect();
        $this->assertDatabaseCount('wallet_entries', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertSame(100, $user->fresh()->wallet->balance);
    }

    public function test_reused_key_for_different_amount_or_another_user_is_rejected(): void
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Owner]);
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($admin, 'admin');
        $fields = $this->validAdjustment();
        $this->post(route('admin.wallets.adjust', $user), $fields)->assertRedirect();
        $this->post(route('admin.wallets.adjust', $user), array_merge($fields, ['amount' => '999']))
            ->assertSessionHasErrors('adjustment');
        $this->post(route('admin.wallets.adjust', $other), $fields)->assertSessionHasErrors('adjustment');
        $this->assertSame(100, $user->fresh()->wallet->balance);
        $this->assertDatabaseCount('wallet_entries', 1);
    }

    public function test_invalid_amount_reason_operation_or_missing_confirmation_never_writes(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');
        $user = User::factory()->create();
        foreach (['-1', '0', '1.5', '1e3', '9223372036854775808', '9999999999999999999', '05'] as $amount) {
            $this->post(route('admin.wallets.adjust', $user), $this->validAdjustment(['amount' => $amount]))
                ->assertSessionHasErrors('amount');
        }
        $this->post(route('admin.wallets.adjust', $user), $this->validAdjustment(['operation' => 'refund']))
            ->assertSessionHasErrors('operation');
        $this->post(route('admin.wallets.adjust', $user), $this->validAdjustment(['reason' => '']))
            ->assertSessionHasErrors('reason');
        $this->post(route('admin.wallets.adjust', $user), $this->validAdjustment(['confirm_adjustment' => '0']))
            ->assertSessionHasErrors('confirm_adjustment');
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_surrounding_whitespace_is_normalized_before_validating_integer_amount(): void
    {
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');
        $user = User::factory()->create();
        $this->post(route('admin.wallets.adjust', $user), $this->validAdjustment(['amount' => ' 5 ']))
            ->assertRedirect(route('admin.wallets.show', $user))->assertSessionHasNoErrors();
        $this->assertSame(5, $user->fresh()->wallet->balance);
        $this->assertDatabaseHas('wallet_entries', ['amount' => 5]);
    }

    public function test_manual_adjustments_are_rate_limited_without_writing_on_invalid_input(): void
    {
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');
        $user = User::factory()->create();
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post(route('admin.wallets.adjust', $user), $this->validAdjustment(['amount' => '0']))
                ->assertSessionHasErrors('amount');
        }
        $this->post(route('admin.wallets.adjust', $user), $this->validAdjustment())->assertTooManyRequests();
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_wallet_appearance_status_and_adjustments_have_independent_limits(): void
    {
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');
        $user = User::factory()->create();
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->put(route('admin.settings.wallet-appearance.update'), [])
                ->assertSessionHasErrors('translations');
        }
        $this->put(route('admin.settings.wallet-appearance.update'), [])->assertTooManyRequests();
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post(route('admin.wallets.status', $user), [])
                ->assertSessionHasErrors('action');
        }
        $this->post(route('admin.wallets.status', $user), [])->assertTooManyRequests();
        $this->post(route('admin.wallets.adjust', $user), $this->validAdjustment())
            ->assertRedirect(route('admin.wallets.show', $user))->assertSessionHasNoErrors();
        $this->assertSame(100, $user->fresh()->wallet->balance);
        $this->assertDatabaseCount('wallet_entries', 1);
        $this->assertDatabaseCount('wallet_status_events', 0);
    }

    public function test_wallet_adjustment_limit_uses_admin_identity_even_when_a_player_is_also_signed_in(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $administrator = Admin::factory()->create(['role' => AdminRole::Administrator]);
        $player = User::factory()->create();
        $url = route('admin.wallets.adjust', $player);
        $this->actingAs($owner, 'admin')->actingAs($player, 'web');
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post($url, $this->validAdjustment(['amount' => '0']))->assertSessionHasErrors('amount');
        }
        $this->post($url, $this->validAdjustment())->assertTooManyRequests();

        $this->actingAs($administrator, 'admin')->actingAs($player, 'web');
        $this->post($url, $this->validAdjustment())
            ->assertRedirect(route('admin.wallets.show', $player))->assertSessionHasNoErrors();
        $this->assertSame(100, $player->fresh()->wallet->balance);
        $this->assertDatabaseCount('wallet_entries', 1);
        $this->assertDatabaseHas('wallet_entries', ['actor_admin_id' => $administrator->id]);
        $this->actingAs($owner, 'admin')->actingAs($player, 'web');
        $this->post($url, $this->validAdjustment())->assertTooManyRequests();
    }

    public function test_overdraft_and_corrupt_history_fail_closed_even_for_blocked_manual_debits(): void
    {
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');
        $user = User::factory()->create();
        $url = route('admin.wallets.adjust', $user);
        $this->post($url, $this->validAdjustment(['operation' => 'debit']))->assertSessionHasErrors('adjustment');
        $this->post($url, $this->validAdjustment())->assertRedirect();
        DB::table('wallets')->where('user_id', $user->id)->update(['is_blocked' => true]);
        $this->post($url, $this->validAdjustment(['operation' => 'debit', 'amount' => '101']))->assertSessionHasErrors('adjustment');
        DB::table('wallets')->where('user_id', $user->id)->update(['balance' => 200]);
        $this->post($url, $this->validAdjustment(['operation' => 'debit']))->assertSessionHasErrors('adjustment');
        $this->post($url, $this->validAdjustment())->assertSessionHasErrors('adjustment');
        $this->assertSame(200, $user->fresh()->wallet->balance);
        $this->assertTrue($user->fresh()->wallet->is_blocked);
        $this->assertDatabaseCount('wallet_entries', 1);
    }

    public function test_administrator_can_correct_a_frozen_wallet_without_unblocking_or_double_debiting(): void
    {
        $user = User::factory()->create();
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $this->actingAs($owner, 'admin');
        $gateway = app(WalletGateway::class);
        $gateway->manualCredit($owner, $user, 100, 'initial-credit', 'Initial reward');
        $gateway->changeBlockStatus($owner, $user, true, 'payment-dispute', 'Investigating a payment dispute');

        $administrator = Admin::factory()->create(['role' => AdminRole::Administrator]);
        $this->actingAs($administrator, 'admin');
        $fields = $this->validAdjustment(['operation' => 'debit', 'amount' => '40', 'reason' => 'Confirmed payment reversal']);
        $url = route('admin.wallets.adjust', $user);
        $this->post($url, $fields)->assertRedirect(route('admin.wallets.show', $user))->assertSessionHasNoErrors();
        $this->post($url, $fields)->assertRedirect(route('admin.wallets.show', $user))->assertSessionHasNoErrors();

        $wallet = $user->fresh()->wallet;
        $this->assertTrue($wallet->is_blocked);
        $this->assertSame(60, $wallet->balance);
        $this->assertDatabaseCount('wallet_entries', 2);
        $this->assertDatabaseCount('wallet_status_events', 1);
        $this->assertDatabaseCount('audit_logs', 3);
        $this->assertDatabaseHas('wallet_entries', [
            'source' => 'manual', 'direction' => 'debit', 'amount' => 40,
            'balance_before' => 100, 'balance_after' => 60,
            'actor_admin_id' => $administrator->id, 'reason' => 'Confirmed payment reversal',
        ]);
        $this->assertTrue(app(WalletLedgerReconciler::class)->check($wallet->id)->valid);
        $this->post($url, array_merge($fields, ['amount' => '41']))->assertSessionHasErrors('adjustment');
        $this->assertSame(60, $user->fresh()->wallet->balance);
    }

    public function test_frozen_manual_debit_rolls_back_if_audit_cannot_be_saved(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create(['role' => AdminRole::Owner]);
        $this->actingAs($admin, 'admin');
        $gateway = app(WalletGateway::class);
        $gateway->manualCredit($admin, $user, 100, 'initial-credit', 'Initial reward');
        $gateway->changeBlockStatus($admin, $user, true, 'payment-dispute', 'Investigating a payment dispute');

        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('success')->once()->andReturnNull();
        app()->instance(AuditLogger::class, $logger);
        $this->post(route('admin.wallets.adjust', $user), $this->validAdjustment([
            'operation' => 'debit', 'amount' => '40',
        ]))->assertSessionHasErrors('adjustment');

        $this->assertSame(100, $user->fresh()->wallet->balance);
        $this->assertTrue($user->fresh()->wallet->is_blocked);
        $this->assertDatabaseCount('wallet_entries', 1);
        $this->assertDatabaseCount('wallet_status_events', 1);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_wallet_list_and_history_never_mix_player_rows(): void
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Owner]);
        $first = User::factory()->create(['name' => 'First Wallet User']);
        $second = User::factory()->create(['name' => 'Second Wallet User']);
        $this->actingAs($admin, 'admin');
        $this->post(route('admin.wallets.adjust', $first), $this->validAdjustment())->assertRedirect();
        $this->post(route('admin.wallets.adjust', $second), $this->validAdjustment(['amount' => '77', 'reason' => 'Other account']))->assertRedirect();
        $this->get(route('admin.wallets.index', ['q' => 'First Wallet User']))
            ->assertOk()->assertSee('First Wallet User')->assertDontSee('Second Wallet User');
        $this->get(route('admin.wallets.show', $first))->assertOk()
            ->assertSee('Partner reward')->assertDontSee('Other account');
        $this->get(route('admin.wallets.show', $second))->assertOk()
            ->assertSee('Other account')->assertDontSee('Partner reward');
    }

    /** @param array<string,string> $override
     * @return array<string,string>
     */
    private function validAdjustment(array $override = []): array
    {
        return array_merge([
            'operation' => 'credit',
            'amount' => '100',
            'reason' => 'Partner reward',
            'operation_key' => (string) Str::uuid(),
            'confirm_adjustment' => '1',
        ], $override);
    }
}
