<?php

namespace Tests\Feature\Updates;

use App\Models\Admin;
use App\Models\SystemUpdate;
use App\Services\Releases\InstalledVersion;
use App\Services\Updates\SystemUpdateInstaller;
use App\Services\Updates\UpdateInstallationLayout;
use App\Services\Updates\UpdatePackageInspector;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;
use ZipArchive;

/**
 * End-to-end Web Update with a composer.lock change against an isolated installation tree:
 * vendor/ is swapped by the first process and the update is finished (or rolled back) by a
 * separate finalize call, as the fresh PHP process does in production.
 */
#[RequiresPhpExtension('zip')]
class DependencyUpdateFlowTest extends TestCase
{
    private string $root;

    private string $databasePath;

    private string $originalConnection;

    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kaevcms-dep-flow-'.bin2hex(random_bytes(6));
        foreach (['core/vendor/composer', 'core/bootstrap/cache', 'public'] as $directory) {
            mkdir($this->root.'/'.$directory, 0777, true);
        }
        file_put_contents($this->root.'/core/VERSION', "1.0.0\n");
        file_put_contents($this->root.'/core/composer.lock', "{\"lock\":\"old\"}\n");
        file_put_contents($this->root.'/core/vendor/autoload.php', "<?php // old tree\n");
        file_put_contents($this->root.'/core/vendor/composer/installed.json', "{\"old\":true}\n");
        file_put_contents($this->root.'/core/bootstrap/cache/packages.php', "<?php return [];\n");
        $this->app->instance(UpdateInstallationLayout::class, new UpdateInstallationLayout($this->root.'/core', $this->root.'/public'));

