<?php

namespace App\Support\UniversalDrivers;

use App\Contracts\ExternalDatabaseConnectionTester;
use App\Models\GameServer;
use App\Models\LoginServer;
use Throwable;

/** A small, read-only check for table/column names supplied to a module. */
final class DriverExtensionSchemaVerifier
{
    public function __construct(private readonly ExternalDatabaseConnectionTester $tester) {}

    /**
     * A column belongs to the sole table field of its extension group.
     * Ambiguous multi-table mappings must be split into separate extension groups.
     *
     * @param  array<string,mixed>  $profile
     * @param  array<string,mixed>  $declaration
     */
    public function compatible(LoginServer|GameServer $server, array $profile, array $declaration): bool
    {
        $values = $profile['config']['extensions'][$declaration['module']][$declaration['key']] ?? [];
        if (! is_array($values)) {
            return false;
        }
        $tables = [];
        $columns = [];
        foreach ($declaration['fields'] as $field) {
            $value = $values[$field['name']] ?? null;
            if ($value === null || $value === '') {
                if ($field['required'] === true) {
                    return false;
                }

                continue;
            }
            if (! is_scalar($value)) {
                return false;
            }
            $text = trim((string) $value);
            $valid = match ($field['kind']) {
                'table', 'column' => preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D', $text) === 1,
                'number' => preg_match('/\A\d{1,9}\z/D', $text) === 1,
                'choice' => in_array($text, $field['options'], true),
                default => strlen($text) <= 100 && preg_match('/[\x00-\x1F\x7F]/', $text) !== 1,
            };
            if (! $valid) {
                return false;
            }
            if ($field['kind'] === 'table' || $field['kind'] === 'column') {
                if ($field['kind'] === 'table') {
                    $tables[] = $value;
                } else {
                    $columns[] = $value;
                }
            }
        }
        if ($tables === [] && $columns === []) {
            return true;
        }
        if (count($tables) !== 1) {
            return false;
        }
        $requirement = [[
            'table' => $tables[0],
            'columns' => array_values(array_unique($columns)),
            'required' => true,
        ]];

        try {
            if ($server instanceof LoginServer) {
                $target = $server;
            } else {
                $target = $server->use_login_server_connection ? $server->loginServer : $server;
            }
            if (! $target instanceof LoginServer && ! $target instanceof GameServer) {
                return false;
            }
            $credentials = [
                'host' => (string) $target->database_host,
                'port' => (int) $target->database_port,
                'database' => (string) $target->database_name,
                'username' => (string) $target->database_username,
                'password' => $target->databasePassword() ?? '',
                'charset' => (string) $target->database_charset,
            ];
            $report = $this->tester->test(ProfileDatabaseEncoding::apply($credentials, $profile), $requirement, true);

            return $report['connected'] === true && $report['compatible'] === true;
        } catch (Throwable) {
            return false;
        }
    }
}
