<?php

namespace App\Services\Updates;

use App\Models\SystemUpdate;
use App\Services\AuditLogger;
use App\Services\Releases\InstalledVersion;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class SystemUpdateInstaller
{
    public function __construct(
        private readonly InstalledVersion $installedVersion,
        private readonly UpdatePackageInspector $inspector,
        private readonly UpdatePreflight $preflight,
        private readonly UpdateDatabaseBackup $databaseBackup,
        private readonly UpdateFilesystemTransaction $filesystem,
        private readonly UpdateLock $updateLock,
        private readonly AuditLogger $auditLogger,
        private readonly Application $application,
    ) {}

    public function apply(SystemUpdate $update, string $maintenanceSecret): SystemUpdate
    {
        if (! $update->isStaged()) {
            throw new RuntimeException(__('Only a staged update package can be installed.'));
        }

        if (preg_match('/\A[a-zA-Z0-9]{32,128}\z/', $maintenanceSecret) !== 1) {
            throw new RuntimeException(__('The update recovery secret is invalid.'));
        }

        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $log = new UpdateLog($update->uuid);
        $lock = $this->updateLock->acquire();
        $currentVersion = null;
        $package = null;
        $fileBackup = null;
        $databaseBackup = null;
        $wasDown = $this->application->isDownForMaintenance();
        $maintenanceActivated = false;
        $installationStarted = false;
        $filesMayHaveChanged = false;
        $databaseMayHaveChanged = false;
        $backupRoot = storage_path('app/kaevcms/update-backups/'.$update->uuid);

        try {
            $interruptedUpdateExists = SystemUpdate::query()
                ->where('status', SystemUpdate::STATUS_APPLYING)
                ->where($update->getKeyName(), '!=', $update->getKey())
                ->exists();
            if ($interruptedUpdateExists) {
                throw new RuntimeException(__('Another interrupted update must be recovered before a new update can start.'));
            }

            $recordedVersion = $this->installedVersion->current();
            if (! is_string($recordedVersion) || $recordedVersion === '') {
                throw new RuntimeException(__('The installed KaevCMS version is not recorded.'));
            }
            $currentVersion = $recordedVersion;

            $log->write("Starting KaevCMS update {$currentVersion} -> {$update->target_version}.");
            $package = $this->inspector->inspect($this->absolutePackagePath($update), $currentVersion);
            $this->assertPackageMatchesRecord($package, $update);

            $checks = $this->preflight->inspect($package);
            if (! $this->preflight->passes($checks)) {
                throw new RuntimeException(__('The update preflight checks did not pass.'));
            }

            $update->forceFill([
                'status' => SystemUpdate::STATUS_APPLYING,
                'phase' => SystemUpdate::PHASE_PREPARING,
                'started_at' => now(),
                'completed_at' => null,
                'error_summary' => null,
                'backup_path' => $this->relativeStoragePath($backupRoot),
                'log_path' => $log->relativePath(),
                'package_sha256' => $package->archiveSha256,
            ])->save();
            $installationStarted = true;
            $log->write('Update phase: preparing backups.');

            if (! $wasDown) {
                $this->runArtisan('down', [
                    '--retry' => 60,
                    '--refresh' => 15,
                    '--secret' => $maintenanceSecret,
                ], $log);
                $maintenanceActivated = true;
            }

            $databaseBackup = $this->databaseBackup->create($backupRoot.'/database', $log);
            $fileBackup = $this->filesystem->backup($package->files, $package->delete, $backupRoot, $log);

            $this->setPhase($update, SystemUpdate::PHASE_FILES, $log);
            $filesMayHaveChanged = true;
            $this->filesystem->apply($package->files, $package->delete, $package->stagingPath, $log);

            if ($package->migrate) {
                $this->setPhase($update, SystemUpdate::PHASE_MIGRATIONS, $log);
                $databaseMayHaveChanged = true;
                $this->runArtisan('migrate', ['--force' => true], $log);
            }

            $this->setPhase($update, SystemUpdate::PHASE_FINALIZING, $log);
            $this->runArtisan('optimize:clear', [], $log);
            $this->runArtisan('queue:restart', [], $log);
            $this->installedVersion->mark($package->targetVersion);

            if ($maintenanceActivated) {
                $this->runArtisan('up', [], $log);
                $maintenanceActivated = false;
            }

            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            $update->forceFill([
                'status' => SystemUpdate::STATUS_SUCCEEDED,
                'phase' => SystemUpdate::PHASE_COMPLETED,
                'completed_at' => now(),
                'error_summary' => null,
            ])->save();

            $log->write("KaevCMS {$package->targetVersion} installed successfully.");
            if (! @unlink($package->archivePath) && is_file($package->archivePath)) {
                $log->write('Unable to remove the installed update package.', 'WARN');
            }
            $this->recordSuccessAudit($package, $currentVersion, $log);
        } catch (Throwable $exception) {
            $log->write($exception->getMessage(), 'ERROR');

            if (! $installationStarted) {
                throw new RuntimeException($exception->getMessage(), previous: $exception);
            }

            $rollbackErrors = [];
            $rollbackReady = true;

            if ($databaseMayHaveChanged) {
                if (! is_array($databaseBackup)) {
                    $rollbackErrors[] = 'Database rollback: required backup is missing.';
                    $rollbackReady = false;
                } else {
                    try {
                        $this->databaseBackup->verify($databaseBackup);
                    } catch (Throwable $rollbackException) {
                        $rollbackErrors[] = 'Database rollback: '.$rollbackException->getMessage();
                        $rollbackReady = false;
                    }
                }
            }
            if ($filesMayHaveChanged) {
                if (! is_array($fileBackup)) {
                    $rollbackErrors[] = 'File rollback: required backup is missing.';
                    $rollbackReady = false;
                } else {
                    try {
                        $this->filesystem->verifyBackup($fileBackup);
                    } catch (Throwable $rollbackException) {
                        $rollbackErrors[] = 'File rollback: '.$rollbackException->getMessage();
                        $rollbackReady = false;
                    }
                }
            }

            if (! $rollbackReady) {
                foreach ($rollbackErrors as $rollbackError) {
                    $log->write($rollbackError, 'ERROR');
                }
                $log->write('Automatic rollback was not started because a required backup is missing.', 'ERROR');
            } else {
                if ($databaseMayHaveChanged && is_array($databaseBackup)) {
                    try {
                        $this->databaseBackup->restore($databaseBackup, $log);
                    } catch (Throwable $rollbackException) {
                        $rollbackMessage = 'Database rollback: '.$rollbackException->getMessage();
                        $rollbackErrors[] = $rollbackMessage;
                        $log->write($rollbackMessage, 'ERROR');
                    }
                }

                if ($filesMayHaveChanged && is_array($fileBackup)) {
                    try {
                        $this->filesystem->rollback($fileBackup, $log);
                    } catch (Throwable $rollbackException) {
                        $rollbackMessage = 'File rollback: '.$rollbackException->getMessage();
                        $rollbackErrors[] = $rollbackMessage;
                        $log->write($rollbackMessage, 'ERROR');
                    }
                }

                if ($rollbackErrors === []) {
                    try {
                        $this->installedVersion->mark($currentVersion);
                    } catch (Throwable $rollbackException) {
                        $rollbackMessage = 'Version rollback: '.$rollbackException->getMessage();
                        $rollbackErrors[] = $rollbackMessage;
                        $log->write($rollbackMessage, 'ERROR');
                    }
                }
            }

            $summary = Str::limit($exception->getMessage(), 400, '');
            if ($rollbackErrors !== []) {
                $summary .= ' '.__('Automatic rollback requires attention.');
            }

            try {
                SystemUpdate::query()->where('uuid', $update->uuid)->update([
                    'status' => SystemUpdate::STATUS_FAILED,
                    'phase' => $update->phase,
                    'completed_at' => now(),
                    'error_summary' => Str::limit($summary, 500, ''),
                    'log_path' => $log->relativePath(),
                    'backup_path' => $this->relativeStoragePath($backupRoot),
                    'updated_at' => now(),
                ]);
            } catch (Throwable) {
                // The file log remains the final recovery source if the database itself cannot be restored.
            }

            $this->recordFailureAudit($update, $currentVersion, $rollbackErrors, $log);

            throw new RuntimeException($summary, previous: $exception);
        } finally {
            if (! $wasDown && ($maintenanceActivated || $this->application->isDownForMaintenance())) {
                try {
                    $this->runArtisan('up', [], $log);
                } catch (Throwable $exception) {
                    $log->write('Unable to leave maintenance mode: '.$exception->getMessage(), 'ERROR');
                }
            }

            if ($package instanceof InspectedUpdatePackage) {
                $this->removeDirectory($package->stagingPath);
            }

            $this->updateLock->release($lock);
        }

        $fresh = $update->fresh();

        return $fresh instanceof SystemUpdate ? $fresh : $update;
    }

    private function assertPackageMatchesRecord(InspectedUpdatePackage $package, SystemUpdate $update): void
    {
        $recordedHash = $update->package_sha256;
        if ($recordedHash !== null && ! hash_equals($recordedHash, $package->archiveSha256)) {
            throw new RuntimeException(__('The staged update archive checksum has changed since upload.'));
        }

        if ($package->packageId !== $update->package_id
            || $package->targetVersion !== $update->target_version
            || $package->currentVersion !== $update->from_version
            || $package->installationType !== $update->installation_type) {
            throw new RuntimeException(__('The staged update record no longer matches the uploaded package.'));
        }
    }

    private function setPhase(SystemUpdate $update, string $phase, UpdateLog $log): void
    {
        $update->forceFill(['phase' => $phase])->save();
        $log->write("Update phase: {$phase}.");
    }

    /** @param array<string, bool|int|string> $parameters */
    private function runArtisan(string $command, array $parameters, UpdateLog $log): void
    {
        $log->write("Running artisan {$command}.");
        $exitCode = Artisan::call($command, $parameters);
        $output = trim(Artisan::output());

        if ($output !== '') {
            foreach (preg_split('/\R/', $output) ?: [] as $line) {
                if ($line !== '') {
                    $log->write("artisan {$command}: {$line}");
                }
            }
        }

        if ($exitCode !== 0) {
            throw new RuntimeException(__('Artisan :command failed with exit code :code.', ['command' => $command, 'code' => $exitCode]));
        }
    }

    private function absolutePackagePath(SystemUpdate $update): string
    {
        $storedPath = str_replace('\\', '/', $update->package_path);
        if (! str_starts_with($storedPath, 'kaevcms/updates/packages/') || str_contains($storedPath, '..')) {
            throw new RuntimeException(__('The staged update package path is invalid.'));
        }

        $relative = str_replace('/', DIRECTORY_SEPARATOR, $storedPath);
        $absolute = storage_path('app'.DIRECTORY_SEPARATOR.$relative);
        if (! is_file($absolute)) {
            throw new RuntimeException(__('The staged update package is missing.'));
        }

        return $absolute;
    }

    private function relativeStoragePath(string $absolute): string
    {
        $storage = rtrim(str_replace('\\', '/', storage_path('app')), '/').'/';
        $path = str_replace('\\', '/', $absolute);

        return str_starts_with($path, $storage) ? substr($path, strlen($storage)) : basename($path);
    }

    private function recordSuccessAudit(
        InspectedUpdatePackage $package,
        string $currentVersion,
        UpdateLog $log,
    ): void {
        try {
            $this->auditLogger->success(
                category: 'system',
                action: 'system.update_installed',
                target: "KaevCMS {$package->targetVersion}",
                details: [
                    'from_version' => $currentVersion,
                    'target_version' => $package->targetVersion,
                    'package_id' => $package->packageId,
                    'installation_type' => $package->installationType,
                ],
            );
        } catch (Throwable $exception) {
            $log->write('Unable to write the success audit event: '.$exception->getMessage(), 'WARN');
        }
    }

    /** @param list<string> $rollbackErrors */
    private function recordFailureAudit(
        SystemUpdate $update,
        ?string $currentVersion,
        array $rollbackErrors,
        UpdateLog $log,
    ): void {
        try {
            $this->auditLogger->failed(
                category: 'system',
                action: 'system.update_failed',
                target: "KaevCMS {$update->target_version}",
                details: [
                    'from_version' => $currentVersion ?? $update->from_version,
                    'target_version' => $update->target_version,
                    'package_id' => $update->package_id,
                    'phase' => $update->phase,
                    'rollback_errors' => $rollbackErrors,
                ],
            );
        } catch (Throwable $exception) {
            $log->write('Unable to write the failure audit event: '.$exception->getMessage(), 'WARN');
        }
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
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
