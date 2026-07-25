<?php

namespace Tests\Fakes;

use App\Contracts\GameAccountGateway;
use App\Models\GameServer;
use App\Models\LoginServer;
use RuntimeException;

class FakeGameAccountGateway implements GameAccountGateway
{
    /** @var array<string,bool> */
    public array $existing = [];

    public bool $failCreate = false;

    public bool $passwordChangeResult = true;

    public bool $failCharacters = false;

    public int $characterCalls = 0;

    /** @var list<array{login_server_id:int,login:string,password:string,email:string}> */
    public array $created = [];

    /** @var list<array{login_server_id:int,login:string,password:string}> */
    public array $passwordChanges = [];

    /** @var array<string,array{login:string,created_at:string|null,last_active:int,status:string}> */
    public array $summaries = [];

    /** @var array<int,list<array<string,mixed>>> */
    public array $charactersByServer = [];

    public function supportsLoginServer(LoginServer $loginServer): bool
    {
        return in_array($loginServer->driver, ['l2j_mobius', 'l2j_mobius_legacy'], true);
    }

    public function supportsGameServer(GameServer $gameServer): bool
    {
        return $gameServer->driver === 'l2j_mobius_ct0_interlude';
    }

    public function accountExists(LoginServer $loginServer, string $login): bool
    {
        return $this->existing[$loginServer->id.':'.strtolower($login)] ?? false;
    }

    public function createAccount(LoginServer $loginServer, string $login, string $password, string $email): void
    {
        if ($this->failCreate) {
            throw new RuntimeException('external_creation_failed');
        }

        $this->created[] = [
            'login_server_id' => $loginServer->id,
            'login' => $login,
            'password' => $password,
            'email' => $email,
        ];
        $this->existing[$loginServer->id.':'.strtolower($login)] = true;
    }

    public function changePassword(LoginServer $loginServer, string $login, string $password): bool
    {
        $this->passwordChanges[] = [
            'login_server_id' => $loginServer->id,
            'login' => $login,
            'password' => $password,
        ];

        return $this->passwordChangeResult;
    }

    public function accountSummary(LoginServer $loginServer, string $login): ?array
    {
        return $this->summaries[$loginServer->id.':'.strtolower($login)] ?? [
            'login' => $login,
            'created_at' => '2026-07-15 10:00:00',
            'last_active' => 0,
            'status' => 'active',
        ];
    }

    public function characters(GameServer $gameServer, string $login): array
    {
        $this->characterCalls++;

        if ($this->failCharacters) {
            throw new RuntimeException('external_character_query_failed');
        }

        return array_map(static fn (array $character): array => array_merge($character, [
            'class_id' => (int) ($character['class_id'] ?? -1),
            'race' => (int) ($character['race'] ?? -1),
            'gender' => (int) ($character['gender'] ?? -1),
        ]), $this->charactersByServer[$gameServer->id] ?? []);
    }
}
