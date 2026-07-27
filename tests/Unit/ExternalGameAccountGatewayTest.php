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
        $this->assertFalse($gateway->supportsLoginServer(new LoginServer([
            'driver' => 'rusacis',
        ])));
    }

    public function test_it_supports_only_the_canonical_mobius_game_driver(): void
    {
        $gateway = app(ExternalGameAccountGateway::class);
        $loginServer = new LoginServer(['driver' => 'l2j_mobius']);

        $supported = new GameServer(['driver' => 'l2j_mobius']);
        $supported->setRelation('loginServer', $loginServer);

        $legacyIdentifier = new GameServer(['driver' => 'l2j_mobius_ct0_interlude']);
        $legacyIdentifier->setRelation('loginServer', $loginServer);

        $this->assertTrue($gateway->supportsGameServer($supported));
        $this->assertFalse($gateway->supportsGameServer($legacyIdentifier));
    }

    public function test_it_prepares_a_normalized_driver_proof_without_plaintext_password(): void
    {
        $gateway = app(ExternalGameAccountGateway::class);
        $prepared = $gateway->prepareAccount(' Player01 ', 'StrongPass1', 'PLAYER@EXAMPLE.COM ');

        $this->assertSame('Player01', $prepared->login);
        $this->assertSame('player@example.com', $prepared->email);
        $this->assertNotSame('StrongPass1', $prepared->credential);
        $this->assertNotSame('', $prepared->credential);
    }
}
