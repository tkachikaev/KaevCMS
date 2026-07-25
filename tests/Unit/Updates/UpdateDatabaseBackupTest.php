<?php

namespace Tests\Unit\Updates;

use App\Services\Updates\UpdateDatabaseBackup;
use App\Services\Updates\UpdateLog;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UpdateDatabaseBackupTest extends TestCase
{
    private string $databasePath;

    private string $backupRoot;

    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->databasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kaevcms-db-'.bin2hex(random_bytes(8)).'.sqlite';
        $this->backupRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kaevcms-db-backup-'.bin2hex(random_bytes(8));
        touch($this->databasePath);

        $this->originalConnection = (string) config('database.default');
        config([
            'database.default' => 'update_backup_test',
            'database.connections.update_backup_test' => [
                'driver' => 'sqlite',
                'database' => $this->databasePath,
                'prefix' => '',
                'foreign_key_constraints' => true,
                'busy_timeout' => null,
                'journal_mode' => null,
                'synchronous' => null,
            ],
        ]);
        DB::purge('update_backup_test');
    }

    protected function tearDown(): void
    {
        DB::purge('update_backup_test');
        config(['database.default' => $this->originalConnection]);
        @unlink($this->databasePath);
        $this->removeDirectory($this->backupRoot);
        foreach (glob(storage_path('logs/update-database-test-*.log')) ?: [] as $logPath) {
            @unlink($logPath);
        }
        parent::tearDown();
    }

    public function test_sqlite_database_is_restored_after_a_failed_update(): void
    {
        DB::statement('CREATE TABLE update_state (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        DB::table('update_state')->insert(['id' => 1, 'value' => 'before']);

        $log = new UpdateLog('database-test-'.bin2hex(random_bytes(4)));
        $service = $this->app->make(UpdateDatabaseBackup::class);
        $backup = $service->create($this->backupRoot, $log);
        $service->verify($backup);

        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0700, fileperms($this->backupRoot) & 0777);
            $this->assertSame(0600, fileperms($backup['path']) & 0777);
            $this->assertSame(0600, fileperms($this->backupRoot.DIRECTORY_SEPARATOR.'backup.json') & 0777);
        }

        DB::table('update_state')->where('id', 1)->update(['value' => 'after']);
        $this->assertSame('after', DB::table('update_state')->value('value'));

        $service->restore($backup, $log);
        $this->assertSame('before', DB::table('update_state')->value('value'));
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path.DIRECTORY_SEPARATOR.$item;
            if (is_dir($child) && ! is_link($child)) {
                $this->removeDirectory($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }
}
