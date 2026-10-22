<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Remembers which tables exist for the lifetime of the application instance (one request
 * under PHP-FPM). On MySQL every Schema::hasTable() is an information_schema query, and the
 * same tables were checked many times per page.
 *
 * Only "exists" is remembered: a table that is still missing is checked again, so a queue
 * worker sees tables created by a later migration. Migrations flush the memory, and code that
 * drops a table on purpose (tests) calls forget().
 */
final class TableExistence
{
    /** @var array<string, true> */
    private array $existing = [];

    public function has(string $table): bool
    {
        if (isset($this->existing[$table])) {
            return true;
        }

        try {
            $exists = Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }

        if ($exists) {
            $this->existing[$table] = true;
        }

        return $exists;
    }

    public function forget(): void
    {
        $this->existing = [];
    }
}
