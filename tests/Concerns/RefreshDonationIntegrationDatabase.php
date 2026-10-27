<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/** Fresh disposable schemas without a transaction around mocked provider HTTP. */
trait RefreshDonationIntegrationDatabase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();

        // Financial migrations deliberately refuse destructive rollback when
        // evidence exists. The next test builds a fresh disposable schema;
        // do not disable the application's evidence-retention guards.
        $this->beforeApplicationDestroyed(static function (): void {
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });
    }
}
