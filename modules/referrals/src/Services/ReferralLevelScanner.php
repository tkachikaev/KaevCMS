<?php

namespace KaevCMS\Modules\Referrals\Services;

use App\Contracts\GameAccountGateway;
use App\Models\GameServer;
use App\Models\ModuleState;
use App\Models\UserGameAccount;
use App\Services\GameAccounts\AccountCharacterDirectory;
use App\Support\Modules\ModuleManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use KaevCMS\Modules\Referrals\Models\ReferralEntitlement;
use KaevCMS\Modules\Referrals\Models\ReferralInvite;
use KaevCMS\Modules\Referrals\Models\ReferralProgress;
use KaevCMS\Modules\Referrals\Models\ReferralRule;
use KaevCMS\Modules\Referrals\Models\ReferralServerSetting;
use KaevCMS\Modules\Referrals\Models\ReferralSetting;
use KaevCMS\Modules\Referrals\Models\ReferralSnapshot;
use Throwable;

/**
 * Observe game levels through the supported gateway only. Never grants inventory.
 * External queries occur outside CMS transactions; the DB guards all writes.
 */
final class ReferralLevelScanner
{
    public function __construct(
        private readonly GameAccountGateway $gateway,
        private readonly AccountCharacterDirectory $characters,
        private readonly ModuleManager $modules,
    ) {}

    public function enabled(): bool
    {
        return $this->modules->inspect('referrals')['status'] === 'enabled'
            && ModuleState::query()->whereKey('referrals')->where('enabled', true)->exists()
            && ReferralSetting::query()->where('setting_key', 'global')->where('enabled', true)->exists();
    }

    /** @return array{due:int,discovery:int} */
    public function scanBatch(int $limit = 30): array
    {
        $limit = max(1, min(200, $limit));
        $counts = ['due' => 0, 'discovery' => 0];
        if (! $this->enabled()) {
            return $counts;
        }

        $servers = ReferralServerSetting::query()->where('enabled', true)
            ->where('checks_paused', false)
            ->where(static fn ($q) => $q->where('inviter_rewards_enabled', true)->orWhere('invitee_rewards_enabled', true))
            ->with('gameServer.loginServer')->orderBy('game_server_id')->get();
        if ($servers->isEmpty()) {
            return $counts;
        }

        // Reserve half the batch for new GameServers, even if all existing progress is due.
        // Alternating turns prevent starvation when a host explicitly sets batch=1.
        $turn = (int) Cache::get('referrals:single-batch-turn', 0);
        if ($limit === 1) {
            Cache::put('referrals:single-batch-turn', $turn + 1, now()->addDay());
        }
        $dueBudget = $limit === 1 ? $turn % 2 : intdiv($limit, 2);
        $due = ReferralProgress::query()->whereIn('game_server_id', $servers->pluck('game_server_id'))
            ->whereIn('status', ['pending', 'unknown'])
            ->where('next_check_at', '<=', now())
            ->whereHas('invite', static fn ($q) => $q->where('status', 'active'))
            ->whereHas('gameServer')
            ->orderBy('next_check_at')->orderBy('id')->limit($dueBudget)->get();
        foreach ($due as $progress) {
            $server = $servers->firstWhere('game_server_id', $progress->game_server_id)?->gameServer;
            $invite = $progress->invite;
            if ($server !== null && $invite !== null) {
                $this->scan($invite, $server);
                $counts['due']++;
            }
        }

        // Discover first participation separately from milestones. This also finds servers
        // added long after registration, even when no entitlements exist on other servers.
        $remaining = $limit - $counts['due'];
        $active = $servers->filter(static fn ($setting) => $setting->gameServer !== null)->values();
        $start = (int) Cache::get('referrals:discovery-server-cursor', 0);
        Cache::put('referrals:discovery-server-cursor', $start + 1, now()->addDay());
        for ($i = 0; $i < $active->count() && $remaining > 0; $i++) {
            $serverSetting = $active[($start + $i) % $active->count()];
            $server = $serverSetting->gameServer;
            if (! $this->hasActiveRules($serverSetting)) {
                continue;
            }
            $slots = max(1, (int) ceil($remaining / ($active->count() - $i)));
            $invites = ReferralInvite::query()->select('module_referral_invites.*')
                ->leftJoin('module_referral_progress as p', static function ($join) use ($server): void {
                    $join->on('p.invite_id', '=', 'module_referral_invites.id')
                        ->where('p.game_server_id', '=', $server->id);
                })
                ->where('module_referral_invites.status', 'active')
                ->whereNotExists(static function ($query) use ($server): void {
                    $query->selectRaw('1')->from('module_referral_snapshots as s')
                        ->whereColumn('s.invite_id', 'module_referral_invites.id')
                        ->where('s.game_server_id', $server->id);
                })
                ->where(static fn ($query) => $query->whereNull('p.id')->orWhere('p.next_check_at', '<=', now()))
                ->orderByRaw('COALESCE(p.next_check_at, module_referral_invites.accepted_at) ASC')
                ->orderBy('module_referral_invites.id')->limit($slots)->get();
            foreach ($invites as $invite) {
                $this->scan($invite, $server);
                $counts['discovery']++;
                $remaining--;
            }
        }

        return $counts;
    }

