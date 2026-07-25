<?php

namespace Tests\Feature\Account;

use App\Contracts\GameAccountGateway;
use App\Livewire\Account\CharacterDirectory;
use App\Models\AuditLog;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Models\User;
use App\Models\UserCharacterPreference;
use App\Models\UserGameAccount;
use App\Services\GameAccounts\AccountCharacterDirectory;
use App\Services\GameAccountSettings;
use App\Services\GameServerDeletionImpact;
use App\Services\GameServerSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithServerFixtures;
use Tests\Fakes\FakeGameAccountGateway;
use Tests\Fakes\RaceInjectingGameAccountGateway;
use Tests\TestCase;

class GameAccountCabinetTest extends TestCase
{
    use InteractsWithServerFixtures, RefreshDatabase;

    private FakeGameAccountGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeGameAccountGateway;
        $this->app->instance(GameAccountGateway::class, $this->gateway);
    }

    public function test_player_account_routes_require_authentication(): void
    {
        $this->get('/account')->assertRedirect(route('login'));
        $this->get('/account/characters')->assertRedirect(route('login'));
        $this->get('/account/game-accounts/create')->assertRedirect(route('login'));
    }

    public function test_character_page_shows_all_characters_without_opening_game_accounts(): void
    {
        $user = $this->user();
        [$loginServer, $gameServer] = $this->servers();
        $account = UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'PlayerOne',
            'normalized_login' => 'playerone',
        ]);
        $this->gateway->charactersByServer[$gameServer->id] = [[
            'id' => 100,
            'name' => 'Bubi',
            'level' => 78,
            'class_id' => 88,
            'online' => true,
            'clan' => 'L2Forge',
            'last_access' => 0,
            'created_at' => CarbonImmutable::parse('2024-04-05'),
        ]];

        $this->actingAs($user)
            ->get('/account/characters')
            ->assertOk()
            ->assertSee('Мои персонажи')
            ->assertSee('По серверам')
            ->assertSee('Все персонажи')
            ->assertSee('PlayerOne')
            ->assertSee('Bubi')
            ->assertSee('Interlude x10');
    }

    public function test_player_character_directory_uses_the_resolved_avatar_when_a_file_exists(): void
    {
        $root = storage_path('framework/testing/character-assets-'.Str::uuid());
        config()->set('cms.game_assets.uploads_path', $root);

        try {
            File::ensureDirectoryExists($root.'/characters/common/human/female');
            File::put($root.'/characters/common/human/female/mage.webp', 'avatar');

            $user = $this->user();
            [$loginServer, $gameServer] = $this->servers();
            UserGameAccount::query()->create([
                'user_id' => $user->id,
                'login_server_id' => $loginServer->id,
                'registration_game_server_id' => $gameServer->id,
                'game_login' => 'AvatarMage01',
                'normalized_login' => 'avatarmage01',
            ]);
            $this->gateway->charactersByServer[$gameServer->id] = [[
                'id' => 101,
                'name' => 'Aurelia',
                'level' => 80,
                'class_id' => 10,
                'race' => 0,
                'gender' => 1,
                'online' => true,
            ]];

            $this->actingAs($user)
                ->get('/account/characters')
                ->assertOk()
                ->assertSee('data-character-avatar', false)
                ->assertSee('data-character-race="human"', false)
                ->assertSee('data-character-gender="female"', false)
                ->assertSee('data-character-archetype="mage"', false)
                ->assertSee('/uploads/game-assets/characters/common/human/female/mage.webp', false);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_localized_character_page_keeps_the_character_directory(): void
    {
        $user = $this->user();
        [$loginServer, $gameServer] = $this->servers();
        $account = UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'LocalizedOne',
            'normalized_login' => 'localizedone',
        ]);

        $this->actingAs($user)
            ->get('/ru/account/characters')
            ->assertOk()
            ->assertSee('LocalizedOne')
            ->assertSee('По серверам')
            ->assertSee('Все персонажи');
    }

    public function test_player_overview_stays_compact_when_no_game_accounts_exist(): void
    {
        $user = $this->user();
        $this->servers();

        $this->actingAs($user)
            ->get('/account')
            ->assertOk()
            ->assertSee('Выберите раздел')
            ->assertSee('Создать игровой аккаунт')
            ->assertDontSee('account-character-directory', false);
    }

    public function test_player_overview_does_not_mix_game_account_or_character_details_into_the_profile_summary(): void
    {
        $user = $this->user();
        [$loginServer, $gameServer] = $this->servers();
        UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'SeparateLogin01',
            'normalized_login' => 'separatelogin01',
        ]);
        $this->gateway->charactersByServer[$gameServer->id] = [[
            'id' => 105,
            'name' => 'SeparateHero',
            'level' => 80,
            'class_id' => 88,
            'online' => false,
        ]];

        $this->actingAs($user)
            ->get('/account')
            ->assertOk()
            ->assertSee('Открыть персонажей')
            ->assertDontSee('SeparateLogin01')
            ->assertDontSee('SeparateHero')
            ->assertDontSee('account-character-directory', false);
    }

    public function test_single_account_details_keep_the_create_action_when_another_account_is_allowed(): void
    {
        $user = $this->user();
        [$loginServer, $gameServer] = $this->servers();
        $this->settings(['max_accounts' => 2]);
        $account = UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'Expandable01',
            'normalized_login' => 'expandable01',
        ]);

        $this->actingAs($user)
            ->get('/account/game-accounts/'.$account->id)
            ->assertOk()
            ->assertSee('Создать игровой аккаунт')
            ->assertDontSee('← Мои аккаунты');
    }

    public function test_player_sees_game_servers_instead_of_the_login_server_name(): void
    {
        $user = $this->user();
        [$loginServer, $gameServer] = $this->servers();
        $secondGameServer = GameServer::query()->create([
            'name' => 'Interlude x50',
            'rates' => 'x50',
            'chronicle' => 'Interlude',
            'mode' => 'PvP',
            'sort_order' => 2,
            'login_server_id' => $loginServer->id,
            'driver' => 'l2j_mobius_ct0_interlude',
            'use_login_server_connection' => true,
        ]);
        $account = UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'MultiWorld01',
            'normalized_login' => 'multiworld01',
        ]);
        UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'SecondWorld02',
            'normalized_login' => 'secondworld02',
        ]);

        $this->actingAs($user)
            ->get('/account/game-accounts')
            ->assertOk()
            ->assertSee('Серверы')
            ->assertSee($gameServer->name)
            ->assertSee($secondGameServer->name)
            ->assertDontSee($loginServer->name);

        $this->actingAs($user)
            ->get('/account/game-accounts/'.$account->id)
            ->assertOk()
            ->assertSee('Серверы')
            ->assertSee($gameServer->name)
            ->assertSee($secondGameServer->name)
            ->assertDontSee($loginServer->name);
    }

    public function test_game_account_forms_render_floating_validation_errors_for_matching_fields(): void
    {
        $user = $this->user();
        [$loginServer, $gameServer] = $this->servers();

        $this->actingAs($user)->post('/account/game-accounts', [
            'game_server_id' => '',
            'game_login' => '',
            'game_password' => 'NewStrong1',
            'game_password_confirmation' => 'Different1',
        ])->assertSessionHasErrors([
            'game_server_id',
            'game_login',
            'game_password_confirmation',
        ]);

        $this->actingAs($user)
            ->get('/account/game-accounts/create')
            ->assertOk()
            ->assertSee('game-server-error', false)
            ->assertSee('game-login-error', false)
            ->assertSee('game-password-confirmation-error', false)
            ->assertSee('class="account-field-control"', false)
            ->assertSee('role="alert"', false)
            ->assertDontSee('<div class="account-notice error"', false);

        $account = UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'InlineErrors01',
            'normalized_login' => 'inlineerrors01',
        ]);

        $this->actingAs($user)->put('/account/game-accounts/'.$account->id.'/password', [
            'current_password' => '',
            'game_password' => 'NewStrong1',
            'game_password_confirmation' => 'Different1',
        ])->assertSessionHasErrors([
            'current_password',
            'game_password_confirmation',
        ]);

        $this->actingAs($user)
            ->get('/account/game-accounts/'.$account->id)
            ->assertOk()
            ->assertSee('current-password-error', false)
            ->assertSee('new-game-password-confirmation-error', false)
            ->assertSee('class="account-field-control"', false)
            ->assertSee('role="alert"', false)
            ->assertDontSee('<div class="account-notice error"', false);

        $accountCss = file_get_contents(public_path('account-themes/luxury/assets/css/app.css'));
        $this->assertIsString($accountCss);
        $this->assertStringContainsString('.account-field-control>.account-field-error{position:absolute', $accountCss);
    }

    public function test_player_can_create_a_mobius_account_from_a_selected_game_server(): void
    {
        $user = $this->user();
        [, $gameServer] = $this->servers();

        $this->actingAs($user)->post('/account/game-accounts', [
            'game_server_id' => $gameServer->id,
            'game_login' => 'Player01',
            'game_password' => 'StrongPass1',
            'game_password_confirmation' => 'StrongPass1',
        ])->assertRedirect();

        $link = UserGameAccount::query()->firstOrFail();
        $this->assertSame($user->id, $link->user_id);
        $this->assertSame('Player01', $link->game_login);
        $this->assertSame('player01', $link->normalized_login);
        $this->assertSame($gameServer->id, $link->registration_game_server_id);
        $this->assertSame('StrongPass1', $this->gateway->created[0]['password']);
    }

    public function test_localized_creation_redirect_and_account_actions_resolve_route_parameters(): void
    {
        $user = $this->user();
        [, $gameServer] = $this->servers();

        $response = $this->actingAs($user)->post('/ru/account/game-accounts', [
            'game_server_id' => $gameServer->id,
            'game_login' => 'Localized01',
            'game_password' => 'StrongPass1',
            'game_password_confirmation' => 'StrongPass1',
        ]);

        $account = UserGameAccount::query()->firstOrFail();

        $response->assertRedirect(route('localized.game-accounts.show', [
            'locale' => 'ru',
            'gameAccount' => $account,
        ]));

        $this->actingAs($user)
            ->get('/ru/account/game-accounts/'.$account->id)
            ->assertOk()
            ->assertSee('Localized01');

        $this->actingAs($user)->put('/ru/account/game-accounts/'.$account->id.'/password', [
            'current_password' => 'Password123',
            'game_password' => 'NewStrong1',
            'game_password_confirmation' => 'NewStrong1',
        ])->assertSessionHas('status');

        $this->assertSame('NewStrong1', $this->gateway->passwordChanges[0]['password']);
    }

    public function test_disabled_game_account_creation_is_rejected(): void
    {
        $user = $this->user();
        [, $gameServer] = $this->servers();
        $this->settings(['enabled' => false]);

        $this->actingAs($user)->post('/account/game-accounts', [
            'game_server_id' => $gameServer->id,
            'game_login' => 'Disabled01',
            'game_password' => 'StrongPass1',
            'game_password_confirmation' => 'StrongPass1',
        ])->assertSessionHasErrors('game_login');

        $this->assertDatabaseCount('user_game_accounts', 0);
        $this->assertSame([], $this->gateway->created);
    }

    public function test_existing_login_server_account_cannot_be_created_again(): void
    {
        $user = $this->user();
        [$loginServer, $gameServer] = $this->servers();
        $this->gateway->existing[$loginServer->id.':player01'] = true;

        $this->actingAs($user)->post('/account/game-accounts', [
            'game_server_id' => $gameServer->id,
            'game_login' => 'Player01',
            'game_password' => 'StrongPass1',
            'game_password_confirmation' => 'StrongPass1',
        ])->assertSessionHasErrors('game_login');

        $this->assertDatabaseCount('user_game_accounts', 0);
    }

    public function test_external_creation_failure_rolls_back_the_local_link(): void
    {
        $user = $this->user();
        [, $gameServer] = $this->servers();
        $this->gateway->failCreate = true;

        $this->actingAs($user)->post('/account/game-accounts', [
            'game_server_id' => $gameServer->id,
            'game_login' => 'Failed01',
            'game_password' => 'StrongPass1',
            'game_password_confirmation' => 'StrongPass1',
        ])->assertSessionHasErrors('game_login')
            ->assertSessionMissingInput(['game_password', 'game_password_confirmation']);

        $this->assertDatabaseCount('user_game_accounts', 0);
        $audit = AuditLog::query()->where('action', 'user.game_account_creation_failed')->firstOrFail();
        $this->assertStringNotContainsString('StrongPass1', json_encode($audit->details, JSON_THROW_ON_ERROR));
    }

    public function test_game_login_and_password_follow_the_admin_policy(): void
    {
        $user = $this->user();
        [, $gameServer] = $this->servers();
        app(GameAccountSettings::class)->update([
            'enabled' => true,
            'max_accounts' => 10,
            'login_min' => 6,
            'login_max' => 12,
            'login_digit' => true,
            'password_min' => 10,
            'password_max' => 20,
            'password_lower' => true,
            'password_upper' => true,
            'password_digit' => true,
        ]);

        $this->actingAs($user)->post('/account/game-accounts', [
            'game_server_id' => $gameServer->id,
            'game_login' => 'player',
            'game_password' => 'weakpass',
            'game_password_confirmation' => 'weakpass',
        ])->assertSessionHasErrors(['game_login', 'game_password'])
            ->assertSessionMissingInput(['game_password', 'game_password_confirmation']);

        $this->assertDatabaseCount('user_game_accounts', 0);
        $this->assertSame([], $this->gateway->created);
    }

    public function test_game_password_rejects_non_ascii_and_special_characters(): void
    {
        $user = $this->user();
        [, $gameServer] = $this->servers();

        foreach (['StrongПароль1', 'StrongPass!1', 'Strong Pass1'] as $password) {
            $this->actingAs($user)->post('/account/game-accounts', [
                'game_server_id' => $gameServer->id,
                'game_login' => 'Player01',
                'game_password' => $password,
                'game_password_confirmation' => $password,
            ])->assertSessionHasErrors('game_password')
                ->assertSessionMissingInput(['game_password', 'game_password_confirmation']);
        }

        $this->assertDatabaseCount('user_game_accounts', 0);
        $this->assertSame([], $this->gateway->created);
    }

    public function test_global_account_limit_is_enforced(): void
    {
        $user = $this->user();
        [$loginServer, $gameServer] = $this->servers();
        app(GameAccountSettings::class)->update([
            'enabled' => true,
            'max_accounts' => 1,
            'login_min' => 4,
            'login_max' => 16,
            'login_digit' => false,
            'password_min' => 8,
            'password_max' => 32,
            'password_lower' => true,
            'password_upper' => true,
            'password_digit' => true,
        ]);
        UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'Existing01',
            'normalized_login' => 'existing01',
        ]);

        $this->actingAs($user)->post('/account/game-accounts', [
            'game_server_id' => $gameServer->id,
            'game_login' => 'Second01',
            'game_password' => 'StrongPass1',
            'game_password_confirmation' => 'StrongPass1',
        ])->assertSessionHasErrors('game_login');

        $this->assertDatabaseCount('user_game_accounts', 1);
    }

    public function test_orphaned_game_account_still_counts_toward_the_limit(): void
    {
        $user = $this->user();
        [$loginServer, $gameServer] = $this->servers();
        $this->settings(['max_accounts' => 1]);
        UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => null,
            'game_login' => 'Hidden01',
            'normalized_login' => 'hidden01',
        ]);

        $this->actingAs($user)
            ->get('/account')
            ->assertOk()
            ->assertSee('1 / 1')
            ->assertSee('Некоторые игровые аккаунты временно недоступны')
            ->assertDontSee('Создать игровой аккаунт');

        $this->actingAs($user)
            ->get('/account/game-accounts/create')
            ->assertRedirect(public_route('account'))
            ->assertSessionHas('warning', 'Вы достигли лимита игровых аккаунтов.');

        $this->actingAs($user)->post('/account/game-accounts', [
            'game_server_id' => $gameServer->id,
            'game_login' => 'Second01',
            'game_password' => 'StrongPass1',
            'game_password_confirmation' => 'StrongPass1',
        ])->assertSessionHasErrors('game_login');

        $this->assertDatabaseCount('user_game_accounts', 1);
        $this->assertSame([], $this->gateway->created);
    }

    public function test_creation_rechecks_the_quota_when_a_competing_link_appears(): void
    {
        $user = $this->user();
        [$loginServer, $gameServer] = $this->servers();
        $this->settings(['max_accounts' => 1]);
        $gateway = new RaceInjectingGameAccountGateway($user, $loginServer);
        $this->app->instance(GameAccountGateway::class, $gateway);

        $this->actingAs($user)->post('/account/game-accounts', [
            'game_server_id' => $gameServer->id,
            'game_login' => 'Second01',
            'game_password' => 'StrongPass1',
            'game_password_confirmation' => 'StrongPass1',
        ])->assertSessionHasErrors('game_login');

        $this->assertDatabaseCount('user_game_accounts', 1);
        $this->assertDatabaseHas('user_game_accounts', [
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => null,
            'normalized_login' => 'concurrent01',
        ]);
        $this->assertSame([], $gateway->created);
    }

    public function test_hidden_account_prevents_single_visible_account_from_bypassing_the_dashboard(): void
    {
        $user = $this->user();
        [$loginServer, $gameServer] = $this->servers();
        $this->settings(['max_accounts' => 2]);
        UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'Visible01',
            'normalized_login' => 'visible01',
        ]);
        UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => null,
            'game_login' => 'Hidden02',
            'normalized_login' => 'hidden02',
        ]);

        $this->actingAs($user)
            ->get('/account/game-accounts')
            ->assertOk()
            ->assertSee('2 / 2')
            ->assertSee('Некоторые игровые аккаунты временно недоступны')
            ->assertSee('Visible01')
            ->assertDontSee('Создать игровой аккаунт');
    }

    public function test_player_cannot_open_another_users_game_account(): void
    {
        $owner = $this->user('owner@example.com', 'owner');
        $intruder = $this->user('intruder@example.com', 'intruder');
        [$loginServer, $gameServer] = $this->servers();
        $account = UserGameAccount::query()->create([
            'user_id' => $owner->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'Private01',
            'normalized_login' => 'private01',
        ]);

        $this->actingAs($intruder)
            ->get('/account/game-accounts/'.$account->id)
            ->assertNotFound();
    }

    public function test_account_details_show_characters_grouped_by_game_server(): void
    {
        $user = $this->user();
        [$loginServer, $gameServer] = $this->servers();
        $account = UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'Hero01',
            'normalized_login' => 'hero01',
        ]);
        $this->gateway->charactersByServer[$gameServer->id] = [[
            'id' => 100,
            'name' => 'Bubi',
            'level' => 78,
            'class_id' => 88,
            'online' => true,
            'clan' => 'L2Forge',
            'last_access' => 0,
            'created_at' => CarbonImmutable::parse('2024-04-05'),
        ]];

        $this->actingAs($user)
            ->get('/account/game-accounts/'.$account->id)
            ->assertOk()
            ->assertSee('Interlude x10')
            ->assertSee('Bubi')
            ->assertSee('Дуэлист')
            ->assertSee('L2Forge')
            ->assertSee('Создан: 05.04.2024')
            ->assertSee('Текущий пароль от личного кабинета');
    }

    public function test_character_creation_date_is_hidden_when_the_driver_does_not_return_it(): void
    {
        $user = $this->user();
        [$loginServer, $gameServer] = $this->servers();
        $account = UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'NoDate01',
            'normalized_login' => 'nodate01',
        ]);
        $this->gateway->charactersByServer[$gameServer->id] = [[
            'id' => 101,
            'name' => 'WithoutDate',
            'level' => 1,
            'class_id' => 0,
            'online' => false,
            'clan' => null,
            'last_access' => 0,
            'created_at' => null,
        ]];

        $this->actingAs($user)
            ->get('/account/game-accounts/'.$account->id)
            ->assertOk()
            ->assertSee('WithoutDate')
            ->assertDontSee('Создан:');
    }

    public function test_character_query_failure_does_not_break_the_account_page(): void
    {
        $user = $this->user();
        [$loginServer, $gameServer] = $this->servers();
        $account = UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'Timeout01',
            'normalized_login' => 'timeout01',
        ]);
        $this->gateway->failCharacters = true;

        $this->actingAs($user)
            ->get('/account/game-accounts/'.$account->id)
            ->assertOk()
            ->assertSee('Interlude x10')
            ->assertSee('Данные персонажей временно недоступны.');
    }

    public function test_player_cannot_change_another_users_game_account_password(): void
    {
        $owner = $this->user('owner@example.com', 'owner');
        $intruder = $this->user('intruder@example.com', 'intruder');
        [$loginServer, $gameServer] = $this->servers();
        $account = UserGameAccount::query()->create([
            'user_id' => $owner->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'PrivatePass01',
            'normalized_login' => 'privatepass01',
        ]);

        $this->actingAs($intruder)->put('/account/game-accounts/'.$account->id.'/password', [
            'current_password' => 'Password123',
            'game_password' => 'NewStrong1',
            'game_password_confirmation' => 'NewStrong1',
        ])->assertNotFound();

        $this->assertSame([], $this->gateway->passwordChanges);
    }

    public function test_player_cannot_change_game_password_to_unsupported_characters(): void
    {
        $user = $this->user();
        [$loginServer, $gameServer] = $this->servers();
        $account = UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'Password01',
            'normalized_login' => 'password01',
        ]);

        $this->actingAs($user)->put('/account/game-accounts/'.$account->id.'/password', [
            'current_password' => 'Password123',
            'game_password' => 'NewStrong!1',
            'game_password_confirmation' => 'NewStrong!1',
        ])->assertSessionHasErrors('game_password')
            ->assertSessionMissingInput(['game_password', 'game_password_confirmation']);

        $this->assertSame([], $this->gateway->passwordChanges);
    }

    public function test_player_can_change_game_password_only_after_confirming_personal_account_password(): void
    {
        $user = $this->user();
        [$loginServer, $gameServer] = $this->servers();
        $account = UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'Password01',
            'normalized_login' => 'password01',
        ]);

        $this->actingAs($user)->put('/account/game-accounts/'.$account->id.'/password', [
            'current_password' => 'WrongPassword',
            'game_password' => 'NewStrong1',
            'game_password_confirmation' => 'NewStrong1',
        ])->assertSessionHasErrors([
            'current_password' => 'Текущий пароль от личного кабинета указан неверно.',
        ]);
        $this->assertSame([], $this->gateway->passwordChanges);

        $this->actingAs($user)->put('/account/game-accounts/'.$account->id.'/password', [
            'current_password' => 'Password123',
            'game_password' => 'NewStrong1',
            'game_password_confirmation' => 'NewStrong1',
        ])->assertSessionHas('status');
        $this->assertSame('NewStrong1', $this->gateway->passwordChanges[0]['password']);
    }

    public function test_deleting_the_last_game_server_hides_its_player_account_card_without_deleting_the_link(): void
    {
        $user = $this->user();
        [$loginServer, $gameServer] = $this->servers();
        $account = UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'RemovedWorld01',
            'normalized_login' => 'removedworld01',
        ]);

        $this->deleteGameServer($gameServer);

        $this->assertDatabaseHas('user_game_accounts', ['id' => $account->id]);
        $this->assertNull($account->fresh()->registration_game_server_id);

        $this->actingAs($user)
            ->get('/account/game-accounts')
            ->assertOk()
            ->assertSee('Игровых аккаунтов пока нет')
            ->assertDontSee('RemovedWorld01')
            ->assertDontSee('Сервер</dt>', false);

        $this->actingAs($user)
            ->get('/account/game-accounts/'.$account->id)
            ->assertNotFound();
    }

    public function test_deleting_one_of_multiple_game_servers_reassigns_the_account_card_to_the_remaining_world(): void
    {
        $user = $this->user();
        [$loginServer, $gameServer] = $this->servers();
        $replacement = GameServer::query()->create([
            'name' => 'Interlude x50',
            'rates' => 'x50',
            'chronicle' => 'Interlude',
            'mode' => 'PvP',
            'sort_order' => 2,
            'login_server_id' => $loginServer->id,
            'driver' => 'l2j_mobius_ct0_interlude',
            'use_login_server_connection' => true,
        ]);
        $account = UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'RemainingWorld01',
            'normalized_login' => 'remainingworld01',
        ]);

        $this->deleteGameServer($gameServer);

        $this->assertSame($replacement->id, $account->fresh()->registration_game_server_id);

        $this->actingAs($user)
            ->get('/account/game-accounts')
            ->assertOk()
            ->assertSee('Interlude x50')
            ->assertDontSee('Interlude x10');

        $this->actingAs($user)
            ->get('/account/game-accounts/'.$account->id)
            ->assertOk()
            ->assertSee('Interlude x50')
            ->assertDontSee('Interlude x10');
    }

    public function test_configuring_a_replacement_game_server_restores_hidden_account_cards(): void
    {
        $user = $this->user();
        [$loginServer, $gameServer] = $this->servers();
        $account = UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'RestoredWorld01',
            'normalized_login' => 'restoredworld01',
        ]);
        $settings = app(GameServerSettings::class);
        $this->deleteGameServer($gameServer);
        $replacement = GameServer::query()->create([
            'name' => 'Interlude Reborn',
            'rates' => 'x7',
            'chronicle' => 'Interlude',
            'mode' => 'PvE',
            'sort_order' => 1,
            'login_server_id' => $loginServer->id,
            'driver' => 'l2j_mobius_ct0_interlude',
            'use_login_server_connection' => true,
        ]);

        $settings->restoreOrphanedAccountLinks($replacement);

        $this->assertSame($replacement->id, $account->fresh()->registration_game_server_id);
    }

    public function test_character_directory_caches_successful_external_queries(): void
    {
        $user = $this->user();
        [$loginServer, $gameServer] = $this->servers();
        UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'Cached01',
            'normalized_login' => 'cached01',
        ]);
        $this->gateway->charactersByServer[$gameServer->id] = [[
            'id' => 300,
            'name' => 'CachedHero',
            'level' => 80,
            'class_id' => 88,
            'online' => true,
            'last_access' => 0,
            'created_at' => null,
        ]];

        $directory = app(AccountCharacterDirectory::class);

        $this->assertSame('CachedHero', $directory->for($user)['characters'][0]['name']);
        $this->assertSame('CachedHero', $directory->for($user)['characters'][0]['name']);
        $this->assertSame(1, $this->gateway->characterCalls);
    }

    public function test_character_directory_uses_a_short_cooldown_after_external_failure(): void
    {
        $user = $this->user();
        [$loginServer, $gameServer] = $this->servers();
        UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'Failure01',
            'normalized_login' => 'failure01',
        ]);
        $this->gateway->failCharacters = true;

        $directory = app(AccountCharacterDirectory::class);
        $first = $directory->for($user);
        $second = $directory->for($user);

        $this->assertFalse($first['servers'][0]['accounts'][0]['available']);
        $this->assertFalse($second['servers'][0]['accounts'][0]['available']);
        $this->assertSame(1, $this->gateway->characterCalls);

        $this->travel(31)->seconds();
        $directory->for($user);

        $this->assertSame(2, $this->gateway->characterCalls);
    }

    public function test_character_directory_switches_between_grouped_and_flat_views(): void
    {
        $user = $this->user();
        [$loginServer, $gameServer] = $this->servers();
        $account = UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'Directory01',
            'normalized_login' => 'directory01',
        ]);
        $this->gateway->charactersByServer[$gameServer->id] = [[
            'id' => 301,
            'name' => 'DirectoryHero',
            'level' => 80,
            'class_id' => 88,
            'race' => 0,
            'gender' => 1,
            'title' => 'Champion',
            'online' => true,
            'clan' => 'L2Forge',
            'last_access' => 1784320000000,
            'play_time_seconds' => 90000,
            'pvp_kills' => 25,
            'pk_kills' => 2,
            'reputation' => 0,
            'noble' => true,
            'hero' => true,
            'created_at' => CarbonImmutable::parse('2024-04-05'),
        ]];

        Livewire::actingAs($user)
            ->test(CharacterDirectory::class)
            ->assertSet('viewMode', 'all')
            ->assertSet('expandedServerIds', [$gameServer->id])
            ->assertSet('expandedAccountIds', [$account->id])
            ->assertSee('DirectoryHero')
            ->assertSee('Герой')
            ->assertSee('Дворянин')
            ->assertSee('В игре')
            ->call('setViewMode', 'grouped')
            ->assertSet('viewMode', 'grouped')
            ->call('setViewMode', 'all')
            ->assertSet('viewMode', 'all')
            ->assertSee('Interlude x10')
            ->assertSee('Аккаунт: Directory01');

        $this->assertSame('all', UserCharacterPreference::query()->where('user_id', $user->id)->value('view_mode'));
    }

    public function test_legacy_character_preference_is_upgraded_once_to_the_simpler_all_characters_view(): void
    {
        $user = $this->user();
        $preference = UserCharacterPreference::query()->create([
            'user_id' => $user->id,
            'view_mode' => 'grouped',
            'schema_version' => 1,
        ]);

        Livewire::actingAs($user)
            ->test(CharacterDirectory::class)
            ->assertSet('viewMode', 'all');

        $preference->refresh();
        $this->assertSame('all', $preference->view_mode);
        $this->assertSame(2, $preference->schema_version);
    }

    public function test_explicit_grouped_character_preference_is_preserved_after_upgrade(): void
    {
        $user = $this->user();
        $preference = UserCharacterPreference::query()->create([
            'user_id' => $user->id,
            'view_mode' => 'grouped',
            'schema_version' => 2,
        ]);

        Livewire::actingAs($user)
            ->test(CharacterDirectory::class)
            ->assertSet('viewMode', 'grouped');

        $preference->refresh();
        $this->assertSame('grouped', $preference->view_mode);
        $this->assertSame(2, $preference->schema_version);
    }

    public function test_hidden_character_groups_are_persisted_but_remain_available_in_all_characters(): void
    {
        $user = $this->user();
        [$loginServer, $gameServer] = $this->servers();
        $account = UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'Hidden01',
            'normalized_login' => 'hidden01',
        ]);
        $this->gateway->charactersByServer[$gameServer->id] = [[
            'id' => 302,
            'name' => 'StillAvailable',
            'level' => 40,
            'class_id' => 9,
            'online' => false,
            'clan' => null,
            'last_access' => 0,
            'created_at' => null,
        ]];

        Livewire::actingAs($user)
            ->test(CharacterDirectory::class)
            ->call('setViewMode', 'grouped')
            ->call('hideAccount', $account->id)
            ->assertDontSee('StillAvailable')
            ->assertSee('Показать скрытые группы')
            ->call('setViewMode', 'all')
            ->assertSee('StillAvailable')
            ->assertSee('Скрытая группа')
            ->set('showHiddenInAll', false)
            ->assertDontSee('StillAvailable');

        $preference = UserCharacterPreference::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame([$account->id], $preference->hidden_game_account_ids);
    }

    public function test_character_directory_filters_the_combined_list(): void
    {
        $user = $this->user();
        [$loginServer, $gameServer] = $this->servers();
        UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'Filter01',
            'normalized_login' => 'filter01',
        ]);
        $this->gateway->charactersByServer[$gameServer->id] = [
            [
                'id' => 303,
                'name' => 'OnlineTarget',
                'level' => 60,
                'class_id' => 88,
                'online' => true,
                'clan' => 'BlueClan',
                'last_access' => 0,
                'created_at' => null,
            ],
            [
                'id' => 304,
                'name' => 'OfflineTarget',
                'level' => 59,
                'class_id' => 88,
                'online' => false,
                'clan' => 'RedClan',
                'last_access' => 0,
                'created_at' => null,
            ],
        ];

        Livewire::actingAs($user)
            ->test(CharacterDirectory::class)
            ->call('setViewMode', 'all')
            ->set('onlineOnly', true)
            ->assertSee('OnlineTarget')
            ->assertDontSee('OfflineTarget')
            ->set('onlineOnly', false)
            ->set('search', 'RedClan')
            ->assertDontSee('OnlineTarget')
            ->assertSee('OfflineTarget');
    }

    private function deleteGameServer(GameServer $gameServer): void
    {
        $impact = app(GameServerDeletionImpact::class)->analyze($gameServer);
        app(GameServerSettings::class)->delete($gameServer, $impact['fingerprint']);
    }

    /** @param array<string,mixed> $overrides */
    private function settings(array $overrides = []): void
    {
        app(GameAccountSettings::class)->update(array_replace([
            'enabled' => true,
            'max_accounts' => 10,
            'login_min' => 4,
            'login_max' => 16,
            'login_digit' => false,
            'password_min' => 8,
            'password_max' => 32,
            'password_lower' => true,
            'password_upper' => true,
            'password_digit' => true,
        ], $overrides));
    }

    private function user(string $email = 'player@example.com', string $name = 'player'): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
        ]);
    }

    /** @return array{LoginServer,GameServer} */
    private function servers(): array
    {
        return $this->freshMobiusServerPair();
    }
}
