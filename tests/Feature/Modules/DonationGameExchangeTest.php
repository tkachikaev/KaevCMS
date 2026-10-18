<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\GameServer;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CmsSettings;
use App\Services\Wallet\WalletTransactionService;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use App\Support\Themes\AccountThemeManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Services\DonationGameExchange;
use KaevCMS\Modules\Donations\Services\DonationSettings;
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

    public function test_dialog_fragment_is_simple_and_wallet_page_has_its_own_exchange_action(): void
    {
        [, $user] = $this->setupExchange();
        foreach (['kaev-aurelia', 'luxury'] as $theme) {
            app(AccountThemeManager::class)->activate($theme);
            $page = $this->actingAs($user)->get('/account/wallet')->assertOk()
                ->assertSee('data-kind="exchange"', false)->assertSee('data-wallet-exchange', false)
                ->assertSee('account-wallet-actions', false);
            $this->assertSame(2, substr_count($page->getContent(), 'data-wallet-exchange'));
        }
        $this->get('/modules/donations/exchange?dialog=1')->assertOk()
            ->assertSee('data-exchange-content', false)->assertSee('Количество монет')
            ->assertSee('data-exchange-inventory', false)->assertDontSee('<html', false)
            ->assertDontSee('data-exchange-summary', false)->assertDontSee('Последние переводы')
            ->assertDontSee('CMS перевести');
    }

    public function test_json_transfer_returns_inventory_and_updated_balance_and_retries_only_once(): void
    {
        [, $user, $server] = $this->setupExchange();
        $payload = ['server_id' => $server->id, 'coins' => 5, 'request_uuid' => (string) Str::uuid()];
        foreach ([1, 2] as $attempt) {
            $this->actingAs($user)->postJson('/modules/donations/exchange', $payload)->assertOk()
                ->assertJsonPath('inventory_url', public_route('web-inventory.index', ['server' => $server->id]))
                ->assertJsonPath('wallet.balance', 95)->assertJsonPath('message', 'Монеты переведены')
                ->assertJsonStructure(['received']);
        }
        $this->assertDatabaseCount('module_donation_game_exchanges', 1);
        $this->assertDatabaseHas('reward_inventory_items', ['game_server_id' => $server->id, 'amount' => 50]);
        $this->get('/account/wallet')->assertOk()->assertSee('Перевод в игру')->assertSee('−5');
    }

    public function test_dialog_transfer_validation_and_insufficient_balance_do_not_debit(): void
    {
        [, $user, $server] = $this->setupExchange();
        $this->actingAs($user)->postJson('/modules/donations/exchange', [
            'server_id' => $server->id, 'coins' => 0, 'request_uuid' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('coins');
        $this->postJson('/modules/donations/exchange', [
            'server_id' => $server->id, 'coins' => 101, 'request_uuid' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonPath('message', __('module-donations::messages.exchange_failed'));
        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance' => 100]);
        $this->assertDatabaseCount('module_donation_game_exchanges', 0);
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

    public function test_all_server_rates_are_saved_together(): void
    {
        [$admin, , $first] = $this->setupExchange();
        $second = GameServer::factory()->create();
        $this->actingAs($admin, 'admin')->put('/admin/extensions/donations/exchange', ['servers' => [
            ['server_id' => $first->id, 'item_id' => 57, 'items_per_coin' => 100, 'enabled' => 1],
            ['server_id' => $second->id, 'item_id' => 4037, 'items_per_coin' => 10, 'enabled' => 0],
        ]])->assertRedirect('/admin/extensions/donations/exchange')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('module_donation_game_exchange_rates', [
            'game_server_id' => $first->id, 'item_id' => 57, 'items_per_coin' => 100, 'enabled' => 1,
        ]);
        $this->assertDatabaseHas('module_donation_game_exchange_rates', [
            'game_server_id' => $second->id, 'item_id' => 4037, 'items_per_coin' => 10, 'enabled' => 0,
        ]);
    }

    public function test_wallet_menu_links_to_exchange_in_both_themes_and_locales_independently_of_topups(): void
    {
        [$admin, $user] = $this->setupExchange();
        foreach ([false, true] as $topUpsVisible) {
            app(DonationSettings::class)->updateVisibility($topUpsVisible, $admin);
            foreach (['kaev-aurelia', 'luxury'] as $theme) {
                app(AccountThemeManager::class)->activate($theme);
                foreach (['ru' => 'Перевести монеты в игру', 'en' => 'Transfer coins to game'] as $locale => $label) {
                    $this->actingAs($user)->get('/'.$locale.'/account/wallet')->assertOk()
                        ->assertSee('data-wallet-exchange', false)->assertSee($label)
                        ->assertSee(route('modules.donations.exchange.index'), false)
                        ->assertSee('data-wallet-journal', false);
                }
            }
        }
        $this->get(route('modules.donations.exchange.index'))->assertOk()->assertSee('data-exchange-form', false);
        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance' => 100]);
        $this->assertDatabaseCount('module_donation_game_exchanges', 0);
    }

    public function test_wallet_menu_hides_exchange_when_all_server_exchanges_are_off(): void
    {
        [$admin, $user, $server] = $this->setupExchange();
        $this->actingAs($user)->get('/account/wallet')->assertOk()->assertSee('data-wallet-exchange', false);
        $this->actingAs($admin, 'admin');
        app(DonationGameExchange::class)->configure($admin, $server->id, 90000, 10, false);
        $this->actingAs($user)->get('/account/wallet')->assertOk()
            ->assertDontSee('data-wallet-exchange', false)->assertSee('data-wallet-journal', false);
    }

    public function test_invalid_second_server_does_not_save_first_and_keeps_input(): void
    {
        [$admin, , $first] = $this->setupExchange();
        $second = GameServer::factory()->create();
        $this->actingAs($admin, 'admin')->from('/admin/extensions/donations/exchange')
            ->put('/admin/extensions/donations/exchange', ['servers' => [
                ['server_id' => $first->id, 'item_id' => 57, 'items_per_coin' => 100, 'enabled' => 1],
                ['server_id' => $second->id, 'item_id' => '', 'items_per_coin' => 10, 'enabled' => 1],
            ]])->assertSessionHasErrors('servers.1.item_id');
        $this->assertSame(57, session()->getOldInput('servers.0.item_id'));
        $this->assertDatabaseHas('module_donation_game_exchange_rates', [
            'game_server_id' => $first->id, 'item_id' => 90000, 'items_per_coin' => 10,
        ]);
        $this->assertDatabaseMissing('module_donation_game_exchange_rates', ['game_server_id' => $second->id]);
    }

    public function test_failure_auditing_second_server_rolls_back_all_settings(): void
    {
        [$admin, , $first] = $this->setupExchange();
        $second = GameServer::factory()->create();
        $writes = 0;
        $this->mock(AuditLogger::class)->shouldReceive('success')->andReturnUsing(
            static function (string $category, string $action) use (&$writes): ?AuditLog {
                return $action === 'donations.exchange_configured' && ++$writes === 2 ? null : new AuditLog;
            }
        );
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($admin, 'admin')->put('/admin/extensions/donations/exchange', ['servers' => [
                ['server_id' => $first->id, 'item_id' => 57, 'items_per_coin' => 100, 'enabled' => 1],
                ['server_id' => $second->id, 'item_id' => 4037, 'items_per_coin' => 10, 'enabled' => 1],
            ]]);
            $this->fail('Missing audit must roll back all servers.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('module_donation_game_exchange_rates', [
                'game_server_id' => $first->id, 'item_id' => 90000, 'items_per_coin' => 10,
            ]);
            $this->assertDatabaseMissing('module_donation_game_exchange_rates', ['game_server_id' => $second->id]);
        }
    }

    public function test_preview_uses_server_icon_over_common_icon_and_is_read_only(): void
    {
        [$admin, , $server] = $this->setupExchange();
        $root = storage_path('framework/testing/exchange-icons-'.Str::uuid());
        config(['cms.game_assets.uploads_path' => $root]);
        File::ensureDirectoryExists($root.'/items/servers/'.$server->id);
        File::ensureDirectoryExists($root.'/items/common');
        File::put($root.'/items/servers/'.$server->id.'/57.png', 'server-icon');
        File::put($root.'/items/common/57.png', 'common-icon');
        try {
            $this->actingAs($admin, 'admin')->getJson('/admin/extensions/donations/exchange/'.$server->id.'/items/57')
                ->assertOk()->assertJsonPath('item_id', 57)
                ->assertJsonPath('icon_url', url('uploads/game-assets/items/servers/'.$server->id.'/57.png'));
            $auditor = Admin::factory()->create(['role' => AdminRole::Auditor]);
            $this->actingAs($auditor, 'admin')->getJson('/admin/extensions/donations/exchange/'.$server->id.'/items/57')->assertOk();
            $this->put('/admin/extensions/donations/exchange', ['servers' => []])->assertForbidden();
            $editor = Admin::factory()->create(['role' => AdminRole::Editor]);
            $this->actingAs($editor, 'admin')->getJson('/admin/extensions/donations/exchange/'.$server->id.'/items/57')->assertForbidden();
            $this->assertDatabaseCount('module_donation_game_exchange_rates', 1);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_form_uses_wallet_branding_and_one_save_for_multiple_servers(): void
    {
        [$admin] = $this->setupExchange();
        GameServer::factory()->create();
        app(CmsSettings::class)->set('wallet.appearance.ru.one', 'жетон');
        $response = $this->actingAs($admin, 'admin')->get('/admin/extensions/donations/exchange')
            ->assertOk()->assertSee('1 жетон')->assertSee('assets/images/wallet-coin.webp')
            ->assertSee('Сохранить настройки')->assertDontSee('Сохранить отображение');
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//form[@data-testid="donations-exchange-settings"]//button[@type="submit"]')->length);
        $this->assertSame(GameServer::query()->count(), $xpath->query('//section[@data-admin-exchange-row]')->length);
    }
}
