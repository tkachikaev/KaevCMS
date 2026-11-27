<?php

namespace KaevCMS\Modules\Voting\Http\Controllers;

use App\Auth\AdminRole;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\GameServer;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use KaevCMS\Modules\Voting\Models\VotingVote;
use KaevCMS\Modules\Voting\Services\VotingProviderRegistry;
use KaevCMS\Modules\Voting\Services\VotingSettings;
use KaevCMS\Modules\Voting\Services\VotingSyncService;
use Throwable;

final class AdminVotingController extends Controller
{
    public function __construct(private readonly VotingSettings $settings, private readonly VotingProviderRegistry $providers) {}

    public function index(): View
    {
        return view('module-voting::admin.providers', [
            'providers' => $this->providers->all(),
            'settings' => $this->settings->state(),
        ]);
    }

    public function provider(): View
    {
        return view('module-voting::admin.ktreon', ['settings' => $this->settings->state()]);
    }

    public function save(Request $request): RedirectResponse
    {
        $admin = $this->admin($request);
        // Laravel excludes the password field from flashed input; never refill the secret.
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'project_id' => ['nullable', 'string', 'uuid'],
            'password' => ['nullable', 'string', 'size:47', 'regex:/\Akti_[A-Za-z0-9_-]{43}\z/D'],
            'pending_policy' => ['required', Rule::in(['wait', 'immediate'])],
        ], [
            'password.size' => __('module-voting::messages.invalid_key'),
            'password.regex' => __('module-voting::messages.invalid_key'),
            'project_id.uuid' => __('module-voting::messages.invalid_project'),
        ]);
        $this->settings->save(
            $admin,
            (bool) $data['enabled'],
            $data['project_id'] ?? null,
            $data['password'] ?? null,
            $data['pending_policy'],
        );

        return $this->back($request)->with('status', __('module-voting::messages.saved'));
    }

    public function check(Request $request): RedirectResponse
    {
        $result = $this->settings->check($this->admin($request));
        $message = __('module-voting::messages.check_'.$result);

        return $result === 'ok'
            ? $this->back($request)->with('status', $message)
            : $this->back($request)->withErrors(['connection' => $message]);
    }

    public function playerAccess(Request $request): RedirectResponse
    {
        $admin = $this->admin($request);
        $data = $request->validate([
            'player_access_enabled' => ['required', 'boolean'],
            'confirm' => ['sometimes', 'accepted'],
        ]);
        $this->settings->setPlayerAccess($admin, (bool) $data['player_access_enabled'], isset($data['confirm']));

        return $this->back($request)->with('status', __('module-voting::messages.saved'));
    }

    public function sync(Request $request, VotingSyncService $sync, AuditLogger $audit): RedirectResponse
    {
        $admin = $this->admin($request);
        abort_unless(in_array($admin->role, [AdminRole::Owner, AdminRole::Administrator], true), 403);
        if ($audit->success('module', 'voting.sync_requested', $admin, 'ktreon') === null) {
            return $this->back($request)->withErrors(['sync' => __('module-voting::messages.sync_failed')]);
        }

        try {
            // Keep the HTTP request bounded. The scheduler processes larger batches.
            $result = $sync->sync(1, 5);
        } catch (Throwable) {
            return $this->back($request)->withErrors(['sync' => __('module-voting::messages.sync_failed')]);
        }

        if ($result['result'] === 'ok') {
            return $this->back($request)->with('status', __('module-voting::messages.sync_manual_ok', [
                'events' => $result['processed'], 'grants' => $result['granted'],
            ]));
        }
        $code = $result['result'];
        if (! in_array($code, ['idle', 'cooldown', 'changed', 'rate_limited', 'unauthorized', 'unavailable', 'invalid_response', 'not_configured', 'failed'], true)) {
            $code = 'failed';
        }

        return in_array($code, ['idle', 'cooldown'], true)
            ? $this->back($request)->with('status', __('module-voting::messages.sync_'.$code))
            : $this->back($request)->withErrors(['sync' => __('module-voting::messages.sync_'.$code)]);
    }

    public function history(Request $request): View
    {
        $status = $request->query('status');
        $status = is_string($status) && in_array($status, ['pending', 'accepted', 'rejected', 'revoked'], true) ? $status : '';
        $account = $request->query('account');
        $account = is_string($account) ? mb_substr(trim($account), 0, 190) : '';
        $server = filter_var($request->query('server'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $rewardState = $request->query('reward');
        $rewardState = is_string($rewardState) && in_array($rewardState, ['waiting', 'granted', 'ineligible', 'not_granted'], true) ? $rewardState : '';
        $query = VotingVote::query()->with('gameServer.translations');
        if ($server !== false) {
            $query->where('game_server_id', $server);
        }
        if ($rewardState !== '') {
            $query->where('reward_state', $rewardState);
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($account !== '') {
            $query->where('game_account_login', $account);
        }

        return view('module-voting::admin.history', [
            'votes' => $query->orderByDesc('id')->paginate(30)->appends(['status' => $status, 'account' => $account, 'server' => $server ?: '', 'reward' => $rewardState]),
            'servers' => GameServer::query()->with('translations')->orderBy('sort_order')->orderBy('id')->get(),
            'server' => $server,
            'rewardState' => $rewardState,
            'status' => $status,
            'account' => $account,
        ]);
    }

    private function admin(Request $request): Admin
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }

    private function back(Request $request): RedirectResponse
    {
        return redirect()->route('admin.module-pages.voting.providers.ktreon.show', [
            'adminPath' => $request->route('adminPath'),
        ]);
    }
}
