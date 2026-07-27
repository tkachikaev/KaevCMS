<?php

namespace App\Contracts;

use App\Models\GameServer;
use App\Models\LoginServer;
use App\Support\GameAccounts\ExternalGameAccountState;
use App\Support\GameAccounts\ExternalGameAccountWriteResult;
use App\Support\GameAccounts\PreparedGameAccount;
use Carbon\CarbonImmutable;

interface GameAccountGateway
{
    public function supportsLoginServer(LoginServer $loginServer): bool;

    public function supportsGameServer(GameServer $gameServer): bool;

    public function prepareAccount(string $login, string $password, string $email): PreparedGameAccount;

    public function inspectPreparedAccount(
        LoginServer $loginServer,
        PreparedGameAccount $account,
    ): ExternalGameAccountState;

    public function createPreparedAccount(
        LoginServer $loginServer,
        PreparedGameAccount $account,
    ): ExternalGameAccountWriteResult;

    public function changePassword(LoginServer $loginServer, string $login, string $password): bool;

    /** @return array{login:string,created_at:string|null,last_active:int,status:string}|null */
    public function accountSummary(LoginServer $loginServer, string $login): ?array;

    /** @return list<array{id:int,name:string,level:int,class_id:int,race:int,gender:int,title:string|null,online:bool,clan:string|null,last_access:int,play_time_seconds:int,pk_kills:int,pvp_kills:int,reputation:int,noble:bool,hero:bool,created_at:CarbonImmutable|null}> */
    public function characters(GameServer $gameServer, string $login): array;
}
