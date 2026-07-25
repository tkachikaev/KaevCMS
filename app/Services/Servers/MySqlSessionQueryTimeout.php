<?php

namespace App\Services\Servers;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;
use PDO;
use Throwable;

final class MySqlSessionQueryTimeout
{
    public function apply(Connection $database): bool
    {
        $timeoutMilliseconds = $this->timeoutMilliseconds();
        $serverVersion = $database->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
        $statement = $this->statementFor(
            is_scalar($serverVersion) ? (string) $serverVersion : '',
            $timeoutMilliseconds,
        );

        if ($statement === null) {
            Log::warning('External database query timeout is unsupported by the server.', [
                'server_version' => is_scalar($serverVersion) ? (string) $serverVersion : null,
                'timeout_ms' => $timeoutMilliseconds,
            ]);

            return false;
        }

        try {
            return $database->unprepared($statement);
        } catch (Throwable $exception) {
            Log::warning('External database query timeout could not be configured.', [
                'exception' => $exception::class,
                'server_version' => is_scalar($serverVersion) ? (string) $serverVersion : null,
                'timeout_ms' => $timeoutMilliseconds,
            ]);

            return false;
        }
    }

    public function statementFor(string $serverVersion, int $timeoutMilliseconds): ?string
    {
        $timeoutMilliseconds = max(100, min(30000, $timeoutMilliseconds));

        if (stripos($serverVersion, 'MariaDB') !== false) {
            $seconds = number_format($timeoutMilliseconds / 1000, 3, '.', '');

            return 'SET SESSION max_statement_time = '.$seconds;
        }

        if (preg_match('/^(\d+)\.(\d+)/', $serverVersion, $matches) !== 1) {
            return null;
        }

        $major = (int) $matches[1];
        $minor = (int) $matches[2];
        if ($major < 5 || ($major === 5 && $minor < 7)) {
            return null;
        }

        return 'SET SESSION max_execution_time = '.$timeoutMilliseconds;
    }

    private function timeoutMilliseconds(): int
    {
        return max(100, min(30000, (int) config('cms.external_database.query_timeout_ms', 3000)));
    }
}
