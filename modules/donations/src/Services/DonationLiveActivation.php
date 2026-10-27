<?php

namespace KaevCMS\Modules\Donations\Services;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Contracts\RoutablePaymentProvider;
use RuntimeException;
use Throwable;

/** Payment UI controls only. Verified callbacks and outstanding credits are never switched off here. */
final class DonationLiveActivation
{
    public function __construct(
        private readonly DonationProviderSettings $providers,
        private readonly DonationProviderRegistry $registry,
        private readonly DonationSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{mode:string,pilot_user_id:int|null} */
    public function state(): array
    {
        try {
            if (! Schema::hasTable('module_donation_live_settings')) {
                return ['mode' => 'off', 'pilot_user_id' => null];
            }
            $row = DB::table('module_donation_live_settings')->where('id', 1)->first();
            if ($row === null || ! in_array($row->mode, ['off', 'pilot', 'live'], true)) {
                return ['mode' => 'off', 'pilot_user_id' => null];
            }
            if ($row->mode === 'pilot' && (! is_numeric($row->pilot_user_id)
                || (int) $row->pilot_user_id < 1)) {
                return ['mode' => 'off', 'pilot_user_id' => null];
            }

            return ['mode' => $row->mode, 'pilot_user_id' => $row->mode === 'pilot' ? (int) $row->pilot_user_id : null];
        } catch (Throwable) {
            return ['mode' => 'off', 'pilot_user_id' => null];
        }
    }

    public function allows(?User $user = null): bool
    {
        $state = $this->state();
        if ($state['mode'] === 'off') {
            return false;
        }
        if ($state['mode'] === 'pilot') {
            return $user instanceof User && (int) $user->getKey() === $state['pilot_user_id'];
        }

        return true;
    }

    /** @return list<string> Local blockers: no external request or secret disclosure. */
    public function blockers(): array
    {
        $issues = [];
        if (! app()->environment('testing') && (! app()->environment('production') || config('app.debug') !== false)) {
            $issues[] = 'environment';
        }
        if (! app()->environment('testing') && (! is_string(config('app.url'))
            || ! str_starts_with(config('app.url'), 'https://'))) {
            $issues[] = 'https';
        }
        if (! app()->environment('testing') && config('database.default') !== 'mysql') {
            $issues[] = 'database';
        }
        if (! $this->settings->isVisibleToPlayers()) {
            $issues[] = 'visibility';
        }
        try {
            $ready = false;
            $states = collect($this->providers->overview())->keyBy('code');
            foreach ($this->registry->all() as $code => $driver) {
                if (! $driver instanceof RoutablePaymentProvider) {
                    continue;
                }
                $provider = $states->get($code);
                $state = $this->providers->localDiagnostic($code);
                if ($code === 'paypal') {
                    // PayPal is configured through its own encrypted settings editor,
                    // so it is intentionally absent from the generic provider overview.
                    if (app(PayPalAdminSettings::class)->liveReady()
                        && $state['state'] === 'local_ok' && $state['matched_currencies'] !== []) {
                        $ready = true;
                        break;
                    }
                    continue;
                }
                if (is_array($provider) && $provider['enabled'] === true && $provider['mode'] === 'live'
                    && $state['state'] === 'local_ok' && $state['matched_currencies'] !== []) {
                    $ready = true;
                    break;
                }
            }
            if (! $ready) {
                $issues[] = 'provider';
            }
        } catch (Throwable) {
            $issues[] = 'provider'; // No DB/module state must never enable live transfers.
        }

        return $issues;
    }

    public function update(Admin $admin, string $mode, ?int $pilotUserId): void
    {
        $current = Auth::guard('admin')->user();
        if (! $admin->exists || ! $admin->is_active || ! $current instanceof Admin
            || (int) $current->getKey() !== (int) $admin->getKey()
            || ! in_array($admin->role, [AdminRole::Owner, AdminRole::Administrator], true)) {
            throw new AuthorizationException;
        }
        if (! in_array($mode, ['off', 'pilot', 'live'], true)) {
            throw new InvalidArgumentException('Invalid activation mode.');
        }
        if ($mode === 'pilot' && ($pilotUserId === null || $pilotUserId <= 0)) {
            throw new InvalidArgumentException('Select an active player for a pilot payment.');
        }
        DB::transaction(function () use ($admin, $mode, $pilotUserId): void {
            // Shared lock order with provider/rate editing and payment preparations.
            DB::table('module_donation_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
            $row = DB::table('module_donation_live_settings')->where('id', 1)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('Live payment controls not installed.');
            }
            if ($mode !== 'off') {
                if ($this->blockers() !== []) {
                    throw new InvalidArgumentException('Fix the readiness checks before enabling payments.');
                }
                if ($mode === 'pilot' && ! User::query()->whereKey($pilotUserId)->where('is_active', true)->exists()) {
                    throw new InvalidArgumentException('Pilot player does not exist or is inactive.');
                }
            }
            DB::table('module_donation_live_settings')->where('id', 1)->update([
                'mode' => $mode, 'pilot_user_id' => $mode === 'pilot' ? $pilotUserId : null,
                'updated_by_admin_id' => $admin->getKey(), 'updated_at' => now(),
            ]);
            if ($this->audit->success(category: 'module', action: 'donations.live_mode_updated', actor: $admin,
                target: 'donations-live-mode', details: ['mode' => $mode, 'pilot_user_id' => $mode === 'pilot' ? $pilotUserId : null]) === null) {
                throw new RuntimeException('Unable to audit Donations activation.');
            }
        });
    }
}
