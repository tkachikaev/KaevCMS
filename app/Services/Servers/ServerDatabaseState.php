<?php

namespace App\Services\Servers;

use App\Models\GameServer;
use App\Models\LoginServer;

final class ServerDatabaseState
{
    /** @param array<string,mixed> $report */
    public function apply(LoginServer|GameServer $server, array $report): bool
    {
        $configured = $this->isConfigured($report);
        $server->forceFill([
            'database_status' => $configured ? 'configured' : 'not_configured',
            'database_error' => $configured ? null : $this->errorCode($report),
            'database_checked_at' => now(),
        ])->save();

        return $configured;
    }

    public function markUnknown(LoginServer|GameServer $server, ?string $error = null): void
    {
        $server->forceFill([
            'database_status' => 'unknown',
            'database_error' => $error,
            'database_checked_at' => null,
        ])->save();
    }

    /** @param array<string,mixed> $report */
    public function isConfigured(array $report): bool
    {
        return ($report['connected'] ?? false) === true
            && ($report['driver_ready'] ?? false) === true
            && ($report['compatible'] ?? false) === true;
    }

    /** @param array<string,mixed> $report */
    private function errorCode(array $report): string
    {
        if (($report['connected'] ?? false) !== true) {
            return 'connection_failed';
        }

        if (($report['driver_ready'] ?? false) !== true) {
            return 'driver_unavailable';
        }

        return 'incompatible_schema';
    }
}
