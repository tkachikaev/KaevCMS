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
