<?php

namespace App\Services;

final class GameAccountSettings
{
    public const KEY_ENABLED = 'game_accounts.creation_enabled';

    public const KEY_LIMIT = 'game_accounts.max_per_user';

    public const KEY_LOGIN_MIN = 'game_accounts.login_min_length';

    public const KEY_LOGIN_MAX = 'game_accounts.login_max_length';

    public const KEY_LOGIN_DIGIT = 'game_accounts.login_require_digit';

    public const KEY_PASSWORD_MIN = 'game_accounts.password_min_length';

    public const KEY_PASSWORD_MAX = 'game_accounts.password_max_length';

    public const KEY_PASSWORD_LOWER = 'game_accounts.password_require_lowercase';

    public const KEY_PASSWORD_UPPER = 'game_accounts.password_require_uppercase';

    public const KEY_PASSWORD_DIGIT = 'game_accounts.password_require_digit';

    public function __construct(private readonly CmsSettings $settings) {}

    /**
     * @return array{
     *     enabled: bool,
     *     max_accounts: int,
     *     login_min: int,
     *     login_max: int,
     *     login_digit: bool,
     *     password_min: int,
     *     password_max: int,
     *     password_lower: bool,
     *     password_upper: bool,
     *     password_digit: bool
     * }
     */
    public function values(): array
    {
        $values = $this->settings->getMany([
            self::KEY_ENABLED => '1',
            self::KEY_LIMIT => '1',
            self::KEY_LOGIN_MIN => '4',
            self::KEY_LOGIN_MAX => '16',
            self::KEY_LOGIN_DIGIT => '0',
            self::KEY_PASSWORD_MIN => '6',
            self::KEY_PASSWORD_MAX => '32',
            self::KEY_PASSWORD_LOWER => '0',
            self::KEY_PASSWORD_UPPER => '0',
            self::KEY_PASSWORD_DIGIT => '0',
        ]);

        return [
            'enabled' => $this->toBool($values[self::KEY_ENABLED] ?? '1'),
            'max_accounts' => $this->between($values[self::KEY_LIMIT] ?? '1', 1, 50, 1),
            'login_min' => $this->between($values[self::KEY_LOGIN_MIN] ?? '4', 1, 45, 4),
            'login_max' => $this->between($values[self::KEY_LOGIN_MAX] ?? '16', 1, 45, 16),
            'login_digit' => $this->toBool($values[self::KEY_LOGIN_DIGIT] ?? '0'),
            'password_min' => $this->between($values[self::KEY_PASSWORD_MIN] ?? '6', 1, 45, 6),
            'password_max' => $this->between($values[self::KEY_PASSWORD_MAX] ?? '32', 1, 45, 32),
            'password_lower' => $this->toBool($values[self::KEY_PASSWORD_LOWER] ?? '0'),
            'password_upper' => $this->toBool($values[self::KEY_PASSWORD_UPPER] ?? '0'),
            'password_digit' => $this->toBool($values[self::KEY_PASSWORD_DIGIT] ?? '0'),
        ];
    }

    /** @param  array<string, mixed>  $values */
    public function update(array $values): void
    {
        $this->settings->setMany([
            self::KEY_ENABLED => ! empty($values['enabled']) ? '1' : '0',
            self::KEY_LIMIT => (string) $values['max_accounts'],
            self::KEY_LOGIN_MIN => (string) $values['login_min'],
            self::KEY_LOGIN_MAX => (string) $values['login_max'],
            self::KEY_LOGIN_DIGIT => ! empty($values['login_digit']) ? '1' : '0',
            self::KEY_PASSWORD_MIN => (string) $values['password_min'],
            self::KEY_PASSWORD_MAX => (string) $values['password_max'],
            self::KEY_PASSWORD_LOWER => ! empty($values['password_lower']) ? '1' : '0',
            self::KEY_PASSWORD_UPPER => ! empty($values['password_upper']) ? '1' : '0',
            self::KEY_PASSWORD_DIGIT => ! empty($values['password_digit']) ? '1' : '0',
        ]);
    }

    private function toBool(?string $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function between(?string $value, int $min, int $max, int $default): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }
}
