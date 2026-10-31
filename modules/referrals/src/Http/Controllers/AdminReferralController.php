<?php

namespace KaevCMS\Modules\Referrals\Http\Controllers;

use App\Contracts\GameAccountGateway;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Database\Eloquent\Model;
use App\Models\GameServer;
use App\Services\AuditLogger;
use App\Services\GameAssets\GameAssetUrlResolver;
use App\Services\GameAssets\GameItemCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use KaevCMS\Modules\Referrals\Models\ReferralEntitlement;
use KaevCMS\Modules\Referrals\Models\ReferralInvite;
use KaevCMS\Modules\Referrals\Models\ReferralProgress;
use KaevCMS\Modules\Referrals\Models\ReferralRule;
use KaevCMS\Modules\Referrals\Models\ReferralServerSetting;
use KaevCMS\Modules\Referrals\Models\ReferralSetting;
use RuntimeException;
use Throwable;

final class AdminReferralController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly GameItemCatalog $catalog,
        private readonly GameAssetUrlResolver $assets,
        private readonly GameAccountGateway $gateway,
    ) {}

    public function index(Request $request): View
    {
        $servers = GameServer::query()->with(['translations', 'loginServer'])->orderBy('sort_order')->orderBy('id')->get();
        $gatewaySupport = [];
        foreach ($servers as $server) {
            $gatewaySupport[$server->id] = $this->gatewaySupported($server);
        }
        $settings = ReferralServerSetting::query()->get()->keyBy('game_server_id');
        $ruleCounts = ReferralRule::query()->where('enabled', true)
            ->selectRaw('game_server_id, COUNT(*) as total')->groupBy('game_server_id')
            ->pluck('total', 'game_server_id');

        return view('module-referrals::admin.index', [
            'servers' => $servers,
            'serverSettings' => $settings,
            'gatewaySupport' => $gatewaySupport,
            'ruleCounts' => $ruleCounts,
            'global' => ReferralSetting::query()->where('setting_key', 'global')->firstOrFail(),
            'serverCount' => $servers->count(),
            'configuredServerCount' => $settings->count(),
            'inviteCount' => ReferralInvite::query()->count(),
            'grantCount' => ReferralEntitlement::query()->where('status', 'granted')->count(),
            'canManage' => $this->canManage($request),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $admin = $this->admin($request);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'invite_ttl_days' => ['required', 'integer', 'between:1,30'],
        ]);

        DB::transaction(function () use ($admin, $data): void {
            $global = ReferralSetting::query()->where('setting_key', 'global')->lockForUpdate()->firstOrFail();
            $before = $global->only(['enabled', 'invite_ttl_days']);
            $global->update([
                'enabled' => (bool) $data['enabled'],
                'invite_ttl_days' => (int) $data['invite_ttl_days'],
                'updated_by_admin_id' => $admin->id,
            ]);
            $this->auditRequired('referrals.settings_updated', $admin, 'referrals', [
                'before' => $before,
                'after' => $global->only(['enabled', 'invite_ttl_days']),
            ]);
        }, 3);

        return $this->back('index')->with('status', __('module-referrals::messages.saved'));
    }

    public function server(Request $request, GameServer $server): View
    {
        $server->load(['translations', 'loginServer']);
        $settings = ReferralServerSetting::query()->where('game_server_id', $server->id)->first()
            ?? new ReferralServerSetting(['game_server_id' => $server->id]);
        $rules = ReferralRule::query()->where('game_server_id', $server->id)
            ->orderBy('audience')->orderBy('level')->get();
        $itemDetails = [];
        foreach ($rules as $rule) {
            $itemDetails[$rule->id] = [
                'name' => $this->catalog->knownName($server, $rule->item_id),
                'icon' => $this->assets->itemIcon($server, $rule->item_id),
            ];
        }

        return view('module-referrals::admin.server', [
            'server' => $server,
            'settings' => $settings,
            'rules' => $rules,
            'itemDetails' => $itemDetails,
            'snapshotCount' => DB::table('module_referral_snapshots')->where('game_server_id', $server->id)->count(),
            'gatewaySupported' => $this->gatewaySupported($server),
            'canManage' => $this->canManage($request),
        ]);
    }

    public function updateServer(Request $request, GameServer $server): RedirectResponse
    {
        $admin = $this->admin($request);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'checks_paused' => ['required', 'boolean'],
            'inviter_rewards_enabled' => ['required', 'boolean'],
            'invitee_rewards_enabled' => ['required', 'boolean'],
            'max_rewarded_invitees' => ['nullable', 'integer', 'between:1,1000000'],
        ]);
        $payload = [
            'enabled' => (bool) $data['enabled'],
            'checks_paused' => (bool) $data['checks_paused'],
            'inviter_rewards_enabled' => (bool) $data['inviter_rewards_enabled'],
            'invitee_rewards_enabled' => (bool) $data['invitee_rewards_enabled'],
            'max_rewarded_invitees' => $data['max_rewarded_invitees'] ?? null,
        ];

        DB::transaction(function () use ($server, $payload, $admin): void {
            // Lock the parent row so settings creation cannot race with another administrator.
            GameServer::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            $setting = ReferralServerSetting::query()->firstOrNew(['game_server_id' => $server->id]);
            $before = $setting->exists ? $setting->only(array_keys($payload)) : null;
            $setting->fill($payload)->save();
            $this->auditRequired('referrals.server_settings_updated', $admin, $setting, [
                'game_server_id' => $server->id, 'before' => $before, 'after' => $payload,
            ]);
        }, 3);

        return $this->back('servers.show', ['server' => $server->id])->with('status', __('module-referrals::messages.saved'));
    }

    public function storeRule(Request $request, GameServer $server): RedirectResponse
    {
        $admin = $this->admin($request);
        $data = $request->validate([
            'audience' => ['required', Rule::in(['inviter', 'invitee'])],
            'level' => ['required', 'integer', 'between:1,65535'],
            'item_id' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
            'amount' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
        ]);

        DB::transaction(function () use ($server, $data, $admin): void {
            GameServer::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            $rules = ReferralRule::query()->where('game_server_id', $server->id)
                ->where('audience', $data['audience']);
            if ((clone $rules)->where('level', (int) $data['level'])->exists()) {
                throw ValidationException::withMessages(['level' => __('module-referrals::messages.duplicate_level')]);
            }
            if ((clone $rules)->count() >= 30) {
                throw ValidationException::withMessages(['level' => __('module-referrals::messages.too_many_rules')]);
            }

            $rule = ReferralRule::query()->create([
                'game_server_id' => $server->id,
                'audience' => $data['audience'],
                'level' => (int) $data['level'],
                'item_id' => (int) $data['item_id'],
                'amount' => (int) $data['amount'],
                'enabled' => true,
            ]);
            $this->auditRequired('referrals.rule_created', $admin, $rule, $rule->only(['game_server_id', 'audience', 'level', 'item_id', 'amount']));
        }, 3);

        return $this->back('servers.show', ['server' => $server->id])->with('status', __('module-referrals::messages.rule_added'));
    }

    public function destroyRule(Request $request, GameServer $server, ReferralRule $rule): RedirectResponse
    {
        $admin = $this->admin($request);
        abort_unless((int) $rule->game_server_id === (int) $server->id, 404);
        DB::transaction(function () use ($rule, $server, $admin): void {
            GameServer::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            $locked = ReferralRule::query()->lockForUpdate()->findOrFail($rule->id);
            $before = $locked->only(['game_server_id', 'audience', 'level', 'item_id', 'amount']);
            $locked->delete();
            $this->auditRequired('referrals.rule_deleted', $admin, 'referral-rule:'.$rule->id, $before);
        }, 3);

        return $this->back('servers.show', ['server' => $server->id])->with('status', __('module-referrals::messages.rule_deleted'));
    }

    public function invites(Request $request): View
    {
        $invites = ReferralInvite::query()->orderByDesc('id')->paginate(25);
        $progressByInvite = ReferralProgress::query()
            ->whereIn('invite_id', $invites->getCollection()->pluck('id'))
            ->orderBy('game_server_id')->get()->groupBy('invite_id');

        return view('module-referrals::admin.invites', [
            'invites' => $invites,
            'progressByInvite' => $progressByInvite,
            'frozenCount' => ReferralInvite::query()->where('status', 'frozen')->count(),
            'canManage' => $this->canManage($request),
        ]);
    }

    public function grants(Request $request): View
    {
        $filters = $request->validate([
            'server' => ['nullable', 'integer', 'min:1'],
            'user' => ['nullable', 'integer', 'min:1'],
            'level' => ['nullable', 'integer', 'between:1,65535'],
            'status' => ['nullable', Rule::in(['pending', 'earned', 'granted'])],
        ]);
        $rewards = ReferralEntitlement::query()
            ->with('gameServer.translations')
            ->when(isset($filters['server']), fn ($query) => $query->where('game_server_id', $filters['server']))
            ->when(isset($filters['user']), fn ($query) => $query->where('recipient_user_id', $filters['user']))
            ->when(isset($filters['level']), fn ($query) => $query->where('level', $filters['level']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->orderByDesc('id')->paginate(25)->withQueryString();

        return view('module-referrals::admin.grants', [
            'rewards' => $rewards,
            'servers' => GameServer::query()->with('translations')->orderBy('sort_order')->orderBy('id')->get(),
            'filters' => $filters,
        ]);
    }

    public function freeze(Request $request, ReferralInvite $invite): RedirectResponse
    {
        $admin = $this->admin($request);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        DB::transaction(function () use ($invite, $admin, $data): void {
            $locked = ReferralInvite::query()->lockForUpdate()->findOrFail($invite->id);
            if ($locked->status !== 'active') {
                throw ValidationException::withMessages(['reason' => __('module-referrals::messages.invalid_invite_state')]);
            }
            $locked->update([
                'status' => 'frozen', 'frozen_at' => now(),
                'frozen_reason' => trim($data['reason']), 'frozen_by_admin_id' => $admin->id,
            ]);
            $this->auditRequired('referrals.invite_frozen', $admin, $locked, [
                'invite_id' => $locked->id, 'reason' => $locked->frozen_reason,
            ]);
        }, 3);

        return $this->back('invites.index')->with('status', __('module-referrals::messages.invite_frozen'));
    }

    public function unfreeze(Request $request, ReferralInvite $invite): RedirectResponse
    {
        $admin = $this->admin($request);
        DB::transaction(function () use ($invite, $admin): void {
            $locked = ReferralInvite::query()->lockForUpdate()->findOrFail($invite->id);
            if ($locked->status !== 'frozen') {
                throw ValidationException::withMessages(['invite' => __('module-referrals::messages.invalid_invite_state')]);
            }
            $priorReason = $locked->frozen_reason;
            $locked->update([
                'status' => 'active', 'frozen_at' => null,
                'frozen_reason' => null, 'frozen_by_admin_id' => null,
            ]);
            $this->auditRequired('referrals.invite_unfrozen', $admin, $locked, [
                'invite_id' => $locked->id, 'prior_reason' => $priorReason,
            ]);
        }, 3);

        return $this->back('invites.index')->with('status', __('module-referrals::messages.invite_unfrozen'));
    }

    private function gatewaySupported(GameServer $server): bool
    {
        try {
            return $this->gateway->supportsGameServer($server);
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $details */
    private function auditRequired(string $action, Admin $admin, Model|string $target, array $details): void
    {
        if ($this->audit->success(category: 'module', action: $action, actor: $admin, target: $target, details: $details) === null) {
            throw new RuntimeException('Referral administrative audit could not be recorded.');
        }
    }

    private function admin(Request $request): Admin
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin && $this->canManage($request), 403);

        return $admin;
    }

    private function canManage(Request $request): bool
    {
        $admin = $request->user('admin');

        return $admin instanceof Admin && in_array($admin->role->value, ['owner', 'administrator'], true);
    }

    /** @param array<string, int> $parameters */
    private function back(string $route, array $parameters = []): RedirectResponse
    {
        return redirect()->route('admin.module-pages.referrals.'.$route, array_merge([
            'adminPath' => request()->route('adminPath'),
        ], $parameters));
    }
}
