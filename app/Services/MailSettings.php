<?php

namespace App\Services;

use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

final class MailSettings
{
    public const KEY_HOST = 'mail.smtp_host';

    public const KEY_PORT = 'mail.smtp_port';

    public const KEY_ENCRYPTION = 'mail.encryption';

    public const KEY_USERNAME = 'mail.username';

    public const KEY_PASSWORD = 'mail.password_encrypted';

    public const KEY_FROM_ADDRESS = 'mail.from_address';

    public const KEY_FROM_NAME = 'mail.from_name';

    public const KEY_ADMIN_EMAIL = 'mail.admin_email';

    public const KEY_TESTED_AT = 'mail.tested_at';

    public const KEY_DELIVERY_MODE = 'mail.delivery_mode';

    public const KEY_BACKGROUND_PROBE_STATUS = 'mail.background_probe_status';

    public const KEY_BACKGROUND_PROBE_TOKEN = 'mail.background_probe_token';

    public const KEY_BACKGROUND_PROBE_REQUESTED_AT = 'mail.background_probe_requested_at';

    public const KEY_BACKGROUND_PROBE_COMPLETED_AT = 'mail.background_probe_completed_at';

    public const KEY_BACKGROUND_PROBE_ERROR = 'mail.background_probe_error';

    public const KEY_BACKGROUND_PROBE_ACTIVATE = 'mail.background_probe_activate';

    public const KEY_DATABASE_PROBE_STATUS = 'mail.database_probe_status';

    public const KEY_DATABASE_PROBE_TOKEN = 'mail.database_probe_token';

    public const KEY_DATABASE_PROBE_REQUESTED_AT = 'mail.database_probe_requested_at';

    public const KEY_DATABASE_PROBE_COMPLETED_AT = 'mail.database_probe_completed_at';

    public const KEY_DATABASE_PROBE_ERROR = 'mail.database_probe_error';

    public const KEY_DATABASE_PROBE_ACTIVATE = 'mail.database_probe_activate';

    public const MODE_SYNC = 'sync';

    public const MODE_BACKGROUND = 'background';

    public const MODE_DATABASE = 'database';

    public function __construct(private readonly CmsSettings $settings) {}

    /**
     * @return array{
     *     host: string,
     *     port: int,
     *     encryption: string,
     *     username: string,
     *     password_saved: bool,
     *     password_valid: bool,
     *     from_address: string,
     *     from_name: string,
     *     admin_email: string,
     *     tested_at: string|null,
     *     delivery_mode: string,
     *     background_probe_status: string,
     *     background_probe_requested_at: string|null,
     *     background_probe_completed_at: string|null,
     *     background_probe_error: string|null,
     *     background_supported: bool,
     *     database_probe_status: string,
     *     database_probe_requested_at: string|null,
     *     database_probe_completed_at: string|null,
     *     database_probe_error: string|null,
     *     database_supported: bool,
     *     configured: bool,
     *     ready: bool
     * }
     */
    public function values(): array
    {
        $this->expireProbeIfNeeded(self::MODE_BACKGROUND);
        $this->expireProbeIfNeeded(self::MODE_DATABASE);

        $defaults = [
            self::KEY_HOST => '',
            self::KEY_PORT => '587',
            self::KEY_ENCRYPTION => 'tls',
            self::KEY_USERNAME => '',
            self::KEY_PASSWORD => '',
            self::KEY_FROM_ADDRESS => '',
            self::KEY_FROM_NAME => (string) config('app.name', 'KaevCMS'),
            self::KEY_ADMIN_EMAIL => '',
            self::KEY_TESTED_AT => null,
            self::KEY_DELIVERY_MODE => self::MODE_SYNC,
            self::KEY_BACKGROUND_PROBE_STATUS => 'not_tested',
            self::KEY_BACKGROUND_PROBE_REQUESTED_AT => null,
            self::KEY_BACKGROUND_PROBE_COMPLETED_AT => null,
            self::KEY_BACKGROUND_PROBE_ERROR => null,
            self::KEY_DATABASE_PROBE_STATUS => 'not_tested',
            self::KEY_DATABASE_PROBE_REQUESTED_AT => null,
            self::KEY_DATABASE_PROBE_COMPLETED_AT => null,
            self::KEY_DATABASE_PROBE_ERROR => null,
        ];

        $values = $this->settings->getMany($defaults);
        $host = trim((string) ($values[self::KEY_HOST] ?? ''));
        $port = max(1, min(65535, (int) ($values[self::KEY_PORT] ?? 587)));
        $encryption = $this->normalizeEncryption((string) ($values[self::KEY_ENCRYPTION] ?? 'tls'));
        $username = trim((string) ($values[self::KEY_USERNAME] ?? ''));
        $fromAddress = trim((string) ($values[self::KEY_FROM_ADDRESS] ?? ''));
        $fromName = trim((string) ($values[self::KEY_FROM_NAME] ?? ''));
        $adminEmail = trim((string) ($values[self::KEY_ADMIN_EMAIL] ?? ''));
        $encryptedPassword = (string) ($values[self::KEY_PASSWORD] ?? '');
        $testedAt = trim((string) ($values[self::KEY_TESTED_AT] ?? ''));
        $deliveryMode = $this->normalizeDeliveryMode((string) ($values[self::KEY_DELIVERY_MODE] ?? self::MODE_SYNC));
        $background = $this->probeValuesFromSettings(self::MODE_BACKGROUND, $values);
        $database = $this->probeValuesFromSettings(self::MODE_DATABASE, $values);
        $passwordSaved = $encryptedPassword !== '';
        $passwordValid = ! $passwordSaved || $this->canDecryptPassword($encryptedPassword);
        $configured = $host !== ''
            && filter_var($fromAddress, FILTER_VALIDATE_EMAIL) !== false
            && $passwordValid;

        return [
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'username' => $username,
            'password_saved' => $passwordSaved,
            'password_valid' => $passwordValid,
            'from_address' => $fromAddress,
            'from_name' => $fromName !== '' ? $fromName : (string) config('app.name', 'KaevCMS'),
            'admin_email' => $adminEmail,
            'tested_at' => $testedAt !== '' ? $testedAt : null,
            'delivery_mode' => $deliveryMode,
            'background_probe_status' => $background['status'],
            'background_probe_requested_at' => $background['requested_at'],
            'background_probe_completed_at' => $background['completed_at'],
            'background_probe_error' => $background['error'],
            'background_supported' => $background['supported'],
            'database_probe_status' => $database['status'],
            'database_probe_requested_at' => $database['requested_at'],
            'database_probe_completed_at' => $database['completed_at'],
            'database_probe_error' => $database['error'],
            'database_supported' => $database['supported'],
            'configured' => $configured,
            'ready' => $configured && $testedAt !== '',
        ];
    }

