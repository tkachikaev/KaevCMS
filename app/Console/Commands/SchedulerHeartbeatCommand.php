<?php

namespace App\Console\Commands;

use App\Services\Infrastructure\RuntimeDiagnostics;
use Illuminate\Console\Command;

final class SchedulerHeartbeatCommand extends Command
{
    protected $signature = 'kaevcms:scheduler-heartbeat';

    protected $description = 'Record a successful Laravel Scheduler run for KaevCMS diagnostics';

    public function handle(RuntimeDiagnostics $diagnostics): int
    {
        $diagnostics->recordSchedulerHeartbeat();

        return self::SUCCESS;
    }
}
