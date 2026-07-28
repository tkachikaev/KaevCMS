<?php

namespace Tests\Fakes;

use App\Contracts\CharacterRescueGateway;
use App\Models\GameServer;
use App\Support\GameServerFeatures\CharacterRescueCapabilities;
use App\Support\GameServerFeatures\CharacterRescueWriteResult;
use Carbon\CarbonImmutable;

final class FakeCharacterRescueGateway implements CharacterRescueGateway
{
    public bool $supported = true;

    public bool $capabilitySupported = true;

    public int $capabilityCalls = 0;

    public int $rescueCalls = 0;

    public CharacterRescueWriteResult $result;

    /** @var array<string,mixed>|null */
    public ?array $lastCall = null;

    public function __construct()
    {
        $this->result = new CharacterRescueWriteResult(
            CharacterRescueWriteResult::SUCCESS,
            characterName: 'Bubi',
            oldX: 10,
            oldY: 20,
            oldZ: 30,
        );
    }

    public function supports(GameServer $server): bool
    {
        return $this->supported;
    }

    public function capabilities(GameServer $server): CharacterRescueCapabilities
    {
        $this->capabilityCalls++;

        return new CharacterRescueCapabilities($this->capabilitySupported);
    }

    public function rescue(
        GameServer $server,
        string $accountLogin,
        int $characterId,
        array $target,
        CarbonImmutable $offlineBefore,
    ): CharacterRescueWriteResult {
        $this->rescueCalls++;
        $this->lastCall = [
            'game_server_id' => $server->id,
            'account_login' => $accountLogin,
            'character_id' => $characterId,
            'target' => $target,
            'offline_before' => $offlineBefore,
        ];

        return $this->result;
    }
}
