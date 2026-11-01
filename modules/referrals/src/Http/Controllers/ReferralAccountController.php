<?php

namespace KaevCMS\Modules\Referrals\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\GameServer;
use App\Models\User;
use App\Services\GameAssets\GameItemCatalog;
use Illuminate\Http\Request;
use Illuminate\View\View;
use KaevCMS\Modules\Referrals\Models\ReferralEntitlement;
use KaevCMS\Modules\Referrals\Models\ReferralInvite;
use KaevCMS\Modules\Referrals\Models\ReferralProgress;
use KaevCMS\Modules\Referrals\Models\ReferralRule;
use KaevCMS\Modules\Referrals\Models\ReferralServerSetting;
use KaevCMS\Modules\Referrals\Services\ReferralInvitationService;
use RuntimeException;

final class ReferralAccountController extends Controller
{
    public function __construct(
        private readonly ReferralInvitationService $invitations,
        private readonly GameItemCatalog $catalog,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user('web');
        abort_unless($user instanceof User && $user->is_active, 403);
        abort_unless($this->invitations->enabled(), 404);

        $activeTab = $request->query('tab', 'invite');
        $activeTab = in_array($activeTab, ['invite', 'progress', 'history'], true) ? $activeTab : 'invite';

        $activeSettings = ReferralServerSetting::query()->availableForInvitations()->get()->keyBy('game_server_id');

        // Keep earned history accessible on a paused/removed program server. Never
        // fetch other players' entitlements to discover the user's server list.
        $historicalServerIds = ReferralEntitlement::query()
            ->where('recipient_user_id', $user->id)->distinct()->pluck('game_server_id');
        $progressServerIds = ReferralProgress::query()
            ->whereHas('invite', static fn ($query) => $query
                ->where('inviter_user_id', $user->id)->orWhere('invitee_user_id', $user->id))
            ->distinct()->pluck('game_server_id');
        $serverIds = $activeSettings->keys()->merge($historicalServerIds)->merge($progressServerIds)
            ->unique()->values();
        $servers = GameServer::query()->with('translations')->whereIn('id', $serverIds)
            ->orderBy('sort_order')->orderBy('id')->get();
        abort_if($servers->isEmpty(), 404);

        $allServers = $activeTab === 'history' && $request->query('server', 'all') === 'all';
        $requestedServerId = filter_var($request->query('server'), FILTER_VALIDATE_INT);
        $server = $servers->firstWhere('id', $requestedServerId) ?? $servers->first();
        $settings = $activeSettings->get($server->id);

        $invitedCount = ReferralInvite::query()->where('inviter_user_id', $user->id)->count();
        $wasInvited = ReferralInvite::query()->where('invitee_user_id', $user->id)->exists();
        $grantedCount = ReferralEntitlement::query()->where('recipient_user_id', $user->id)
            ->where('status', 'granted')->count();

        // A revoked link must not break the whole account page.
        try {
            $link = $this->invitations->linkFor($user);
        } catch (RuntimeException) {
            $link = null;
        }

        $rules = ReferralRule::query()->where('game_server_id', $server->id)->where('enabled', true)
            ->orderBy('audience')->orderBy('level')->get()
            ->filter(static fn (ReferralRule $rule): bool => $settings !== null
                && ($rule->audience === 'inviter' ? $settings->inviter_rewards_enabled : $settings->invitee_rewards_enabled))
            ->values();
        $rewards = ReferralEntitlement::query()
            ->with('gameServer.translations')
            ->where('recipient_user_id', $user->id)
            ->when($allServers, fn ($query) => $query->whereIn('game_server_id', $servers->modelKeys()), fn ($query) => $query->where('game_server_id', $server->id))
            ->orderByDesc('id')
            ->paginate(12, ['*'], 'rewards_page')->appends(['server' => $allServers ? 'all' : $server->id, 'tab' => 'history']);

        $progress = ReferralProgress::query()
            ->where('game_server_id', $server->id)
            ->whereHas('invite', static fn ($query) => $query
                ->where('inviter_user_id', $user->id)->orWhere('invitee_user_id', $user->id))
            ->with('invite')
            ->orderByDesc('last_checked_at')->orderByDesc('id')
            ->limit(20)->get();

        // Use the game's existing item catalogue rather than duplicating icon or name logic.
        $items = [];
        foreach ($rules as $entry) {
            if (! isset($items[$entry->item_id])) {
                $items[$entry->item_id] = $this->catalog->findForServer($server, (int) $entry->item_id);
            }
        }

        $rewardItems = [];
        foreach ($rewards as $reward) {
            $rewardItems[$reward->id] = $this->catalog->findForServer($reward->gameServer, $reward->item_id);
        }

        return view('module-referrals::account.index', [
            'activeTab' => $activeTab,
            'allServers' => $allServers,
            'rewardItems' => $rewardItems,
            'user' => $user,
            'link' => $link,
            'invitedCount' => $invitedCount,
            'wasInvited' => $wasInvited,
            'grantedCount' => $grantedCount,
            'servers' => $servers,
            'server' => $server,
            'settings' => $settings,
            'rules' => $rules,
            'rewards' => $rewards,
            'progress' => $progress,
            'items' => $items,
        ]);
    }
}
