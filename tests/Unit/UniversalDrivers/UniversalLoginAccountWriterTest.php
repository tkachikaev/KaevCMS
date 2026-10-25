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

    public function test_hex_password_schemes_write_the_expected_digest_and_reject_other_shapes(): void
    {
        $writer = new UniversalLoginAccountWriter;
        $profile = (new BundledProfileCatalog)->all()['login:l2j_mobius'];
        $expected = [
            'sha1_hex' => ['5baa61e4c9b93f3f0682250b6cf8331b7ee68fd8', 40],
            'md5_hex' => ['5f4dcc3b5aa765d61d8327deb882cf99', 32],
            'sha256_hex' => ['5e884898da28047151d0e56f8dc6292773603d0d6aabbdd62a11ef721d1542d8', 64],
            'whirlpool_hex' => [hash('whirlpool', 'password'), 128],
        ];
        foreach ($expected as $scheme => [$digest, $length]) {
            $profile['config']['password'] = ['scheme' => $scheme];
            $hash = $writer->preparePassword($profile, 'password');
            $this->assertSame($digest, $hash);
            $this->assertSame($length, strlen($hash));
            $this->assertTrue($writer->acceptsCredential($profile, $hash));
            $this->assertFalse($writer->acceptsCredential($profile, strtoupper($hash)));
            $this->assertFalse($writer->acceptsCredential($profile, substr($hash, 1)));
            $this->assertFalse($writer->acceptsCredential($profile, base64_encode($hash)));
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
