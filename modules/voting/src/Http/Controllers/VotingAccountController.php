<?php

namespace KaevCMS\Modules\Voting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GameAssets\GameItemCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use KaevCMS\Modules\Voting\Models\VotingVote;
use KaevCMS\Modules\Voting\Services\VotingAvailability;
use KaevCMS\Modules\Voting\Services\VotingInvitationService;

final class VotingAccountController extends Controller
{
    public function __construct(
        private readonly VotingAvailability $availability,
        private readonly VotingInvitationService $invitations,
        private readonly GameItemCatalog $catalog,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $user = $this->user($request);
        if (! $this->availability->enabled()) {
            abort_unless($this->availability->hasHistory($user), 404);

            return redirect()->route('modules.voting.history');
        }
        $rewards = $this->availability->rewards();
        if ($rewards->isEmpty()) {
            abort_unless($this->availability->hasHistory($user), 404);

            return redirect()->route('modules.voting.history');
        }
        $serverId = filter_var($request->query('server'), FILTER_VALIDATE_INT);
        $selected = $rewards->firstWhere('game_server_id', $serverId) ?? $rewards->first();

        $items = [];
        foreach ($selected->configuredItems() as $item) {
            $items[] = $this->catalog->findForServer($selected->gameServer, $item['item_id']) + ['amount' => $item['amount']];
        }

        return view('module-voting::account.index', [
            'rewards' => $rewards,
            'selected' => $selected,
            'accounts' => $this->availability->accounts($user, $selected->gameServer),
            'items' => $items,
        ]);
    }

    public function history(Request $request): View
    {
        $user = $this->user($request);
        $status = $request->query('status');
        $status = is_string($status) && in_array($status, ['pending', 'accepted', 'rejected', 'revoked'], true) ? $status : '';
        $votes = VotingVote::query()->where('user_id', $user->id)->with('gameServer.translations');
        if ($status !== '') {
            $votes->where('status', $status);
        }

        return view('module-voting::account.history', [
            'votes' => $votes->orderByDesc('id')->paginate(20)->appends(['status' => $status]),
            'status' => $status,
            'votingAvailable' => $this->availability->enabled() && $this->availability->rewards()->isNotEmpty(),
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($this->availability->enabled(), 404);
        $data = $request->validate([
            'game_server_id' => ['required', 'integer', 'min:1'],
            'user_game_account_id' => ['required', 'integer', 'min:1'],
        ]);
        $result = $this->invitations->invite($user, (int) $data['user_game_account_id'], (int) $data['game_server_id']);
        if ($result->result === 'ok' && $result->url !== null) {
            return redirect()->away($result->url, 303, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
        }

        $destination = $this->availability->enabled()
            ? redirect()->route('modules.voting.index', ['server' => (int) $data['game_server_id']])
            : redirect()->route('modules.voting.history');

        return $destination->withErrors(['voting' => __('module-voting::messages.invite_'.$result->result)]);
    }

    private function user(Request $request): User
    {
        $user = $request->user('web');
        abort_unless($user instanceof User && $user->is_active, 403);

        return $user;
    }
}
