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

final class SystemUpdateRecovery
{
    public function __construct(
        private readonly InstalledVersion $installedVersion,
        private readonly UpdateDatabaseBackup $databaseBackup,
        private readonly UpdateFilesystemTransaction $filesystem,
        private readonly UpdateLock $updateLock,
        private readonly AuditLogger $auditLogger,
        private readonly Application $application,
    ) {}

    public function recover(SystemUpdate $update): SystemUpdate
    {
        if ($update->status !== SystemUpdate::STATUS_APPLYING) {
            throw new RuntimeException(__('Only an interrupted update can be recovered.'));
        }

        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $lock = $this->updateLock->acquire();
        $log = new UpdateLog($update->uuid);

        try {
            $backupRoot = $this->backupRoot($update);
            $databaseBackup = $this->databaseBackup->load($backupRoot.'/database');
            $fileBackup = $this->filesystem->loadBackup($backupRoot);
            $interruptedPhase = $update->phase;
            $filesRequired = $update->filesMayHaveChanged();
            $databaseRequired = $update->databaseMayHaveChanged();

            $verifiedDatabaseBackup = null;
            if ($databaseRequired) {
                if ($databaseBackup === null) {
                    throw new RuntimeException(__('Recovery cannot start because the required database backup is missing.'));
                }

                $this->databaseBackup->verify($databaseBackup);
                $verifiedDatabaseBackup = $databaseBackup;
            }

            $verifiedFileBackup = null;
            if ($filesRequired) {
                if ($fileBackup === null) {
                    throw new RuntimeException(__('Recovery cannot start because the required file backup is missing.'));
                }

                $this->filesystem->verifyBackup($fileBackup);
                $verifiedFileBackup = $fileBackup;
            }

            $log->write('Manual recovery of an interrupted update started.', 'WARN');
            $log->write('Interrupted phase: '.($update->phase ?? 'legacy-unknown').'.', 'WARN');

            if ($verifiedDatabaseBackup !== null) {
                $this->databaseBackup->restore($verifiedDatabaseBackup, $log);
            }

            if ($verifiedFileBackup !== null) {
                $this->filesystem->rollback($verifiedFileBackup, $log);
            }

            $this->installedVersion->mark($update->from_version);
            $this->runArtisan('optimize:clear', $log);
            $this->runArtisan('queue:restart', $log);

            if ($this->application->isDownForMaintenance()) {
                $this->runArtisan('up', $log);
            }

            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            SystemUpdate::query()->where('uuid', $update->uuid)->update([
                'status' => SystemUpdate::STATUS_FAILED,
                'phase' => $interruptedPhase,
                'completed_at' => now(),
                'error_summary' => __('The interrupted update was rolled back manually.'),
                'log_path' => $log->relativePath(),
                'updated_at' => now(),
            ]);

            $log->write('Interrupted update recovery completed.', 'WARN');
            $this->recordAudit($update, $log);
        } catch (Throwable $exception) {
            $log->write('Interrupted update recovery failed: '.$exception->getMessage(), 'ERROR');

            try {
                SystemUpdate::query()->where('uuid', $update->uuid)->update([
                    'error_summary' => Str::limit(__('Recovery failed: :message', ['message' => $exception->getMessage()]), 500, ''),
                    'log_path' => $log->relativePath(),
                    'updated_at' => now(),
                ]);
            } catch (Throwable) {
                // The file log remains available when the database cannot be written.
            }

            throw new RuntimeException(
                __('Recovery failed: :message', ['message' => $exception->getMessage()]),
                previous: $exception,
            );
        } finally {
            $this->updateLock->release($lock);
        }

        $fresh = $update->fresh();

        return $fresh instanceof SystemUpdate ? $fresh : $update;
    }

    private function backupRoot(SystemUpdate $update): string
    {
        $relative = str_replace('\\', '/', (string) $update->backup_path);
        $expected = 'kaevcms/update-backups/'.$update->uuid;
        if ($relative !== $expected) {
            throw new RuntimeException(__('The update recovery backup path is invalid.'));
        }

        return storage_path('app'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative));
    }

    private function runArtisan(string $command, UpdateLog $log): void
    {
        $log->write("Running artisan {$command} during recovery.");
        $exitCode = Artisan::call($command);
        $output = trim(Artisan::output());

        if ($output !== '') {
            foreach (preg_split('/\R/', $output) ?: [] as $line) {
                if ($line !== '') {
                    $log->write("artisan {$command}: {$line}");
                }
            }
        }

        if ($exitCode !== 0) {
            throw new RuntimeException(__('Artisan :command failed with exit code :code.', [
                'command' => $command,
                'code' => $exitCode,
            ]));
        }
    }

    private function recordAudit(SystemUpdate $update, UpdateLog $log): void
    {
        try {
            $this->auditLogger->success(
                category: 'system',
                action: 'system.update_recovered',
                target: "KaevCMS {$update->from_version}",
                details: [
                    'from_version' => $update->target_version,
                    'restored_version' => $update->from_version,
                    'package_id' => $update->package_id,
                    'interrupted_phase' => $update->phase,
                ],
            );
        } catch (Throwable $exception) {
            $log->write('Unable to write the recovery audit event: '.$exception->getMessage(), 'WARN');
        }
    }
}
