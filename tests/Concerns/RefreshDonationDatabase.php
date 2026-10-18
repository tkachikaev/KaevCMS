<?php

namespace Tests\Concerns;

use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

trait RefreshDonationDatabase
{
    use RefreshDatabase {
        beginDatabaseTransaction as private beginDonationTestTransaction;
    }

    public function beginDatabaseTransaction(): void
    {
        // MySQL DDL commits implicitly. Install module tables before the test's
        // rollback transaction, so it can exercise real savepoints and audits.
        if (DB::connection()->getDriverName() === 'mysql') {
            $module = app(ModuleManager::class)->enable('donations');
            app(ModuleRuntime::class)->bootModule($module);
        }

        $this->beginDonationTestTransaction();
    }
}
