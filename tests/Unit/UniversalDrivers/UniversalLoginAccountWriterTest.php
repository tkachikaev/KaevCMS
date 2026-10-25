<?php

namespace Tests\Unit\UniversalDrivers;

use App\Services\GameAccounts\UniversalLoginAccountWriter;
use App\Support\GameAccounts\PreparedGameAccount;
use App\Support\UniversalDrivers\BundledProfileCatalog;
use App\Support\UniversalDrivers\ProfileValidationException;
use Illuminate\Database\Connection;
use Mockery;
use Tests\TestCase;

final class UniversalLoginAccountWriterTest extends TestCase
{
    public function test_builtin_game_password_formats_match_legacy_contracts(): void
    {
        $writer = new UniversalLoginAccountWriter;
        foreach ((new BundledProfileCatalog)->all() as $profile) {
            if ($profile['type'] !== 'login') {
                continue;
            }
            $hash = $writer->preparePassword($profile, 'TestPassword123');
            $this->assertTrue($writer->acceptsCredential($profile, $hash));
            $this->assertFalse($writer->acceptsCredential($profile, 'not-a-credential'));
            if ($profile['id'] === 'rusacis') {
                $this->assertStringStartsWith('$2a$10$', $hash);
                $this->assertTrue(password_verify('TestPassword123', $hash));
            } else {
                $this->assertSame(base64_encode(sha1('TestPassword123', true)), $hash);
            }
        }
    }

    public function test_no_write_occurs_when_profile_is_invalid_or_capability_is_disabled(): void
    {
        $profile = (new BundledProfileCatalog)->all()['login:rusacis'];
        $profile['config']['capabilities'] = ['account_lookup'];
        $db = Mockery::mock(Connection::class);
        $db->shouldNotReceive('table', 'getSchemaBuilder');
        $account = new PreparedGameAccount('someAccount', 'bad hash', 'x@example.invalid');

        $this->expectException(ProfileValidationException::class);
        (new UniversalLoginAccountWriter)->create($db, $profile, $account);
    }

    public function test_unsafe_fixed_insert_privileges_are_rejected_before_db_access(): void
    {
        $profile = (new BundledProfileCatalog)->all()['login:rusacis'];
        $profile['config']['accounts']['create']['fixed_values'] = ['access_level' => 100];
        $db = Mockery::mock(Connection::class);
        $db->shouldNotReceive('getDriverName', 'table', 'getSchemaBuilder');
        $this->expectException(ProfileValidationException::class);
        (new UniversalLoginAccountWriter)->create($db, $profile, new PreparedGameAccount('someAccount', '', ''));
    }

    public function test_invalid_sql_identifier_never_reaches_mysql(): void
    {
        $profile = (new BundledProfileCatalog)->all()['login:l2j_mobius'];
        $profile['config']['accounts']['table'] = 'accounts; DROP TABLE accounts';
        $db = Mockery::mock(Connection::class);
        $db->shouldNotReceive('getDriverName', 'table', 'getSchemaBuilder');
        $this->expectException(ProfileValidationException::class);
        (new UniversalLoginAccountWriter)->changePreparedPassword($db, $profile, 'alice', 'a', 'b');
    }
}
