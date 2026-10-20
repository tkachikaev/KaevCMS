<?php

namespace App\Console\Commands;

use App\Models\SystemUpdate;
use App\Services\AuditLogger;
use App\Services\Updates\SystemUpdateRecovery;
use App\Services\Updates\VdsUpdateAgent;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Throwable;

/**
 * Server-side escape hatch for a stuck Web Update. Recovery from the admin panel runs as
 * the PHP-FPM user, which on VDS installations cannot rewrite the owner's program files;
 * this command runs as the project owner and performs the same verified rollback.
 */
final class RecoverSystemUpdateCommand extends Command
{
    protected $signature = 'kaevcms:update-recover
        {uuid? : Update identifier; optional when exactly one update is stuck}
        {--yes : Confirm without an interactive prompt}';

    protected $description = 'Roll back an interrupted KaevCMS update, cancel a stuck VDS agent request, or lift maintenance mode';

    public function handle(
        SystemUpdateRecovery $recovery,
        VdsUpdateAgent $agent,
        AuditLogger $auditLogger,
        Application $application,
    ): int {
        if (! $this->runsAsProjectOwner()) {
            $this->error('Run this command as the owner of the KaevCMS project files, for example: sudo -u <owner> php artisan kaevcms:update-recover');
            $this->line('Running it as another user would leave restored files with the wrong owner.');

            return self::FAILURE;
        }

        $stuck = SystemUpdate::query()
            ->where('status', SystemUpdate::STATUS_APPLYING)
            ->orWhere(fn ($query) => $query
                ->where('status', SystemUpdate::STATUS_STAGED)
                ->where('execution_mode', SystemUpdate::EXECUTION_VDS_AGENT)
                ->whereNotNull('agent_requested_at'))
            ->orderBy('id')
            ->get();

        $uuid = $this->argument('uuid');
        if (is_string($uuid) && $uuid !== '') {
            $update = $stuck->firstWhere('uuid', strtolower($uuid));
            if (! $update instanceof SystemUpdate) {
                $this->error('No interrupted or queued update with this identifier was found.');
                $this->listStuck($stuck->all());

                return self::FAILURE;
            }
        } elseif ($stuck->count() > 1) {
            $this->error('Several updates need attention. Pass the identifier of the one to recover.');
            $this->listStuck($stuck->all());

            return self::FAILURE;
        } else {
            $update = $stuck->first();
        }

        if (! $update instanceof SystemUpdate) {
            return $this->liftMaintenanceOnly($application);
        }

        if ($update->isQueuedForAgent()) {
            $this->warn("Update {$update->uuid} ({$update->from_version} → {$update->target_version}) is waiting for the VDS agent, which never started it.");
            if (! $this->confirmed('Cancel this agent request and return the package to the staged state?')) {
                return self::FAILURE;
            }

            if (! $agent->cancelQueued($update)) {
                $this->error('The update is no longer waiting for the agent. Run the command again to see its current state.');

                return self::FAILURE;
            }

            $this->recordCancellation($auditLogger, $update);
            $this->info('The agent request was cancelled. The package can be started again from the administration panel or discarded there.');

            return self::SUCCESS;
        }

        $this->warn("Update {$update->uuid} ({$update->from_version} → {$update->target_version}) was interrupted during: ".($update->phase ?? 'unknown phase').'.');
        if ($update->awaitsFinalization()) {
            $this->line('Its Composer dependencies were already replaced. To finish the update instead of rolling it back, run:');
            $this->line("  php artisan kaevcms:update-finalize {$update->uuid}");
        }
        $this->line('Recovery restores the database and files from the verified update backup, records the previous version and lifts maintenance mode.');
        if (! $this->confirmed('Roll back this interrupted update now?')) {
            return self::FAILURE;
        }

        try {
            $recovered = $recovery->recover($update);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            $this->line('The site stays in maintenance mode. Check the update log in storage/logs before trying again.');

            return self::FAILURE;
        }

        $this->info("Rolled back to KaevCMS {$recovered->from_version}. The site is available again.");

        return self::SUCCESS;
    }

    private function liftMaintenanceOnly(Application $application): int
    {
        $this->info('No interrupted or queued update was found.');
        if (! $application->isDownForMaintenance()) {
            return self::SUCCESS;
        }

        if (! $this->confirmed('The site is still in maintenance mode. Bring it back online?')) {
            return self::FAILURE;
        }

        $exitCode = Artisan::call('up');
        $this->line(trim(Artisan::output()));

        return $exitCode === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function confirmed(string $question): bool
    {
        if ((bool) $this->option('yes')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Confirmation required: re-run with --yes.');

            return false;
        }

        return $this->confirm($question, false);
    }

    /** @param list<SystemUpdate> $updates */
    private function listStuck(array $updates): void
    {
        foreach ($updates as $update) {
            $state = $update->isQueuedForAgent() ? 'waiting for VDS agent' : 'interrupted ('.($update->phase ?? 'unknown phase').')';
            $this->line("  {$update->uuid}  {$update->from_version} → {$update->target_version}  {$state}");
        }
    }

    private function recordCancellation(AuditLogger $auditLogger, SystemUpdate $update): void
    {
        try {
            $auditLogger->success(
                category: 'system',
                action: 'system.update_agent_request_cancelled',
                target: Str::limit("KaevCMS {$update->target_version}", 190, ''),
                details: [
                    'from_version' => $update->from_version,
                    'target_version' => $update->target_version,
                    'package_id' => $update->package_id,
                    'source' => 'console',
                ],
            );
        } catch (Throwable $exception) {
            $this->warn('The cancellation could not be written to the audit log: '.$exception->getMessage());
        }
    }

    private function runsAsProjectOwner(): bool
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_geteuid')) {
            return true;
        }

        $owner = @fileowner(base_path('artisan'));

        return ! is_int($owner) || posix_geteuid() === $owner;
    }
}
