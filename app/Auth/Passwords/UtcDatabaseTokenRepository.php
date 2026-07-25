<?php

namespace App\Auth\Passwords;

use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Support\Carbon;
use SensitiveParameter;

final class UtcDatabaseTokenRepository extends DatabaseTokenRepository
{
    /** @return array{email: string, token: string, created_at: Carbon} */
    protected function getPayload($email, #[SensitiveParameter] $token): array
    {
        return [
            'email' => (string) $email,
            'token' => $this->hasher->make($token),
            'created_at' => Carbon::now('UTC'),
        ];
    }

    protected function tokenExpired($createdAt): bool
    {
        return Carbon::parse($createdAt, 'UTC')
            ->addSeconds($this->expires)
            ->isPast();
    }

    protected function tokenRecentlyCreated($createdAt): bool
    {
        if ($this->throttle <= 0) {
            return false;
        }

        return Carbon::parse($createdAt, 'UTC')
            ->addSeconds($this->throttle)
            ->isFuture();
    }

    public function deleteExpired(): void
    {
        $expiredAt = Carbon::now('UTC')->subSeconds($this->expires);

        $this->getTable()->where('created_at', '<', $expiredAt)->delete();
    }
}
