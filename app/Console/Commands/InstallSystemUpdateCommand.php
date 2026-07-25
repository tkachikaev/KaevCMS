<?php

namespace App\Console\Commands;

use App\Models\SystemUpdate;
use App\Services\Releases\InstalledVersion;
use App\Services\Updates\InspectedUpdatePackage;
use App\Services\Updates\SystemUpdateInstaller;
use App\Services\Updates\UpdateInstallationLayout;
use App\Services\Updates\UpdatePackageInspector;
use App\Services\Updates\UpdatePreflight;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class InstallSystemUpdateCommand extends Command
{
    protected $signature = 'kaevcms:update
        {package : Absolute or current-directory-relative path to a cumulative KaevCMS update ZIP}
        {--yes : Apply the verified update without an interactive confirmation}';

    protected $description = 'Safely apply a cumulative KaevCMS update as the deployment user';

    public function handle(
        InstalledVersion $installedVersion,
        UpdateInstallationLayout $layout,
        UpdatePackageInspector $inspector,
        UpdatePreflight $preflight,
        SystemUpdateInstaller $installer,
    ): int {
        $sourcePath = $this->resolveSourcePath((string) $this->argument('package'));
        if ($sourcePath === null) {
            $this->error('The update package does not exist or is not a readable ZIP file.');

            return self::FAILURE;
        }

        try {
            $currentVersion = $installedVersion->current();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $uuid = (string) Str::uuid();
        $packageDirectory = storage_path('app/kaevcms/updates/packages');
        $storedPath = $packageDirectory.DIRECTORY_SEPARATOR.$uuid.'.zip';
        $package = null;
        $update = null;

        try {
            $this->preparePrivateDirectory($packageDirectory);
            if (! copy($sourcePath, $storedPath)) {
                throw new RuntimeException('Unable to copy the update package into protected storage.');
            }
            $this->protectFile($storedPath);

            $package = $inspector->inspect($storedPath, $currentVersion);
            $checks = $preflight->inspect($package);

            $this->newLine();
            $this->info("KaevCMS {$currentVersion} -> {$package->targetVersion}");
            $this->line('Installation type: '.$layout->type());
            $this->line('Package: '.$package->name);
            $this->line('Files: '.count($package->files).'; deletions: '.count($package->delete));
            $this->newLine();

            $this->table(
                ['Check', 'Result', 'Details'],
                array_map(
                    static fn (array $check): array => [
                        $check['label'],
                        $check['passed'] ? 'OK' : 'FAILED',
                        $check['detail'],
                    ],
                    $checks,
                ),
            );

            if (! $preflight->passes($checks)) {
                throw new RuntimeException('The update preflight checks did not pass. No files were changed.');
            }

            if (! $this->option('yes') && ! $this->confirm('Apply this update now?', false)) {
                $this->warn('Update cancelled. No files were changed.');

                return self::SUCCESS;
            }

            $update = SystemUpdate::query()->create([
                'uuid' => $uuid,
                'admin_id' => null,
                'package_id' => $package->packageId,
                'from_version' => $package->currentVersion,
                'target_version' => $package->targetVersion,
                'status' => SystemUpdate::STATUS_STAGED,
                'phase' => null,
                'installation_type' => $package->installationType,
                'package_path' => 'kaevcms/updates/packages/'.$uuid.'.zip',
                'package_sha256' => $package->archiveSha256,
                'file_count' => count($package->files),
                'delete_count' => count($package->delete),
                'manifest' => $package->manifest,
            ]);

            $maintenanceSecret = Str::random(48);
            $this->warn('The website will briefly enter maintenance mode.');
            $this->line('Temporary recovery URL: '.url('/'.$maintenanceSecret));
            $this->line('Do not close this terminal until the command finishes.');
            $this->newLine();

            $installer->apply($update, $maintenanceSecret);
            $this->newLine();
            $this->info("KaevCMS {$package->targetVersion} installed successfully.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->newLine();
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($package instanceof InspectedUpdatePackage) {
                $this->removeDirectory($package->stagingPath);
            }

            if (! $update instanceof SystemUpdate || $update->status === SystemUpdate::STATUS_STAGED) {
                @unlink($storedPath);
                if ($update instanceof SystemUpdate && $update->exists) {
                    $update->delete();
                }
            }
        }
    }

    private function resolveSourcePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (! $this->isAbsolutePath($path)) {
            $workingDirectory = getcwd();
            if ($workingDirectory === false) {
                return null;
            }
            $path = $workingDirectory.DIRECTORY_SEPARATOR.$path;
        }

        $resolved = realpath($path);
        if (! is_string($resolved) || ! is_file($resolved) || ! is_readable($resolved)) {
            return null;
        }

        return strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) === 'zip' ? $resolved : null;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function preparePrivateDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('Unable to create the protected update package directory.');
        }

        if (PHP_OS_FAMILY !== 'Windows' && ! @chmod($path, 0700)) {
            throw new RuntimeException('Unable to secure the update package directory.');
        }
    }

    private function protectFile(string $path): void
    {
        if (PHP_OS_FAMILY !== 'Windows' && ! @chmod($path, 0600)) {
            throw new RuntimeException('Unable to secure the copied update package.');
        }
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
