<?php

namespace KaevCMS\Modules\Donations\Services;

use App\Models\Admin;
use App\Services\CmsSettings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Providers\YooMoneyProvider;
use Throwable;

/**
 * "Test" button on the YooMoney settings page. While an owner waits (at most five
 * minutes), the notification address answers YooMoney's test notification itself and
 * records whether its signature matches the secret being tested. Outside that window,
 * and for every notification without test_notification=true, the address behaves as
 * before: real payments never reach this class. The waiting state lives in the CMS settings
 * table, which every request shares whatever the cache store; the secret in it is encrypted
 * and dropped once the outcome is known.
 *
 * Anyone can post to the notification address, so a wrong signature only marks the window
 * and keeps it waiting: a forged body cannot end the test before YooMoney's real one arrives.
 */
final class DonationYooMoneyNotificationTest
{
    public const TTL_SECONDS = 300;

    private const SETTING_KEY = 'donations.yoomoney.notification_test';

    public function __construct(
        private readonly DonationProviderSettings $settings,
        private readonly CmsSettings $cms,
    ) {}

    /** Starts waiting for a test notification and returns the token the admin page polls with. */
    public function start(Admin $admin, string $candidateSecret): string
    {
        $secret = $candidateSecret !== '' ? $candidateSecret : $this->storedSecret();
        if ($secret === null || strlen($secret) < 8 || strlen($secret) > 1024) {
            throw new InvalidArgumentException('A notification secret is required.');
        }

        $token = Str::random(40);
        $this->write([
            'token' => hash('sha256', $token),
            'admin_id' => (int) $admin->getKey(),
            'secret' => Crypt::encryptString($secret),
            'status' => 'waiting',
            'mismatch' => false,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS)->getTimestamp(),
        ]);

        return $token;
    }

    /**
     * status: waiting, received, signature_mismatch or expired. waiting: the window is still
     * open, so a signature mismatch may yet be followed by YooMoney's correctly signed test.
     *
     * @return array{status:string,waiting:bool}
     */
    public function status(string $token): array
    {
        $state = $this->state(includeExpired: true);
        if ($state === null || ! hash_equals($state['token'], hash('sha256', $token))) {
            return ['status' => 'expired', 'waiting' => false];
        }

        $mismatch = $state['status'] === 'signature_mismatch' || ($state['mismatch'] ?? false) === true;
        $waiting = $state['status'] === 'waiting' && $state['expires_at'] >= now()->getTimestamp();
        $status = match (true) {
            $state['status'] === 'received' => 'received',
            $mismatch => 'signature_mismatch',
            $waiting => 'waiting',
            default => 'expired',
        };

        return ['status' => $status, 'waiting' => $waiting];
    }

    /**
     * True only while the admin page waits and the body is marked as a YooMoney test.
     * Any failure answers false, so real payments keep their usual path.
     */
    public function intercepts(string $rawBody): bool
    {
        try {
            parse_str($rawBody, $hint);
            $label = $hint['label'] ?? '';
            // Real payments, and test notifications for one of our orders (recorded, never
            // credited), keep their usual path without even reading the waiting state.
            if (($hint['test_notification'] ?? null) !== 'true'
                || (is_string($label) && str_starts_with($label, 'kaevcms-'))) {
                return false;
            }

            return ($this->state()['status'] ?? null) === 'waiting';
        } catch (Throwable) {
            return false;
        }
    }

    /** Records the outcome for the waiting admin page; true when the signature matches. */
    public function accept(string $rawBody): bool
    {
        $state = $this->state();
        if ($state === null) {
            return false;
        }

        $valid = (new YooMoneyProvider)->testNotificationSignatureValid($rawBody, Crypt::decryptString($state['secret']));
        if ($valid) {
            $state['status'] = 'received';
            $state['secret'] = '';
        } else {
            $state['mismatch'] = true;
        }
        $this->write($state);

        return $valid;
    }

    /** @return array{token:string,admin_id:int,secret:string,status:string,mismatch?:bool,expires_at:int}|null */
    private function state(bool $includeExpired = false): ?array
    {
        $this->cms->forget();
        $state = json_decode((string) $this->cms->get(self::SETTING_KEY, ''), true);
        if (! is_array($state) || ! is_string($state['token'] ?? null) || ! is_string($state['secret'] ?? null)
            || ! in_array($state['status'] ?? null, ['waiting', 'received', 'signature_mismatch'], true)
            || ! is_int($state['expires_at'] ?? null)
            || (! $includeExpired && $state['expires_at'] < now()->getTimestamp())) {
            return null;
        }

        /** @var array{token:string,admin_id:int,secret:string,status:string,mismatch?:bool,expires_at:int} $state */
        return $state;
    }

    /** @param array<string,mixed> $state */
    private function write(array $state): void
    {
        $this->cms->set(self::SETTING_KEY, (string) json_encode($state));
    }

    private function storedSecret(): ?string
    {
        try {
            $secret = $this->settings->credentialsForDriver('yoomoney')['notification_secret'] ?? null;
        } catch (Throwable) {
            return null;
        }

        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
