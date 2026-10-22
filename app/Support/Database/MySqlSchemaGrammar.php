<?php

namespace App\Support\Database;

use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Support\Fluent;
use Throwable;

/**
 * MariaDB before 10.10 and MySQL 5.7 run with explicit_defaults_for_timestamp off by default.
 * A NOT NULL timestamp without a default then changes meaning: the first one in a table is
 * silently given ON UPDATE CURRENT_TIMESTAMP (its value is overwritten on every row update),
 * and any later one gets the zero date, which strict mode rejects, so the table is not created.
 * MySQL 8 and MariaDB 10.10+ do neither. Declaring CURRENT_TIMESTAMP as the default on such
 * servers keeps the same columns behaving the same everywhere.
 */
final class MySqlSchemaGrammar extends MySqlGrammar
{
    private ?bool $legacyTimestamps = null;

    /** @param  Fluent<string, mixed>  $column */
    protected function typeTimestamp(Fluent $column)
    {
        if ($column->get('nullable') !== true
            && $column->get('default') === null
            && $column->get('useCurrent') !== true
            && $this->legacyTimestamps()) {
            $column->offsetSet('useCurrent', true);
        }

        return parent::typeTimestamp($column);
    }

    /**
     * Asked once per connection, and only while the schema changes. No scope prefix: MariaDB
     * before 10.10 has the variable only globally and rejects @@session. When the server cannot
     * tell, the explicit default is declared anyway; on a modern server it changes nothing.
     */
    private function legacyTimestamps(): bool
    {
        if ($this->legacyTimestamps === null) {
            try {
                $row = $this->connection->selectOne('select @@explicit_defaults_for_timestamp as explicit_defaults');
                $this->legacyTimestamps = (int) ($row->explicit_defaults ?? 0) === 0;
            } catch (Throwable) {
                $this->legacyTimestamps = true;
            }
        }

        return $this->legacyTimestamps;
    }
}
