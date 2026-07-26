<?php

namespace Tests\Feature\Account;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_change_requires_authentication(): void
    {
        $this->put('/account/security/password', [
            'current_password' => 'Password123',
            'password' => 'NewPassword456',
            'password_confirmation' => 'NewPassword456',
        ])->assertRedirect(route('login'));
    }

    public function test_player_can_change_the_cms_account_password(): void
    {
        $user = User::factory()->create();
        $previousRememberToken = $user->remember_token;

        $this->actingAs($user)
            ->put('/account/security/password', [
                'current_password' => 'Password123',
                'password' => 'NewPassword456',
                'password_confirmation' => 'NewPassword456',
            ])
            ->assertRedirect('/account/security')
            ->assertSessionHas('status', __('Your account password has been changed.'));

        $user->refresh();

        $this->assertTrue(Hash::check('NewPassword456', $user->password));
        $this->assertNotSame($previousRememberToken, $user->remember_token);
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', [
            'category' => 'user',
            'action' => 'user.password_changed',
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'result' => 'success',
        ]);
    }

    public function test_wrong_current_password_is_rejected_without_changing_the_password(): void
    {
        $user = User::factory()->create();
        $originalHash = $user->password;

        $this->actingAs($user)
            ->put('/account/security/password', [
                'current_password' => 'WrongPassword123',
                'password' => 'NewPassword456',
                'password_confirmation' => 'NewPassword456',
            ])
            ->assertRedirect('/account/security')
            ->assertSessionHasErrors([
                'current_password' => __('The current password is incorrect.'),
            ])
            ->assertSessionMissingInput(['current_password', 'password', 'password_confirmation']);

        $this->assertSame($originalHash, $user->refresh()->password);
        $this->assertSame(0, AuditLog::query()->where('action', 'user.password_changed')->count());
    }

    public function test_new_password_must_be_strong_confirmed_and_different(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/account/security/password', [
                'current_password' => 'Password123',
                'password' => 'short',
                'password_confirmation' => 'different',
            ])
            ->assertRedirect('/account/security')
            ->assertSessionHasErrors('password');

        $this->actingAs($user)
            ->put('/account/security/password', [
                'current_password' => 'Password123',
                'password' => 'Password123',
                'password_confirmation' => 'Password123',
            ])
            ->assertRedirect('/account/security')
            ->assertSessionHasErrors([
                'password' => __('The new password must be different from the current password.'),
            ]);

        $this->assertTrue(Hash::check('Password123', $user->refresh()->password));
    }

    public function test_localized_password_change_route_returns_to_localized_security_page(): void
    {
        $user = User::factory()->create(['locale' => 'ru']);

        $this->actingAs($user)
            ->put('/ru/account/security/password', [
                'current_password' => 'Password123',
                'password' => 'Localized456',
                'password_confirmation' => 'Localized456',
            ])
            ->assertRedirect('/ru/account/security');

        $this->assertTrue(Hash::check('Localized456', $user->refresh()->password));
    }
}
