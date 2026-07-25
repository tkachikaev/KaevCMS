<?php

namespace Tests\Unit;

use App\Rules\PasswordWithinHasherLimit;
use Illuminate\Hashing\Argon2IdHasher;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class HashingConfigurationTest extends TestCase
{
    public function test_auto_hashing_uses_argon2id_when_available_and_bcrypt_otherwise(): void
    {
        $expected = in_array('argon2id', password_algos(), true) ? 'argon2id' : 'bcrypt';

        $this->assertSame('auto', config('hashing.requested_driver'));
        $this->assertSame($expected, config('hashing.driver'));
        $this->assertTrue(config('hashing.bcrypt.verify'));
        $this->assertTrue(config('hashing.argon.verify'));

        $hash = Hash::make('l2forge-test-password');

        $this->assertTrue(Hash::check('l2forge-test-password', $hash));
        $this->assertSame($expected, password_get_info($hash)['algoName']);
    }

    public function test_argon_driver_can_verify_and_mark_a_legacy_bcrypt_hash_for_rehashing(): void
    {
        if (! defined('PASSWORD_ARGON2ID') || ! in_array('argon2id', password_algos(), true)) {
            $this->markTestSkipped('Argon2id is not available in this PHP executable.');
        }

        $hasher = new Argon2IdHasher([
            'memory' => 1024,
            'threads' => 1,
            'time' => 1,
            'verify' => false,
        ]);
        $bcryptHash = password_hash('legacy-password', PASSWORD_BCRYPT, ['cost' => 4]);

        $this->assertTrue($hasher->check('legacy-password', $bcryptHash));
        $this->assertTrue($hasher->needsRehash($bcryptHash));
    }

    public function test_bcrypt_rejects_passwords_longer_than_seventy_two_bytes(): void
    {
        config()->set('hashing.driver', 'bcrypt');
        $validator = Validator::make(
            ['password' => str_repeat('я', 37)],
            ['password' => [new PasswordWithinHasherLimit]],
        );

        $this->assertTrue($validator->fails());
    }

    public function test_argon_does_not_apply_the_bcrypt_byte_limit(): void
    {
        config()->set('hashing.driver', 'argon2id');
        $validator = Validator::make(
            ['password' => str_repeat('я', 37)],
            ['password' => [new PasswordWithinHasherLimit]],
        );

        $this->assertFalse($validator->fails());
    }
}
