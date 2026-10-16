<?php

namespace Tests\Feature\Account;

use App\Models\User;
use App\Services\CmsSettings;
use App\Services\Wallet\WalletTransactionService;
use App\Support\Themes\AccountThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WalletPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_read_wallet_or_localized_history(): void
    {
        $this->get('/account/wallet')->assertRedirect(route('login'));
        $this->get('/ru/account/wallet')->assertRedirect(route('login'));
    }

    public function test_new_player_sees_zero_balance_and_empty_history_without_creating_wallet(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/account/wallet')
            ->assertOk()
            ->assertSee(__('My wallet'))
            ->assertSee(__('No wallet transactions yet'))
            ->assertSee('0 ', false)
            ->assertDontSee(__('Top up balance'))
            ->assertDontSee('Not connected');

        $this->assertDatabaseCount('wallets', 0);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_player_only_reads_own_operations_and_private_engine_data_is_not_leaked(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $engine = app(WalletTransactionService::class);
        $engine->credit($owner, 321, 'manual', 'secret-key-a', 'invoice-private-a', 'internal reason that must not show');
        $engine->debit($owner, 21, 'shop', 'secret-key-b', 'invoice-private-b', 'internal shop details');
        $engine->credit($other, 99999, 'manual', 'secret-key-c', 'invoice-other', 'other user secret');

        $page = $this->actingAs($owner)->get('/account/wallet');
        $page->assertOk()->assertSee('300')->assertSee('+321')->assertSee('−21')
            ->assertDontSee('99999')->assertDontSee('other user secret')
            ->assertDontSee('secret-key-a')->assertDontSee('invoice-private-a')
            ->assertDontSee('internal reason that must not show')
            ->assertDontSee('invoice-other');

        // A crafted wallet ID or user ID never changes the authenticated owner.
        $this->actingAs($owner)->get('/account/wallet?user_id='.$other->id.'&wallet_id='.$other->wallet->id)
            ->assertOk()->assertDontSee('99999');
    }

    public function test_wallet_history_is_paginated_at_twenty_and_latest_first(): void
    {
        $owner = User::factory()->create();
        $engine = app(WalletTransactionService::class);
        for ($i = 1; $i <= 24; $i++) {
            $engine->credit($owner, $i, 'manual', 'batch-'.$i);
        }

        $this->actingAs($owner)->get('/account/wallet?page=1')
            ->assertOk()->assertSee('Страница 1 из 2')->assertSee('+24')->assertDontSee('>+1<', false);
        $this->actingAs($owner)->get('/account/wallet?page=2')
            ->assertOk()->assertSee('Страница 2 из 2')->assertSee('>+1<', false)->assertDontSee('>+24<', false);
    }

    public function test_custom_coin_name_icon_and_blocked_state_are_shown_without_payment_controls(): void
    {
        $owner = User::factory()->create(['locale' => 'ru']);
        app(CmsSettings::class)->setMany([
            'wallet.appearance.ru.one' => 'дублон',
            'wallet.appearance.ru.few' => 'дублона',
            'wallet.appearance.ru.many' => 'дублонов',
            'wallet.appearance.icon' => 'settings/wallet-coin/123e4567-e89b-12d3-a456-426614174000.png',
        ]);
        // The icon URL is derived by the official image path validator, never from a request value.
        app(WalletTransactionService::class)->credit($owner, 100, 'manual', 'gift-1');
        DB::table('wallets')->where('user_id', $owner->id)->update(['is_blocked' => 1]);

        $this->actingAs($owner)->get('/ru/account/wallet')
            ->assertOk()->assertSee('100 дублонов')->assertSee(__('Wallet spending suspended'))
            ->assertSee('wallet-coin/123e4567-e89b-12d3-a456-426614174000.png', false)
            ->assertDontSee('name="coin_count"', false)
            ->assertDontSee('name="payment_provider"', false);
    }

    public function test_wallet_link_and_real_balance_appear_on_both_account_themes(): void
    {
        $owner = User::factory()->create();
        app(WalletTransactionService::class)->credit($owner, 75, 'manual', 'gift-2');
        foreach (['kaev-aurelia', 'luxury'] as $theme) {
            app(CmsSettings::class)->set('account_theme.active', $theme);
            app(AccountThemeManager::class)->activate($theme);

            $this->actingAs($owner)->get('/account')->assertOk()
                ->assertSee('/account/wallet', false)
                ->assertSee('account-topbar-wallet', false)
                ->assertSee('75 ', false)
                ->assertDontSee('Not connected');
            $this->actingAs($owner)->get('/account/wallet')->assertOk()
                ->assertSee('account-wallet-summary', false)
                ->assertSee('account-wallet-entry', false);
        }
    }
}
