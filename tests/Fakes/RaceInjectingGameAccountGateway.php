<?php

namespace Tests\Fakes;

use App\Models\LoginServer;
use App\Models\User;
use App\Models\UserGameAccount;
use App\Support\GameAccounts\ExternalGameAccountState;
use App\Support\GameAccounts\PreparedGameAccount;

class RaceInjectingGameAccountGateway extends FakeGameAccountGateway
{
    private bool $injected = false;

    public function __construct(
        private readonly User $user,
        private readonly LoginServer $loginServer,
    ) {}

    public function inspectPreparedAccount(
        LoginServer $loginServer,
        PreparedGameAccount $account,
    ): ExternalGameAccountState {
        if (! $this->injected && $loginServer->is($this->loginServer)) {
            $this->injected = true;

            UserGameAccount::factory()
                ->for($this->user)
                ->orphaned($this->loginServer)
                ->create([
                    'game_login' => 'Concurrent01',
                    'normalized_login' => 'concurrent01',
                ]);
        }

        return parent::inspectPreparedAccount($loginServer, $account);
    }
}
