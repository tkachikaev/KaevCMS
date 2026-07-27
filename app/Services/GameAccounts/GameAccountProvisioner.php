<?php

namespace App\Services\GameAccounts;

use App\Contracts\GameAccountGateway;
use App\Exceptions\GameAccountCreationException;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Models\User;
use App\Models\UserGameAccount;
use App\Services\AuditLogger;
use App\Support\GameAccounts\ExternalGameAccountState;
use App\Support\GameAccounts\ExternalGameAccountWriteResult;
use App\Support\GameAccounts\GameAccountCreationFailure;
use App\Support\GameAccounts\PreparedGameAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class GameAccountProvisioner
{
    public function __construct(
        private readonly GameAccountGateway $gateway,
        private readonly GameAccountQuota $quota,
        private readonly AuditLogger $audit,
    ) {}

    public function create(
        User $user,
        GameServer $gameServer,
        string $login,
        string $password,
        string $email,
        int $maximumAccounts,
    ): UserGameAccount {
        $login = trim($login);
        $normalizedLogin = Str::lower($login);
        $prepared = $this->gateway->prepareAccount($login, $password, $email);

        /** @var array{0: UserGameAccount, 1: bool} $reservation */
        $reservation = DB::transaction(function () use (
            $user,
            $gameServer,
            $login,
            $normalizedLogin,
            $prepared,
            $maximumAccounts,
        ): array {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $lockedGameServer = GameServer::query()->lockForUpdate()->findOrFail($gameServer->id);
            $lockedLoginServer = $lockedGameServer->login_server_id !== null
                ? LoginServer::query()->lockForUpdate()->find($lockedGameServer->login_server_id)
                : null;

            if (! $lockedLoginServer instanceof LoginServer
                || ! $lockedGameServer->connectionConfigured()
                || ! $this->gateway->supportsLoginServer($lockedLoginServer)) {
                throw new GameAccountCreationException(GameAccountCreationFailure::ServerUnavailable);
            }

            $existing = UserGameAccount::query()
                ->where('login_server_id', $lockedLoginServer->id)
                ->where('normalized_login', $normalizedLogin)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof UserGameAccount) {
                if ($existing->user_id !== $lockedUser->id) {
                    throw new GameAccountCreationException(GameAccountCreationFailure::LinkConflict, $existing);
                }

                return [$existing, false];
            }

            if ($this->quota->reached($lockedUser, $maximumAccounts)) {
                throw new GameAccountCreationException(GameAccountCreationFailure::LimitReached);
            }

            $account = new UserGameAccount;
            $account->forceFill([
                'user_id' => $lockedUser->id,
                'login_server_id' => $lockedLoginServer->id,
                'registration_game_server_id' => $lockedGameServer->id,
                'game_login' => $login,
                'normalized_login' => $normalizedLogin,
                'created_via_cms' => true,
                'creation_uuid' => (string) Str::uuid(),
                'creation_status' => UserGameAccount::STATUS_PENDING,
                'creation_credential' => $prepared->credential,
                'creation_email' => $prepared->email,
                'creation_attempts' => 0,
                'creation_last_error' => null,
                'creation_write_attempted_at' => null,
                'creation_processing_at' => null,
                'creation_last_checked_at' => null,
                'creation_completed_at' => null,
            ])->save();

            return [$account, true];
        }, 3);
        [$account, $created] = $reservation;

        if ($account->isActive()) {
            return $account;
        }

        return $this->provision(
            account: $account,
            replacement: $prepared,
            allowWrite: true,
            firstAttempt: $created,
            actor: $user,
            maximumAccounts: $maximumAccounts,
        );
    }

    public function recover(UserGameAccount|int $account, bool $retryMissing = false): UserGameAccount
    {
        $accountId = $account instanceof UserGameAccount ? $account->id : $account;
        $account = UserGameAccount::query()->findOrFail($accountId);

        if ($account->isActive()) {
            return $account;
        }

        return $this->provision(
            account: $account,
            replacement: null,
            allowWrite: $retryMissing,
            firstAttempt: false,
            actor: null,
            maximumAccounts: null,
        );
    }

    private function provision(
        UserGameAccount $account,
        ?PreparedGameAccount $replacement,
        bool $allowWrite,
        bool $firstAttempt,
        ?User $actor,
        ?int $maximumAccounts,
    ): UserGameAccount {
        $account = $this->claim($account->id);

        try {
            if ($account->isActive()) {
                return $account;
            }

            $loginServer = LoginServer::query()->find($account->login_server_id);
            if (! $loginServer instanceof LoginServer || ! $this->gateway->supportsLoginServer($loginServer)) {
                if ($account->creation_write_attempted_at !== null) {
                    $this->markPending($account, GameAccountCreationFailure::ServerUnavailable, $actor);
                } else {
                    $this->markFailed($account, GameAccountCreationFailure::ServerUnavailable, $actor);
                }

                throw new GameAccountCreationException(GameAccountCreationFailure::ServerUnavailable, $this->freshAccount($account->id));
            }

            try {
                $stored = $this->storedAccount($account);
            } catch (Throwable) {
                $stored = null;
            }

            if (! $stored instanceof PreparedGameAccount) {
                $this->markFailed(
                    $account,
                    GameAccountCreationFailure::CreationProofMissing,
                    $actor,
                    clearWriteEvidence: true,
                );

                throw new GameAccountCreationException(GameAccountCreationFailure::CreationProofMissing, $this->freshAccount($account->id));
            }

            try {
                $state = $this->gateway->inspectPreparedAccount($loginServer, $stored);
            } catch (Throwable) {
                $this->markPending($account, GameAccountCreationFailure::VerificationUnavailable, $actor);

                throw new GameAccountCreationException(GameAccountCreationFailure::VerificationUnavailable, $this->freshAccount($account->id));
            }

            if ($state === ExternalGameAccountState::Matching) {
                if ($account->creation_write_attempted_at !== null) {
                    return $this->markActive($account, $actor, recovered: ! $firstAttempt);
                }

                $this->markFailed(
                    $account,
                    GameAccountCreationFailure::ExternalAccountExists,
                    $actor,
                    clearWriteEvidence: true,
                );

                throw new GameAccountCreationException(GameAccountCreationFailure::ExternalAccountExists, $this->freshAccount($account->id));
            }

            if ($state === ExternalGameAccountState::Conflict) {
                $failure = $account->creation_write_attempted_at === null
                    ? GameAccountCreationFailure::ExternalAccountExists
                    : GameAccountCreationFailure::ExternalAccountConflict;
                $this->markFailed($account, $failure, $actor, clearWriteEvidence: true);

                throw new GameAccountCreationException($failure, $this->freshAccount($account->id));
            }

            if (! $allowWrite) {
                $this->markFailed(
                    $account,
                    GameAccountCreationFailure::ExternalAccountMissing,
                    $actor,
                    clearWriteEvidence: true,
                );

                throw new GameAccountCreationException(GameAccountCreationFailure::ExternalAccountMissing, $this->freshAccount($account->id));
            }

            if ($maximumAccounts !== null && ! $this->quotaStillAvailable($account, $maximumAccounts)) {
                $this->markFailed(
                    $account,
                    GameAccountCreationFailure::LimitReached,
                    $actor,
                    clearWriteEvidence: true,
                );

                throw new GameAccountCreationException(GameAccountCreationFailure::LimitReached, $this->freshAccount($account->id));
            }

            $prepared = $replacement ?? $stored;
            $account = $this->markWriteAttempt($account, $prepared);

            try {
                $writeResult = $this->gateway->createPreparedAccount($loginServer, $prepared);
            } catch (Throwable) {
                return $this->resolveAfterWriteFailure($account, $loginServer, $prepared, $actor);
            }

            if ($writeResult === ExternalGameAccountWriteResult::AlreadyExists) {
                $this->markFailed(
                    $account,
                    GameAccountCreationFailure::ExternalAccountExists,
                    $actor,
                    clearWriteEvidence: true,
                );

                throw new GameAccountCreationException(
                    GameAccountCreationFailure::ExternalAccountExists,
                    $this->freshAccount($account->id),
                );
            }

            return $this->verifyAfterWrite($account, $loginServer, $prepared, $actor);
        } finally {
            $this->releaseClaim($account->id);
        }
    }

    private function resolveAfterWriteFailure(
        UserGameAccount $account,
        LoginServer $loginServer,
        PreparedGameAccount $prepared,
        ?User $actor,
    ): UserGameAccount {
        try {
            $state = $this->gateway->inspectPreparedAccount($loginServer, $prepared);
        } catch (Throwable) {
            $this->markPending($account, GameAccountCreationFailure::VerificationUnavailable, $actor);

            throw new GameAccountCreationException(GameAccountCreationFailure::VerificationUnavailable, $this->freshAccount($account->id));
        }

        if ($state === ExternalGameAccountState::Matching) {
            return $this->markActive($account, $actor, recovered: true);
        }

        $failure = $state === ExternalGameAccountState::Conflict
            ? GameAccountCreationFailure::ExternalAccountConflict
            : GameAccountCreationFailure::ExternalCreateFailed;
        $this->markFailed($account, $failure, $actor, clearWriteEvidence: true);

        throw new GameAccountCreationException($failure, $this->freshAccount($account->id));
    }

    private function verifyAfterWrite(
        UserGameAccount $account,
        LoginServer $loginServer,
        PreparedGameAccount $prepared,
        ?User $actor,
    ): UserGameAccount {
        try {
            $state = $this->gateway->inspectPreparedAccount($loginServer, $prepared);
        } catch (Throwable) {
            $this->markPending($account, GameAccountCreationFailure::VerificationUnavailable, $actor);

            throw new GameAccountCreationException(GameAccountCreationFailure::VerificationUnavailable, $this->freshAccount($account->id));
        }

        if ($state === ExternalGameAccountState::Matching) {
            return $this->markActive($account, $actor, recovered: false);
        }

        $failure = $state === ExternalGameAccountState::Conflict
            ? GameAccountCreationFailure::ExternalAccountConflict
            : GameAccountCreationFailure::ExternalAccountMissing;
        $this->markFailed($account, $failure, $actor, clearWriteEvidence: true);

        throw new GameAccountCreationException($failure, $this->freshAccount($account->id));
    }

    private function claim(int $accountId): UserGameAccount
    {
        return DB::transaction(function () use ($accountId): UserGameAccount {
            $account = UserGameAccount::query()->lockForUpdate()->findOrFail($accountId);
            if ($account->isActive()) {
                return $account;
            }

            $timeout = max(30, min(3600, (int) config('cms.game_account_creation.processing_timeout_seconds', 300)));
            if ($account->creation_processing_at !== null
                && $account->creation_processing_at->isAfter(now()->subSeconds($timeout))) {
                throw new GameAccountCreationException(GameAccountCreationFailure::OperationBusy, $account);
            }

            $account->forceFill(['creation_processing_at' => now()])->save();

            return $this->freshAccount($account->id);
        }, 3);
    }

    private function quotaStillAvailable(UserGameAccount $account, int $maximumAccounts): bool
    {
        return DB::transaction(function () use ($account, $maximumAccounts): bool {
            $locked = UserGameAccount::query()->lockForUpdate()->findOrFail($account->id);
            $user = User::query()->lockForUpdate()->findOrFail($locked->user_id);

            return $user->gameAccountsCountingTowardLimit()
                ->where('id', '!=', $locked->id)
                ->count() < max(1, $maximumAccounts);
        }, 3);
    }

    private function markWriteAttempt(
        UserGameAccount $account,
        PreparedGameAccount $prepared,
    ): UserGameAccount {
        return DB::transaction(function () use ($account, $prepared): UserGameAccount {
            $locked = UserGameAccount::query()->lockForUpdate()->findOrFail($account->id);
            $locked->forceFill([
                'creation_status' => UserGameAccount::STATUS_PENDING,
                'creation_credential' => $prepared->credential,
                'creation_email' => $prepared->email,
                'creation_attempts' => $locked->creation_attempts + 1,
                'creation_last_error' => null,
                'creation_write_attempted_at' => now(),
                'creation_last_checked_at' => now(),
                'creation_completed_at' => null,
            ])->save();

            return $locked->fresh();
        }, 3);
    }

    private function markActive(UserGameAccount $account, ?User $actor, bool $recovered): UserGameAccount
    {
        $transitioned = false;
        $account = DB::transaction(function () use ($account, &$transitioned): UserGameAccount {
            $locked = UserGameAccount::query()->lockForUpdate()->findOrFail($account->id);
            if (! $locked->isActive()) {
                $transitioned = true;
                $locked->forceFill([
                    'creation_status' => UserGameAccount::STATUS_ACTIVE,
                    'creation_credential' => null,
                    'creation_email' => null,
                    'creation_last_error' => null,
                    'creation_processing_at' => null,
                    'creation_last_checked_at' => now(),
                    'creation_completed_at' => now(),
                ])->save();
            }

            return $locked->fresh();
        }, 3);

        if ($transitioned) {
            $details = $this->auditDetails($account);
            $details['recovered'] = $recovered;
            $action = $recovered ? 'user.game_account_creation_recovered' : 'user.game_account_created';

            if ($actor instanceof User) {
                $this->audit->success(
                    category: 'game_account',
                    action: $action,
                    actor: $actor,
                    target: $account,
                    details: $details,
                );
            } else {
                $this->audit->system(
                    category: 'game_account',
                    action: $action,
                    target: $account,
                    details: $details,
                );
            }
        }

        return $account;
    }

    private function markPending(
        UserGameAccount $account,
        GameAccountCreationFailure $failure,
        ?User $actor,
    ): void {
        $account = $this->storeStatus($account, UserGameAccount::STATUS_PENDING, $failure);
        $this->auditFailure('user.game_account_creation_pending', $account, $actor);
    }

    private function markFailed(
        UserGameAccount $account,
        GameAccountCreationFailure $failure,
        ?User $actor,
        bool $clearWriteEvidence = false,
    ): void {
        $account = $this->storeStatus(
            $account,
            UserGameAccount::STATUS_FAILED,
            $failure,
            clearWriteEvidence: $clearWriteEvidence,
        );
        $this->auditFailure('user.game_account_creation_failed', $account, $actor);
    }

    private function storeStatus(
        UserGameAccount $account,
        string $status,
        GameAccountCreationFailure $failure,
        bool $clearWriteEvidence = false,
    ): UserGameAccount {
        return DB::transaction(function () use (
            $account,
            $status,
            $failure,
            $clearWriteEvidence,
        ): UserGameAccount {
            $locked = UserGameAccount::query()->lockForUpdate()->findOrFail($account->id);
            $values = [
                'creation_status' => $status,
                'creation_last_error' => $failure->value,
                'creation_processing_at' => null,
                'creation_last_checked_at' => now(),
                'creation_completed_at' => $status === UserGameAccount::STATUS_FAILED ? now() : null,
            ];
            if ($clearWriteEvidence) {
                $values['creation_write_attempted_at'] = null;
            }

            $locked->forceFill($values)->save();

            return $locked->fresh();
        }, 3);
    }

    private function auditFailure(string $action, UserGameAccount $account, ?User $actor): void
    {
        if ($actor instanceof User) {
            $this->audit->failed(
                category: 'game_account',
                action: $action,
                actor: $actor,
                target: $account,
                details: $this->auditDetails($account),
            );

            return;
        }

        $this->audit->system(
            category: 'game_account',
            action: $action,
            target: $account,
            details: $this->auditDetails($account),
            result: 'failed',
        );
    }

    private function releaseClaim(int $accountId): void
    {
        try {
            UserGameAccount::query()
                ->whereKey($accountId)
                ->whereNotNull('creation_processing_at')
                ->update(['creation_processing_at' => null]);
        } catch (Throwable $exception) {
            Log::warning('Unable to release game account creation claim.', [
                'game_account_id' => $accountId,
                'exception' => $exception::class,
            ]);
        }
    }

    private function storedAccount(UserGameAccount $account): ?PreparedGameAccount
    {
        if ($account->creation_credential === null || $account->creation_email === null) {
            return null;
        }

        return new PreparedGameAccount(
            login: $account->game_login,
            credential: $account->creation_credential,
            email: $account->creation_email,
        );
    }

    private function freshAccount(int $accountId): UserGameAccount
    {
        return UserGameAccount::query()->findOrFail($accountId);
    }

    /** @return array<string, mixed> */
    private function auditDetails(UserGameAccount $account): array
    {
        return [
            'operation_uuid' => $account->creation_uuid,
            'login_server_id' => $account->login_server_id,
            'game_server_id' => $account->registration_game_server_id,
            'game_login' => $account->game_login,
            'status' => $account->creation_status,
            'attempts' => $account->creation_attempts,
            'failure_code' => $account->creation_last_error,
        ];
    }
}
