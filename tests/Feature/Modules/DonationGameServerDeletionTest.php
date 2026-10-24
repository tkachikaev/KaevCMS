<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Livewire\Admin\GameServerManager;
use App\Models\Admin;
use App\Models\GameServer;
use App\Models\User;
use App\Models\UserGameAccount;
use App\Services\Wallet\WalletTransactionService;
use App\Support\Modules\ModuleGameServerDependencyRegistry;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use KaevCMS\Modules\Donations\Services\DonationGameExchange;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RefreshDonationDatabase;
use Tests\TestCase;

final class DonationGameServerDeletionTest extends TestCase
{
    use RefreshDonationDatabase {
        beginDatabaseTransaction as private beginDeletionTestTransaction;
    }

    public function beginDatabaseTransaction(): void
    {
        // MySQL DDL must precede the rollback transaction, just like module migrations.
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->createRetentionFixture();
        }
        $this->beginDeletionTestTransaction();
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->beforeApplicationDestroyed(static fn () => Schema::dropIfExists('test_game_server_retention'));
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('donations');
        app(ModuleRuntime::class)->bootModule($module);
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->createRetentionFixture();
        }
    }

    private function createRetentionFixture(): void
    {
        Schema::create('test_game_server_retention', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_server_id')->constrained('game_servers')->restrictOnDelete();
        });
    }

    public static function moduleStates(): array
    {
        return ['enabled module' => [true], 'disabled module' => [false]];
    }

    /** @return array{Admin, User, GameServer} */
    private function configureExchange(bool $moduleEnabled): array
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Owner]);
        $user = User::factory()->create();
        $server = GameServer::factory()->create();
        $this->actingAs($admin, 'admin');
        app(DonationGameExchange::class)->configure($admin, $server->id, 57, 10, true);
        app(WalletTransactionService::class)->credit($user, 100, 'manual', 'deletion-'.Str::uuid());
        $this->actingAs($user, 'web');
        if (! $moduleEnabled) {
            app(ModuleManager::class)->disable('donations');
            // A fresh request does not boot a disabled module's dependency guards.
            app()->instance(ModuleGameServerDependencyRegistry::class, new ModuleGameServerDependencyRegistry);
        }
        $this->actingAs($admin, 'admin');

        return [$admin, $user, $server];
    }

    #[DataProvider('moduleStates')]
    public function test_unused_rate_is_removed_with_the_server_without_touching_other_rates_or_wallets(bool $enabled): void
    {
        [$admin, $user, $server] = $this->configureExchange($enabled);
        $other = GameServer::factory()->create();
        app(DonationGameExchange::class)->configure($admin, $other->id, 4037, 5, false);

        Livewire::test(GameServerManager::class)
            ->call('confirmDelete', $server->id)->call('deleteServer')
            ->assertSet('confirmingDeleteId', null);

        $this->assertDatabaseMissing('game_servers', ['id' => $server->id]);
        $this->assertDatabaseMissing('module_donation_game_exchange_rates', ['game_server_id' => $server->id]);
        $this->assertDatabaseHas('game_servers', ['id' => $other->id]);
        $this->assertDatabaseHas('module_donation_game_exchange_rates', ['game_server_id' => $other->id, 'items_per_coin' => 5]);
        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance' => 100]);
        $this->assertDatabaseCount('wallet_entries', 1);
        $this->assertDatabaseCount('module_donation_game_exchanges', 0);
    }

    #[DataProvider('moduleStates')]
    public function test_actual_exchange_prevents_deletion_and_preserves_financial_and_reward_history(bool $enabled): void
    {
        [, $user, $server] = $this->configureExchange(true);
        app(DonationGameExchange::class)->exchange($user, $server->id, 2, (string) Str::uuid());
        $this->assertTrue(app(ModuleGameServerDependencyRegistry::class)->blocksDeletion($server));
        if (! $enabled) {
            app(ModuleManager::class)->disable('donations');
            app()->instance(ModuleGameServerDependencyRegistry::class, new ModuleGameServerDependencyRegistry);
        }

        Livewire::test(GameServerManager::class)
            ->call('confirmDelete', $server->id)->call('deleteServer')
            ->assertSet('confirmingDeleteId', $server->id)
            ->assertSet('deleteImpactWarning', __('This game server has rewards, history or other linked module data. Deletion is blocked to preserve these records.'));

        $this->assertDatabaseHas('game_servers', ['id' => $server->id]);
        $this->assertDatabaseHas('module_donation_game_exchange_rates', ['game_server_id' => $server->id]);
        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance' => 98]);
        $this->assertDatabaseCount('wallet_entries', 2);
        $this->assertDatabaseCount('module_donation_game_exchanges', 1);
        $this->assertDatabaseCount('reward_inventory_grants', 1);
        $this->assertDatabaseCount('reward_inventory_items', 1);
    }

    public static function warningLocales(): array
    {
        return [
            ['ru', 'Нельзя удалить сервер: с ним связаны награды, история операций или данные модулей. Удаление заблокировано, чтобы сохранить эти данные.'],
            ['en', 'This game server has rewards, history or other linked module data. Deletion is blocked to preserve these records.'],
        ];
    }

    #[DataProvider('warningLocales')]
    public function test_unregistered_foreign_key_shows_a_localized_warning_and_rolls_back_all_cleanup(string $locale, string $warning): void
    {
        [$admin, $user, $server] = $this->configureExchange(false);
        $admin->update(['locale' => $locale]);
        app()->setLocale($locale);
        GameServer::factory()->create(['login_server_id' => $server->login_server_id]);
        $account = UserGameAccount::factory()->for($user)->registeredOn($server)->create();
        DB::table('test_game_server_retention')->insert(['game_server_id' => $server->id]);

        Livewire::test(GameServerManager::class)
            ->call('confirmDelete', $server->id)->call('deleteServer')
            ->assertSet('confirmingDeleteId', $server->id)
            ->assertSet('deleteImpactWarning', $warning);

        $this->assertDatabaseHas('game_servers', ['id' => $server->id]);
        $this->assertDatabaseHas('module_donation_game_exchange_rates', ['game_server_id' => $server->id]);
        $this->assertDatabaseHas('test_game_server_retention', ['game_server_id' => $server->id]);
        $this->assertSame($server->id, $account->fresh()->registration_game_server_id);
        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance' => 100]);
    }
}
