<?php

namespace Tests\Unit\Updates;

use App\Services\Updates\UpdateFilesystemTransaction;
use App\Services\Updates\UpdateInstallationLayout;
use App\Services\Updates\UpdateLog;
use App\Services\Updates\UpdatePathPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class UpdateFilesystemTransactionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kaevcms-fs-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
        foreach (glob(storage_path('logs/update-filesystem-test-*.log')) ?: [] as $logPath) {
            @unlink($logPath);
        }
        parent::tearDown();
    }

    #[DataProvider('installationLayouts')]
    public function test_changed_and_deleted_files_are_restored_from_backup(bool $split): void
    {
        [$coreRoot, $publicRoot] = $this->createLayout($split);
        file_put_contents($coreRoot.'/existing.txt', 'old');
        file_put_contents($publicRoot.'/obsolete.txt', 'obsolete');
        mkdir($this->root.'/staging/payload/core', 0775, true);
        file_put_contents($this->root.'/staging/payload/core/existing.txt', 'new');
        file_put_contents($this->root.'/staging/payload/core/created.txt', 'created');

        $files = [
            $this->file('payload/core/existing.txt', 'core/existing.txt', 'new'),
            $this->file('payload/core/created.txt', 'core/created.txt', 'created'),
        ];
        $delete = ['public/obsolete.txt'];
        $transaction = $this->transaction($coreRoot, $publicRoot);
        $log = new UpdateLog('filesystem-test-'.bin2hex(random_bytes(4)));
        $backup = $transaction->backup($files, $delete, $this->root.'/backup', $log);

        $transaction->apply($files, $delete, $this->root.'/staging', $log);
        $this->assertSame('new', file_get_contents($coreRoot.'/existing.txt'));
        $this->assertFileExists($coreRoot.'/created.txt');
        $this->assertFileDoesNotExist($publicRoot.'/obsolete.txt');

        $transaction->rollback($backup, $log);
        $this->assertSame('old', file_get_contents($coreRoot.'/existing.txt'));
        $this->assertFileDoesNotExist($coreRoot.'/created.txt');
        $this->assertSame('obsolete', file_get_contents($publicRoot.'/obsolete.txt'));
    }

    public function test_deletion_log_lists_only_removed_paths_and_summarizes_already_absent_entries(): void
    {
        [$coreRoot, $publicRoot] = $this->createLayout(false);
        file_put_contents($publicRoot.'/obsolete.txt', 'obsolete');
        $log = new UpdateLog('filesystem-test-'.bin2hex(random_bytes(4)));

        $this->transaction($coreRoot, $publicRoot)->apply(
            [],
            ['public/obsolete.txt', 'core/already-absent.txt'],
            $this->root.'/staging',
            $log,
        );

        $contents = (string) file_get_contents($log->path());
        $this->assertStringContainsString('Removed obsolete path: public/obsolete.txt', $contents);
        $this->assertStringNotContainsString('Removed obsolete path: core/already-absent.txt', $contents);
        $this->assertStringContainsString('Obsolete paths checked: 2; removed: 1; already absent: 1.', $contents);
    }

    public function test_vds_agent_normalizes_new_program_files_under_a_restrictive_umask(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permissions are not available on Windows.');
        }

        [$coreRoot, $publicRoot] = $this->createLayout(false);
        mkdir($this->root.'/staging/payload/core/resources/views/components', 0775, true);
        file_put_contents($this->root.'/staging/payload/core/resources/views/components/new.blade.php', '<div>ok</div>');
        $files = [$this->file(
            'payload/core/resources/views/components/new.blade.php',
            'core/resources/views/components/new.blade.php',
            '<div>ok</div>',
        )];
        $uid = fileowner($coreRoot);
        $gid = filegroup($coreRoot);
        $this->assertIsInt($uid);
        $this->assertIsInt($gid);

        $previousUmask = umask(0077);
        try {
            $this->transaction($coreRoot, $publicRoot)->apply(
                $files,
                [],
                $this->root.'/staging',
                new UpdateLog('filesystem-test-'.bin2hex(random_bytes(4))),
                [
                    'deployment_user' => 'test-owner',
                    'deployment_uid' => $uid,
                    'web_group' => 'test-web',
                    'web_gid' => $gid,
                ],
            );
        } finally {
            umask($previousUmask);
        }

        $target = $coreRoot.'/resources/views/components/new.blade.php';
        $this->assertSame(0640, fileperms($target) & 07777);
        $this->assertSame(02750, fileperms(dirname($target)) & 07777);
        $this->assertSame($gid, filegroup($target));
    }

    public function test_vds_agent_normalizes_restored_program_files_after_rollback(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permissions are not available on Windows.');
        }

        [$coreRoot, $publicRoot] = $this->createLayout(false);
        mkdir($coreRoot.'/resources/views/components', 0775, true);
        $target = $coreRoot.'/resources/views/components/existing.blade.php';
        file_put_contents($target, '<div>old</div>');
        chmod($target, 0600);
        mkdir($this->root.'/staging/payload/core/resources/views/components', 0775, true);
        file_put_contents($this->root.'/staging/payload/core/resources/views/components/existing.blade.php', '<div>new</div>');

        $files = [$this->file(
            'payload/core/resources/views/components/existing.blade.php',
            'core/resources/views/components/existing.blade.php',
            '<div>new</div>',
        )];
        $uid = fileowner($coreRoot);
        $gid = filegroup($coreRoot);
        $this->assertIsInt($uid);
        $this->assertIsInt($gid);
        $permissions = [
            'deployment_user' => 'test-owner',
            'deployment_uid' => $uid,
            'web_group' => 'test-web',
            'web_gid' => $gid,
        ];
        $transaction = $this->transaction($coreRoot, $publicRoot);
        $log = new UpdateLog('filesystem-test-'.bin2hex(random_bytes(4)));
        $backup = $transaction->backup($files, [], $this->root.'/backup', $log);
        $transaction->apply($files, [], $this->root.'/staging', $log, $permissions);

        $previousUmask = umask(0077);
        try {
            $transaction->rollback($backup, $log, $permissions);
        } finally {
            umask($previousUmask);
        }

        $this->assertSame('<div>old</div>', file_get_contents($target));
        $this->assertSame(0640, fileperms($target) & 07777);
        $this->assertSame(02750, fileperms(dirname($target)) & 07777);
        $this->assertSame($gid, filegroup($target));
    }

    #[DataProvider('invalidDeploymentPermissions')]
    public function test_invalid_vds_deployment_identity_is_rejected_before_any_target_is_changed(array $permissions): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX deployment identities are not used on Windows.');
        }

        [$coreRoot, $publicRoot] = $this->createLayout(false);
        $target = $coreRoot.'/existing.txt';
        file_put_contents($target, 'keep');
        $transaction = $this->transaction($coreRoot, $publicRoot);

        try {
            $transaction->apply(
                [],
                ['core/existing.txt'],
                $this->root.'/staging',
                new UpdateLog('filesystem-test-'.bin2hex(random_bytes(4))),
                $permissions,
            );
            $this->fail('Invalid VDS deployment identity was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                __('The VDS update agent deployment identity is invalid. Reinstall the agent.'),
                $exception->getMessage(),
            );
        }

        $this->assertFileExists($target);
        $this->assertSame('keep', file_get_contents($target));
    }

    public function test_corrupted_backup_blocks_rollback_before_any_target_is_changed(): void
    {
        [$coreRoot, $publicRoot] = $this->createLayout(false);
        file_put_contents($coreRoot.'/existing.txt', 'old');
        mkdir($this->root.'/staging/payload/core', 0775, true);
        file_put_contents($this->root.'/staging/payload/core/existing.txt', 'new');

        $files = [$this->file('payload/core/existing.txt', 'core/existing.txt', 'new')];
        $transaction = $this->transaction($coreRoot, $publicRoot);
        $log = new UpdateLog('filesystem-test-'.bin2hex(random_bytes(4)));
        $backup = $transaction->backup($files, [], $this->root.'/backup', $log);
        $transaction->apply($files, [], $this->root.'/staging', $log);
        file_put_contents($this->root.'/backup/files/core/existing.txt', 'damaged');

        try {
            $transaction->rollback($backup, $log);
            $this->fail('A damaged file backup was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                __('The update file backup failed integrity verification: :target', ['target' => 'core/existing.txt']),
                $exception->getMessage(),
            );
        }

        $this->assertSame('new', file_get_contents($coreRoot.'/existing.txt'));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidDeploymentPermissions(): array
    {
        return [
            'missing deployment uid' => [[
                'deployment_user' => 'owner',
                'web_group' => 'www-data',
                'web_gid' => 33,
            ]],
            'string deployment uid' => [[
                'deployment_user' => 'owner',
                'deployment_uid' => '1000',
                'web_group' => 'www-data',
                'web_gid' => 33,
            ]],
            'negative deployment uid' => [[
                'deployment_user' => 'owner',
                'deployment_uid' => -1,
                'web_group' => 'www-data',
                'web_gid' => 33,
            ]],
            'empty deployment user' => [[
                'deployment_user' => '   ',
                'deployment_uid' => 1000,
                'web_group' => 'www-data',
                'web_gid' => 33,
            ]],
            'empty web group' => [[
                'deployment_user' => 'owner',
                'deployment_uid' => 1000,
                'web_group' => '',
                'web_gid' => 33,
            ]],
            'string web gid' => [[
                'deployment_user' => 'owner',
                'deployment_uid' => 1000,
                'web_group' => 'www-data',
                'web_gid' => '33',
            ]],
            'negative web gid' => [[
                'deployment_user' => 'owner',
                'deployment_uid' => 1000,
                'web_group' => 'www-data',
                'web_gid' => -1,
            ]],
        ];
    }

    /** @return array<string, array{bool}> */
    public static function installationLayouts(): array
    {
        return [
            'standard public root' => [false],
            'split public root' => [true],
        ];
    }

    /** @return array{string, string} */
    private function createLayout(bool $split): array
    {
        $coreRoot = $this->root.'/core';
        $publicRoot = $split ? $this->root.'/public' : $coreRoot.'/public';
        mkdir($coreRoot, 0775, true);
        mkdir($publicRoot, 0775, true);

        return [$coreRoot, $publicRoot];
    }

    private function transaction(string $coreRoot, string $publicRoot): UpdateFilesystemTransaction
    {
        return new UpdateFilesystemTransaction(
            new UpdateInstallationLayout($coreRoot, $publicRoot),
            new UpdatePathPolicy,
        );
    }

    /** @return array{source: string, target: string, sha256: string, size: int} */
    private function file(string $source, string $target, string $contents): array
    {
        return [
            'source' => $source,
            'target' => $target,
            'sha256' => hash('sha256', $contents),
            'size' => strlen($contents),
        ];
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
