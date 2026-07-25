<?php

namespace App\Services\Servers;

use App\Services\CmsSettings;
use InvalidArgumentException;

final class ServerMonitorSettings
{
    public const KEY_REFRESH_INTERVAL_SECONDS = 'server_monitor.refresh_interval_seconds';

    /** @var list<int> */
    public const REFRESH_INTERVAL_OPTIONS = [30, 60, 120, 300];

    public function __construct(private readonly CmsSettings $settings) {}

    /** @return array{refresh_interval_seconds:int} */
    public function values(): array
    {
        return [
            'refresh_interval_seconds' => $this->refreshIntervalSeconds(),
        ];
    }

    public function refreshIntervalSeconds(): int
    {
        $default = $this->defaultRefreshIntervalSeconds();
        $stored = $this->settings->get(self::KEY_REFRESH_INTERVAL_SECONDS, (string) $default);

        if (filter_var($stored, FILTER_VALIDATE_INT) === false) {
            return $default;
        }

        $seconds = (int) $stored;

        return in_array($seconds, self::REFRESH_INTERVAL_OPTIONS, true)
            ? $seconds
            : $default;
    }

    public function update(int $refreshIntervalSeconds): void
    {
        if (! in_array($refreshIntervalSeconds, self::REFRESH_INTERVAL_OPTIONS, true)) {
            throw new InvalidArgumentException('Unsupported server monitor refresh interval.');
        }

        $this->settings->set(
            self::KEY_REFRESH_INTERVAL_SECONDS,
            (string) $refreshIntervalSeconds,
        );

        // Keep the currently running request consistent even when the generic
        // settings service had already cached the previous default value.
        config()->set('cms.server_monitor.refresh_interval_seconds', $refreshIntervalSeconds);
    }

    private function defaultRefreshIntervalSeconds(): int
    {
        $configured = (int) config('cms.server_monitor.refresh_interval_seconds', 60);

        return in_array($configured, self::REFRESH_INTERVAL_OPTIONS, true)
            ? $configured
            : 60;
    }
}
