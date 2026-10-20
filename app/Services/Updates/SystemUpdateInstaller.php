<?php

namespace App\Services\Updates;

use App\Auth\AdminPermission;
use App\Models\SystemUpdate;
use App\Services\AuditLogger;
use App\Services\Infrastructure\RuntimeDirectoryManager;
use App\Services\Notifications\AdminNotificationCenter;
use App\Services\Releases\InstalledVersion;
use App\Support\Notifications\AdminNotificationData;
use App\Support\Notifications\AdminNotificationSeverity;
use App\Support\Notifications\AdminNotificationType;
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
        private readonly RuntimeDirectoryManager $runtimeDirectories,
        private readonly VdsUpdateAgent $vdsUpdateAgent,
        private readonly AdminNotificationCenter $notifications,
        private readonly Application $application,
        private readonly UpdateDependencyBundle $dependencies,
        private readonly UpdateInstallationLayout $layout,
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
        $deploymentPermissions = null;
        $wasDown = $this->application->isDownForMaintenance();
        $maintenanceActivated = false;
        $installationStarted = false;
        $filesMayHaveChanged = false;
        $databaseMayHaveChanged = false;
        $dependenciesMayHaveChanged = false;
        $handedOff = false;
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

            $this->runtimeDirectories->ensure(true);
            $log->write("Starting KaevCMS update {$currentVersion} -> {$update->target_version}.");
            $package = $this->inspector->inspect($this->absolutePackagePath($update), $currentVersion);
            $this->assertPackageMatchesRecord($package, $update);

            $checks = $this->preflight->inspect($package);
            if (! $this->preflight->passes($checks)) {
                throw new RuntimeException(__('The update preflight checks did not pass.'));
            }

            if ($update->isAgentExecution()) {
                $deploymentPermissions = $this->vdsUpdateAgent->deploymentPermissions();
                if ($deploymentPermissions === null) {
                    throw new RuntimeException(__('The VDS update agent deployment identity is invalid. Reinstall the agent.'));
                }
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
            $this->filesystem->apply(
                $package->files,
                $package->delete,
                $package->stagingPath,
                $log,
                $deploymentPermissions,
            );

            if ($package->dependencies !== null) {
                // This process already loaded the previous framework classes, so after the
                // vendor swap it hands the remaining steps to a fresh PHP process.
                $this->setPhase($update, SystemUpdate::PHASE_DEPENDENCIES, $log);
                $dependenciesMayHaveChanged = true;
                $staged = $this->dependencies->stage($package->dependencies, $log);
                $this->filesystem->secureTree($staged, 'core/vendor', $deploymentPermissions);
                $this->dependencies->swap($staged, $backupRoot, $update->uuid, [
                    'from_version' => $currentVersion,
                    'target_version' => $package->targetVersion,
                    'package_id' => $package->packageId,
                    'migrate' => $package->migrate,
                    'maintenance_activated' => $maintenanceActivated,
                    'archive_path' => $package->archivePath,
                ], $log);

                if (function_exists('opcache_reset')) {
                    @opcache_reset();
                }

                $handedOff = true;
                $log->write('Remaining update steps continue in a fresh PHP process with the new dependencies.');
            } else {
                $this->complete($update, $package->migrate, $package->targetVersion, $maintenanceActivated, $log, $databaseMayHaveChanged);
                $maintenanceActivated = false;

                $log->write("KaevCMS {$package->targetVersion} installed successfully.");
                if (! @unlink($package->archivePath) && is_file($package->archivePath)) {
                    $log->write('Unable to remove the installed update package.', 'WARN');
                }
                $this->recordSuccessAudit($package->packageId, $package->installationType, $package->targetVersion, $currentVersion, $log);
            }
        } catch (Throwable $exception) {
            $log->write($exception->getMessage(), 'ERROR');

            if (! $installationStarted) {
                throw new RuntimeException($exception->getMessage(), previous: $exception);
            }

            $this->failAndRollBack(
                $update,
                $currentVersion,
                $exception,
                $log,
                $backupRoot,
                $databaseMayHaveChanged ? $databaseBackup : false,
                $filesMayHaveChanged ? $fileBackup : false,
                $dependenciesMayHaveChanged,
                $deploymentPermissions,
            );
        } finally {
            if (! $handedOff && ! $wasDown && ($maintenanceActivated || $this->application->isDownForMaintenance())) {
                $this->leaveMaintenance($log);
            }

            if ($package instanceof InspectedUpdatePackage) {
                $this->removeDirectory($package->stagingPath);
            }

            $this->updateLock->release($lock);
        }

        $fresh = $update->fresh();

        return $fresh instanceof SystemUpdate ? $fresh : $update;
    }

    /**
     * Finishes an update after its vendor swap. Must run in a PHP process started after the
     * swap (new artisan process or new HTTP request), so only the new dependencies are loaded.
     */
    public function finalize(SystemUpdate $update): SystemUpdate
    {
        if (! $update->awaitsFinalization()) {
            throw new RuntimeException(__('This update is not waiting to be finalized.'));
        }

        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $lock = $this->updateLock->acquire();
        $log = new UpdateLog($update->uuid);
        $backupRoot = $this->backupRootFor($update);
        $state = null;
        $databaseMayHaveChanged = false;
        $maintenanceActivated = false;
        $deploymentPermissions = null;

        try {
            $state = $this->dependencies->state($backupRoot);
            if (! is_array($state) || ($state['swapped'] ?? false) !== true || ($state['uuid'] ?? null) !== $update->uuid) {
                throw new RuntimeException(__('The dependency update state is missing; recover the update instead.'));
            }

            $maintenanceActivated = (bool) ($state['maintenance_activated'] ?? false);
            if ($update->isAgentExecution()) {
                $deploymentPermissions = $this->vdsUpdateAgent->deploymentPermissions();
            }

            $log->write('Finalizing the update in a fresh PHP process with the new dependencies.');
            $target = (string) ($state['target_version'] ?? $update->target_version);
            $this->complete($update, (bool) ($state['migrate'] ?? true), $target, $maintenanceActivated, $log, $databaseMayHaveChanged);
            $maintenanceActivated = false;

            $this->dependencies->discardPrevious($log);
            $log->write("KaevCMS {$target} installed successfully.");
            $archive = (string) ($state['archive_path'] ?? '');
            if ($archive !== '' && is_file($archive) && ! @unlink($archive)) {
                $log->write('Unable to remove the installed update package.', 'WARN');
            }
            $this->recordSuccessAudit($update->package_id, $update->installation_type, $target, $update->from_version, $log);
        } catch (Throwable $exception) {
            $log->write($exception->getMessage(), 'ERROR');
            $databaseBackup = null;
            $fileBackup = null;
            try {
                $databaseBackup = $this->databaseBackup->load($backupRoot.'/database');
                $fileBackup = $this->filesystem->loadBackup($backupRoot);
            } catch (Throwable $loadException) {
                $log->write('Unable to load the update backups: '.$loadException->getMessage(), 'ERROR');
            }

            $this->failAndRollBack(
                $update,
                $update->from_version,
                $exception,
                $log,
                $backupRoot,
                $databaseMayHaveChanged ? $databaseBackup : false,
                $fileBackup,
                $state !== null,
                $deploymentPermissions,
            );
        } finally {
            if ($maintenanceActivated || ($state !== null && ($state['maintenance_activated'] ?? false) && $this->application->isDownForMaintenance())) {
                // After a failure the previous vendor tree is back on disk while this process
                // still runs the new one, so the console must not be booted again here.
                $this->leaveMaintenance($log, useArtisan: false);
            }

            $this->updateLock->release($lock);
        }

        $fresh = $update->fresh();

        return $fresh instanceof SystemUpdate ? $fresh : $update;
    }

    /**
     * Migrations, cache clearing and version marking shared by both update paths.
     */
    private function complete(
        SystemUpdate $update,
        bool $migrate,
        string $targetVersion,
        bool $maintenanceActivated,
        UpdateLog $log,
        bool &$databaseMayHaveChanged,
    ): void {
        if ($migrate) {
            $this->setPhase($update, SystemUpdate::PHASE_MIGRATIONS, $log);
            $databaseMayHaveChanged = true;
            $this->runArtisan('migrate', ['--force' => true], $log);
        }

        $this->setPhase($update, SystemUpdate::PHASE_FINALIZING, $log);
        $this->runArtisan('optimize:clear', [], $log);
        $this->runtimeDirectories->ensure(true);
        $log->write('Runtime directories recreated and write-tested after cache clear.');
        $this->runArtisan('queue:restart', [], $log);
        $this->installedVersion->mark($targetVersion);

        if ($maintenanceActivated) {
            $this->runArtisan('up', [], $log);
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        $this->removeStoredDependencyBundle($log);

        $update->forceFill([
            'status' => SystemUpdate::STATUS_SUCCEEDED,
            'phase' => SystemUpdate::PHASE_COMPLETED,
            'completed_at' => now(),
            'error_summary' => null,
        ])->save();
    }

    /**
     * Restores database, files and dependencies (each only when it may have changed and a
     * verified backup exists), records the failure and rethrows a summarised error.
     *
     * @param  array{driver: string, path: string, sha256: string}|false|null  $databaseBackup  false: unchanged
     * @param  array<string, mixed>|false|null  $fileBackup  false: unchanged
     * @param  array<string, mixed>|null  $deploymentPermissions
     */
    private function failAndRollBack(
        SystemUpdate $update,
        ?string $currentVersion,
        Throwable $exception,
        UpdateLog $log,
        string $backupRoot,
        array|false|null $databaseBackup,
        array|false|null $fileBackup,
        bool $dependenciesMayHaveChanged,
        ?array $deploymentPermissions,
    ): never {
        $rollbackErrors = [];
        $rollbackReady = true;

        if ($databaseBackup !== false) {
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
        if ($fileBackup !== false) {
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
            if (is_array($databaseBackup)) {
                try {
                    $this->databaseBackup->restore($databaseBackup, $log);
                } catch (Throwable $rollbackException) {
                    $rollbackMessage = 'Database rollback: '.$rollbackException->getMessage();
                    $rollbackErrors[] = $rollbackMessage;
                    $log->write($rollbackMessage, 'ERROR');
                }
            }

            if (is_array($fileBackup)) {
                try {
                    $this->filesystem->rollback($fileBackup, $log, $deploymentPermissions);
                } catch (Throwable $rollbackException) {
                    $rollbackMessage = 'File rollback: '.$rollbackException->getMessage();
                    $rollbackErrors[] = $rollbackMessage;
                    $log->write($rollbackMessage, 'ERROR');
                }
            }

            if ($dependenciesMayHaveChanged) {
                try {
                    $this->dependencies->restore($backupRoot, $log);
                } catch (Throwable $rollbackException) {
                    $rollbackMessage = 'Dependency rollback: '.$rollbackException->getMessage();
                    $rollbackErrors[] = $rollbackMessage;
                    $log->write($rollbackMessage, 'ERROR');
                }
            }

            if ($rollbackErrors === [] && is_string($currentVersion)) {
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

        try {
            $this->notifications->notifyOnce(
                new AdminNotificationData(
                    type: AdminNotificationType::SystemUpdateFailed,
                    severity: AdminNotificationSeverity::Error,
                    titleKey: 'KaevCMS update failed',
                    messageKey: 'Update to version :version did not complete successfully.',
                    parameters: ['version' => $update->target_version],
                    routeName: 'admin.settings.system.updates.show',
                    routeParameters: ['systemUpdate' => $update->id],
                ),
                "system-update-failed:{$update->uuid}",
                AdminPermission::SystemUpdatesView,
            );
        } catch (Throwable $notificationException) {
            $log->write('Unable to create the failure notification: '.$notificationException->getMessage(), 'WARN');
        }

        throw new RuntimeException($summary, previous: $exception);
    }

    /**
     * Leaves maintenance mode through Artisan, falling back to removing the maintenance
     * marker directly when the console cannot run (e.g. right after a dependency rollback).
     */
    private function leaveMaintenance(UpdateLog $log, bool $useArtisan = true): void
    {
        if ($useArtisan) {
            try {
                $this->runArtisan('up', [], $log);
            } catch (Throwable $exception) {
                $log->write('Unable to leave maintenance mode through Artisan: '.$exception->getMessage(), 'ERROR');
            }
        }

        foreach (['down', 'maintenance.php'] as $marker) {
            $path = storage_path('framework'.DIRECTORY_SEPARATOR.$marker);
            if (is_file($path) && ! @unlink($path)) {
                $log->write("Unable to remove the maintenance file {$marker}.", 'ERROR');
            }
        }

        if (! $useArtisan) {
            $log->write('Maintenance mode lifted without Artisan after restoring the previous dependencies.');
        }
    }

    /**
     * Web Updaters older than dependency bundles copy the bundle archive into the project
     * as a plain file; it is never needed afterwards.
     */
    private function removeStoredDependencyBundle(UpdateLog $log): void
    {
        try {
            $path = $this->layout->resolveTarget(UpdatePackageInspector::DEPENDENCY_BUNDLE_TARGET);
        } catch (Throwable) {
            return;
        }

        if (is_file($path) && ! is_link($path)) {
            if (@unlink($path)) {
                @rmdir(dirname($path));
                $log->write('Removed a dependency bundle copied into the project by an earlier updater.');
            }
        }
    }

    private function backupRootFor(SystemUpdate $update): string
    {
        $relative = str_replace('\\', '/', (string) $update->backup_path);
        if ($relative !== 'kaevcms/update-backups/'.$update->uuid) {
            throw new RuntimeException(__('The update recovery backup path is invalid.'));
        }

        return storage_path('app'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative));
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
        string $packageId,
        string $installationType,
        string $targetVersion,
        string $currentVersion,
        UpdateLog $log,
    ): void {
        try {
            $this->auditLogger->success(
                category: 'system',
                action: 'system.update_installed',
                target: "KaevCMS {$targetVersion}",
                details: [
                    'from_version' => $currentVersion,
                    'target_version' => $targetVersion,
                    'package_id' => $packageId,
                    'installation_type' => $installationType,
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
