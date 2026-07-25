<?php

namespace App\Contracts;

use App\Models\GameServer;

interface GameWorldDriver
{
    /** @return list<string> */
    public function capabilities(GameServer $server): array;

    /** @return list<array<string,mixed>> */
    public function ranking(GameServer $server, string $section, int $limit): array;

    /** @return list<array<string,mixed>> */
    public function heroes(GameServer $server): array;

    /** @return list<array<string,mixed>> */
    public function castleOwners(GameServer $server): array;

    /** @return list<array<string,mixed>> */
    public function charactersForAccount(GameServer $server, string $accountName): array;
}
