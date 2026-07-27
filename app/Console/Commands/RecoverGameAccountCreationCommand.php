<?php

namespace App\Console\Commands;

use App\Exceptions\GameAccountCreationException;
use App\Models\UserGameAccount;
use App\Services\GameAccounts\GameAccountProvisioner;
use Illuminate\Console\Command;

final class RecoverGameAccountCreationCommand extends Command
{
    protected $signature = 'kaevcms:game-accounts-recover
        {operation? : Creation operation UUID to inspect or recover}
        {--retry : Retry the LoginServer write only when the account is confirmed missing}
        {--older-than=300 : Minimum pending age in seconds for diagnostics}
        {--limit=50 : Maximum number of pending operations to list}';

    protected $description = 'Diagnose and safely recover game account creation operations';

    public function handle(GameAccountProvisioner $provisioner): int
    {
        $operation = trim((string) ($this->argument('operation') ?? ''));
        if ($operation === '') {
            return $this->diagnose();
        }

        $account = UserGameAccount::query()
            ->with(['user', 'loginServer', 'registrationGameServer'])
            ->where('creation_uuid', $operation)
            ->first();

        if (! $account instanceof UserGameAccount) {
            $this->error('Game account creation operation was not found.');

            return self::FAILURE;
        }

        try {
            $account = $provisioner->recover($account, retryMissing: (bool) $this->option('retry'));
        } catch (GameAccountCreationException $exception) {
            $account = UserGameAccount::query()->findOrFail(($exception->gameAccount ?? $account)->id);
            $this->showOperation($account);
            $this->error('Recovery stopped safely: '.$exception->failure->value.'.');

            return self::FAILURE;
        }

        $this->showOperation($account);
        $this->info('Game account creation operation is active.');

        return self::SUCCESS;
    }

    private function diagnose(): int
    {
        $limit = min(max((int) $this->option('limit'), 1), 500);
        $olderThan = min(max((int) $this->option('older-than'), 30), 86400);

        $operations = UserGameAccount::query()
            ->with(['user', 'loginServer', 'registrationGameServer'])
            ->where('creation_status', UserGameAccount::STATUS_PENDING)
            ->where('updated_at', '<=', now()->subSeconds($olderThan))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($operations->isEmpty()) {
            $this->info('No stale pending game account creation operations were found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Operation UUID', 'Login', 'User', 'LoginServer', 'Attempts', 'Updated', 'Last error'],
            $operations->map(static fn (UserGameAccount $account): array => [
                $account->creation_uuid ?? '-',
                $account->game_login,
                $account->user->email,
                $account->loginServer->name,
                $account->creation_attempts,
                $account->updated_at?->toDateTimeString() ?? '-',
                $account->creation_last_error ?? '-',
            ])->all(),
        );

        $this->warn('Run the command with an operation UUID to verify it. Add --retry only to retry a confirmed-missing external account.');

        return self::SUCCESS;
    }

    private function showOperation(UserGameAccount $account): void
    {
        $this->table(['Field', 'Value'], [
            ['Operation UUID', $account->creation_uuid ?? '-'],
            ['Status', $account->creation_status],
            ['Login', $account->game_login],
            ['User ID', (string) $account->user_id],
            ['LoginServer ID', (string) $account->login_server_id],
            ['GameServer ID', $account->registration_game_server_id !== null ? (string) $account->registration_game_server_id : '-'],
            ['Attempts', (string) $account->creation_attempts],
            ['Last error', $account->creation_last_error ?? '-'],
            ['Last checked', $account->creation_last_checked_at?->toDateTimeString() ?? '-'],
        ]);
    }
}