    /**
     * @param  array{
     *     host: string,
     *     port: int,
     *     encryption: string,
     *     username: string,
     *     password: string|null,
     *     from_address: string,
     *     from_name: string,
     *     admin_email: string
     * }  $values
     */
    public function update(array $values): void
    {
        $existingPassword = $this->settings->get(self::KEY_PASSWORD, '') ?? '';
        $password = $values['password'];
        $encryptedPassword = is_string($password) && $password !== ''
            ? Crypt::encryptString($password)
            : $existingPassword;

        $this->settings->setMany([
            self::KEY_HOST => trim($values['host']),
            self::KEY_PORT => (string) $values['port'],
            self::KEY_ENCRYPTION => $this->normalizeEncryption($values['encryption']),
            self::KEY_USERNAME => trim($values['username']),
            self::KEY_PASSWORD => $encryptedPassword,
            self::KEY_FROM_ADDRESS => trim($values['from_address']),
            self::KEY_FROM_NAME => trim($values['from_name']),
            self::KEY_ADMIN_EMAIL => trim($values['admin_email']),
            self::KEY_TESTED_AT => null,
        ]);
    }

    public function markTested(): void
    {
        $this->settings->set(self::KEY_TESTED_AT, now()->toIso8601String());
    }

    public function deliveryMode(): string
    {
        return $this->normalizeDeliveryMode((string) ($this->settings->get(self::KEY_DELIVERY_MODE, self::MODE_SYNC) ?? self::MODE_SYNC));
    }

    public function setDeliveryMode(string $mode): void
    {
        $mode = $this->normalizeDeliveryMode($mode);

        if ($this->requiresCapabilityTest($mode) && ! $this->modeSupported($mode)) {
            throw new DomainException('Mail delivery mode has not passed the capability test.');
        }

        $this->settings->setMany([
            self::KEY_DELIVERY_MODE => $mode,
            self::KEY_BACKGROUND_PROBE_ACTIVATE => '0',
            self::KEY_DATABASE_PROBE_ACTIVATE => '0',
        ]);
    }

    public function requiresCapabilityTest(string $mode): bool
    {
        return in_array($this->normalizeDeliveryMode($mode), [self::MODE_BACKGROUND, self::MODE_DATABASE], true);
    }

