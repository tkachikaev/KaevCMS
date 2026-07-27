<?php

namespace App\Support\GameAccounts;

enum GameAccountCreationFailure: string
{
    case LimitReached = 'game_account_limit_reached';
    case LinkConflict = 'game_account_link_conflict';
    case ServerUnavailable = 'game_server_unavailable';
    case ExternalAccountExists = 'external_account_exists';
    case ExternalAccountConflict = 'external_account_conflict';
    case ExternalCreateFailed = 'external_create_failed';
    case ExternalAccountMissing = 'external_account_missing';
    case VerificationUnavailable = 'external_verification_unavailable';
    case CreationProofMissing = 'creation_proof_missing';
    case OperationBusy = 'creation_operation_busy';
}