        // The update backs up and restores a real SQLite file, so use one instead of :memory:.
        $this->databasePath = $this->root.'/cms.sqlite';
        touch($this->databasePath);
        $this->originalConnection = (string) config('database.default');
        config([
            'database.default' => 'dependency_flow',
            'database.connections.dependency_flow' => [
                'driver' => 'sqlite',
                'database' => $this->databasePath,
                'prefix' => '',
                'foreign_key_constraints' => true,
                'busy_timeout' => null,
                'journal_mode' => null,
                'synchronous' => null,
            ],
            'cms.installed_version_marker' => $this->root.'/installed-version.json',
        ]);
        DB::purge('dependency_flow');
        Artisan::call('migrate', ['--force' => true]);
        app(InstalledVersion::class)->mark('1.0.0');
    }

    protected function tearDown(): void
    {
        if (app()->isDownForMaintenance()) {
            Artisan::call('up');
        }
        @unlink(storage_path('framework/maintenance.php'));
        DB::purge('dependency_flow');
        config(['database.default' => $this->originalConnection]);
        foreach ([$this->root, ...$this->cleanup] as $path) {
            $this->removeTree($path);
        }

        parent::tearDown();
    }

    public function test_dependency_update_swaps_vendor_then_finalizes_in_a_separate_step(): void
    {
        $update = $this->stagedUpdate();
        $installer = $this->app->make(SystemUpdateInstaller::class);
        // A copy left by an older updater that installed the bundle as a plain file.
        mkdir($this->root.'/core/deployment/updates/dependency-bundle', 0777, true);
        file_put_contents($this->root.'/core/deployment/updates/dependency-bundle/vendor.zip', 'stale');

        $handedOff = $installer->apply($update, Str::random(40));

        $this->assertTrue($handedOff->awaitsFinalization());
        $this->assertSame(SystemUpdate::PHASE_DEPENDENCIES, $handedOff->phase);
        $this->assertStringContainsString('new tree', (string) file_get_contents($this->root.'/core/vendor/autoload.php'));
        $this->assertFileExists($this->root.'/core/vendor.kaevcms-previous/autoload.php');
        $this->assertSame("1.1.0\n", file_get_contents($this->root.'/core/VERSION'));
        $this->assertFileDoesNotExist($this->root.'/core/bootstrap/cache/packages.php');
        // The site stays in maintenance until the fresh process finishes the update.
        $this->assertTrue(app()->isDownForMaintenance());
        $this->assertSame('1.0.0', app(InstalledVersion::class)->current());

        $finished = $installer->finalize($handedOff);

        $this->assertSame(SystemUpdate::STATUS_SUCCEEDED, $finished->status);
        $this->assertSame(SystemUpdate::PHASE_COMPLETED, $finished->phase);
        $this->assertSame('1.1.0', app(InstalledVersion::class)->current());
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertDirectoryDoesNotExist($this->root.'/core/vendor.kaevcms-previous');
        $this->assertStringContainsString('new tree', (string) file_get_contents($this->root.'/core/vendor/autoload.php'));
        $this->assertFileDoesNotExist($this->root.'/core/deployment/updates/dependency-bundle/vendor.zip');
    }

    public function test_failed_finalization_rolls_back_files_dependencies_and_lifts_maintenance(): void
    {
        $update = $this->stagedUpdate();
        $installer = $this->app->make(SystemUpdateInstaller::class);
        $handedOff = $installer->apply($update, Str::random(40));
        $this->assertTrue($handedOff->awaitsFinalization());

        // Make the final version marker write fail, after migrations already ran.
        file_put_contents($this->root.'/blocked', 'not a directory');
        config(['cms.installed_version_marker' => $this->root.'/blocked/installed-version.json']);

        try {
            $installer->finalize($handedOff);
            $this->fail('Finalization was expected to fail.');
        } catch (\RuntimeException) {
            // Expected.
        }

        $fresh = $update->fresh();
        $this->assertSame(SystemUpdate::STATUS_FAILED, $fresh?->status);
        $this->assertSame("<?php // old tree\n", file_get_contents($this->root.'/core/vendor/autoload.php'));
        $this->assertDirectoryDoesNotExist($this->root.'/core/vendor.kaevcms-previous');
        $this->assertSame("1.0.0\n", file_get_contents($this->root.'/core/VERSION'));
        $this->assertSame("{\"lock\":\"old\"}\n", file_get_contents($this->root.'/core/composer.lock'));
        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function test_broken_bundle_is_rolled_back_before_vendor_is_touched(): void
    {
        $update = $this->stagedUpdate(brokenBundle: true);

        try {
            $this->app->make(SystemUpdateInstaller::class)->apply($update, Str::random(40));
            $this->fail('An incomplete dependency bundle must fail the update.');
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertSame(SystemUpdate::STATUS_FAILED, $update->fresh()?->status);
        $this->assertSame("<?php // old tree\n", file_get_contents($this->root.'/core/vendor/autoload.php'));
        $this->assertDirectoryDoesNotExist($this->root.'/core/vendor.kaevcms-next');
        $this->assertSame("1.0.0\n", file_get_contents($this->root.'/core/VERSION'));
        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function test_panel_hands_off_to_a_signed_finalize_request_that_bypasses_maintenance(): void
    {
        $owner = Admin::factory()->create(['password' => Hash::make('CorrectPassword123')]);
        $update = $this->stagedUpdate();
        $this->actingAs($owner, 'admin')->get('/admin/settings/system/updates/'.$update->id)->assertOk();

        $response = $this->actingAs($owner, 'admin')->post('/admin/settings/system/updates/'.$update->id.'/apply', [
            'current_password' => 'CorrectPassword123',
            'trusted_source' => '1',
            'confirmation' => '1',
        ]);

        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('/admin/settings/system/updates/'.$update->id.'/finalize', $location);
        $this->assertStringContainsString('signature=', $location);
        $response->assertCookie('laravel_maintenance');
        $this->assertTrue($update->fresh()?->awaitsFinalization());

        // Like Laravel's own bypass cookie it is not encrypted: maintenance is checked before decryption.
        $bypass = (string) $response->getCookie('laravel_maintenance', false)?->getValue();

        // Without the bypass cookie the site is in maintenance; with it, an unsigned call is still refused.
        $this->actingAs($owner, 'admin')
            ->get('/admin/settings/system/updates/'.$update->id.'/finalize')
            ->assertStatus(503);
        $this->actingAs($owner, 'admin')
            ->withUnencryptedCookie('laravel_maintenance', $bypass)
            ->get('/admin/settings/system/updates/'.$update->id.'/finalize')
            ->assertForbidden();
        $this->assertTrue($update->fresh()?->awaitsFinalization());

        $this->actingAs($owner, 'admin')
            ->withUnencryptedCookie('laravel_maintenance', $bypass)
            ->get($location)
            ->assertRedirect('/admin/settings/system/updates/'.$update->id)
            ->assertSessionHas('status');

        $this->assertSame(SystemUpdate::STATUS_SUCCEEDED, $update->fresh()?->status);
        $this->assertSame('1.1.0', app(InstalledVersion::class)->current());
        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function test_finalize_refuses_an_update_that_did_not_swap_dependencies(): void
    {
        $update = $this->stagedUpdate();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(__('This update is not waiting to be finalized.'));

        $this->app->make(SystemUpdateInstaller::class)->finalize($update);
    }

    private function stagedUpdate(bool $brokenBundle = false): SystemUpdate
    {
        $uuid = (string) Str::uuid();
        $relative = 'kaevcms/updates/packages/'.$uuid.'.zip';
        $archive = storage_path('app/'.$relative);
        if (! is_dir(dirname($archive))) {
            mkdir(dirname($archive), 0777, true);
        }
        $this->buildPackage($archive, $brokenBundle);
        $this->cleanup[] = $archive;
        $this->cleanup[] = storage_path('app/kaevcms/update-backups/'.$uuid);
        $this->cleanup[] = storage_path('logs/update-'.$uuid.'.log');

        return SystemUpdate::query()->create([
            'uuid' => $uuid,
            'package_id' => 'kaevcms-1.1.0',
            'from_version' => '1.0.0',
            'target_version' => '1.1.0',
            'status' => SystemUpdate::STATUS_STAGED,
            'installation_type' => 'split',
            'package_path' => $relative,
            'package_sha256' => hash_file('sha256', $archive),
            'file_count' => 2,
            'delete_count' => 0,
            'manifest' => ['schema' => 1, 'migrate' => true],
        ]);
    }

    private function buildPackage(string $archive, bool $brokenBundle = false): void
    {
        $vendorBundle = $this->root.'/vendor-bundle.zip';
        $bundle = new ZipArchive;
        $this->assertTrue($bundle->open($vendorBundle, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $vendorFiles = [
            'autoload.php' => "<?php // new tree\n",
            'composer/installed.json' => "{\"new\":true}\n",
            'composer/autoload_real.php' => "<?php\n",
        ];
        if ($brokenBundle) {
            unset($vendorFiles['composer/installed.json']);
        }
        $bytes = 0;
        foreach ($vendorFiles as $relative => $contents) {
            $bundle->addFromString('vendor/'.$relative, $contents);
            $bytes += strlen($contents);
        }
        $bundle->close();

        $payloads = [
            'payload/core/VERSION' => "1.1.0\n",
            'payload/core/composer.lock' => "{\"lock\":\"new\"}\n",
        ];
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $files = [];
        foreach ($payloads as $source => $contents) {
            $zip->addFromString($source, $contents);
            $files[] = ['source' => $source, 'target' => substr($source, 8), 'sha256' => hash('sha256', $contents), 'size' => strlen($contents)];
        }
        $bundleSource = 'payload/'.UpdatePackageInspector::DEPENDENCY_BUNDLE_TARGET;
        $zip->addFile($vendorBundle, $bundleSource);
        $files[] = ['source' => $bundleSource, 'target' => UpdatePackageInspector::DEPENDENCY_BUNDLE_TARGET, 'sha256' => hash_file('sha256', $vendorBundle), 'size' => filesize($vendorBundle)];
        $manifest = [
            'schema' => 1,
            'package_id' => 'kaevcms-1.1.0',
            'name' => 'KaevCMS 1.1.0',
            'target_version' => '1.1.0',
            'minimum_version' => '1.0.0',
            'maximum_version' => '1.0.0',
            'requires' => ['php' => '8.3.0', 'extensions' => ['zip']],
            'migrate' => true,
            'files' => $files,
            'delete' => [],
            'changelog' => ['Dependency flow test'],
            'dependencies' => [
                'schema' => 1,
                'archive' => $bundleSource,
                'sha256' => hash_file('sha256', $vendorBundle),
                'size' => filesize($vendorBundle),
                'files' => count($vendorFiles),
                'bytes' => $bytes,
                'composer_lock_sha256' => hash('sha256', $payloads['payload/core/composer.lock']),
                'platform' => ['php_version_id' => 80300, 'extensions' => ['json']],
            ],
        ];
        $zip->addFromString('kaevcms-update.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();
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
