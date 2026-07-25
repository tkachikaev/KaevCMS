<?php

namespace App\Services\Updates;

use Illuminate\Database\DatabaseManager;
use PDO;
use RuntimeException;
use Throwable;

final class UpdateDatabaseBackup
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly UpdatePathPolicy $pathPolicy,
    ) {}

    /** @return array{driver: string, path: string, sha256: string} */
    public function create(string $backupRoot, UpdateLog $log): array
    {
        $connection = $this->database->connection();
        $driver = $connection->getDriverName();

        if (! is_dir($backupRoot) && ! mkdir($backupRoot, 0700, true) && ! is_dir($backupRoot)) {
            throw new RuntimeException(__('Unable to create the update backup directory.'));
        }
        $this->protectDirectory($backupRoot);

        $backup = match ($driver) {
            'sqlite' => $this->backupSqlite($backupRoot, $log),
            'mysql' => $this->backupMysql($backupRoot, $log),
            default => throw new RuntimeException(__('Automatic update backups do not support the :driver database driver.', ['driver' => $driver])),
        };

        $this->writeMetadata($backupRoot, $backup);

        return $backup;
    }

    /** @return array{driver: string, path: string, sha256: string}|null */
    public function load(string $backupRoot): ?array
    {
        $metadataPath = rtrim($backupRoot, '/\\').DIRECTORY_SEPARATOR.'backup.json';
        if (! is_file($metadataPath) || ! is_readable($metadataPath)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($metadataPath), true);
        if (! is_array($decoded)
            || ($decoded['schema'] ?? null) !== 1
            || ! is_string($decoded['driver'] ?? null)
            || ! is_string($decoded['file'] ?? null)
            || ! is_string($decoded['sha256'] ?? null)) {
            throw new RuntimeException(__('The update database backup metadata is invalid.'));
        }

        $driver = $decoded['driver'];
        $file = $decoded['file'];
        $sha256 = strtolower($decoded['sha256']);
        $expectedFile = match ($driver) {
            'sqlite' => 'database.sqlite',
            'mysql' => 'database.mysql.jsonl',
            default => null,
        };

        if ($expectedFile === null || $file !== $expectedFile || preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1) {
            throw new RuntimeException(__('The update database backup metadata is invalid.'));
        }

        return [
            'driver' => $driver,
            'path' => rtrim($backupRoot, '/\\').DIRECTORY_SEPARATOR.$file,
            'sha256' => $sha256,
        ];
    }

    /** @param array{driver: string, path: string, sha256: string} $backup */
    public function verify(array $backup): void
    {
        $actualHash = is_file($backup['path']) ? hash_file('sha256', $backup['path']) : false;
        if (! is_string($actualHash) || ! hash_equals($backup['sha256'], $actualHash)) {
            throw new RuntimeException(__('The update database backup failed integrity verification.'));
        }
    }

    /** @param array{driver: string, path: string, sha256: string} $backup */
    public function restore(array $backup, UpdateLog $log): void
    {
        $this->verify($backup);

        match ($backup['driver']) {
            'sqlite' => $this->restoreSqlite($backup['path'], $log),
            'mysql' => $this->restoreMysql($backup['path'], $log),
            default => throw new RuntimeException(__('The update database backup driver is not supported.')),
        };
    }

    /** @return array{driver: string, path: string, sha256: string} */
    private function backupSqlite(string $backupRoot, UpdateLog $log): array
    {
        $configuredPath = (string) $this->database->connection()->getConfig('database');
        if ($configuredPath === '' || $configuredPath === ':memory:') {
            throw new RuntimeException(__('The SQLite database path is not available for backup.'));
        }

        $databasePath = $this->absoluteDatabasePath($configuredPath);
        if (! is_file($databasePath) || ! is_readable($databasePath)) {
            throw new RuntimeException(__('The SQLite database file is not readable.'));
        }

        $backupPath = $backupRoot.'/database.sqlite';
        @unlink($backupPath);
        $pdo = $this->pdo();
        $escapedBackupPath = str_replace('\'', '\'\'', $backupPath);
        $pdo->exec("VACUUM INTO '{$escapedBackupPath}'");
        if (! is_file($backupPath)) {
            throw new RuntimeException(__('Unable to create the SQLite update backup.'));
        }
        $this->protectFile($backupPath);

        $log->write('SQLite database backup created.');

        return ['driver' => 'sqlite', 'path' => $backupPath, 'sha256' => $this->fileHash($backupPath)];
    }

    /** @return array{driver: string, path: string, sha256: string} */
    private function backupMysql(string $backupRoot, UpdateLog $log): array
    {
        $pdo = $this->pdo();
        $backupPath = $backupRoot.'/database.mysql.jsonl';
        $stream = fopen($backupPath, 'wb');
        if (! is_resource($stream)) {
            throw new RuntimeException(__('Unable to create the MySQL update backup.'));
        }

        try {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->beginTransaction();
            $this->writeBackupRecord($stream, [
                'type' => 'meta',
                'schema' => 1,
                'driver' => 'mysql',
                'created_at' => now()->utc()->toIso8601String(),
            ]);

            foreach ($this->mysqlTables($pdo) as $table) {
                $quotedTable = $this->quoteIdentifier($table);
                $createStatement = $pdo->query("SHOW CREATE TABLE {$quotedTable}");
                $create = $createStatement !== false ? $createStatement->fetch(PDO::FETCH_NUM) : false;
                if (! is_array($create) || ! is_string($create[1] ?? null)) {
                    throw new RuntimeException(__('Unable to read the database definition for :table.', ['table' => $table]));
                }

                $this->writeSqlRecord($stream, "DROP TABLE IF EXISTS {$quotedTable}");
                $this->writeSqlRecord($stream, $create[1]);

                $rows = $pdo->query("SELECT * FROM {$quotedTable}");
                if ($rows === false) {
                    throw new RuntimeException(__('Unable to back up database rows from :table.', ['table' => $table]));
                }

                while (($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $columns = array_map(fn (string $column): string => $this->quoteIdentifier($column), array_keys($row));
                    $values = array_map(fn (mixed $value): string => $this->mysqlLiteral($value), array_values($row));
                    $this->writeSqlRecord(
                        $stream,
                        "INSERT INTO {$quotedTable} (".implode(', ', $columns).') VALUES ('.implode(', ', $values).')',
                    );
                }
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        } finally {
            fclose($stream);
        }
        $this->protectFile($backupPath);

        $log->write('MySQL database backup created.');

        return ['driver' => 'mysql', 'path' => $backupPath, 'sha256' => $this->fileHash($backupPath)];
    }

    private function restoreSqlite(string $backupPath, UpdateLog $log): void
    {
        $configuredPath = (string) $this->database->connection()->getConfig('database');
        $databasePath = $this->absoluteDatabasePath($configuredPath);
        if (! is_file($backupPath)) {
            throw new RuntimeException(__('The SQLite update backup is missing.'));
        }

        $this->database->purge();
        if (! copy($backupPath, $databasePath)) {
            throw new RuntimeException(__('Unable to restore the SQLite update backup.'));
        }
        $this->database->reconnect();
        $log->write('SQLite database backup restored.', 'WARN');
    }

    private function restoreMysql(string $backupPath, UpdateLog $log): void
    {
        if (! is_file($backupPath) || ! is_readable($backupPath)) {
            throw new RuntimeException(__('The MySQL update backup is missing.'));
        }

        $stream = fopen($backupPath, 'rb');
        if (! is_resource($stream)) {
            throw new RuntimeException(__('Unable to open the MySQL update backup.'));
        }

        $firstLine = fgets($stream);
        $metadata = is_string($firstLine) ? json_decode($firstLine, true) : null;
        if (! is_array($metadata)
            || ($metadata['type'] ?? null) !== 'meta'
            || ($metadata['schema'] ?? null) !== 1
            || ($metadata['driver'] ?? null) !== 'mysql') {
            fclose($stream);

            throw new RuntimeException(__('The MySQL update backup header is invalid.'));
        }

        $pdo = $this->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($this->mysqlTables($pdo) as $table) {
                $pdo->exec('DROP TABLE IF EXISTS '.$this->quoteIdentifier($table));
            }

            while (($line = fgets($stream)) !== false) {
                $record = json_decode($line, true);
                if (! is_array($record) || ($record['type'] ?? null) !== 'sql') {
                    throw new RuntimeException(__('The MySQL update backup is invalid.'));
                }

                $encoded = $record['statement'] ?? null;
                $statement = is_string($encoded) ? base64_decode($encoded, true) : false;
                if (! is_string($statement) || $statement === '') {
                    throw new RuntimeException(__('The MySQL update backup contains an invalid statement.'));
                }

                $pdo->exec($statement);
            }
        } finally {
            fclose($stream);
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->database->purge();
        $this->database->reconnect();
        $log->write('MySQL database backup restored.', 'WARN');
    }

    /** @return list<string> */
    private function mysqlTables(PDO $pdo): array
    {
        $statement = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'');
        if ($statement === false) {
            throw new RuntimeException(__('Unable to list MySQL tables for the update backup.'));
        }

        $tables = [];
        while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
            if (is_string($row[0] ?? null) && $row[0] !== '') {
                $tables[] = $row[0];
            }
        }

        return $tables;
    }

    /** @param resource $stream */
    private function writeSqlRecord($stream, string $statement): void
    {
        $this->writeBackupRecord($stream, [
            'type' => 'sql',
            'statement' => base64_encode($statement),
        ]);
    }

    /**
     * @param  resource  $stream
     * @param  array<string, mixed>  $record
     */
    private function writeBackupRecord($stream, array $record): void
    {
        $line = json_encode($record, JSON_UNESCAPED_SLASHES);
        if (! is_string($line) || fwrite($stream, $line."\n") === false) {
            throw new RuntimeException(__('Unable to write the database update backup.'));
        }
    }

    /** @param array{driver: string, path: string, sha256: string} $backup */
    private function writeMetadata(string $backupRoot, array $backup): void
    {
        $metadata = [
            'schema' => 1,
            'driver' => $backup['driver'],
            'file' => basename($backup['path']),
            'sha256' => $backup['sha256'],
        ];
        $encoded = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $metadataPath = rtrim($backupRoot, '/\\').DIRECTORY_SEPARATOR.'backup.json';
        if (! is_string($encoded)
            || file_put_contents($metadataPath, $encoded."\n", LOCK_EX) === false) {
            throw new RuntimeException(__('Unable to write the update database backup metadata.'));
        }
        $this->protectFile($metadataPath);
    }

    private function protectDirectory(string $path): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        if (! @chmod($path, 0700)) {
            throw new RuntimeException(__('Unable to secure the update backup directory permissions.'));
        }

        $mode = @fileperms($path);
        if (is_int($mode) && ($mode & 0777) !== 0700) {
            throw new RuntimeException(__('The update backup directory permissions are too broad.'));
        }
    }

    private function protectFile(string $path): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        if (! @chmod($path, 0600)) {
            throw new RuntimeException(__('Unable to secure the update backup file permissions.'));
        }

        $mode = @fileperms($path);
        if (is_int($mode) && ($mode & 0777) !== 0600) {
            throw new RuntimeException(__('The update backup file permissions are too broad.'));
        }
    }

    private function mysqlLiteral(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return '0x'.bin2hex((string) $value);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function fileHash(string $path): string
    {
        $hash = hash_file('sha256', $path);
        if (! is_string($hash)) {
            throw new RuntimeException(__('Unable to calculate the database update backup checksum.'));
        }

        return $hash;
    }

    private function pdo(): PDO
    {
        $pdo = $this->connectionPdo();
        if (! $pdo instanceof PDO) {
            throw new RuntimeException(__('The CMS database connection is not ready.'));
        }

        return $pdo;
    }

    private function connectionPdo(): mixed
    {
        return $this->database->connection()->getPdo();
    }

    private function absoluteDatabasePath(string $path): string
    {
        if ($path === '') {
            return $path;
        }

        if ($this->pathPolicy->isAbsoluteFilesystemPath($path)) {
            return $path;
        }

        return base_path($path);
    }
}
