<?php

namespace Tests\Feature\Admin;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\User;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');
        $user = User::factory()->create();
        foreach (['-1', '0', '1.5', '1e3', '9223372036854775808', '9999999999999999999', ' 5', '05'] as $amount) {
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

    public function test_overdraft_blocked_debit_and_corrupt_history_fail_closed(): void
    {
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');
        $user = User::factory()->create();
        $url = route('admin.wallets.adjust', $user);
        $this->post($url, $this->validAdjustment(['operation' => 'debit']))->assertSessionHasErrors('adjustment');
        $this->post($url, $this->validAdjustment())->assertRedirect();
        DB::table('wallets')->where('user_id', $user->id)->update(['is_blocked' => true]);
        $this->post($url, $this->validAdjustment(['operation' => 'debit']))->assertSessionHasErrors('adjustment');
        DB::table('wallets')->where('user_id', $user->id)->update(['is_blocked' => false, 'balance' => 200]);
        $this->post($url, $this->validAdjustment())->assertSessionHasErrors('adjustment');
        $this->assertDatabaseCount('wallet_entries', 1);
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
     *  @return array<string,string>
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
