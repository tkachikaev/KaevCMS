<?php

namespace KaevCMS\Modules\Referrals\Services;

use App\Models\User;
use App\Support\Modules\ModuleManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use KaevCMS\Modules\Referrals\Models\ReferralCode;
use KaevCMS\Modules\Referrals\Models\ReferralInvite;
use KaevCMS\Modules\Referrals\Models\ReferralSetting;
use RuntimeException;

final class ReferralInvitationService
{
    public const COOKIE_NAME = 'kaev_referral_invitation';

    public function enabled(): bool
    {
        return app(ModuleManager::class)->inspect('referrals')['status'] === 'enabled'
            && ReferralSetting::query()->where('setting_key', 'global')->where('enabled', true)->exists();
    }

    public function ttlDays(): int
    {
        $configured = (int) (ReferralSetting::query()->where('setting_key', 'global')->value('invite_ttl_days') ?? 7);

        return max(1, min(30, $configured));
    }

    /** A stable, opaque referral code owned by one CMS user. */
    public function codeFor(User $inviter): string
    {
        if (! $this->enabled() || ! $inviter->is_active) {
            throw new RuntimeException('Referral invitations are unavailable.');
        }

        $existing = ReferralCode::query()->where('user_id', $inviter->id)->first();
        if ($existing !== null) {
            if ($existing->revoked_at !== null) {
                throw new RuntimeException('Referral code was revoked.');
            }

            return $existing->code;
        }

        // The database enforces uniqueness of both the user and the random code.
        $created = ReferralCode::query()->firstOrCreate(
            ['user_id' => $inviter->id],
            ['code' => Str::random(40)],
        );

        if ($created->revoked_at !== null) {
            throw new RuntimeException('Referral code was revoked.');
        }

        return $created->code;
    }

    public function linkFor(User $inviter): string
    {
        return url('/r/'.$this->codeFor($inviter));
    }

    public function activeCode(string $code): ?ReferralCode
    {
        if (! $this->enabled() || preg_match('/\A[A-Za-z0-9]{32,64}\z/D', $code) !== 1) {
            return null;
        }

        return ReferralCode::query()
            ->where('code', $code)
            ->whereNull('revoked_at')
            ->whereHas('user', static fn ($query) => $query->where('is_active', true))
            ->first();
    }

    /** Store only an opaque code and expiration, protected by Laravel EncryptCookies. */
    public function remember(ReferralCode $code, Request $request): void
    {
        $days = $this->ttlDays();
        $expiry = now()->addDays($days)->timestamp;
        $value = json_encode(['code' => $code->code, 'expires_at' => $expiry], JSON_THROW_ON_ERROR);

        Cookie::queue(cookie(
            self::COOKIE_NAME,
            $value,
            $days * 24 * 60,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'lax',
        ));
    }

    public function forget(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE_NAME, '/'));
    }

    /** Read from the independent, encrypted browser cookie; never from the session. */
    public function pendingCode(Request $request): ?ReferralCode
    {
        $raw = $request->cookie(self::COOKIE_NAME);
        if (! is_string($raw) || strlen($raw) > 512) {
            return null;
        }

        $data = json_decode($raw, true);
        if (! is_array($data) || ! is_string($data['code'] ?? null)
            || ! is_int($data['expires_at'] ?? null)
            || $data['expires_at'] <= now()->timestamp
            || $data['expires_at'] > now()->addDays(30)->timestamp) {
            return null;
        }

        return $this->activeCode($data['code']);
    }

    /** Called only from the shared explicit registration hook, inside the User INSERT transaction. */
    public function acceptForNewUser(User $invitee, Request $request): void
    {
        $code = $this->pendingCode($request);
        if ($code === null || (int) $code->user_id === (int) $invitee->id) {
            return;
        }

        // First acceptance wins; database unique(invitee_user_id) is the final guard.
        ReferralInvite::query()->firstOrCreate(
            ['invitee_user_id' => $invitee->id],
            [
                'referral_code_id' => $code->id,
                'inviter_user_id' => $code->user_id,
                'accepted_at' => now(),
                'status' => 'active',
            ],
        );
    }
}
