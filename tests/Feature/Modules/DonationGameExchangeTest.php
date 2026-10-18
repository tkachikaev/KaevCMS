<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\GameServer;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Wallet\WalletTransactionService;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Services\DonationGameExchange;
use KaevCMS\Modules\Donations\Services\DonationYooMoneyCheckout;
use RuntimeException;
use Tests\Concerns\RefreshDonationDatabase;
use Tests\TestCase;

final class DonationGameExchangeTest extends TestCase
{
    use RefreshDonationDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('donations');
        app(ModuleRuntime::class)->bootModule($module);
    }

    /** @return array{Admin,User,GameServer} */
    private function setupExchange(int $rate = 10): array
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Owner]);
        $user = User::factory()->create();
        $server = GameServer::factory()->create();
        $this->actingAs($admin, 'admin');
        app(DonationGameExchange::class)->configure($admin, $server->id, 90000, $rate, true);
        app(WalletTransactionService::class)->credit($user, 100, 'manual', 'test-'.Str::uuid());
        $this->actingAs($user, 'web');

        return [$admin, $user, $server];
    }

    public function test_exchange_debits_and_grants_atomically_and_does_not_depend_on_yoomoney(): void
    {
        [, $user, $server] = $this->setupExchange();
        $uuid = (string) Str::uuid();
        $this->assertFalse(DonationYooMoneyCheckout::enabled());
        $this->actingAs($user)->post('/modules/donations/exchange', [
            'server_id' => $server->id, 'coins' => 5, 'request_uuid' => $uuid,
        ])->assertRedirect('/modules/donations/exchange');
        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance' => 95]);
        $this->assertDatabaseHas('wallet_entries', ['source' => 'donation_exchange', 'direction' => 'debit', 'amount' => 5]);
        $this->assertDatabaseHas('reward_inventory_items', [
            'user_id' => $user->id, 'game_server_id' => $server->id, 'item_id' => 90000, 'amount' => 50, 'status' => 'available',
        ]);
        $this->assertDatabaseHas('module_donation_game_exchanges', [
            'request_uuid' => $uuid, 'user_id' => $user->id, 'coins' => 5,
            'item_id' => 90000, 'items_per_coin' => 10, 'game_amount' => 50,
        ]);
    }

    public function test_player_and_admin_can_open_exchange_pages(): void
    {
        [$admin, $user, $server] = $this->setupExchange();

        $this->actingAs($user)->get('/modules/donations/exchange')
            ->assertOk()->assertSee('data-exchange-form', false)->assertSee($server->name);
        $this->actingAs($admin, 'admin')->get('/admin/extensions/donations/exchange')
            ->assertOk()->assertSee('donations-exchange-settings')->assertSee($server->name);
    }

    public function test_same_request_is_idempotent_and_modified_terms_are_rejected(): void
    {
        [, $user, $server] = $this->setupExchange();
        $uuid = (string) Str::uuid();
        $service = app(DonationGameExchange::class);
        $first = $service->exchange($user, $server->id, 7, $uuid);
        $this->assertSame($first, $service->exchange($user, $server->id, 7, $uuid));
        $this->expectException(InvalidArgumentException::class);
        try {
            $service->exchange($user, $server->id, 8, $uuid);
        } finally {
            $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance' => 93]);
            $this->assertDatabaseCount('module_donation_game_exchanges', 1);
            $this->assertDatabaseCount('reward_inventory_items', 1);
        }
    }

    public function test_existing_exchange_replays_original_terms_after_rate_change(): void
    {
        [$admin, $user, $server] = $this->setupExchange();
        $uuid = (string) Str::uuid();
        $service = app(DonationGameExchange::class);
        $first = $service->exchange($user, $server->id, 5, $uuid);
        $this->actingAs($admin, 'admin');
        $service->configure($admin, $server->id, 90001, 20, true);
        $this->actingAs($user);
        $this->assertSame($first, $service->exchange($user, $server->id, 5, $uuid));
        $this->assertSame(50, $first['game_amount']);
        $this->assertDatabaseCount('module_donation_game_exchanges', 1);
        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance' => 95]);
    }

    public function test_disabled_exchange_and_insufficient_funds_never_debit(): void
    {
        [$admin, $user, $server] = $this->setupExchange();
        $this->actingAs($user)->post('/modules/donations/exchange', [
            'server_id' => $server->id, 'coins' => 101, 'request_uuid' => (string) Str::uuid(),
        ])->assertSessionHasErrors('exchange');
        $this->actingAs($admin, 'admin');
        app(DonationGameExchange::class)->configure($admin, $server->id, 90000, 10, false);
        $this->actingAs($user)->post('/modules/donations/exchange', [
            'server_id' => $server->id, 'coins' => 1, 'request_uuid' => (string) Str::uuid(),
        ])->assertSessionHasErrors('exchange');
        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance' => 100]);
        $this->assertDatabaseCount('module_donation_game_exchanges', 0);
        $this->assertDatabaseCount('reward_inventory_items', 0);
    }

    public function test_another_user_cannot_spend_first_users_wallet(): void
    {
        [, $owner, $server] = $this->setupExchange();
        $other = User::factory()->create();
        $this->actingAs($other);
        $this->expectException(AuthorizationException::class);
        app(DonationGameExchange::class)->exchange($owner, $server->id, 1, (string) Str::uuid());
    }

    public function test_inactive_wallet_and_overflow_are_rejected(): void
    {
        [, $user, $server] = $this->setupExchange();
        $this->actingAs($user)->post('/modules/donations/exchange', [
            'server_id' => $server->id, 'coins' => 1000001, 'request_uuid' => (string) Str::uuid(),
        ])->assertSessionHasErrors('coins');
        $this->assertDatabaseCount('module_donation_game_exchanges', 0);
    }

    public function test_failed_exchange_audit_rolls_back_spent_coins_and_reward(): void
    {
        [, $user, $server] = $this->setupExchange();
        $audit = $this->mock(AuditLogger::class);
        $audit->shouldReceive('success')->andReturnUsing(static function (string $category, string $action): ?AuditLog {
            return $action === 'donations.coins_exchanged' ? null : new AuditLog;
        });
        try {
            app(DonationGameExchange::class)->exchange($user, $server->id, 2, (string) Str::uuid());
            $this->fail('Missing audit should roll back the exchange.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance' => 100]);
            $this->assertDatabaseCount('wallet_entries', 1);
            $this->assertDatabaseCount('reward_inventory_grants', 0);
            $this->assertDatabaseCount('module_donation_game_exchanges', 0);
        }
    }

    public function test_read_only_auditor_cannot_enable_exchange(): void
    {
        [$admin, , $server] = $this->setupExchange();
        $auditor = Admin::factory()->create(['role' => AdminRole::Auditor]);
        $this->actingAs($auditor, 'admin');
        $this->put('/admin/extensions/donations/exchange/'.$server->id, [
            'enabled' => 1, 'item_id' => 90000, 'items_per_coin' => 1,
        ])->assertForbidden();
        $this->assertDatabaseHas('module_donation_game_exchange_rates', [
            'game_server_id' => $server->id, 'items_per_coin' => 10,
        ]);
    }
}
