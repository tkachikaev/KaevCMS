<?php

namespace KaevCMS\Modules\Voting\Services;

use App\Models\GameServer;
use App\Models\ModuleState;
use App\Models\User;
use App\Services\RegistrationSettings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use KaevCMS\Modules\Voting\Data\VoteInvitation;
use KaevCMS\Modules\Voting\Models\VotingBinding;
use KaevCMS\Modules\Voting\Models\VotingServerReward;
use KaevCMS\Modules\Voting\Models\VotingSetting;

final class VotingInvitationService
{
    public function __construct(
        private readonly VotingProviderRegistry $providers,
        private readonly VotingAvailability $availability,
        private readonly RegistrationSettings $registration,
    ) {}

    public function invite(User $user, int $accountId, int $serverId): VoteInvitation
    {
        // Persist the UUID and recipient before HTTP. Never hold database locks during a network call.
        $prepared = DB::transaction(function () use ($user, $accountId, $serverId): array|VoteInvitation {
            abort_unless(ModuleState::query()->whereKey('voting')->lockForUpdate()->first()?->enabled === true, 404);
            $setting = VotingSetting::query()->where('provider_code', 'ktreon')->lockForUpdate()->firstOrFail();
            abort_unless($this->availability->enabled($setting), 404);
            $owner = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($owner->is_active && (! $this->registration->emailVerificationRequired() || $owner->hasVerifiedEmail()), 403);
            $server = GameServer::query()->whereKey($serverId)->lockForUpdate()->firstOrFail();
            $account = $owner->availableGameAccounts()->whereKey($accountId)->lockForUpdate()->first();
            abort_unless($account !== null && $server->login_server_id !== null && $account->login_server_id === $server->login_server_id, 404);
            $reward = VotingServerReward::query()->where('game_server_id', $serverId)->lockForUpdate()->first();
            abort_unless($reward !== null && $reward->configuredItems() !== [], 404);
            if ($setting->intent_retry_at?->isFuture()) {
                return new VoteInvitation('rate_limited');
            }

            $project = $setting->project_id;
            $ciphertext = $setting->api_key_ciphertext;
            if ($project === null || $ciphertext === null) {
                return new VoteInvitation('not_configured');
            }
            try {
                $key = Crypt::decryptString($ciphertext);
            } catch (DecryptException) {
                $setting->forceFill([
                    'player_access_enabled' => false,
                    'last_check_result' => 'not_configured',
                    'last_checked_at' => now(),
                ])->save();

                return new VoteInvitation('not_configured');
            }

            $identity = [
                'project_id' => $project,
                'user_id' => $owner->id,
                'user_game_account_id' => $account->id,
                'game_server_id' => $server->id,
            ];
            $binding = VotingBinding::query()->where($identity)->lockForUpdate()->first();
            if ($binding === null) {
                $binding = new VotingBinding;
                $binding->forceFill($identity + ['player_ref' => bin2hex(random_bytes(32))]);
            }

            if ($binding->request_id === null || ! $binding->attempt_expires_at?->isFuture()
                || $binding->credential_revision !== $setting->credential_revision) {
                $binding->forceFill([
                    'request_id' => (string) Str::uuid(),
                    'intent_id' => null,
                    'credential_revision' => $setting->credential_revision,
                    // Until we have a response, keep the same UUID across uncertain outcomes.
                    'attempt_expires_at' => now()->addMinutes(11),
                ])->save();
            }

            return [
                'binding_id' => $binding->id,
                'project' => $project,
                'key' => $key,
                'request' => (string) $binding->request_id,
                'player_ref' => $binding->player_ref,
                'revision' => $setting->credential_revision,
            ];
        }, 3);

        if ($prepared instanceof VoteInvitation) {
            return $prepared;
        }

        $result = $this->providers->get('ktreon')->invite($prepared['project'], $prepared['key'], $prepared['request'], $prepared['player_ref']);
        unset($prepared['key']);

        return DB::transaction(function () use ($prepared, $result, $user, $accountId, $serverId): VoteInvitation {
            if (ModuleState::query()->whereKey('voting')->lockForUpdate()->first()?->enabled !== true) {
                return new VoteInvitation('changed');
            }
            $setting = VotingSetting::query()->where('provider_code', 'ktreon')->lockForUpdate()->firstOrFail();
            $binding = VotingBinding::query()->whereKey($prepared['binding_id'])->lockForUpdate()->firstOrFail();
            if ($setting->project_id !== $prepared['project'] || $setting->credential_revision !== $prepared['revision']
                || $binding->request_id !== $prepared['request']) {
                return new VoteInvitation('changed');
            }
            if ($result->result === 'rate_limited') {
                $until = now()->addSeconds($result->retryAfter);
                if ($setting->intent_retry_at === null || $setting->intent_retry_at->lessThan($until)) {
                    $setting->forceFill(['intent_retry_at' => $until])->save();
                }
            }
            if (in_array($result->result, ['unauthorized', 'not_configured'], true)) {
                // A definitively rejected key must be checked again before reopening invitations.
                $setting->forceFill([
                    'player_access_enabled' => false,
                    'last_check_result' => $result->result,
                    'last_checked_at' => now(),
                ])->save();
            }
            if ($result->result === 'expired') {
                $binding->forceFill(['attempt_expires_at' => now()])->save();
            }
            if ($result->result !== 'ok') {
                return $result;
            }
            // Recheck the recipient after the remote request. A deleted or moved account
            // cannot be silently replaced and an old credential response cannot be used.
            $owner = User::query()->whereKey($user->id)->lockForUpdate()->first();
            $server = GameServer::query()->whereKey($serverId)->lockForUpdate()->first();
            $account = $owner?->availableGameAccounts()->whereKey($accountId)->lockForUpdate()->first();
            $reward = VotingServerReward::query()->where('game_server_id', $serverId)->lockForUpdate()->first();
            if (! $this->availability->enabled($setting) || $owner === null || ! $owner->is_active
                || ($this->registration->emailVerificationRequired() && ! $owner->hasVerifiedEmail())
                || $server === null || $server->login_server_id === null || $account === null
                || $account->login_server_id !== $server->login_server_id || $binding->user_id !== $owner->id
                || $binding->user_game_account_id !== $account->id || $binding->game_server_id !== $server->id
                || $reward === null || $reward->configuredItems() === []
                || $result->expiresAt === null || ! $result->expiresAt->isFuture()
                || ! $binding->attempt_expires_at?->isFuture()
                || ($binding->intent_id !== null && $binding->intent_id !== $result->intentId)) {
                return new VoteInvitation('changed');
            }
            $binding->forceFill([
                'intent_id' => $result->intentId,
                'attempt_expires_at' => $result->expiresAt->setTimezone(now()->getTimezone()),
            ])->save();

            return $result;
        }, 3);
    }
}
