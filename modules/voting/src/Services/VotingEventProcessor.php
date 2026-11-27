<?php

namespace KaevCMS\Modules\Voting\Services;

use App\Models\GameServer;
use App\Models\RewardInventoryGrant;
use App\Models\RewardInventoryItem;
use App\Models\User;
use App\Models\UserGameAccount;
use App\Services\AuditLogger;
use App\Services\RegistrationSettings;
use App\Services\Rewards\RewardInventoryService;
use App\Support\Rewards\RewardGrantItem;
use Illuminate\Support\Facades\DB;
use KaevCMS\Modules\Voting\Data\VoteEvent;
use KaevCMS\Modules\Voting\Models\VotingBinding;
use KaevCMS\Modules\Voting\Models\VotingEvent;
use KaevCMS\Modules\Voting\Models\VotingServerReward;
use KaevCMS\Modules\Voting\Models\VotingVote;
use RuntimeException;

/** Applies an event within the synchronization transaction holding the settings row lock. */
final class VotingEventProcessor
{
    public function __construct(
        private readonly RewardInventoryService $inventory,
        private readonly RegistrationSettings $registration,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{processed:int,granted:int} */
    public function apply(VoteEvent $event, string $pendingPolicy): array
    {
        if (DB::transactionLevel() < 1 || ! in_array($pendingPolicy, ['wait', 'immediate'], true)) {
            throw new RuntimeException('Voting processing requires a transaction and a valid policy.');
        }

        $fields = [
            'project_id' => $event->projectId,
            'event_id' => $event->eventId,
            'vote_id' => $event->voteId,
            'cursor' => $event->cursor,
            'player_ref' => $event->playerRef,
            'status' => $event->status,
            'status_version' => $event->statusVersion,
            'voted_at' => $event->votedAt,
            'status_changed_at' => $event->statusChangedAt,
        ];
        $matches = VotingEvent::query()->where('project_id', $event->projectId)
            ->where(function ($query) use ($event): void {
                $query->where('event_id', $event->eventId)->orWhere('cursor', $event->cursor)
                    ->orWhere(function ($query) use ($event): void {
                        $query->where('vote_id', $event->voteId)->where('status_version', $event->statusVersion);
                    });
            })->lockForUpdate()->get();
        $vote = VotingVote::query()->where('project_id', $event->projectId)
            ->where('vote_id', $event->voteId)->lockForUpdate()->first();

        if ($matches->isNotEmpty()) {
            $stored = $matches->first();
            if ($matches->count() !== 1 || $stored === null || $stored->only(array_keys($fields)) !== $fields
                || $vote === null || $vote->player_ref !== $event->playerRef || $vote->voted_at !== $event->votedAt
                || $vote->status_version < $event->statusVersion
                || ($vote->status_version === $event->statusVersion
                    && ($vote->status !== $event->status || $vote->status_changed_at !== $event->statusChangedAt))) {
                throw new RuntimeException('Conflicting voting event replay.');
            }

            return ['processed' => 0, 'granted' => 0];
        }

        $granted = 0;
        if ($vote !== null) {
            if ($vote->player_ref !== $event->playerRef || $vote->voted_at !== $event->votedAt) {
                throw new RuntimeException('Voting recipient or vote timestamp changed.');
            }
            if ($event->statusVersion === $vote->status_version
                && ($event->status !== $vote->status || $event->statusChangedAt !== $vote->status_changed_at)) {
                throw new RuntimeException('Conflicting voting status version.');
            }
        }

        if ($vote === null || $event->statusVersion > $vote->status_version) {
            $binding = VotingBinding::query()->where('project_id', $event->projectId)
                ->where('player_ref', $event->playerRef)->lockForUpdate()->first();
            // SQL collations may be case insensitive; never resolve an approximate reference.
            if ($binding !== null && $binding->player_ref !== $event->playerRef) {
                $binding = null;
            }

            $recipient = $this->recipient($binding);
            $grantKey = 'voting:ktreon:'.$event->projectId.':'.$event->voteId;
            $reference = 'ktreon:'.$event->projectId.':'.$event->voteId;
            $existingGrant = RewardInventoryGrant::query()->where('grant_key', $grantKey)
                ->lockForUpdate()->first();

            if ($vote === null) {
                $items = $recipient === null ? [] : (
                    VotingServerReward::query()->where('game_server_id', $recipient['server']->id)
                        ->lockForUpdate()->first()?->configuredItems() ?? []
                );
                $vote = new VotingVote;
                $vote->forceFill([
                    'project_id' => $event->projectId,
                    'vote_id' => $event->voteId,
                    'player_ref' => $event->playerRef,
                    'binding_id' => $binding?->id,
                    'user_id' => $binding?->user_id,
                    'user_game_account_id' => $binding?->user_game_account_id,
                    'game_server_id' => $binding?->game_server_id,
                    'game_account_login' => $recipient === null ? null : $recipient['account']->game_login,
                    'voted_at' => $event->votedAt,
                    'items_snapshot' => $items,
                    'reward_state' => $items === [] ? 'ineligible' : 'waiting',
                    'reward_reason' => $recipient === null ? 'recipient_unavailable' : ($items === [] ? 'reward_unconfigured' : null),
                ]);
            } elseif ($binding?->id !== $vote->binding_id
                || $binding?->user_id !== $vote->user_id
                || $binding?->user_game_account_id !== $vote->user_game_account_id
                || $binding?->game_server_id !== $vote->game_server_id) {
                // A previously unresolved or removed recipient is not eligible for reassignment.
                $recipient = null;
            }

            $vote->forceFill([
                'status' => $event->status,
                'status_version' => $event->statusVersion,
                'status_changed_at' => $event->statusChangedAt,
            ]);

            if ($existingGrant !== null) {
                $this->restoreGrant($vote, $existingGrant, $binding, $reference);
            } elseif ($vote->reward_inventory_grant_id !== null || $vote->reward_state === 'granted') {
                throw new RuntimeException('Voting grant ledger is inconsistent.');
            } elseif ($vote->reward_state !== 'ineligible') {
                if ($recipient === null) {
                    $vote->forceFill(['reward_state' => 'ineligible', 'reward_reason' => 'recipient_unavailable']);
                } elseif ($event->status === 'accepted' || ($event->status === 'pending' && $pendingPolicy === 'immediate')) {
                    $items = $this->grantItems($vote->items_snapshot);
                    $grant = $this->inventory->grant(
                        user: $recipient['user'],
                        server: $recipient['server'],
                        grantKey: $grantKey,
                        sourceType: 'voting',
                        items: $items,
                        sourceReference: $reference,
                        sourceLabel: 'KTreon',
                        metadata: [
                            'binding_id' => $vote->binding_id,
                            'user_game_account_id' => $vote->user_game_account_id,
                            'player_ref_hash' => hash('sha256', $event->playerRef),
                        ],
                        actor: 'system',
                    );
                    $this->restoreGrant($vote, $grant, $binding, $reference);
                    if ($this->audit->system('module', 'voting.reward_granted', $grant, [
                        'project_id' => $event->projectId,
                        'vote_id' => $event->voteId,
                        'status' => $event->status,
                        'grant_id' => $grant->id,
                    ]) === null) {
                        throw new RuntimeException('Voting reward audit could not be recorded.');
                    }
                    $granted = 1;
                } else {
                    $vote->forceFill([
                        'reward_state' => $event->status === 'pending' ? 'waiting' : 'not_granted',
                        'reward_reason' => null,
                    ]);
                }
            }
            $vote->save();
        }

        // Stale status events are retained but must never overwrite a newer vote.
        (new VotingEvent)->forceFill($fields + ['processed_at' => now()])->save();

        return ['processed' => 1, 'granted' => $granted];
    }

    /** @return array{user:User,server:GameServer,account:UserGameAccount}|null */
    private function recipient(?VotingBinding $binding): ?array
    {
        if ($binding === null || $binding->user_id === null || $binding->user_game_account_id === null
            || $binding->game_server_id === null || $binding->request_id === null) {
            return null;
        }
        $user = User::query()->whereKey($binding->user_id)->lockForUpdate()->first();
        if ($user === null || ! $user->is_active || ($this->registration->emailVerificationRequired() && ! $user->hasVerifiedEmail())) {
            return null;
        }
        $server = GameServer::query()->whereKey($binding->game_server_id)->lockForUpdate()->first();
        $account = $user->availableGameAccounts()->whereKey($binding->user_game_account_id)->lockForUpdate()->first();
        if ($server === null || $server->login_server_id === null || $account === null
            || $account->login_server_id !== $server->login_server_id) {
            return null;
        }

        return ['user' => $user, 'server' => $server, 'account' => $account];
    }

    /** @return list<RewardGrantItem> */
    private function grantItems(mixed $snapshot): array
    {
        $items = (new VotingServerReward)->forceFill(['items' => $snapshot])->validatedItems();
        if ($items === []) {
            throw new RuntimeException('Invalid voting reward snapshot.');
        }

        return array_map(static fn (array $item): RewardGrantItem => new RewardGrantItem($item['item_id'], $item['amount']), $items);
    }

    private function restoreGrant(VotingVote $vote, RewardInventoryGrant $grant, ?VotingBinding $binding, string $reference): void
    {
        $metadata = $grant->metadata ?? [];
        // Never attach an existing key to a different recipient, even on partial recovery.
        if ($binding === null || $binding->id !== $vote->binding_id || $grant->source_type !== 'voting' || $grant->source_reference !== $reference
            || $grant->user_id !== $vote->user_id || $grant->game_server_id !== $vote->game_server_id
            || ($metadata['binding_id'] ?? null) !== $vote->binding_id
            || ! is_int($metadata['user_game_account_id'] ?? null) || $metadata['user_game_account_id'] < 1
            || ($vote->user_game_account_id !== null && $metadata['user_game_account_id'] !== $vote->user_game_account_id)
            || ($metadata['player_ref_hash'] ?? null) !== hash('sha256', $vote->player_ref)
            || ($vote->reward_inventory_grant_id !== null && $vote->reward_inventory_grant_id !== $grant->id)) {
            throw new RuntimeException('Voting grant identity conflict.');
        }

        $stored = $grant->items()->lockForUpdate()->get()->map(static function (RewardInventoryItem $item) use ($grant): array {
            if ($item->user_id !== $grant->user_id || $item->game_server_id !== $grant->game_server_id) {
                throw new RuntimeException('Voting grant item recipient conflict.');
            }

            return ['item_id' => $item->item_id, 'amount' => $item->amount];
        })->all();
        $this->grantItems($stored);

        if ($vote->exists) {
            $snapshot = $this->grantItems($vote->items_snapshot);
            $expected = array_map(static fn (RewardGrantItem $item): array => ['item_id' => $item->itemId, 'amount' => $item->amount], $snapshot);
            usort($expected, static fn (array $a, array $b): int => $a['item_id'] <=> $b['item_id']);
            usort($stored, static fn (array $a, array $b): int => $a['item_id'] <=> $b['item_id']);
            if ($expected !== $stored) {
                throw new RuntimeException('Voting grant items conflict.');
            }
        }

        // If only the vote journal was lost, the surviving inventory ledger is authoritative.
        $vote->forceFill([
            'items_snapshot' => $vote->exists ? $vote->items_snapshot : $stored,
            'reward_inventory_grant_id' => $grant->id,
            'granted_at' => $grant->granted_at,
            'reward_state' => 'granted',
            'reward_reason' => null,
        ]);
    }
}
