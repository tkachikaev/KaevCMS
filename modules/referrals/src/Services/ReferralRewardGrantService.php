<?php

namespace KaevCMS\Modules\Referrals\Services;

use App\Models\GameServer;
use App\Models\ModuleState;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\RegistrationSettings;
use App\Services\Rewards\RewardInventoryService;
use App\Support\Modules\ModuleManager;
use App\Support\Rewards\RewardGrantItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use KaevCMS\Modules\Referrals\Models\ReferralEntitlement;
use KaevCMS\Modules\Referrals\Models\ReferralInvite;
use KaevCMS\Modules\Referrals\Models\ReferralProgress;
use KaevCMS\Modules\Referrals\Models\ReferralServerSetting;
use KaevCMS\Modules\Referrals\Models\ReferralSetting;
use KaevCMS\Modules\Referrals\Models\ReferralSnapshot;
use RuntimeException;
use Throwable;

/**
 * Deliver already-earned milestones into Web Inventory, never the game database.
 * The immutable entitlement ID is the idempotency key. All writes use one CMS
 * transaction; retries can reconcile an existing grant with an unlinked row.
 */
final class ReferralRewardGrantService
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly RewardInventoryService $inventory,
        private readonly RegistrationSettings $registration,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{granted:int,deferred:int,failed:int,inspected:int} */
    public function grantBatch(int $limit = 30): array
    {
        $limit = max(1, min(200, $limit));
        $result = ['granted' => 0, 'deferred' => 0, 'failed' => 0, 'inspected' => 0];
        if (! $this->enabled()) {
            return $result;
        }

        // A cursor avoids starvation when many old earned rows are frozen,
        // awaiting email verification or over the optional inviter limit.
        // Locking only optimizes overlapping scans: DB row locks and grant keys
        // are the actual correctness boundary for concurrent workers.
        $lock = Cache::lock('referrals:grants-batch', 120);
        if (! $lock->get()) {
            return $result;
        }

        try {
            $cursor = max(0, (int) Cache::get('referrals:grants-cursor', 0));
            $pageSize = $limit * 20;
            $ids = ReferralEntitlement::query()->where('status', 'earned')->where('id', '>', $cursor)
                ->orderBy('id')->limit($pageSize)->pluck('id');
            if ($ids->count() < $pageSize && $cursor > 0) {
                $ids = $ids->concat(ReferralEntitlement::query()->where('status', 'earned')
                    ->where('id', '<=', $cursor)->orderBy('id')->limit($pageSize - $ids->count())->pluck('id'));
            }
            foreach ($ids as $id) {
                if ($result['granted'] >= $limit) {
                    break;
                }
                Cache::put('referrals:grants-cursor', (int) $id, now()->addDays(7));
                $this->attempt((int) $id, $result);
            }
        } finally {
            $lock->release();
        }

        return $result;
    }

    /**
     * Called only from the authorized, throttled player check after scanning.
     * It can never accept an item, recipient, level or amount from a browser.
     *
     * @return array{granted:int,deferred:int,failed:int,inspected:int}
     */
    public function grantForPair(ReferralInvite $invite, GameServer $server): array
    {
        $result = ['granted' => 0, 'deferred' => 0, 'failed' => 0, 'inspected' => 0];
        if (! $this->enabled()) {
            return $result;
        }

        $ids = ReferralEntitlement::query()->where('invite_id', $invite->id)
            ->where('game_server_id', $server->id)->where('status', 'earned')
            ->orderBy('id')->limit(60)->pluck('id');
        foreach ($ids as $id) {
            $this->attempt((int) $id, $result);
        }

        return $result;
    }

    /** @return 'granted'|'already_granted'|'deferred' */
    public function grantEntitlement(int $entitlementId): string
    {
        if (! $this->enabled()) {
            return 'deferred';
        }

        // No network/GameServer calls are made while holding CMS DB locks.
        return DB::transaction(function () use ($entitlementId): string {
            $initial = ReferralEntitlement::query()->find($entitlementId);
            if ($initial === null) {
                return 'deferred';
            }
            if ($initial->status === 'granted') {
                return 'already_granted';
            }
            if ($initial->status !== 'earned') {
                return 'deferred';
            }

            // Keep the same order as the referral scanner and admin rule editor:
            // GameServer -> invitation -> module/global state -> server settings.
            $server = GameServer::query()->whereKey($initial->game_server_id)->lockForUpdate()->first();
            $invite = ReferralInvite::query()->whereKey($initial->invite_id)->lockForUpdate()->first();
            if ($server === null || $invite?->status !== 'active') {
                return 'deferred';
            }
            $module = ModuleState::query()->whereKey('referrals')->lockForUpdate()->first();
            $global = ReferralSetting::query()->where('setting_key', 'global')->lockForUpdate()->first();
            if ($module?->enabled !== true || $global?->enabled !== true) {
                return 'deferred';
            }
            $setting = ReferralServerSetting::query()->where('game_server_id', $server->id)
                ->lockForUpdate()->first();
            if ($setting === null || ! $setting->enabled || $setting->checks_paused) {
                return 'deferred';
            }

            $entitlement = ReferralEntitlement::query()->whereKey($entitlementId)->lockForUpdate()->firstOrFail();
            if ($entitlement->status === 'granted') {
                return 'already_granted';
            }
            if ($entitlement->status !== 'earned' || $entitlement->earned_at === null
                || (int) $entitlement->game_server_id !== (int) $server->id
                || (int) $entitlement->invite_id !== (int) $invite->id
                || ! in_array($entitlement->audience, ['inviter', 'invitee'], true)
                || ! ReferralSnapshot::query()->whereKey($entitlement->snapshot_id)
                    ->where('invite_id', $invite->id)->where('game_server_id', $server->id)->exists()
                || ! ReferralProgress::query()->where('invite_id', $invite->id)
                    ->where('game_server_id', $server->id)
                    ->where('max_seen_level', '>=', $entitlement->level)->exists()) {
                return 'deferred';
            }

            $sideEnabled = $entitlement->audience === 'inviter'
                ? $setting->inviter_rewards_enabled : $setting->invitee_rewards_enabled;
            if (! $sideEnabled) {
                return 'deferred';
            }

            // Serialize optional invitee caps across two invitations to the same
            // inviter. The inventory service itself locks the recipient user.
            $inviter = User::query()->whereKey($invite->inviter_user_id)->lockForUpdate()->first();
            $invitee = User::query()->whereKey($invite->invitee_user_id)->lockForUpdate()->first();
            $recipientId = $entitlement->audience === 'inviter'
                ? $invite->inviter_user_id : $invite->invitee_user_id;
            $recipient = (int) $recipientId === (int) $inviter?->id
                ? $inviter : $invitee;
            if ($invitee === null || ! $invitee->is_active || $recipient === null
                || ! $recipient->is_active || (int) $entitlement->recipient_user_id !== (int) $recipient->id) {
                return 'deferred';
            }
            if ($this->registration->emailVerificationRequired()
                && (! $invitee->hasVerifiedEmail() || ! $recipient->hasVerifiedEmail())) {
                return 'deferred';
            }
            if (! $this->withinLimit($invite, $server, $setting)) {
                return 'deferred';
            }

            $grant = $this->inventory->grant(
                user: $recipient,
                server: $server,
                grantKey: 'referrals.entitlement.'.$entitlement->id,
                sourceType: 'referral',
                items: [new RewardGrantItem((int) $entitlement->item_id, (int) $entitlement->amount)],
                sourceReference: (string) $entitlement->id,
                sourceLabel: __('module-referrals::messages.reward_source_label', ['level' => $entitlement->level], $recipient->locale),
                metadata: [
                    'module' => 'referrals',
                    'invite_id' => $invite->id,
                    'entitlement_id' => $entitlement->id,
                    'audience' => $entitlement->audience,
                    'level' => $entitlement->level,
                ],
                actor: 'Referrals',
            );
            $entitlement->update([
                'status' => 'granted',
                'reward_inventory_grant_id' => $grant->id,
                'granted_at' => $grant->granted_at ?? now(),
            ]);
            if ($this->audit->system('module', 'referrals.entitlement_granted', $entitlement, [
                'entitlement_id' => $entitlement->id,
                'grant_id' => $grant->id,
                'game_server_id' => $server->id,
                'recipient_user_id' => $recipient->id,
            ]) === null) {
                throw new RuntimeException('Referral reward audit could not be recorded.');
            }

            return 'granted';
        }, 3);
    }

    private function enabled(): bool
    {
        return $this->modules->inspect('referrals')['status'] === 'enabled'
            && ModuleState::query()->whereKey('referrals')->where('enabled', true)->exists()
            && ReferralSetting::query()->where('setting_key', 'global')->where('enabled', true)->exists();
    }

    private function withinLimit(ReferralInvite $invite, GameServer $server, ReferralServerSetting $setting): bool
    {
        $limit = $setting->max_rewarded_invitees;
        if ($limit === null) {
            return true;
        }

        // Already rewarded invitations retain their remaining immutable promises
        // even if an administrator later lowers the optional limit.
        $rewarded = ReferralEntitlement::query()
            ->join('module_referral_invites as ri', 'ri.id', '=', 'module_referral_entitlements.invite_id')
            ->where('ri.inviter_user_id', $invite->inviter_user_id)
            ->where('module_referral_entitlements.game_server_id', $server->id)
            ->where('module_referral_entitlements.status', 'granted');
        if ((clone $rewarded)->where('module_referral_entitlements.invite_id', $invite->id)->exists()) {
            return true;
        }
        $alreadyRewarded = (clone $rewarded)->distinct()->count('module_referral_entitlements.invite_id');
        $freeSlots = (int) $limit - $alreadyRewarded;
        if ($freeSlots <= 0) {
            return false;
        }

        // Select oldest first-participation snapshots with earned milestones,
        // excluding any invitations already counted as rewarded. Locking the
        // inviter user above serializes this decision with competing grants.
        $nextIds = ReferralSnapshot::query()->select('module_referral_snapshots.invite_id')
            ->join('module_referral_invites as ri', 'ri.id', '=', 'module_referral_snapshots.invite_id')
            ->where('ri.inviter_user_id', $invite->inviter_user_id)
            // Frozen invitations are not candidates for a new reward slot.
            // Otherwise a frozen older invite can permanently starve an active
            // invite when the administrator sets an optional inviter limit.
            ->where('ri.status', 'active')
            ->where('module_referral_snapshots.game_server_id', $server->id)
            ->whereExists(static function ($query): void {
                $query->selectRaw('1')->from('module_referral_entitlements as e')
                    ->whereColumn('e.snapshot_id', 'module_referral_snapshots.id')
                    ->where('e.status', 'earned');
            })
            ->whereNotExists(static function ($query): void {
                $query->selectRaw('1')->from('module_referral_entitlements as rewarded')
                    ->whereColumn('rewarded.snapshot_id', 'module_referral_snapshots.id')
                    ->where('rewarded.status', 'granted');
            })
            ->orderBy('module_referral_snapshots.snapshotted_at')
            ->orderBy('module_referral_snapshots.id')
            ->limit($freeSlots)->pluck('module_referral_snapshots.invite_id');

        return $nextIds->contains(static fn ($id): bool => (int) $id === (int) $invite->id);
    }

    /** @param array{granted:int,deferred:int,failed:int,inspected:int} $result */
    private function attempt(int $id, array &$result): void
    {
        $result['inspected']++;
        try {
            if ($this->grantEntitlement($id) === 'granted') {
                $result['granted']++;
            } else {
                $result['deferred']++;
            }
        } catch (Throwable $exception) {
            $result['failed']++;
            // Never log item data, emails, user credentials or raw SQL errors.
            Log::warning('Referral inventory grant deferred after error.', [
                'entitlement_id' => $id,
                'exception_class' => $exception::class,
            ]);
        }
    }
}
