<?php

namespace App\Services\Servers;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;
use PDO;
use Throwable;

final class MySqlSessionQueryTimeout
{
    /**
     * Callers that already opened the connection pass its server version; they must have
     * opened it through withNetworkReadTimeout() themselves.
     */
    public function apply(Connection $database, ?string $serverVersion = null): bool
    {
        if ($serverVersion === null) {
            // mysqlnd copies its network read timeout when the PDO connection is opened, so
            // the lazy connection must be opened while the shorter limit is active.
            $pdo = $this->withNetworkReadTimeout(static fn (): PDO => $database->getPdo());
            $serverVersion = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        }
        $this->applyLockWaitTimeout($database);

        $timeoutMilliseconds = $this->timeoutMilliseconds();
        $statement = $this->statementFor($serverVersion, $timeoutMilliseconds);

        if ($statement === null) {
            Log::warning('External database query timeout is unsupported by the server.', [
                'server_version' => $serverVersion !== '' ? $serverVersion : null,
                'timeout_ms' => $timeoutMilliseconds,
            ]);

            return false;
        }

        try {
            return $database->unprepared($statement);
        } catch (Throwable $exception) {
            Log::warning('External database query timeout could not be configured.', [
                'exception_class' => $exception::class,
                'server_version' => $serverVersion !== '' ? $serverVersion : null,
                'timeout_ms' => $timeoutMilliseconds,
            ]);

            return false;
        }
    }

    /**
     * Runs $connect with mysqlnd.net_read_timeout lowered for this process and restores
     * the previous value afterwards, so the application's own database keeps its default.
     *
     * @template T
     *
     * @param  callable(): T  $connect
     * @return T
     */
    public function withNetworkReadTimeout(callable $connect): mixed
    {
        $previous = ini_get('mysqlnd.net_read_timeout');
        $changed = $previous !== false
            && ini_set('mysqlnd.net_read_timeout', (string) $this->readTimeoutSeconds()) !== false;

        try {
            return $connect();
        } finally {
            if ($changed) {
                ini_set('mysqlnd.net_read_timeout', (string) $previous);
            }
        }
    }

    public function lockWaitStatement(): string
    {
        return 'SET SESSION innodb_lock_wait_timeout = '.$this->lockWaitSeconds();
    }

    public function readTimeoutSeconds(): int
    {
        return max(5, min(300, (int) config('cms.external_database.read_timeout_seconds', 20)));
    }

    public function lockWaitSeconds(): int
    {
        // Always shorter than the network read timeout: a blocked write must be rejected by
        // the server (nothing written) before the client could lose track of its outcome.
        $configured = max(1, min(300, (int) config('cms.external_database.lock_wait_timeout_seconds', 10)));

        return max(1, min($configured, $this->readTimeoutSeconds() - 5));
    }

    private function applyLockWaitTimeout(Connection $database): void
    {
        try {
            $database->unprepared($this->lockWaitStatement());
        } catch (Throwable $exception) {
            Log::warning('External database lock wait timeout could not be configured.', [
                'exception_class' => $exception::class,
                'lock_wait_seconds' => $this->lockWaitSeconds(),
            ]);
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
