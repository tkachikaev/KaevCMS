<?php

namespace Tests\Feature\Account;

use App\Contracts\GameAccountGateway;
use App\Livewire\Account\GameAccountPasswordForm;
use App\Models\User;
use App\Models\UserGameAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithServerFixtures;
use Tests\Fakes\FakeGameAccountGateway;
use Tests\TestCase;

class ReactiveGameAccountPasswordTest extends TestCase
{
    use InteractsWithServerFixtures, RefreshDatabase;

    private FakeGameAccountGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeGameAccountGateway;
        $this->app->instance(GameAccountGateway::class, $this->gateway);
    }

    public function test_wrong_personal_password_is_shown_inline_without_leaving_the_account_page(): void
    {
        [$user, $account] = $this->account();
        $this->actingAs($user);

        Livewire::test(GameAccountPasswordForm::class, ['accountId' => $account->id])
            ->set('currentPassword', 'WrongPassword')
            ->set('gamePassword', 'NewStrong1')
            ->set('gamePasswordConfirmation', 'NewStrong1')
            ->call('save')
            ->assertHasErrors('currentPassword')
            ->assertSet('status', null)
            ->assertSet('gamePassword', 'NewStrong1');

        $this->assertSame([], $this->gateway->passwordChanges);
    }

    public function test_password_policy_errors_are_shown_inline_without_calling_the_game_database(): void
    {
        [$user, $account] = $this->account();
        $this->actingAs($user);

        Livewire::test(GameAccountPasswordForm::class, ['accountId' => $account->id])
            ->set('currentPassword', 'Password123')
            ->set('gamePassword', 'NewStrong!1')
            ->set('gamePasswordConfirmation', 'NewStrong!1')
            ->call('save')
            ->assertHasErrors('gamePassword')
            ->assertSet('status', null);

        $this->assertSame([], $this->gateway->passwordChanges);
    }

    public function test_valid_game_password_is_changed_reactively_and_the_form_is_cleared(): void
    {
        [$user, $account] = $this->account();
        $this->actingAs($user);

        Livewire::test(GameAccountPasswordForm::class, ['accountId' => $account->id])
            ->set('currentPassword', 'Password123')
            ->set('gamePassword', 'NewStrong1')
            ->set('gamePasswordConfirmation', 'NewStrong1')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('currentPassword', '')
            ->assertSet('gamePassword', '')
            ->assertSet('gamePasswordConfirmation', '')
            ->assertSet('status', __('Game account password changed.'));

        $this->assertSame('Password01', $this->gateway->passwordChanges[0]['login']);
        $this->assertSame('NewStrong1', $this->gateway->passwordChanges[0]['password']);
    }

    /** @return array{User,UserGameAccount} */
    private function account(): array
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password123'),
        ]);
        [$loginServer, $gameServer] = $this->freshMobiusServerPair();
        $account = UserGameAccount::factory()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'Password01',
            'normalized_login' => 'password01',
        ]);

        return [$user, $account];
    }
}
