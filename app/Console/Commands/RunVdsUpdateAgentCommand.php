<?php

namespace App\Console\Commands;

use App\Models\SystemUpdate;
use App\Services\Updates\SystemUpdateInstaller;
use App\Services\Updates\VdsUpdateAgent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class RunVdsUpdateAgentCommand extends Command
{
    protected $signature = 'kaevcms:update-agent:run';

    protected $description = 'Process one queued KaevCMS update request from the local VDS agent';

    public function handle(VdsUpdateAgent $agent, SystemUpdateInstaller $installer): int
    {
        App::setLocale('en');

        $status = $agent->status();
        if (! $status->isReady()) {
            $this->error($status->message);

            return self::FAILURE;
        }

        $agent->touch();
        $requests = $agent->pendingRequests();
        if ($requests === []) {
            $this->line('No queued KaevCMS update requests.');

            return self::SUCCESS;
        }

        $requestPath = $requests[0];
        $request = $agent->readRequest($requestPath);
        if ($request === null) {
            $agent->removeRequest($requestPath);
            $this->error('Discarded an invalid VDS update request file.');

            return self::FAILURE;
        }

        $update = null;

        try {
            $update = DB::transaction(function () use ($request): SystemUpdate {
                $locked = SystemUpdate::query()->where('uuid', $request['uuid'])->lockForUpdate()->first();
                if (! $locked instanceof SystemUpdate || ! $locked->isQueuedForAgent()) {
                    throw new RuntimeException('The queued update record is missing or no longer waiting for the agent.');
                }

                if (! is_string($locked->package_sha256)
                    || ! hash_equals(strtolower($locked->package_sha256), $request['package_sha256'])) {
                    throw new RuntimeException('The queued update request checksum does not match the staged package record.');
                }

                $locked->forceFill(['agent_seen_at' => now()])->save();

                return $locked;
            });

            $packagePath = $agent->absolutePackagePath($update);
            $actualHash = is_file($packagePath) ? hash_file('sha256', $packagePath) : false;
            if (! is_string($actualHash) || ! hash_equals($request['package_sha256'], strtolower($actualHash))) {
                throw new RuntimeException('The queued update archive checksum changed before the agent started.');
            }

            $maintenanceSecret = $update->maintenance_secret;
            if (! is_string($maintenanceSecret)
                || preg_match('/\A[a-zA-Z0-9]{32,128}\z/', $maintenanceSecret) !== 1) {
                throw new RuntimeException('The queued update recovery secret is missing or invalid.');
            }

            $this->info("Applying KaevCMS {$update->from_version} -> {$update->target_version}.");
            $installer->apply($update, $maintenanceSecret);
            $this->info("KaevCMS {$update->target_version} installed successfully.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            $this->recordAgentFailure($update, $request['uuid'], $exception);

            return self::FAILURE;
        } finally {
            $agent->removeRequest($requestPath);
            $this->clearAgentSecrets($request['uuid']);
            $agent->touch();
        }
    }

    private function recordAgentFailure(?SystemUpdate $update, string $uuid, Throwable $exception): void
    {
        try {
            $fresh = $update?->fresh() ?? SystemUpdate::query()->where('uuid', $uuid)->first();
            if (! $fresh instanceof SystemUpdate) {
                return;
            }

            if ($fresh->status !== SystemUpdate::STATUS_STAGED) {
                return;
            }

            SystemUpdate::query()->where('uuid', $uuid)->update([
                'agent_requested_at' => null,
                'error_summary' => Str::limit(__('The VDS update agent could not start the update: :message', [
                    'message' => $exception->getMessage(),
                ]), 500, ''),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // The agent output and update log remain available when database reporting fails.
        }
    }

    private function clearAgentSecrets(string $uuid): void
    {
        try {
            SystemUpdate::query()->where('uuid', $uuid)->update([
                'maintenance_secret' => null,
                'agent_requested_at' => null,
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // A future run can clean stale encrypted state after the database is available again.
        }
    }
}
