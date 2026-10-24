<?php

namespace Tests\Feature\Account;

use App\Models\GameServer;
use App\Models\LoginServer;
use App\Models\User;
use App\Models\UserGameAccount;
use App\Services\Rewards\RewardInventoryService;
use App\Services\SiteSettings;
use App\Support\Rewards\RewardGrantItem;
use App\Support\Themes\AccountThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class KaevClassicAccountThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_theme_is_valid_and_ships_every_account_view_of_the_other_bundled_themes(): void
    {
        $theme = app(AccountThemeManager::class)->inspect('kaev-classic');

        $this->assertTrue($theme['valid']);
        $this->assertTrue($theme['compatible']);

        $views = static fn (string $slug): array => collect(File::allFiles(base_path("account-themes/{$slug}/views")))
            ->map(static fn ($file): string => str_replace('\\', '/', $file->getRelativePathname()))
            ->sort()
            ->values()
            ->all();
        $this->assertSame($views('luxury'), $views('kaev-classic'));
    }

    public function test_layout_restores_the_saved_scheme_before_paint_and_offers_a_named_toggle(): void
    {
        app(AccountThemeManager::class)->activate('kaev-classic');

        $html = (string) $this->actingAs(User::factory()->create())
            ->get('/account')
            ->assertOk()
            ->assertSee('data-account-scheme-toggle', false)
            ->assertSee(__('Switch to the dark theme'))
            ->assertSee('account-themes/kaev-classic/assets/css/app.css', false)
            ->getContent();

        // A deferred script would paint the light page first and then flash to dark.
        $this->assertMatchesRegularExpression('#<script src="[^"]*account-themes/kaev-classic/assets/js/scheme\.js[^"]*" data-navigate-once></script>#', $html);
        $this->assertLessThan(strpos($html, 'assets/css/app.css'), strpos($html, 'assets/js/scheme.js'));

        // The font is fetched before the stylesheet, so text is never drawn twice in two fonts.
        $this->assertLessThan(strpos($html, 'assets/css/app.css'), strpos($html, 'rel="preload" href="'.asset('assets/admin/fonts/inter-variable.woff2').'" as="font"'));
        // The overview art is requested early and revealed only once it has loaded.
        $this->assertStringContainsString('rel="preload" href="'.account_theme_asset('assets/images/banner.webp').'" as="image"', $html);
        $this->assertStringContainsString('data-kc-reveal', $html);
        $this->assertStringContainsString(account_theme_asset('assets/images/banner-dark.webp'), $html);
        $this->assertFileExists(public_path('account-themes/kaev-classic/assets/images/banner-dark.webp'));
        $script = (string) file_get_contents(public_path('account-themes/kaev-classic/assets/js/scheme.js'));
        $this->assertStringContainsString("'kaevcms.account.scheme'", $script);
        $this->assertStringContainsString('root.dataset.accountScheme', $script);
        // Livewire navigation overwrites <html> attributes; the scheme is restored in the swap.
        $this->assertStringContainsString('event.detail?.onSwap?.(() => {', $script);
        $css = (string) file_get_contents(public_path('account-themes/kaev-classic/assets/css/app.css'));
        $this->assertStringContainsString(':root[data-account-scheme="dark"]', $css);
        $this->assertStringContainsString('font-display: block;', $css);
        // Entrance motion stays optional for players who ask the system for reduced motion.
        $this->assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\) \{\s*\.account-content > \*, \.kc-overview > \*/', $css);
    }

    public function test_banner_effects_have_a_localized_control_only_on_the_overview(): void
    {
        app(AccountThemeManager::class)->activate('kaev-classic');
        $this->actingAs(User::factory()->create());

        $this->get('/account')->assertOk()
            ->assertSee('data-kc-banner-particles', false)
            ->assertSee('data-kc-effects-toggle', false)
            ->assertSee(__('Banner effects'))
            ->assertSee(__('Disable banner effects'));
        $this->get('/account/wallet')->assertOk()
            ->assertDontSee('data-kc-banner-particles', false)
            ->assertDontSee('data-kc-effects-toggle', false);
    }

    public function test_sidebar_shows_each_server_status_and_online_only_when_it_is_public(): void
    {
        app(AccountThemeManager::class)->activate('kaev-classic');
        $loginServer = LoginServer::factory()->online()->create();
        GameServer::factory()->online(1248)->create(['name' => 'Interlude x5', 'login_server_id' => $loginServer->id]);
        GameServer::factory()->create(['name' => 'Classic x1', 'login_server_id' => $loginServer->id, 'sort_order' => 2]);
        $user = User::factory()->create();

        app(SiteSettings::class)->setShowPublicOnline(true);
        $this->actingAs($user)->get('/account')
            ->assertOk()
            ->assertSee('data-account-server-status', false)
            ->assertSeeInOrder(['Interlude x5', __('Available'), __('Online').': '.format_number(1248), 'Classic x1', __('Status pending')]);

        app(SiteSettings::class)->setShowPublicOnline(false);
        $this->actingAs($user)->get('/account')
            ->assertOk()
            ->assertSee('Interlude x5')
            ->assertSee(__('Available'))
            ->assertDontSee(__('Online').': '.format_number(1248));
    }

    public function test_overview_shows_what_waits_for_the_player_and_their_game_accounts(): void
    {
        app(AccountThemeManager::class)->activate('kaev-classic');
        $server = GameServer::factory()->create(['name' => 'Interlude x5']);
        $user = User::factory()->create(['name' => 'Artist']);
        UserGameAccount::factory()->for($user)->registeredOn($server)->create(['game_login' => 'artist01']);
        app(RewardInventoryService::class)->grant(
            user: $user,
            server: $server,
            grantKey: 'promo:activation:overview',
            sourceType: 'promo_code',
            items: [new RewardGrantItem(57, 1000000, 'Adena'), new RewardGrantItem(4037, 2, 'Coin of Luck')],
        );

        $this->actingAs($user)->get('/account')
            ->assertOk()
            ->assertSee(__('Hello, :name!', ['name' => 'Artist']))
            ->assertSee(__('Items in the web inventory: :count', ['count' => 2]))
            ->assertSee(__('Transfer to a character'))
            ->assertSeeInOrder([__('My game accounts'), 'artist01', 'Interlude x5'])
            ->assertSee(page_key_url('downloads'), false)
            // Without a visible Donations top-up the overview offers no payment button.
            ->assertDontSee(__('Top up balance'))
            ->assertDontSee(__('Create your first game account'));
    }

    public function test_overview_without_game_accounts_invites_to_create_one_and_waits_for_nothing_else(): void
    {
        app(AccountThemeManager::class)->activate('kaev-classic');

        $this->actingAs(User::factory()->create())->get('/account')
            ->assertOk()
            ->assertSee(__('Create your first game account'))
            ->assertSee(__('Game accounts will appear here after you create one.'))
            ->assertDontSee(__('Transfer to a character'));
    }

    public function test_sidebar_omits_the_status_block_without_game_servers(): void
    {
        app(AccountThemeManager::class)->activate('kaev-classic');
        // Migrations create a default game server; an install may also remove them all.
        GameServer::query()->delete();

        $this->actingAs(User::factory()->create())->get('/account')
            ->assertOk()
            ->assertDontSee('data-account-server-status', false);
    }
}
