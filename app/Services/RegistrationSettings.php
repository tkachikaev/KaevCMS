<?php

namespace App\Services;

use Illuminate\Validation\Rules\Password;

final class RegistrationSettings
{
    public const KEY_ENABLED = 'registration.enabled';

    public const KEY_REQUIRE_EMAIL_VERIFICATION = 'registration.email_verification_required';

    public const KEY_USERNAME_MIN = 'registration.username_min';

    public const KEY_USERNAME_MAX = 'registration.username_max';

    public const KEY_USERNAME_ALLOW_HYPHEN = 'registration.username_allow_hyphen';

    public const KEY_USERNAME_ALLOW_UNDERSCORE = 'registration.username_allow_underscore';

    public const KEY_PASSWORD_MIN = 'registration.password_min';

    public const KEY_PASSWORD_LETTERS = 'registration.password_letters';

    public const KEY_PASSWORD_MIXED_CASE = 'registration.password_mixed_case';

    public const KEY_PASSWORD_NUMBERS = 'registration.password_numbers';

    public const KEY_PASSWORD_SYMBOLS = 'registration.password_symbols';

    public function __construct(private readonly CmsSettings $settings) {}

    /**
     * @return array{
     *     enabled:bool,
     *     email_verification_required:bool,
     *     username_min:int,
     *     username_max:int,
     *     username_allow_hyphen:bool,
     *     username_allow_underscore:bool,
     *     password_min:int,
     *     password_letters:bool,
     *     password_mixed_case:bool,
     *     password_numbers:bool,
     *     password_symbols:bool
     * }
     */
    public function values(): array
    {
        $defaultEnabled = (bool) config('cms.registration.enabled', false);
        $defaultVerification = (bool) config('cms.registration.email_verification_required', true);
        $values = $this->settings->getMany([
            self::KEY_ENABLED => $defaultEnabled ? '1' : '0',
            self::KEY_REQUIRE_EMAIL_VERIFICATION => $defaultVerification ? '1' : '0',
            self::KEY_USERNAME_MIN => '3',
            self::KEY_USERNAME_MAX => '32',
            self::KEY_USERNAME_ALLOW_HYPHEN => '1',
            self::KEY_USERNAME_ALLOW_UNDERSCORE => '1',
            self::KEY_PASSWORD_MIN => '8',
            self::KEY_PASSWORD_LETTERS => '1',
            self::KEY_PASSWORD_MIXED_CASE => '0',
            self::KEY_PASSWORD_NUMBERS => '1',
            self::KEY_PASSWORD_SYMBOLS => '0',
        ]);

        $usernameMinimum = $this->boundedInt($values[self::KEY_USERNAME_MIN] ?? '3', 3, 32, 3);
        $usernameMaximum = max(
            $usernameMinimum,
            $this->boundedInt($values[self::KEY_USERNAME_MAX] ?? '32', 3, 64, 32),
        );

        return [
            'enabled' => $this->toBool($values[self::KEY_ENABLED] ?? '0'),
            'email_verification_required' => $this->toBool($values[self::KEY_REQUIRE_EMAIL_VERIFICATION] ?? '1'),
            'username_min' => $usernameMinimum,
            'username_max' => $usernameMaximum,
            'username_allow_hyphen' => $this->toBool($values[self::KEY_USERNAME_ALLOW_HYPHEN] ?? '1'),
            'username_allow_underscore' => $this->toBool($values[self::KEY_USERNAME_ALLOW_UNDERSCORE] ?? '1'),
            'password_min' => $this->boundedInt($values[self::KEY_PASSWORD_MIN] ?? '8', 8, 64, 8),
            'password_letters' => $this->toBool($values[self::KEY_PASSWORD_LETTERS] ?? '1'),
            'password_mixed_case' => $this->toBool($values[self::KEY_PASSWORD_MIXED_CASE] ?? '0'),
            'password_numbers' => $this->toBool($values[self::KEY_PASSWORD_NUMBERS] ?? '1'),
            'password_symbols' => $this->toBool($values[self::KEY_PASSWORD_SYMBOLS] ?? '0'),
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

    /**
     * @param  array{
     *     username_min?:int,
     *     username_max?:int,
     *     username_allow_hyphen?:bool,
     *     username_allow_underscore?:bool,
     *     password_min?:int,
     *     password_letters?:bool,
     *     password_mixed_case?:bool,
     *     password_numbers?:bool,
     *     password_symbols?:bool
     * }|null  $policy
     */
    public function update(bool $enabled, bool $emailVerificationRequired, ?array $policy = null): void
    {
        $current = $this->values();
        $policy ??= [];

        $this->settings->setMany([
            self::KEY_ENABLED => $enabled ? '1' : '0',
            self::KEY_REQUIRE_EMAIL_VERIFICATION => $emailVerificationRequired ? '1' : '0',
            self::KEY_USERNAME_MIN => (string) ($policy['username_min'] ?? $current['username_min']),
            self::KEY_USERNAME_MAX => (string) ($policy['username_max'] ?? $current['username_max']),
            self::KEY_USERNAME_ALLOW_HYPHEN => ($policy['username_allow_hyphen'] ?? $current['username_allow_hyphen']) ? '1' : '0',
            self::KEY_USERNAME_ALLOW_UNDERSCORE => ($policy['username_allow_underscore'] ?? $current['username_allow_underscore']) ? '1' : '0',
            self::KEY_PASSWORD_MIN => (string) ($policy['password_min'] ?? $current['password_min']),
            self::KEY_PASSWORD_LETTERS => ($policy['password_letters'] ?? $current['password_letters']) ? '1' : '0',
            self::KEY_PASSWORD_MIXED_CASE => ($policy['password_mixed_case'] ?? $current['password_mixed_case']) ? '1' : '0',
            self::KEY_PASSWORD_NUMBERS => ($policy['password_numbers'] ?? $current['password_numbers']) ? '1' : '0',
            self::KEY_PASSWORD_SYMBOLS => ($policy['password_symbols'] ?? $current['password_symbols']) ? '1' : '0',
        ]);
    }

    public function usernameValidationRule(): string
    {
        $values = $this->values();
        $characters = 'A-Za-z0-9';

        if ($values['username_allow_hyphen']) {
            $characters .= '\\-';
        }

        if ($values['username_allow_underscore']) {
            $characters .= '_';
        }

        return '/\A['.$characters.']+\z/';
    }

    public function usernameHtmlPattern(): string
    {
        $values = $this->values();
        $characters = 'A-Za-z0-9';

        if ($values['username_allow_hyphen']) {
            $characters .= '\\-';
        }

        if ($values['username_allow_underscore']) {
            $characters .= '_';
        }

        return '['.$characters.']+';
    }

    public function passwordRule(): Password
    {
        $values = $this->values();
        $rule = Password::min($values['password_min']);

        if ($values['password_mixed_case']) {
            $rule->mixedCase();
        } elseif ($values['password_letters']) {
            $rule->letters();
        }

        if ($values['password_numbers']) {
            $rule->numbers();
        }

        if ($values['password_symbols']) {
            $rule->symbols();
        }

        return $rule;
    }

    /** @return list<string> */
    public function usernameRequirements(): array
    {
        $values = $this->values();
        $requirements = [
            __('From :min to :max characters.', [
                'min' => $values['username_min'],
                'max' => $values['username_max'],
            ]),
            __('Latin letters and digits.'),
        ];

        if ($values['username_allow_hyphen']) {
            $requirements[] = __('Hyphen is allowed.');
        }

        if ($values['username_allow_underscore']) {
            $requirements[] = __('Underscore is allowed.');
        }

        return $requirements;
    }

    /** @return list<string> */
    public function passwordRequirements(): array
    {
        $values = $this->values();
        $requirements = [
            __('At least :count characters.', ['count' => $values['password_min']]),
        ];

        if ($values['password_mixed_case']) {
            $requirements[] = __('At least one uppercase and one lowercase letter.');
        } elseif ($values['password_letters']) {
            $requirements[] = __('At least one letter.');
        }

        if ($values['password_numbers']) {
            $requirements[] = __('At least one digit.');
        }

        if ($values['password_symbols']) {
            $requirements[] = __('At least one symbol.');
        }

        return $requirements;
    }

    private function toBool(?string $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function boundedInt(?string $value, int $minimum, int $maximum, int $fallback): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if (! is_int($integer)) {
            return $fallback;
        }

        return max($minimum, min($maximum, $integer));
    }
}
