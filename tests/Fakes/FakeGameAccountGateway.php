<?php

namespace Tests\Fakes;

use App\Contracts\GameAccountGateway;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Support\GameAccounts\ExternalGameAccountState;
use App\Support\GameAccounts\ExternalGameAccountWriteResult;
use App\Support\GameAccounts\PreparedGameAccount;
use RuntimeException;

class FakeGameAccountGateway implements GameAccountGateway
{
    /** @var array<string,bool> */
    public array $existing = [];

    /** @var array<string,array{credential:string,email:string}> */
    public array $externalAccounts = [];

    public bool $failCreate = false;

    public bool $failCreateAfterWrite = false;

    public bool $failInspect = false;

    public bool $failInspectAfterCreate = false;

    public bool $duplicateOnCreate = false;

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
        return $gameServer->driver === 'l2j_mobius';
    }

    public function prepareAccount(string $login, string $password, string $email): PreparedGameAccount
    {
        return new PreparedGameAccount(trim($login), $password, strtolower(trim($email)));
    }

    public function inspectPreparedAccount(
        LoginServer $loginServer,
        PreparedGameAccount $account,
    ): ExternalGameAccountState {
        if ($this->failInspect || ($this->failInspectAfterCreate && $this->created !== [])) {
            throw new RuntimeException('external_inspection_failed');
        }

        $key = $this->key($loginServer, $account->login);
        if (isset($this->externalAccounts[$key])) {
            $stored = $this->externalAccounts[$key];

            return hash_equals($stored['credential'], $account->credential)
                && hash_equals($stored['email'], $account->email)
                ? ExternalGameAccountState::Matching
                : ExternalGameAccountState::Conflict;
        }

        return ($this->existing[$key] ?? false)
            ? ExternalGameAccountState::Conflict
            : ExternalGameAccountState::Missing;
    }

    public function createPreparedAccount(
        LoginServer $loginServer,
        PreparedGameAccount $account,
    ): ExternalGameAccountWriteResult {
        if ($this->failCreate) {
            throw new RuntimeException('external_creation_failed');
        }

        if ($this->duplicateOnCreate) {
            $this->externalAccounts[$this->key($loginServer, $account->login)] = [
                'credential' => $account->credential,
                'email' => $account->email,
            ];

            return ExternalGameAccountWriteResult::AlreadyExists;
        }

        $this->created[] = [
            'login_server_id' => $loginServer->id,
            'login' => $account->login,
            'password' => $account->credential,
            'email' => $account->email,
        ];
        $this->externalAccounts[$this->key($loginServer, $account->login)] = [
            'credential' => $account->credential,
            'email' => $account->email,
        ];

        if ($this->failCreateAfterWrite) {
            throw new RuntimeException('external_creation_timeout');
        }

        return ExternalGameAccountWriteResult::Created;
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

    private function key(LoginServer $loginServer, string $login): string
    {
        return $loginServer->id.':'.strtolower($login);
    }
}
