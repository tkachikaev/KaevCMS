<?php

namespace Tests\Fakes;

use App\Models\LoginServer;
use App\Models\User;
use App\Models\UserGameAccount;

class RaceInjectingGameAccountGateway extends FakeGameAccountGateway
{
    private bool $injected = false;

    public function __construct(
        private readonly User $user,
        private readonly LoginServer $loginServer,
    ) {}

    public function accountExists(LoginServer $loginServer, string $login): bool
    {
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

        return parent::accountExists($loginServer, $login);
    }
}
