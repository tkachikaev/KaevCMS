<?php

namespace App\Services\GameAccounts;

use App\Contracts\GameAccountGateway;
use App\Models\User;
use App\Models\UserGameAccount;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class GameAccountPasswordChanger
{
    public function __construct(
        private readonly GameAccountGateway $gateway,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function change(
        User $user,
        UserGameAccount $account,
        string $currentPassword,
        string $newPassword,
    ): void {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('The current personal account password is incorrect.'),
            ]);
        }

        if (mb_strtolower($newPassword) === mb_strtolower($account->game_login)) {
            throw ValidationException::withMessages([
                'game_password' => __('The game password must not match the game login.'),
            ]);
        }

        try {
            if (! $this->gateway->changePassword(
                $account->loginServer,
                $account->game_login,
                $newPassword,
            )) {
                throw ValidationException::withMessages([
                    'game_password' => __('The game account was not found on the LoginServer.'),
                ]);
            }

            $this->auditLogger->success(
                category: 'game_account',
                action: 'user.game_account_password_changed',
                actor: $user,
                target: $account,
                details: ['login_server_id' => $account->login_server_id, 'game_login' => $account->game_login],
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('Game account password change failed.', [
                'exception' => $exception::class,
                'login_server_id' => $account->login_server_id,
            ]);
            $this->auditLogger->failed(
                category: 'game_account',
                action: 'user.game_account_password_change_failed',
                actor: $user,
                target: $account,
                details: ['exception_class' => $exception::class],
            );

            throw ValidationException::withMessages([
                'game_password' => __('The game password could not be changed. Try again later.'),
            ]);
        }
    }
}
