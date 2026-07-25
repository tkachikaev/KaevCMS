<?php

namespace App\Contracts;

use App\Models\GameServer;
use App\Models\LoginServer;
use Carbon\CarbonImmutable;

interface GameAccountGateway
{
    public function supportsLoginServer(LoginServer $loginServer): bool;

    public function supportsGameServer(GameServer $gameServer): bool;

    public function accountExists(LoginServer $loginServer, string $login): bool;

    public function createAccount(LoginServer $loginServer, string $login, string $password, string $email): void;

    public function changePassword(LoginServer $loginServer, string $login, string $password): bool;

    /** @return array{login:string,created_at:string|null,last_active:int,status:string}|null */
    public function accountSummary(LoginServer $loginServer, string $login): ?array;

    /** @return list<array{id:int,name:string,level:int,class_id:int,race:int,gender:int,title:string|null,online:bool,clan:string|null,last_access:int,play_time_seconds:int,pvp_kills:int,pk_kills:int,reputation:int,noble:bool,hero:bool,created_at:CarbonImmutable|null}> */
    public function characters(GameServer $gameServer, string $login): array;
}
