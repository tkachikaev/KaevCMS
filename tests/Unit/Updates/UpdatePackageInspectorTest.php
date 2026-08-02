<?php

namespace Tests\Unit\Updates;

use App\Services\Updates\UpdateInstallationLayout;
use App\Services\Updates\UpdatePackageInspector;
use App\Services\Updates\UpdatePathPolicy;
use Closure;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

#[RequiresPhpExtension('zip')]
class UpdatePackageInspectorTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryArchives = [];

    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryArchives as $archive) {
            @unlink($archive);
        }

        foreach ($this->temporaryDirectories as $directory) {
            File::deleteDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_existing_secure_staging_directory_does_not_require_owner_chmod(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unix directory permissions are not available on Windows.');
        }

        $stagingRoot = storage_path('framework/testing/update-staging-'.bin2hex(random_bytes(8)));
        $this->temporaryDirectories[] = $stagingRoot;
        $this->assertTrue(mkdir($stagingRoot, 0770, true));
        $this->assertTrue(chmod($stagingRoot, 02770));

        $archive = $this->package();
        $chmodCalls = 0;
        $chmod = static function (string $path, int $permissions) use (&$chmodCalls): bool {
            $chmodCalls++;

            return false;
        };
        $package = $this->inspector($stagingRoot, $chmod)->inspect($archive, '0.32.4');

        try {
            $this->assertSame('0.33.0', $package->targetVersion);
            $this->assertDirectoryExists($package->stagingPath);
            $this->assertSame(0, $chmodCalls);
        } finally {
            File::deleteDirectory($package->stagingPath);
        }
    }

    public function test_symbolic_link_staging_root_is_rejected(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symbolic-link staging validation is covered on Unix systems.');
        }

        $target = storage_path('framework/testing/update-staging-target-'.bin2hex(random_bytes(8)));
        $stagingRoot = storage_path('framework/testing/update-staging-link-'.bin2hex(random_bytes(8)));
        $this->temporaryDirectories[] = $target;
        $this->temporaryArchives[] = $stagingRoot;
        $this->assertTrue(mkdir($target, 0770, true));
        $this->assertTrue(symlink($target, $stagingRoot));

        $archive = $this->package();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('Unable to secure the update staging directory.'));

        $this->inspector($stagingRoot)->inspect($archive, '0.32.4');
    }

    public function test_cumulative_package_accepts_an_intermediate_supported_version(): void
    {
        $archive = $this->package();
        $package = $this->inspector()->inspect($archive, '0.32.4');

        try {
            $this->assertSame('0.33.0', $package->targetVersion);
            $this->assertSame('0.32.4', $package->currentVersion);
            $this->assertSame('0.32.0', $package->minimumVersion);
            $this->assertSame('0.32.12', $package->maximumVersion);
            $this->assertCount(2, $package->files);
        } finally {
            File::deleteDirectory($package->stagingPath);
        }
    }

    public function test_package_with_an_undeclared_file_is_rejected(): void
    {
        $archive = $this->package(extraEntries: ['payload/core/hidden.php' => '<?php']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            __('The update archive contains an undeclared file: :path', ['path' => 'payload/core/hidden.php']),
        );

        $this->inspector()->inspect($archive, '0.32.0');
    }

    public function test_package_with_a_damaged_payload_is_rejected(): void
    {
        $archive = $this->package(checksumOverride: str_repeat('0', 64));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            __('The update payload checksum does not match the manifest: :source', ['source' => 'payload/public/assets/update-test.txt']),
        );

        $this->inspector()->inspect($archive, '0.32.0');
    }

    public function test_package_that_changes_composer_dependencies_is_rejected(): void
    {
        $archive = $this->package(changeComposerLock: true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            __('This update changes Composer dependencies. Web Updater 1.0 requires a full deployment for dependency changes.'),
        );

        $this->inspector()->inspect($archive, '0.32.0');
    }

    public function test_package_outside_the_supported_source_range_is_rejected(): void
    {
        $archive = $this->package();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            __('This package supports KaevCMS :minimum through :maximum; the installed version is :current.', [
                'minimum' => '0.32.0',
                'maximum' => '0.32.12',
                'current' => '0.31.12',
            ]),
        );

        $this->inspector()->inspect($archive, '0.31.12');
    }

    private function inspector(?string $stagingRoot = null, ?Closure $chmod = null): UpdatePackageInspector
    {
        return new UpdatePackageInspector(
            $this->app->make(UpdateInstallationLayout::class),
            $this->app->make(UpdatePathPolicy::class),
            $stagingRoot,
            $chmod,
        );
    }

    /**
     * @param  array<string, string>  $extraEntries
     */
    private function package(
        array $extraEntries = [],
        ?string $checksumOverride = null,
        bool $changeComposerLock = false,
    ): string {
        $archive = tempnam(sys_get_temp_dir(), 'kaevcms-inspector-');
        $this->assertIsString($archive);
        $this->temporaryArchives[] = $archive;

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        $payloads = [
            'payload/core/VERSION' => "0.33.0\n",
            'payload/public/assets/update-test.txt' => "payload\n",
        ];
        if ($changeComposerLock) {
            $payloads['payload/core/composer.lock'] = "{\"changed\":true}\n";
        }

        $files = [];
        foreach ($payloads as $source => $contents) {
            $this->assertTrue($zip->addFromString($source, $contents));
            $target = substr($source, strlen('payload/'));
            $files[] = [
                'source' => $source,
                'target' => $target,
                'sha256' => $source === 'payload/public/assets/update-test.txt' && $checksumOverride !== null
                    ? $checksumOverride
                    : hash('sha256', $contents),
                'size' => strlen($contents),
            ];
        }

        foreach ($extraEntries as $source => $contents) {
            $this->assertTrue($zip->addFromString($source, $contents));
        }

        $manifest = [
            'schema' => 1,
            'package_id' => 'kaevcms-0.33.0',
            'name' => 'KaevCMS 0.33.0',
            'target_version' => '0.33.0',
            'minimum_version' => '0.32.0',
            'maximum_version' => '0.32.12',
            'requires' => [
                'php' => '8.3.0',
                'extensions' => ['zip'],
            ],
            'migrate' => true,
            'files' => $files,
            'delete' => [],
            'changelog' => ['Inspector test'],
        ];

        $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($encoded);
        $this->assertTrue($zip->addFromString('kaevcms-update.json', $encoded."\n"));
        $this->assertTrue($zip->close());

        return $archive;
    }
}
