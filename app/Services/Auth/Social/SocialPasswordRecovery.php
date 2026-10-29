<?php

namespace App\Services\Auth\Social;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/** Revokes all CMS web authentication paths atomically on an email password reset. */
final class SocialPasswordRecovery
{
    public function reset(User $user, string $plainPassword, string $resetToken): User
    {
        return DB::transaction(static function () use ($user, $plainPassword, $resetToken): User {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            if (! Password::broker('users')->tokenExists($locked, $resetToken)) {
                throw new InvalidSocialPasswordResetToken;
            }

            $locked->forceFill([
                'password' => Hash::make($plainPassword),
                'password_set_at' => now(),
                'remember_token' => Str::random(60),
                'auth_session_version' => (int) $locked->auth_session_version + 1,
            ])->save();

            $revoked = $locked->socialAccounts()->delete();
            // Inside the same transaction: a concurrently reused token must fail.
            Password::broker('users')->deleteToken($locked);

            app(AuditLogger::class)->success(
                category: 'user',
                action: 'social.password_reset_links_revoked',
                actor: $locked,
                target: $locked,
                details: ['links_revoked_count' => $revoked],
            );

            return $locked;
        });
    }
}
