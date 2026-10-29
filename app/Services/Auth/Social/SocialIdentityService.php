<?php

namespace App\Services\Auth\Social;

use App\Models\User;
use App\Models\UserSocialAccount;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Data-level identity operations. Callers must separately verify OAuth state and step-up. */
final class SocialIdentityService
{
    public function findLinkedUser(SocialIdentity $identity): ?User
    {
        // Never search by email, including when the external service marks it verified.
        return UserSocialAccount::query()
            ->where('provider', $identity->provider)
            ->where('provider_user_id', $identity->providerUserId)
            ->first()?->user;
    }

    /** Call only after a verified callback and authenticated, recently revalidated CMS user. */
    public function link(User $user, SocialIdentity $identity, int $expectedSessionVersion): UserSocialAccount
    {
        try {
            return DB::transaction(static function () use ($user, $identity, $expectedSessionVersion): UserSocialAccount {
                $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                // A password reset invalidates even OAuth callbacks already in flight.
                if ((int) $locked->auth_session_version !== $expectedSessionVersion) {
                    throw new SocialAccountConflict('The authenticated session is no longer current.');
                }
                if (! $locked->is_active) {
                    throw new SocialAccountConflict('The CMS account is inactive.');
                }

                $existing = $locked->socialAccounts()->where('provider', $identity->provider)->first();
                if ($existing !== null) {
                    if (hash_equals($existing->provider_user_id, $identity->providerUserId)) {
                        return $existing;
                    }

                    throw new SocialAccountConflict('Another identity is already linked for this provider.');
                }

                return $locked->socialAccounts()->create([
                    'provider' => $identity->provider,
                    'provider_user_id' => $identity->providerUserId,
                ]);
            });
        } catch (QueryException $exception) {
            // An index, not a prior SELECT, resolves races across users and processes.
            if (in_array((string) $exception->getCode(), ['23000', '23505', '19'], true)) {
                throw new SocialAccountConflict('The social identity is already linked.', previous: $exception);
            }

            throw $exception;
        }
    }
}
