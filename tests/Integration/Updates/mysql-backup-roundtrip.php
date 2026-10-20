<?php

/**
 * Optional real MySQL/MariaDB gate for the Web Update database backup and rollback.
 * Run only against a disposable, empty test database: restore drops EVERY table in it.
 * APP_ENV=testing UPDATE_BACKUP_TEST_ALLOW=1 php tests/Integration/Updates/mysql-backup-roundtrip.php
 *
 * SQLite PHPUnit tests cannot prove MySQL literal handling (JSON columns, numeric
 * conversion, empty strings, generated columns, character sets, zero AUTO_INCREMENT).
 */

use App\Services\Updates\UpdateDatabaseBackup;
use App\Services\Updates\UpdateLog;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
});

$database = (string) DB::connection()->getDatabaseName();
if ((string) config('app.env') !== 'testing'
    || DB::connection()->getDriverName() !== 'mysql'
    || preg_match('/(?:test|testing)/i', $database) !== 1
    || getenv('UPDATE_BACKUP_TEST_ALLOW') !== '1') {
    fwrite(STDERR, "Refusing to run: requires APP_ENV=testing, a MySQL/MariaDB test database and UPDATE_BACKUP_TEST_ALLOW=1.\n");
    exit(2);
}

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

$isMariaDb = stripos((string) DB::selectOne('SELECT VERSION() AS v')->v, 'mariadb') !== false;
$pdo = DB::connection()->getPdo();
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $row) {
    $pdo->exec('DROP TABLE IF EXISTS `'.str_replace('`', '``', (string) array_values((array) $row)[0]).'`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

$pdo->exec(<<<'SQL'
CREATE TABLE backup_parent (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
$pdo->exec(<<<'SQL'
CREATE TABLE backup_values (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    parent_id INT UNSIGNED NULL,
    small_number TINYINT NOT NULL,
    big_number BIGINT UNSIGNED NOT NULL,
    money DECIMAL(12,2) NOT NULL,
    ratio DOUBLE NULL,
    flag TINYINT(1) NOT NULL,
    bits BIT(8) NULL,
    title VARCHAR(190) NOT NULL,
    legacy_text TEXT CHARACTER SET latin1 NULL,
    body LONGTEXT NULL,
    payload JSON NULL,
    raw_bytes VARBINARY(32) NULL,
    blob_bytes BLOB NULL,
    status ENUM('draft','live') NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    happened_on DATE NULL,
    title_length INT AS (CHAR_LENGTH(title)) VIRTUAL,
    CONSTRAINT backup_values_parent FOREIGN KEY (parent_id) REFERENCES backup_parent(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

$pdo->exec("SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'NO_AUTO_VALUE_ON_ZERO')");
DB::table('backup_parent')->insert([['id' => 0, 'name' => 'zero id parent'], ['id' => 7, 'name' => 'Родитель']]);
$pdo->exec("SET SESSION sql_mode = REPLACE(@@SESSION.sql_mode, 'NO_AUTO_VALUE_ON_ZERO', '')");
DB::table('backup_values')->insert([
    [
        'id' => 5, 'parent_id' => 7, 'small_number' => 5, 'big_number' => '18446744073709551615',
        'money' => '12.50', 'ratio' => 0.12345678901234568, 'flag' => 1, 'bits' => DB::raw("b'10100101'"),
        'title' => 'Привет, мир — «кавычки» \' " \\ ; DROP TABLE x; --', 'legacy_text' => 'café',
        'body' => str_repeat('Длинный текст ', 2000), 'payload' => '{"coins": 5, "name": "Тест", "list": [1, 2]}',
        'raw_bytes' => "\x00\xff\x10\x00", 'blob_bytes' => random_bytes(64), 'status' => 'live',
        'created_at' => '2026-10-20 12:34:56', 'happened_on' => '2026-01-31',
    ],
    [
        'id' => 6, 'parent_id' => null, 'small_number' => -3, 'big_number' => 0,
        'money' => '0.00', 'ratio' => null, 'flag' => 0, 'bits' => null,
        'title' => '', 'legacy_text' => '', 'body' => '', 'payload' => null,
        'raw_bytes' => '', 'blob_bytes' => null, 'status' => 'draft',
        'created_at' => null, 'happened_on' => null,
    ],
]);

$snapshot = static function (): array {
    $tables = [];
    foreach (['backup_parent', 'backup_values'] as $table) {
        $rows = DB::select("SELECT * FROM `{$table}` ORDER BY id");
        $tables[$table] = array_map(static function (object $row): array {
            $values = (array) $row;
            foreach ($values as $key => $value) {
                $values[$key] = match (true) {
                    $value === null => null,
                    is_float($value) => var_export($value, true),
                    default => bin2hex((string) $value),
                };
            }

            return $values;
        }, $rows);
    }

    return $tables;
};

$before = $snapshot();
$backupRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kaevcms-mysql-backup-'.bin2hex(random_bytes(6));
$log = new UpdateLog('mysql-backup-roundtrip-'.bin2hex(random_bytes(4)));
$service = $app->make(UpdateDatabaseBackup::class);

try {
    $backup = $service->create($backupRoot, $log);
    $service->verify($backup);

    // Simulate a failed update: data changes, a new table and a dropped table.
    DB::table('backup_values')->where('id', 5)->update(['title' => 'changed', 'money' => '99.99', 'payload' => '{"broken": true}']);
    DB::table('backup_values')->where('id', 6)->delete();
    $pdo->exec('CREATE TABLE backup_added_by_update (id INT PRIMARY KEY)');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec('DROP TABLE backup_parent');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    $service->restore($backup, $log);

    $after = $snapshot();
    if ($after !== $before) {
        $fail('restored rows differ from the original rows: '.json_encode(['before' => $before, 'after' => $after]));
    }
    if (DB::select("SHOW TABLES LIKE 'backup_added_by_update'") !== []) {
        $fail('a table created after the backup survived the restore.');
    }
    if ((int) DB::table('backup_values')->where('id', 5)->value('title_length') !== mb_strlen((string) DB::table('backup_values')->where('id', 5)->value('title'))) {
        $fail('the generated column was not recomputed after restore.');
    }
    if (DB::selectOne("SELECT COUNT(*) AS c FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY'")->c < 1) {
        $fail('the foreign key was not restored.');
    }

    // A damaged backup must be rejected before any table is dropped.
    $damaged = $backupRoot.DIRECTORY_SEPARATOR.'damaged';
    mkdir($damaged, 0700, true);
    $lines = file($backup['path']);
    $lines[count($lines) - 1] = "{not json}\n";
    file_put_contents($damaged.DIRECTORY_SEPARATOR.'database.mysql.jsonl', implode('', $lines));
    $damagedBackup = ['driver' => 'mysql', 'path' => $damaged.DIRECTORY_SEPARATOR.'database.mysql.jsonl', 'sha256' => hash_file('sha256', $damaged.DIRECTORY_SEPARATOR.'database.mysql.jsonl')];
    try {
        $service->restore($damagedBackup, $log);
        $fail('a damaged backup was accepted.');
    } catch (RuntimeException) {
        if ($snapshot() !== $before) {
            $fail('a rejected damaged backup still changed the database.');
        }
    }
} finally {
    if (is_dir($backupRoot)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backupRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($backupRoot);
    }
}

echo 'OK: MySQL update backup round-trip verified on '.($isMariaDb ? 'MariaDB' : 'MySQL').' '.DB::selectOne('SELECT VERSION() AS v')->v."\n";
