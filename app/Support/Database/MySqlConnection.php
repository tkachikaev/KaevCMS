<?php

namespace App\Support\Database;

use Illuminate\Database\MySqlConnection as LaravelMySqlConnection;

/** The Laravel MySQL connection with KaevCMS's schema grammar; see MySqlSchemaGrammar. */
final class MySqlConnection extends LaravelMySqlConnection
{
    protected function getDefaultSchemaGrammar()
    {
        return new MySqlSchemaGrammar($this);
    }
}
