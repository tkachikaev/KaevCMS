<?php

namespace Tests\Unit\Updates;

use App\Services\Updates\UpdateDependencyBundle;
use App\Services\Updates\UpdateInstallationLayout;
use App\Services\Updates\UpdateLog;
use App\Services\Updates\UpdatePathPolicy;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class UpdateDependencyBundleTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kaevcms-deps-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/core/vendor/composer', 0777, true);
        mkdir($this->root.'/core/bootstrap/cache', 0777, true);
        mkdir($this->root.'/public', 0777, true);
        mkdir($this->root.'/backup', 0777, true);
        file_put_contents($this->root.'/core/vendor/autoload.php', "<?php // old\n");
        file_put_contents($this->root.'/core/vendor/old-only.txt', "old\n");
        file_put_contents($this->root.'/core/bootstrap/cache/packages.php', "<?php return [];\n");
        file_put_contents($this->root.'/core/bootstrap/cache/.gitignore', "*\n");
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        foreach (glob(storage_path('logs/update-deps-test-*.log')) ?: [] as $log) {
            @unlink($log);
        }

        parent::tearDown();
    }

    public function test_bundle_is_swapped_in_and_the_previous_tree_kept_for_rollback(): void
    {
        $service = $this->service();
        $log = $this->log();

        $staged = $service->stage($this->bundle(), $log);
        $service->swap($staged, $this->root.'/backup', 'uuid-1', ['target_version' => '9.9.9', 'migrate' => true], $log);

        $this->assertStringContainsString('new', (string) file_get_contents($this->root.'/core/vendor/autoload.php'));
        $this->assertFileDoesNotExist($this->root.'/core/vendor/old-only.txt');
        $this->assertFileExists($this->root.'/core/vendor.kaevcms-previous/old-only.txt');
        $this->assertDirectoryDoesNotExist($this->root.'/core/vendor.kaevcms-next');

        // Discovery caches name providers of the old tree and must be dropped; dotfiles stay.
        $this->assertFileDoesNotExist($this->root.'/core/bootstrap/cache/packages.php');
        $this->assertFileExists($this->root.'/core/bootstrap/cache/.gitignore');

        $state = $service->state($this->root.'/backup');
        $this->assertIsArray($state);
        $this->assertTrue($state['swapped']);
        $this->assertSame('9.9.9', $state['target_version']);
    }

    public function test_restore_puts_the_previous_tree_back(): void
    {
        $service = $this->service();
        $log = $this->log();
        $service->swap($service->stage($this->bundle(), $log), $this->root.'/backup', 'uuid-2', [], $log);

        $service->restore($this->root.'/backup', $log);

        $this->assertSame("<?php // old\n", file_get_contents($this->root.'/core/vendor/autoload.php'));
        $this->assertFileExists($this->root.'/core/vendor/old-only.txt');
        $this->assertDirectoryDoesNotExist($this->root.'/core/vendor.kaevcms-previous');
        $this->assertDirectoryDoesNotExist($this->root.'/core/vendor.kaevcms-failed');
        $this->assertFalse($service->state($this->root.'/backup')['swapped'] ?? true);

        // A second restore is a no-op instead of an error.
        $service->restore($this->root.'/backup', $log);
        $this->assertFileExists($this->root.'/core/vendor/old-only.txt');
    }

    public function test_restore_without_a_swap_only_removes_a_staged_tree(): void
    {
        $service = $this->service();
        $service->stage($this->bundle(), $this->log());

        $service->restore($this->root.'/backup', $this->log());

        $this->assertDirectoryDoesNotExist($this->root.'/core/vendor.kaevcms-next');
        $this->assertSame("<?php // old\n", file_get_contents($this->root.'/core/vendor/autoload.php'));
    }

    public function test_discard_removes_the_kept_previous_tree_after_success(): void
    {
        $service = $this->service();
        $log = $this->log();
        $service->swap($service->stage($this->bundle(), $log), $this->root.'/backup', 'uuid-3', [], $log);

        $service->discardPrevious($log);

        $this->assertDirectoryDoesNotExist($this->root.'/core/vendor.kaevcms-previous');
        $this->assertStringContainsString('new', (string) file_get_contents($this->root.'/core/vendor/autoload.php'));
    }

    public function test_incomplete_bundle_is_rejected_and_leaves_vendor_untouched(): void
    {
        $service = $this->service();

        try {
            $service->stage($this->bundle(['autoload.php' => "<?php\n"]), $this->log());
            $this->fail('An incomplete bundle must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('vendor/composer/installed.json', $exception->getMessage());
        }

        $this->assertDirectoryDoesNotExist($this->root.'/core/vendor.kaevcms-next');
        $this->assertSame("<?php // old\n", file_get_contents($this->root.'/core/vendor/autoload.php'));
    }

    public function test_bundle_entries_outside_vendor_are_rejected(): void
    {
        $path = $this->root.'/evil.zip';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE));
        $zip->addFromString('app/Evil.php', "<?php\n");
        $zip->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('app/Evil.php');

        $this->service()->stage(['bundle_path' => $path, 'files' => 1, 'bytes' => 6], $this->log());
    }

    public function test_bundle_that_does_not_match_its_manifest_is_rejected(): void
    {
        $bundle = $this->bundle();
        $bundle['bytes']--;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('The dependency bundle does not match its manifest.'));

        $this->service()->stage($bundle, $this->log());
    }

    private function service(): UpdateDependencyBundle
    {
        return new UpdateDependencyBundle(
            new UpdateInstallationLayout($this->root.'/core', $this->root.'/public'),
            new UpdatePathPolicy,
        );
    }

    private function log(): UpdateLog
    {
        return new UpdateLog('deps-test-'.bin2hex(random_bytes(4)));
    }

    /**
     * @param  array<string, string>|null  $files
     * @return array{bundle_path: string, files: int, bytes: int}
     */
    private function bundle(?array $files = null): array
    {
        $files ??= [
            'autoload.php' => "<?php // new\n",
            'composer/installed.json' => "{\"packages\":[]}\n",
            'composer/autoload_real.php' => "<?php\n",
            'acme/lib/src/Lib.php' => "<?php\n",
        ];
        $path = $this->root.'/vendor-'.bin2hex(random_bytes(3)).'.zip';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE));
        $bytes = 0;
        foreach ($files as $relative => $contents) {
            $zip->addFromString('vendor/'.$relative, $contents);
            $bytes += strlen($contents);
        }
        $zip->close();

        return ['bundle_path' => $path, 'files' => count($files), 'bytes' => $bytes];
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                $this->removeTree($path.DIRECTORY_SEPARATOR.$item);
            }
        }
        @rmdir($path);
    }
}