    public function modeSupported(string $mode): bool
    {
        $mode = $this->normalizeDeliveryMode($mode);

        if ($mode === self::MODE_SYNC) {
            return true;
        }

        return (string) ($this->settings->get($this->probeKeys($mode)['status'], 'not_tested') ?? 'not_tested') === 'passed';
    }

    public function beginProbe(string $mode, bool $activateOnSuccess = false): string
    {
        $mode = $this->normalizeProbeMode($mode);
        $keys = $this->probeKeys($mode);
        $token = Str::uuid()->toString();

        $values = [
            $keys['status'] => 'pending',
            $keys['token'] => $token,
            $keys['requested_at'] => now()->toIso8601String(),
            $keys['completed_at'] => null,
            $keys['error'] => null,
            $keys['activate'] => $activateOnSuccess ? '1' : '0',
        ];

        if ($activateOnSuccess) {
            $values[$mode === self::MODE_BACKGROUND
                ? self::KEY_DATABASE_PROBE_ACTIVATE
                : self::KEY_BACKGROUND_PROBE_ACTIVATE] = '0';
        }

        $this->settings->setMany($values);

        return $token;
    }

    public function completeProbe(string $mode, string $token): void
    {
        $mode = $this->normalizeProbeMode($mode);
        $keys = $this->probeKeys($mode);

        if (! hash_equals((string) ($this->settings->get($keys['token'], '') ?? ''), $token)
            || (string) ($this->settings->get($keys['status'], 'not_tested') ?? 'not_tested') !== 'pending') {
            return;
        }

        $values = [
            $keys['status'] => 'passed',
            $keys['completed_at'] => now()->toIso8601String(),
            $keys['error'] => null,
            $keys['activate'] => '0',
        ];

        if ((string) ($this->settings->get($keys['activate'], '0') ?? '0') === '1') {
            $values[self::KEY_DELIVERY_MODE] = $mode;
        }

        $this->settings->setMany($values);
    }

    public function failProbe(string $mode, string $token, string $errorClass): void
    {
        $mode = $this->normalizeProbeMode($mode);
        $keys = $this->probeKeys($mode);

        if (! hash_equals((string) ($this->settings->get($keys['token'], '') ?? ''), $token)
            || (string) ($this->settings->get($keys['status'], 'not_tested') ?? 'not_tested') !== 'pending') {
            return;
        }

        $values = [
            $keys['status'] => 'failed',
            $keys['completed_at'] => now()->toIso8601String(),
            $keys['error'] => $errorClass,
            $keys['activate'] => '0',
        ];

        if ($this->deliveryMode() === $mode) {
            $values[self::KEY_DELIVERY_MODE] = self::MODE_SYNC;
        }

        $this->settings->setMany($values);
    }

    public function probeConnection(string $mode): string
    {
        return $this->normalizeProbeMode($mode) === self::MODE_BACKGROUND
            ? 'background'
            : 'database';
    }

    public function probeTimeoutSeconds(string $mode): int
    {
        return $this->normalizeProbeMode($mode) === self::MODE_DATABASE ? 90 : 15;
    }

    public function beginBackgroundProbe(): string
    {
        return $this->beginProbe(self::MODE_BACKGROUND);
    }

    public function completeBackgroundProbe(string $token): void
    {
        $this->completeProbe(self::MODE_BACKGROUND, $token);
    }

    public function failBackgroundProbe(string $token, string $errorClass): void
    {
        $this->failProbe(self::MODE_BACKGROUND, $token, $errorClass);
    }

    public function backgroundSupported(): bool
    {
        return $this->modeSupported(self::MODE_BACKGROUND);
    }

    public function databaseSupported(): bool
    {
        return $this->modeSupported(self::MODE_DATABASE);
    }

    public function isConfigured(): bool
    {
        return $this->values()['configured'];
    }

    public function isReady(): bool
    {
        return $this->values()['ready'];
    }

    public function applyConfiguration(): void
    {
        $values = $this->values();

        if (! $values['configured']) {
            return;
        }
        $password = $this->decryptedPassword();
        $scheme = $values['encryption'] === 'ssl' ? 'smtps' : 'smtp';

        config()->set([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'scheme' => $scheme,
                'url' => null,
                'host' => $values['host'],
                'port' => $values['port'],
                'username' => $values['username'] !== '' ? $values['username'] : null,
                'password' => $password !== '' ? $password : null,
                'timeout' => 15,
                'local_domain' => parse_url((string) config('app.url', 'http://localhost'), PHP_URL_HOST),
                'auto_tls' => $values['encryption'] !== 'none',
            ],
            'mail.from.address' => $values['from_address'],
            'mail.from.name' => $values['from_name'],
        ]);

