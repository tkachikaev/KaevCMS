<?php

namespace KaevCMS\Modules\Voting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use KaevCMS\Modules\Voting\Models\VotingVote;
use KaevCMS\Modules\Voting\Services\VotingProviderRegistry;
use KaevCMS\Modules\Voting\Services\VotingSettings;

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

    public function history(Request $request): View
    {
        $status = $request->query('status');
        $status = is_string($status) && in_array($status, ['pending', 'accepted', 'rejected', 'revoked'], true) ? $status : '';
        $account = $request->query('account');
        $account = is_string($account) ? mb_substr(trim($account), 0, 190) : '';
        $query = VotingVote::query()->with('gameServer.translations');
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($account !== '') {
            $query->where('game_account_login', $account);
        }

        return view('module-voting::admin.history', [
            'votes' => $query->orderByDesc('id')->paginate(30)->appends(['status' => $status, 'account' => $account]),
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
