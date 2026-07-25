<?php

namespace App\Console\Commands;

use App\Services\Servers\ServerMonitorCoordinator;
use Illuminate\Console\Command;

final class MonitorServersCommand extends Command
{
    protected $signature = 'kaevcms:servers-monitor
        {--force : Refresh even when the saved server status is still fresh}';

    protected $aliases = ['l2forge:servers-monitor'];

    protected $description = 'Check LoginServer and GameServer ports and refresh online player counts';

    public function handle(ServerMonitorCoordinator $coordinator): int
    {
        $result = $coordinator->refreshIfDue(force: (bool) $this->option('force'));

        if ($result['refreshing']) {
            $this->warn('Server monitoring is already running.');

            return self::SUCCESS;
        }

        if (! $result['refreshed']) {
            $this->info('Server monitoring snapshot is still fresh.');

            return self::SUCCESS;
        }

        $this->info('LoginServer and GameServer monitoring snapshot refreshed.');

        return self::SUCCESS;
    }
}
