<?php

namespace Tests\Feature\Account;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordChangeSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_change_elsewhere_signs_out_an_existing_session(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Old-password-123'), 'email_verified_at' => now()]);

        // First request binds this "other browser" session to the current password.
        $this->actingAs($user)->get('/account')->assertOk();
        $this->assertTrue(session()->has('password_hash_web'));

        // The password changes in another session (or through a reset link).
        $user->forceFill(['password' => Hash::make('New-password-456')])->save();

        $this->get('/account')->assertRedirect();
        $this->assertGuest('web');
    }

    public function test_changing_the_password_keeps_the_current_session_signed_in(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Old-password-123'), 'email_verified_at' => now()]);

        $this->actingAs($user)->get('/account/security')->assertOk();
        $this->put('/account/security/password', [
            'current_password' => 'Old-password-123',
            'password' => 'New-password-456',
            'password_confirmation' => 'New-password-456',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('New-password-456', (string) $user->fresh()?->password));
        $this->get('/account')->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_player_sign_out_leaves_an_administrator_login_in_the_same_browser(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Old-password-123'), 'email_verified_at' => now()]);
        $admin = Admin::factory()->create();

        $this->actingAs($user)->get('/account')->assertOk();
        $this->actingAs($admin, 'admin')->get('/admin')->assertOk();

        $user->forceFill(['password' => Hash::make('New-password-456')])->save();

        $this->get('/account')->assertRedirect();
        $this->assertGuest('web');
        $this->get('/admin')->assertOk();
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_session_without_a_stored_hash_adopts_it_instead_of_signing_out(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        // Sessions created before this protection existed carry no password hash yet.
        $this->actingAs($user)->withSession([])->get('/account')->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertTrue(session()->has('password_hash_web'));
    }
}
