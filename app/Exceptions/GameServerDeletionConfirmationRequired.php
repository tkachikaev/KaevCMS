<?php

namespace App\Exceptions;

use RuntimeException;

final class GameServerDeletionConfirmationRequired extends RuntimeException
{
    /**
     * @param array{
     *     game_server_id:int,
     *     login_server_id:int|null,
     *     login_server_name:string|null,
     *     replacement_game_server_id:int|null,
     *     login_server_account_count:int,
     *     accounts_becoming_unavailable:int,
     *     unavailable_after_deletion:int,
     *     requires_confirmation:bool,
     *     fingerprint:string
     * } $impact
     */
    public function __construct(public readonly array $impact)
    {
        parent::__construct('GameServer deletion impact must be confirmed again.');
    }
}
