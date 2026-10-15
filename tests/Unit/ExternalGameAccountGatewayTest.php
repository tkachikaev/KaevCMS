<?php

namespace Tests\Unit;

use App\Models\GameServer;
use App\Models\LoginServer;
use App\Services\GameAccounts\ExternalGameAccountGateway;
use Tests\TestCase;

class ExternalGameAccountGatewayTest extends TestCase
{
    public function test_it_supports_modern_and_legacy_mobius_login_drivers(): void
    {
        $gateway = app(ExternalGameAccountGateway::class);

        $this->assertTrue($gateway->supportsLoginServer(new LoginServer([
            'driver' => 'l2j_mobius',
        ])));
        $this->assertTrue($gateway->supportsLoginServer(new LoginServer([
            'driver' => 'l2j_mobius_legacy',
        ])));
        $this->assertTrue($gateway->supportsLoginServer(new LoginServer([
            'driver' => 'rusacis',
        ])));
    }

    public function test_rusacis_prepared_account_uses_bcrypt_2a_without_plaintext(): void
    {
        $gateway = app(ExternalGameAccountGateway::class);
        $server = new LoginServer(['driver' => 'rusacis']);
        $first = $gateway->prepareAccount($server, ' TestLogin ', 'Password123', 'test@example.com');
        $second = $gateway->prepareAccount($server, ' TestLogin ', 'Password123', 'test@example.com');

        $this->assertSame('TestLogin', $first->login);
        $this->assertSame('test@example.com', $first->email);
        $this->assertMatchesRegularExpression('/^\$2a\$10\$[.\/A-Za-z0-9]{53}$/', $first->credential);
        $this->assertTrue(password_verify('Password123', $first->credential));
        $this->assertFalse(password_verify('Wrong123', $first->credential));
        $this->assertNotSame($first->credential, $second->credential);
    }

    public function test_it_supports_canonical_mobius_and_matched_rusacis_game_drivers(): void
    {
        $gateway = app(ExternalGameAccountGateway::class);
        $loginServer = new LoginServer(['driver' => 'l2j_mobius']);

        $supported = new GameServer(['driver' => 'l2j_mobius']);
        $supported->setRelation('loginServer', $loginServer);

        $legacyIdentifier = new GameServer(['driver' => 'l2j_mobius_ct0_interlude']);
        $legacyIdentifier->setRelation('loginServer', $loginServer);

        $rusacisLogin = new LoginServer(['driver' => 'rusacis']);
        $invalidMixed = new GameServer(['driver' => 'l2j_mobius']);
        $invalidMixed->setRelation('loginServer', $rusacisLogin);

        $this->assertTrue($gateway->supportsGameServer($supported));
        $this->assertFalse($gateway->supportsGameServer($legacyIdentifier));
        $this->assertFalse($gateway->supportsGameServer($invalidMixed));

        $rusacisGame = new GameServer(['driver' => 'rusacis']);
        $rusacisGame->setRelation('loginServer', $rusacisLogin);
        $this->assertTrue($gateway->supportsGameServer($rusacisGame));

        $rusacisGame->setRelation('loginServer', $loginServer);
        $this->assertFalse($gateway->supportsGameServer($rusacisGame));
    }

    public function test_it_prepares_a_normalized_driver_proof_without_plaintext_password(): void
    {
        $gateway = app(ExternalGameAccountGateway::class);
        $prepared = $gateway->prepareAccount(new LoginServer(['driver' => 'l2j_mobius']), ' Player01 ', 'StrongPass1', 'PLAYER@EXAMPLE.COM ');

        $this->assertSame('Player01', $prepared->login);
        $this->assertSame('player@example.com', $prepared->email);
        $this->assertNotSame('StrongPass1', $prepared->credential);
        $this->assertNotSame('', $prepared->credential);
    }
}
