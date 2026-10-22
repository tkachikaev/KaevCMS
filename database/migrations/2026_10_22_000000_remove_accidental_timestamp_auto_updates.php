<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * On MariaDB before 10.10 and MySQL 5.7 (explicit_defaults_for_timestamp off) the server gave
 * the first NOT NULL timestamp of these tables ON UPDATE CURRENT_TIMESTAMP by itself, so the
 * time was overwritten whenever the row changed (queued, requested, granted, claimed...).
 * MySQL 8 never did. Remove that behaviour where it exists; new tables are created correctly
 * by App\Support\Database\MySqlSchemaGrammar. Values already overwritten cannot be recovered.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> core and bundled-module tables */
    private const COLUMNS = [
        'character_rescue_operations' => ['requested_at'],
        'cms_module_migrations' => ['ran_at'],
        'mail_deliveries' => ['queued_at'],
        'module_daily_reward_claims' => ['claimed_at'],
        'module_promo_code_activations' => ['activated_at'],
        'module_support_tickets' => ['last_message_at'],
        'module_support_ticket_message_revisions' => ['edited_at'],
        'reward_deliveries' => ['requested_at'],
        'reward_inventory_grants' => ['granted_at'],
        'system_heartbeats' => ['last_seen_at'],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $definition = DB::selectOne(
                    'select COLUMN_TYPE as type, EXTRA as extra from information_schema.COLUMNS
                     where TABLE_SCHEMA = database() and TABLE_NAME = ? and COLUMN_NAME = ?',
                    [$table, $column],
                );
                if ($definition === null || stripos((string) $definition->extra, 'on update') === false) {
                    continue;
                }

                $type = (string) $definition->type;
                $precision = preg_match('/\((\d)\)/', $type, $matches) === 1 ? '('.$matches[1].')' : '';
                DB::statement(sprintf(
                    'alter table `%s` modify `%s` %s not null default current_timestamp%s',
                    $table,
                    $column,
                    $type,
                    $precision,
                ));
            }
        }
    }

    public function down(): void
    {
        // The accidental auto-update is not restored.
    }
};
