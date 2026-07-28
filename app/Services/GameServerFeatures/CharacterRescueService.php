<?php

namespace App\Services\GameServerFeatures;

use App\Contracts\CharacterRescueGateway;
use App\Models\CharacterRescueOperation;
use App\Models\GameServer;
use App\Models\User;
use App\Models\UserGameAccount;
use App\Services\AuditLogger;
use App\Services\GameAccounts\AccountCharacterDirectory;
use App\Support\GameServerFeatures\CharacterRescueOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

final class CharacterRescueService
{
    public function __construct(
        private readonly GameServerFeatureSettings $features,
        private readonly CharacterRescueGateway $gateway,
        private readonly AccountCharacterDirectory $directory,
        private readonly AuditLogger $audit,
    ) {}

    public function rescue(
        User $user,
        GameServer $server,
        UserGameAccount $account,
        int $characterId,
    ): CharacterRescueOutcome {
        if (
            (int) $account->user_id !== (int) $user->id
            || $account->isActive() === false
            || (int) $account->login_server_id !== (int) $server->login_server_id
        ) {
            return new CharacterRescueOutcome(false, 'denied');
        }

        $settings = $this->features->characterRescue($server);
        if ($settings['enabled'] === false) {
            return new CharacterRescueOutcome(false, 'disabled');
        }

        if ($this->gateway->supports($server) === false) {
            return new CharacterRescueOutcome(false, 'unsupported');
        }

        $lockKey = implode(':', ['character-rescue', $server->id, $account->id, $characterId]);
        $outcome = Cache::lock($lockKey, 15)->get(function () use (
            $user,
            $server,
            $account,
            $characterId,
            $settings,
        ): CharacterRescueOutcome {
            $cooldown = $this->cooldownOutcome($user, $server, $characterId, $settings['cooldown_hours']);
            if ($cooldown instanceof CharacterRescueOutcome) {
                return $cooldown;
            }

            $operation = CharacterRescueOperation::query()->create([
                'operation_uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'game_server_id' => $server->id,
                'user_game_account_id' => $account->id,
                'character_id' => $characterId,
                'character_name' => 'Character #'.$characterId,
                'account_login' => $account->game_login,
                'location_name' => $settings['location_name'],
                'target_x' => $settings['x'],
                'target_y' => $settings['y'],
                'target_z' => $settings['z'],
                'status' => CharacterRescueOperation::STATUS_PENDING,
                'requested_at' => now(),
            ]);

            try {
                $write = $this->gateway->rescue(
                    $server,
                    $account->game_login,
                    $characterId,
                    ['x' => $settings['x'], 'y' => $settings['y'], 'z' => $settings['z']],
                    CarbonImmutable::now()->subMinutes($settings['offline_delay_minutes']),
                );
            } catch (Throwable $exception) {
                $this->finishFailed($operation, 'database_unavailable');
                $this->auditFailure($user, $server, $characterId, 'database_unavailable', $exception);

                return new CharacterRescueOutcome(false, 'database_unavailable');
            }

            if ($write->successful() === false) {
                $this->finishFailed($operation, $write->status, $write->characterName);
                $this->audit->failed(
                    category: 'character',
                    action: 'character.rescue',
                    actor: $user,
                    target: $server,
                    details: [
                        'operation_uuid' => $operation->operation_uuid,
                        'character_id' => $characterId,
                        'game_account_id' => $account->id,
                        'failure_code' => $write->status,
                    ],
                );

                return new CharacterRescueOutcome(
                    false,
                    $write->status,
                    characterName: $write->characterName,
                    locationName: $settings['location_name'],
                );
            }

            $operation->forceFill([
                'character_name' => $write->characterName ?? $operation->character_name,
                'old_x' => $write->oldX,
                'old_y' => $write->oldY,
                'old_z' => $write->oldZ,
                'status' => CharacterRescueOperation::STATUS_SUCCESS,
                'failure_code' => null,
                'completed_at' => now(),
            ])->save();

            $this->directory->forget($server, $account);
            $this->audit->success(
                category: 'character',
                action: 'character.rescue',
                actor: $user,
                target: $server,
                details: [
                    'operation_uuid' => $operation->operation_uuid,
                    'character_id' => $characterId,
                    'game_account_id' => $account->id,
                    'location_name' => $settings['location_name'],
                    'old_coordinates' => [$write->oldX, $write->oldY, $write->oldZ],
                    'target_coordinates' => [$settings['x'], $settings['y'], $settings['z']],
                ],
            );

            return new CharacterRescueOutcome(
                true,
                'success',
                characterName: $write->characterName,
                locationName: $settings['location_name'],
            );
        });

        return $outcome instanceof CharacterRescueOutcome
            ? $outcome
            : new CharacterRescueOutcome(false, 'busy');
    }

    private function cooldownOutcome(
        User $user,
        GameServer $server,
        int $characterId,
        int $cooldownHours,
    ): ?CharacterRescueOutcome {
        if ($cooldownHours <= 0) {
            return null;
        }

        $last = CharacterRescueOperation::query()
            ->where('user_id', $user->id)
            ->where('game_server_id', $server->id)
            ->where('character_id', $characterId)
            ->where('status', CharacterRescueOperation::STATUS_SUCCESS)
            ->latest('completed_at')
            ->first();

        if ($last?->completed_at === null) {
            return null;
        }

        $retryAt = CarbonImmutable::instance($last->completed_at)->addHours($cooldownHours);
        if ($retryAt->isPast()) {
            return null;
        }

        return new CharacterRescueOutcome(false, 'cooldown', retryAt: $retryAt);
    }

    private function finishFailed(
        CharacterRescueOperation $operation,
        string $failureCode,
        ?string $characterName = null,
    ): void {
        $operation->forceFill([
            'character_name' => $characterName ?? $operation->character_name,
            'status' => CharacterRescueOperation::STATUS_FAILED,
            'failure_code' => $failureCode,
            'completed_at' => now(),
        ])->save();
    }

    private function auditFailure(
        User $user,
        GameServer $server,
        int $characterId,
        string $failureCode,
        Throwable $exception,
    ): void {
        $this->audit->failed(
            category: 'character',
            action: 'character.rescue',
            actor: $user,
            target: $server,
            details: [
                'character_id' => $characterId,
                'failure_code' => $failureCode,
                'exception_class' => $exception::class,
            ],
        );
    }
}
