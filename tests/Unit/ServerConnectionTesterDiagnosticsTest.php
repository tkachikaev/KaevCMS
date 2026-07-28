<?php

namespace Tests\Unit;

use App\Exceptions\ExternalDatabaseSchemaMismatch;
use App\Models\LoginServer;
use App\Services\GameWorld\MobiusGameSchemaInspector;
use App\Services\Servers\ServerConnectionTester;
use App\Services\Servers\ServerDriverRegistry;
use Tests\Fakes\FakeExternalDatabaseConnectionTester;
use Tests\TestCase;

class ServerConnectionTesterDiagnosticsTest extends TestCase
{
    public function test_login_driver_reports_profile_and_only_available_capabilities(): void
    {
        $fake = new FakeExternalDatabaseConnectionTester;
        $fake->report['checks'] = [
            $this->check('accounts'),
            $this->check('account_data'),
            $this->check('accounts_ipauth', exists: false),
        ];
        $tester = $this->tester($fake);

        $report = $tester->testLoginValues($this->loginValues());

        $this->assertSame('mobius_interlude_plus', $report['schema_profile']);
        $this->assertSame([
            'account_lookup',
            'account_creation',
            'password_change',
            'account_data',
        ], $report['capabilities']);
    }

    public function test_mobius_game_driver_reports_modern_profile_and_optional_capabilities(): void
    {
        $fake = new FakeExternalDatabaseConnectionTester;
        $fake->report['checks'] = [
            $this->check('characters', matched: ['reputation'], optional: ['x', 'y', 'z']),
            $this->check('clan_data'),
            $this->check('heroes'),
            $this->check('castle', exists: false),
        ];
        $tester = $this->tester($fake);
        $loginServer = new LoginServer($this->loginValues());

        $report = $tester->testGameValues([
            'driver' => ServerDriverRegistry::MOBIUS_DRIVER,
            'chronicle' => 'Tales Untold',
            'use_login_server_connection' => false,
            'database_host' => '127.0.0.1',
            'database_port' => 3306,
            'database_name' => 'l2jmobius',
            'database_username' => 'kaevcms',
            'database_password' => 'SecretDatabasePassword',
            'database_charset' => 'utf8mb4',
        ], $loginServer);

        $this->assertSame('mobius_modern', $report['schema_profile']);
        $this->assertSame(['level', 'pvp', 'pk', 'play_time', 'heroes', 'character_rescue'], $report['capabilities']);
    }

    public function test_incompatible_schema_uses_safe_domain_error_class(): void
    {
        $fake = new FakeExternalDatabaseConnectionTester;
        $fake->report['compatible'] = false;
        $fake->report['checks'] = [
            $this->check('accounts', missing: ['password']),
        ];
        $tester = $this->tester($fake);

        $report = $tester->testLoginValues($this->loginValues());

        $this->assertSame(ExternalDatabaseSchemaMismatch::class, $report['error_class']);
        $this->assertSame([], $report['capabilities']);
    }

    private function tester(FakeExternalDatabaseConnectionTester $fake): ServerConnectionTester
    {
        return new ServerConnectionTester(
            $fake,
            new ServerDriverRegistry,
            new MobiusGameSchemaInspector,
        );
    }

    /** @return array<string,mixed> */
    private function loginValues(): array
    {
        return [
            'driver' => ServerDriverRegistry::MOBIUS_DRIVER,
            'database_host' => '127.0.0.1',
            'database_port' => 3306,
            'database_name' => 'l2jmobius',
            'database_username' => 'kaevcms',
            'database_password' => 'SecretDatabasePassword',
            'database_charset' => 'utf8mb4',
        ];
    }

    /** @return array{table:string,required:bool,table_exists:bool,missing_columns:list<string>,matched_any_columns:list<string>,matched_optional_columns:list<string>} */
    private function check(
        string $table,
        bool $exists = true,
        array $missing = [],
        array $matched = [],
        array $optional = [],
    ): array {
        return [
            'table' => $table,
            'required' => in_array($table, ['accounts', 'characters', 'clan_data'], true),
            'table_exists' => $exists,
            'missing_columns' => $missing,
            'matched_any_columns' => $matched,
            'matched_optional_columns' => $optional,
        ];
    }
}
