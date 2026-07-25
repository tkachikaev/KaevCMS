<?php

namespace App\Services;

final class RegistrationSettings
{
    public const KEY_ENABLED = 'registration.enabled';

    public const KEY_REQUIRE_EMAIL_VERIFICATION = 'registration.email_verification_required';

    public function __construct(private readonly CmsSettings $settings) {}

    /** @return array{enabled: bool, email_verification_required: bool} */
    public function values(): array
    {
        $defaultEnabled = (bool) config('cms.registration.enabled', false);
        $defaultVerification = (bool) config('cms.registration.email_verification_required', true);
        $values = $this->settings->getMany([
            self::KEY_ENABLED => $defaultEnabled ? '1' : '0',
            self::KEY_REQUIRE_EMAIL_VERIFICATION => $defaultVerification ? '1' : '0',
        ]);

        return [
            'enabled' => $this->toBool($values[self::KEY_ENABLED] ?? '0'),
            'email_verification_required' => $this->toBool($values[self::KEY_REQUIRE_EMAIL_VERIFICATION] ?? '1'),
        ];
    }

    public function enabled(): bool
    {
        return $this->values()['enabled'];
    }

    public function emailVerificationRequired(): bool
    {
        return $this->values()['email_verification_required'];
    }

    public function update(bool $enabled, bool $emailVerificationRequired): void
    {
        $this->settings->setMany([
            self::KEY_ENABLED => $enabled ? '1' : '0',
            self::KEY_REQUIRE_EMAIL_VERIFICATION => $emailVerificationRequired ? '1' : '0',
        ]);
    }

    private function toBool(?string $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
