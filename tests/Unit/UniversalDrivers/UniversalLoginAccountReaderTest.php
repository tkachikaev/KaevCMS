<?php

namespace Tests\Unit\UniversalDrivers;

use App\Services\GameAccounts\UniversalLoginAccountReader;
use App\Support\UniversalDrivers\BundledProfileCatalog;
use App\Support\UniversalDrivers\ProfileValidationException;
use Illuminate\Database\Connection;
use Mockery;
use Tests\TestCase;

final class UniversalLoginAccountReaderTest extends TestCase
{
    public function test_invalid_identifier_is_rejected_before_any_database_call(): void
    {
        $profile = (new BundledProfileCatalog)->all()['login:rusacis'];
        $profile['config']['accounts']['table'] = 'accounts` UNION SELECT password';
        $database = Mockery::mock(Connection::class);
        $database->shouldNotReceive('getDriverName', 'getSchemaBuilder', 'table');

        $this->expectException(ProfileValidationException::class);
        (new UniversalLoginAccountReader)->summary($database, $profile, 'alice');
    }

    public function test_game_profile_cannot_be_used_for_login_reads(): void
    {
        $profile = (new BundledProfileCatalog)->all()['game:rusacis'];
        $database = Mockery::mock(Connection::class);
        $database->shouldNotReceive('getSchemaBuilder', 'table');

        $this->expectException(ProfileValidationException::class);
        (new UniversalLoginAccountReader)->summary($database, $profile, 'alice');
    }

    public function test_unapproved_database_dialect_is_rejected_before_queries(): void
    {
        $profile = (new BundledProfileCatalog)->all()['login:l2j_mobius'];
        $profile['config']['database']['dialect'] = 'sqlserver';
        $database = Mockery::mock(Connection::class);
        $database->shouldNotReceive('getDriverName', 'getSchemaBuilder', 'table');

        $this->expectException(ProfileValidationException::class);
        (new UniversalLoginAccountReader)->diagnose($database, $profile);
    }

    public function test_read_only_profile_without_lookup_capability_cannot_read_accounts(): void
    {
        $profile = (new BundledProfileCatalog)->all()['login:rusacis'];
        $profile['config']['capabilities'] = ['account_status'];
        $database = Mockery::mock(Connection::class);
        $database->shouldReceive('getDriverName')->once()->andReturn('mysql');
        $database->shouldNotReceive('getSchemaBuilder', 'table');

        $this->expectException(ProfileValidationException::class);
        (new UniversalLoginAccountReader)->summary($database, $profile, 'alice');
    }
}
