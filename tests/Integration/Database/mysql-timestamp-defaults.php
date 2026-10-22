<?php

/**
 * Optional real MySQL/MariaDB gate for timestamp definitions. Run only on a disposable,
 * already migrated test database:
 * APP_ENV=testing SCHEMA_TEST_ALLOW=1 php tests/Integration/Database/mysql-timestamp-defaults.php
 *
 * MariaDB before 10.10 and MySQL 5.7 give the first NOT NULL timestamp of a table
 * ON UPDATE CURRENT_TIMESTAMP by themselves and reject a second one, which kept the
 * Donations module from installing in 0.51.3. This enables every bundled module and fails
 * if any table still has a silently auto-updated timestamp.
 */

use App\Support\Modules\ModuleManager;
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
    || getenv('SCHEMA_TEST_ALLOW') !== '1') {
    fwrite(STDERR, "Refusing to run: requires APP_ENV=testing, a MySQL/MariaDB test database and SCHEMA_TEST_ALLOW=1.\n");
    exit(2);
}

$server = DB::selectOne('select version() as version, @@explicit_defaults_for_timestamp as explicit_defaults');
foreach (['daily-rewards', 'promo-codes', 'support-tickets', 'donations'] as $module) {
    $app->make(ModuleManager::class)->enable($module);
}

$autoUpdated = DB::select(
    "select TABLE_NAME as table_name, COLUMN_NAME as column_name from information_schema.COLUMNS
     where TABLE_SCHEMA = database() and EXTRA like '%on update%'",
);
if ($autoUpdated !== []) {
    fwrite(STDERR, 'FAIL: silently auto-updated timestamps: '.implode(', ', array_map(
        static fn (object $column): string => $column->table_name.'.'.$column->column_name,
        $autoUpdated,
    ))."\n");
    exit(1);
}

printf(
    "PASS: all bundled modules installed, no auto-updated timestamps (%s, explicit_defaults_for_timestamp=%s).\n",
    $server->version,
    $server->explicit_defaults,
);
