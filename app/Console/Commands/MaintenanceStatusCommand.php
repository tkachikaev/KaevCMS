<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Foundation\Application;

class MaintenanceStatusCommand extends Command
{
    protected $signature = 'kaevcms:maintenance-status';

    protected $description = 'Report whether KaevCMS is currently in maintenance mode';

    public function handle(Application $application): int
    {
        $this->line($application->maintenanceMode()->active() ? 'down' : 'up');

        return self::SUCCESS;
    }
}
