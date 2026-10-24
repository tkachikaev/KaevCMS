<?php

namespace App\Http\Controllers\Auth;

use App\Contracts\GameAccountGateway;
use App\Http\Controllers\Controller;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Models\RewardInventoryItem;
use App\Models\User;
use App\Services\GameAccounts\GameAccountQuota;
use App\Services\GameAccountSettings;
use App\Services\Servers\ServerDriverRegistry;
use App\Services\Servers\ServerStatusOverview;
use App\Services\Wallet\WalletAccountReadService;
use App\Support\Modules\ModuleNavigationRegistry;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function __invoke(
        Request $request,
        GameAccountSettings $settings,
        GameAccountGateway $gateway,
        GameAccountQuota $quota,
        WalletAccountReadService $wallets,
        ModuleNavigationRegistry $navigation,
        ServerStatusOverview $statuses,
    ): View {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $availableAccountCount = $user->availableGameAccounts()->count();
        $quotaAccountCount = $quota->count($user);

        $availableServers = GameServer::query()
            ->with('loginServer')
            ->whereNotNull('login_server_id')
            ->whereIn('driver', [ServerDriverRegistry::MOBIUS_DRIVER, ServerDriverRegistry::RUSACIS_DRIVER])
            ->get()
            ->filter(static fn (GameServer $server): bool => $server->connectionConfigured()
                && $server->loginServer instanceof LoginServer
                && $gateway->supportsLoginServer($server->loginServer)
                && ($server->driver === ServerDriverRegistry::RUSACIS_DRIVER
                    ? $server->loginServer->driver === ServerDriverRegistry::RUSACIS_DRIVER
                    : in_array($server->loginServer->driver, [
                        ServerDriverRegistry::MOBIUS_DRIVER,
                        ServerDriverRegistry::MOBIUS_LEGACY_LOGIN_DRIVER,
                    ], true)))
            ->count();

        $topUpLink = collect($navigation->accountLinks())->firstWhere('route', 'modules.donations.index');

        return view('account-theme::dashboard', [
            'user' => $user,
            'quotaAccountCount' => $quotaAccountCount,
            'hiddenAccountCount' => max(0, $quotaAccountCount - $availableAccountCount),
            'settings' => $settings->values(),
            'availableServers' => $availableServers,
            // Themes may show a fuller overview; older themes simply ignore these values.
            'walletView' => $wallets->forUser($user),
            'topUpUrl' => $topUpLink !== null ? route($topUpLink['route']) : null,
            'gameAccounts' => $user->availableGameAccounts()
                ->with(['registrationGameServer.translations', 'loginServer'])
                ->orderBy('game_login')
                ->get(),
            'availableRewardCount' => $user->rewardInventoryItems()
                ->where('status', RewardInventoryItem::STATUS_AVAILABLE)
                ->count(),
            'serverStatuses' => $statuses->get(app()->getLocale())['game_servers'] ?? [],
        ]);
    }
}