    /**
     * @return string updated|waiting|unknown|completed|disabled|busy|not_due
     */
    public function scan(ReferralInvite $invite, GameServer $server, bool $force = false): string
    {
        if (! $this->enabled() || ! $this->participating($invite, $server)) {
            return 'disabled';
        }

        $lock = Cache::lock('referrals:scan:'.$invite->id.':'.$server->id, 120);
        if (! $lock->get()) {
            return 'busy';
        }

        try {
            $progress = ReferralProgress::query()->where('invite_id', $invite->id)
                ->where('game_server_id', $server->id)->first();
            if ($progress?->status === 'completed') {
                return 'completed';
            }
            if (! $force && $progress?->next_check_at?->isFuture()) {
                return 'not_due';
            }
            if (! ReferralSnapshot::query()->where('invite_id', $invite->id)
                ->where('game_server_id', $server->id)->exists()
                && ! $this->hasActiveRules(ReferralServerSetting::query()
                    ->where('game_server_id', $server->id)->firstOrFail())) {
                return 'waiting';
            }

            if (! $server->connectionConfigured()) {
                return $this->failure($invite, $server, 'unsupported_or_unconfigured');
            }
            try {
                $supported = $this->gateway->supportsGameServer($server);
            } catch (Throwable $exception) {
                Log::warning('Referral GameServer gateway capability check failed.', [
                    'game_server_id' => $server->id,
                    'exception_class' => $exception::class,
                ]);

                return $this->failure($invite, $server, 'unsupported_or_unconfigured');
            }
            if (! $supported) {
                return $this->failure($invite, $server, 'unsupported_or_unconfigured');
            }

            // Only newly provisioned CMS accounts, completed after the referral was
            // accepted, and attached to this server's LoginServer. Other user data
            // and imported/pre-existing account records are never queried.
            $accountRows = $invite->invitee->gameAccounts()
                ->where('login_server_id', $server->login_server_id)
                ->where('created_via_cms', true)
                ->where('creation_status', UserGameAccount::STATUS_ACTIVE)
                ->whereNotNull('registration_game_server_id')
                ->whereHas('registrationGameServer', static fn ($query) => $query->where('login_server_id', $server->login_server_id))
                ->whereNotNull('creation_completed_at')
                ->where('created_at', '>=', $invite->accepted_at)
                ->where('creation_completed_at', '>=', $invite->accepted_at)
                ->orderBy('id')->limit(101)->get();
            $incomplete = $accountRows->count() > 100;
            $accounts = $accountRows->take(100);
            $found = false;
            $max = 0;
            foreach ($accounts as $account) {
                $rows = $this->characters->characterRows($server, $account);
                if ($rows === null) {
                    $incomplete = true;
                    continue;
                }
                foreach ($rows as $row) {
                    $level = $row['level'] ?? null;
                    if (! is_int($level) || $level < 1) {
                        $incomplete = true;
                        continue;
                    }
                    $found = true;
                    $max = max($max, min(65535, $level));
                }
            }

            if ($incomplete && ! $found) {
                return $this->failure($invite, $server, 'game_server_unavailable');
            }

            return $this->persist($invite, $server, $found, $max, $incomplete);
        } finally {
            $lock->release();
        }
    }

    private function hasActiveRules(ReferralServerSetting $setting): bool
    {
        $audiences = [];
        if ($setting->inviter_rewards_enabled) {
            $audiences[] = 'inviter';
        }
        if ($setting->invitee_rewards_enabled) {
            $audiences[] = 'invitee';
        }

        return $audiences !== [] && ReferralRule::query()
            ->where('game_server_id', $setting->game_server_id)
            ->where('enabled', true)->whereIn('audience', $audiences)->exists();
    }

    private function participating(ReferralInvite $invite, GameServer $server): bool
    {
        if ($invite->status !== 'active' || ! $invite->invitee?->is_active) {
            return false;
        }
        $setting = ReferralServerSetting::query()->where('game_server_id', $server->id)->first();

        return $setting !== null && $setting->enabled && ! $setting->checks_paused
            && ($setting->inviter_rewards_enabled || $setting->invitee_rewards_enabled);
    }

    /** Serialize with administrative disable/pause changes before recording progress. */
    private function activeForWrite(): bool
    {
        $state = ModuleState::query()->whereKey('referrals')->lockForUpdate()->first();
        $global = ReferralSetting::query()->where('setting_key', 'global')->lockForUpdate()->first();

        return $state?->enabled === true && $global?->enabled === true;
    }