        try {
            Mail::purge('smtp');
        } catch (Throwable) {
            // The mail manager may not be resolved yet during application boot.
        }
    }

    private function canDecryptPassword(string $encrypted): bool
    {
        if ($encrypted === '') {
            return true;
        }

        try {
            Crypt::decryptString($encrypted);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }

    private function decryptedPassword(): string
    {
        $encrypted = (string) ($this->settings->get(self::KEY_PASSWORD, '') ?? '');

        if ($encrypted === '') {
            return '';
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            return '';
        }
    }

    private function expireProbeIfNeeded(string $mode): void
    {
        $keys = $this->probeKeys($mode);
        $status = (string) ($this->settings->get($keys['status'], 'not_tested') ?? 'not_tested');
        $requestedAt = (string) ($this->settings->get($keys['requested_at'], '') ?? '');

        if ($status !== 'pending' || $requestedAt === '') {
            return;
        }

        try {
            if (Carbon::parse($requestedAt)->gte(now()->subSeconds($this->probeTimeoutSeconds($mode)))) {
                return;
            }
        } catch (Throwable) {
            // An invalid timestamp is treated as a failed capability test.
        }

        $token = (string) ($this->settings->get($keys['token'], '') ?? '');

        if ($token !== '') {
            $this->failProbe($mode, $token, 'MailDeliveryProbeTimeout');
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{status: string, requested_at: string|null, completed_at: string|null, error: string|null, supported: bool}
     */
    private function probeValuesFromSettings(string $mode, array $values): array
    {
        $keys = $this->probeKeys($mode);
        $status = $this->normalizeProbeStatus((string) ($values[$keys['status']] ?? 'not_tested'));
        $requestedAt = trim((string) ($values[$keys['requested_at']] ?? ''));
        $completedAt = trim((string) ($values[$keys['completed_at']] ?? ''));
        $error = trim((string) ($values[$keys['error']] ?? ''));

        return [
            'status' => $status,
            'requested_at' => $requestedAt !== '' ? $requestedAt : null,
            'completed_at' => $completedAt !== '' ? $completedAt : null,
            'error' => $error !== '' ? $error : null,
            'supported' => $status === 'passed',
        ];
    }

    /** @return array{status: string, token: string, requested_at: string, completed_at: string, error: string, activate: string} */
    private function probeKeys(string $mode): array
    {
        if ($this->normalizeProbeMode($mode) === self::MODE_BACKGROUND) {
            return [
                'status' => self::KEY_BACKGROUND_PROBE_STATUS,
                'token' => self::KEY_BACKGROUND_PROBE_TOKEN,
                'requested_at' => self::KEY_BACKGROUND_PROBE_REQUESTED_AT,
                'completed_at' => self::KEY_BACKGROUND_PROBE_COMPLETED_AT,
                'error' => self::KEY_BACKGROUND_PROBE_ERROR,
                'activate' => self::KEY_BACKGROUND_PROBE_ACTIVATE,
            ];
        }

        return [
            'status' => self::KEY_DATABASE_PROBE_STATUS,
            'token' => self::KEY_DATABASE_PROBE_TOKEN,
            'requested_at' => self::KEY_DATABASE_PROBE_REQUESTED_AT,
            'completed_at' => self::KEY_DATABASE_PROBE_COMPLETED_AT,
            'error' => self::KEY_DATABASE_PROBE_ERROR,
            'activate' => self::KEY_DATABASE_PROBE_ACTIVATE,
        ];
    }

    private function normalizeDeliveryMode(string $value): string
    {
        return in_array($value, [self::MODE_SYNC, self::MODE_BACKGROUND, self::MODE_DATABASE], true)
            ? $value
            : self::MODE_SYNC;
    }

    private function normalizeProbeMode(string $value): string
    {
        $mode = $this->normalizeDeliveryMode($value);

        if ($mode === self::MODE_SYNC) {
            throw new DomainException('Synchronous mail delivery does not require a capability test.');
        }

        return $mode;
    }

    private function normalizeProbeStatus(string $value): string
    {
        return in_array($value, ['not_tested', 'pending', 'passed', 'failed'], true) ? $value : 'not_tested';
    }

    private function normalizeEncryption(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['tls', 'ssl', 'none'], true) ? $value : 'tls';
    }
}
