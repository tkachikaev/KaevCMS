<?php

namespace App\Services;

use App\Models\GameServer;
use App\Models\UserGameAccount;

final class GameServerDeletionImpact
{
    /**
     * @return array{
     *     game_server_id:int,
     *     login_server_id:int|null,
     *     login_server_name:string|null,
     *     replacement_game_server_id:int|null,
     *     login_server_account_count:int,
     *     accounts_becoming_unavailable:int,
     *     unavailable_after_deletion:int,
     *     requires_confirmation:bool,
     *     fingerprint:string
     * }
     */
    public function analyze(GameServer $server): array
    {
        $server->loadMissing('loginServer');
        $loginServerId = $server->login_server_id;
        $replacementId = null;
        $loginServerAccountCount = 0;
        $accountsBecomingUnavailable = 0;

        if ($loginServerId !== null) {
            $replacementId = GameServer::query()
                ->where('login_server_id', $loginServerId)
                ->whereKeyNot($server->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->value('id');
            $replacementId = is_numeric($replacementId) ? (int) $replacementId : null;

            $loginServerAccountCount = UserGameAccount::query()
                ->where('login_server_id', $loginServerId)
                ->count();

            if ($replacementId === null) {
                $accountsBecomingUnavailable = UserGameAccount::query()
                    ->where('login_server_id', $loginServerId)
                    ->where('registration_game_server_id', $server->id)
                    ->count();
            }
        }

        $unavailableAfterDeletion = $replacementId === null ? $loginServerAccountCount : 0;
        $fingerprintPayload = [
            'game_server_id' => (int) $server->id,
            'login_server_id' => $loginServerId,
            'replacement_game_server_id' => $replacementId,
            'login_server_account_count' => $loginServerAccountCount,
            'accounts_becoming_unavailable' => $accountsBecomingUnavailable,
            'unavailable_after_deletion' => $unavailableAfterDeletion,
        ];

        return $fingerprintPayload + [
            'login_server_name' => $server->loginServer?->name,
            'requires_confirmation' => $accountsBecomingUnavailable > 0,
            'fingerprint' => hash('sha256', json_encode($fingerprintPayload, JSON_THROW_ON_ERROR)),
        ];
    }
}