    private function failure(ReferralInvite $invite, GameServer $server, string $code): string
    {
        DB::transaction(function () use ($invite, $server, $code): void {
            GameServer::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            $locked = ReferralInvite::query()->whereKey($invite->id)->lockForUpdate()->firstOrFail();
            if (! $this->activeForWrite() || ! $this->participating($locked, $server)) {
                return;
            }
            $progress = ReferralProgress::query()->firstOrNew([
                'invite_id' => $invite->id, 'game_server_id' => $server->id,
            ]);
            if ($progress->status === 'completed') {
                return;
            }
            $failures = min(65535, (int) $progress->consecutive_failures + 1);
            $progress->fill([
                'status' => 'unknown',
                'consecutive_failures' => $failures,
                'last_error_code' => $code,
                'last_checked_at' => now(),
                'next_check_at' => now()->addSeconds(min(3600, 60 * (2 ** min(6, $failures)))),
            ])->save();
        }, 3);

        return 'unknown';
    }

    private function persist(ReferralInvite $invite, GameServer $server, bool $found, int $max, bool $incomplete): string
    {
        return DB::transaction(function () use ($invite, $server, $found, $max, $incomplete): string {
            // Same lock ordering as the existing admin rules editor (GameServer first).
            GameServer::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            $locked = ReferralInvite::query()->whereKey($invite->id)->lockForUpdate()->firstOrFail();
            if (! $this->activeForWrite() || ! $this->participating($locked, $server)) {
                return 'disabled';
            }
            $progress = ReferralProgress::query()->firstOrNew([
                'invite_id' => $invite->id, 'game_server_id' => $server->id,
            ]);
            $previousLevel = (int) $progress->max_seen_level;
            $highest = max($previousLevel, $max);
            $snapshot = ReferralSnapshot::query()->where('invite_id', $invite->id)
                ->where('game_server_id', $server->id)->first();
            if ($found && $snapshot === null) {
                $setting = ReferralServerSetting::query()->where('game_server_id', $server->id)->firstOrFail();
                $rules = ReferralRule::query()->where('game_server_id', $server->id)->where('enabled', true)
                    ->where(static function ($query) use ($setting): void {
                        if ($setting->inviter_rewards_enabled && $setting->invitee_rewards_enabled) {
                            $query->whereIn('audience', ['inviter', 'invitee']);
                        } elseif ($setting->inviter_rewards_enabled) {
                            $query->where('audience', 'inviter');
                        } else {
                            $query->where('audience', 'invitee');
                        }
                    })->orderBy('audience')->orderBy('level')->get();
                if ($rules->isNotEmpty()) {
                    $snapshot = ReferralSnapshot::query()->create([
                        'invite_id' => $invite->id,
                        'game_server_id' => $server->id,
                        'snapshotted_at' => now(),
                    ]);
                    foreach ($rules as $rule) {
                        ReferralEntitlement::query()->create([
                            'snapshot_id' => $snapshot->id,
                            'invite_id' => $invite->id,
                            'game_server_id' => $server->id,
                            'recipient_user_id' => $rule->audience === 'inviter' ? $locked->inviter_user_id : $locked->invitee_user_id,
                            'audience' => $rule->audience,
                            'level' => $rule->level,
                            'item_id' => $rule->item_id,
                            'amount' => $rule->amount,
                            'status' => 'pending',
                        ]);
                    }
                }
            }

            if ($snapshot !== null && $highest > 0) {
                ReferralEntitlement::query()->where('snapshot_id', $snapshot->id)
                    ->where('status', 'pending')->where('level', '<=', $highest)
                    ->update(['status' => 'earned', 'earned_at' => now(), 'updated_at' => now()]);
            }
            $completed = $snapshot !== null && ! ReferralEntitlement::query()
                ->where('snapshot_id', $snapshot->id)->where('status', 'pending')->exists();
            $priorSeconds = $progress->last_checked_at !== null && $progress->next_check_at !== null
                ? max(150, $progress->last_checked_at->diffInSeconds($progress->next_check_at)) : 150;
            $delay = $highest > $previousLevel ? 150 : min(3600, max(300, (int) $priorSeconds * 2));
            $progress->fill([
                'max_seen_level' => $highest,
                'status' => $completed ? 'completed' : ($incomplete ? 'unknown' : 'pending'),
                'next_check_at' => $completed ? null : now()->addSeconds($incomplete ? 300 : $delay),
                'consecutive_failures' => $incomplete ? min(65535, (int) $progress->consecutive_failures + 1) : 0,
                'last_error_code' => $incomplete ? 'partial_game_server_read' : null,
                'last_checked_at' => now(),
                'last_success_at' => now(),
            ])->save();

            return $completed ? 'completed' : ($found ? 'updated' : 'waiting');
        }, 3);
    }
}
