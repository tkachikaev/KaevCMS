<?php

namespace App\Services\Rewards;

use App\Models\GameServer;
use App\Models\User;
use App\Services\GameAccounts\AccountCharacterDirectory;

/**
 * @phpstan-type RewardCharacter array{id:int,name:string,online:bool,level:int,race_key:string,gender_key:string,archetype:string,avatar_key:string,avatar_url:?string,account_id:int,account_login:string,server_id:int,server_name:string}
 */
final class RewardCharacterDirectory
{
    public function __construct(private readonly AccountCharacterDirectory $characters) {}

    /** @return list<RewardCharacter> */
    public function forServer(User $user, GameServer $server): array
    {
        $directory = $this->characters->for($user);

        return array_values(array_filter(
            $directory['characters'],
            static fn (array $character): bool => $character['server_id'] === $server->id,
        ));
    }

    /** @return RewardCharacter|null */
    public function find(User $user, GameServer $server, int $characterId): ?array
    {
        foreach ($this->forServer($user, $server) as $character) {
            if ($character['id'] === $characterId) {
                return $character;
            }
        }

        return null;
    }
}
