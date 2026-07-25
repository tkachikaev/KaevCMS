<?php

namespace App\Services;

final class SecuritySettings
{
    public const KEY_LOGIN_MAX_ATTEMPTS = 'security.admin_login_max_attempts';

    public const KEY_LOGIN_DECAY_SECONDS = 'security.admin_login_decay_seconds';

    public const KEY_LOGIN_IP_PER_MINUTE = 'security.admin_login_ip_per_minute';

    public const KEY_LOGIN_IP_PER_HOUR = 'security.admin_login_ip_per_hour';

    public const KEY_AUDIT_RETENTION_DAYS = 'security.audit_retention_days';

    public const KEY_ADMIN_LOGIN_RETENTION_DAYS = 'security.admin_login_retention_days';

    public const KEY_LOGS_LAST_CLEANED_AT = 'security.logs_last_cleaned_at';

    public function __construct(private readonly CmsSettings $settings) {}

    /**
     * @return array{
     *     login_max_attempts: int,
     *     login_decay_minutes: int,
     *     login_decay_seconds: int,
     *     login_ip_per_minute: int,
     *     login_ip_per_hour: int,
     *     audit_retention_days: int,
     *     admin_login_retention_days: int,
     *     logs_last_cleaned_at: string|null
     * }
     */
    public function values(): array
    {
        $defaults = [
            self::KEY_LOGIN_MAX_ATTEMPTS => (string) config('cms.admin.login_max_attempts', 5),
            self::KEY_LOGIN_DECAY_SECONDS => (string) config('cms.admin.login_decay_seconds', 60),
            self::KEY_LOGIN_IP_PER_MINUTE => (string) config('cms.admin.login_ip_max_attempts_per_minute', 10),
            self::KEY_LOGIN_IP_PER_HOUR => (string) config('cms.admin.login_ip_max_attempts_per_hour', 100),
            self::KEY_AUDIT_RETENTION_DAYS => (string) config('cms.audit.retention_days', 90),
            self::KEY_ADMIN_LOGIN_RETENTION_DAYS => (string) config('cms.admin.login_log_retention_days', 30),
            self::KEY_LOGS_LAST_CLEANED_AT => null,
        ];
        $values = $this->settings->getMany($defaults);
        $decaySeconds = $this->boundedInt(
            $values[self::KEY_LOGIN_DECAY_SECONDS] ?? '60',
            60,
            3600,
            60,
        );

        $perMinute = $this->boundedInt(
            $values[self::KEY_LOGIN_IP_PER_MINUTE] ?? '10',
            5,
            60,
            10,
        );
        $perHour = max(
            $perMinute,
            $this->boundedInt(
                $values[self::KEY_LOGIN_IP_PER_HOUR] ?? '100',
                30,
                1000,
                100,
            ),
        );

        return [
            'login_max_attempts' => $this->boundedInt(
                $values[self::KEY_LOGIN_MAX_ATTEMPTS] ?? '5',
                3,
                20,
                5,
            ),
            'login_decay_minutes' => max(1, intdiv($decaySeconds, 60)),
            'login_decay_seconds' => $decaySeconds,
            'login_ip_per_minute' => $perMinute,
            'login_ip_per_hour' => $perHour,
            'audit_retention_days' => $this->boundedInt(
                $values[self::KEY_AUDIT_RETENTION_DAYS] ?? '90',
                30,
                730,
                90,
            ),
            'admin_login_retention_days' => $this->boundedInt(
                $values[self::KEY_ADMIN_LOGIN_RETENTION_DAYS] ?? '30',
                7,
                365,
                30,
            ),
            'logs_last_cleaned_at' => $this->nullableString($values[self::KEY_LOGS_LAST_CLEANED_AT] ?? null),
        ];
    }

    /**
     * @param  array{
     *     login_max_attempts: int,
     *     login_decay_minutes: int,
     *     login_ip_per_minute: int,
     *     login_ip_per_hour: int,
     *     audit_retention_days: int,
     *     admin_login_retention_days: int
     * }  $values
     */
    public function update(array $values): void
    {
        $perMinute = $this->boundedInt((string) $values['login_ip_per_minute'], 5, 60, 10);
        $perHour = max(
            $perMinute,
            $this->boundedInt((string) $values['login_ip_per_hour'], 30, 1000, 100),
        );
        $maxAttempts = $this->boundedInt((string) $values['login_max_attempts'], 3, 20, 5);
        $decayMinutes = $this->boundedInt((string) $values['login_decay_minutes'], 1, 60, 1);
        $auditDays = $this->boundedInt((string) $values['audit_retention_days'], 30, 730, 90);
        $adminLoginDays = $this->boundedInt((string) $values['admin_login_retention_days'], 7, 365, 30);

        $this->settings->setMany([
            self::KEY_LOGIN_MAX_ATTEMPTS => (string) $maxAttempts,
            self::KEY_LOGIN_DECAY_SECONDS => (string) ($decayMinutes * 60),
            self::KEY_LOGIN_IP_PER_MINUTE => (string) $perMinute,
            self::KEY_LOGIN_IP_PER_HOUR => (string) $perHour,
            self::KEY_AUDIT_RETENTION_DAYS => (string) $auditDays,
            self::KEY_ADMIN_LOGIN_RETENTION_DAYS => (string) $adminLoginDays,
        ]);
    }

    public function markLogsCleaned(): string
    {
        $cleanedAt = now()->toIso8601String();
        $this->settings->set(self::KEY_LOGS_LAST_CLEANED_AT, $cleanedAt);

        return $cleanedAt;
    }

    private function boundedInt(?string $value, int $minimum, int $maximum, int $default): int
    {
        if ($value === null || filter_var($value, FILTER_VALIDATE_INT) === false) {
            return $default;
        }

        return max($minimum, min($maximum, (int) $value));
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
