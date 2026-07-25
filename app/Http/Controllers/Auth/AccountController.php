<?php

namespace App\Http\Controllers\Auth;

use App\Contracts\GameAccountGateway;
use App\Http\Controllers\Controller;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Models\User;
use App\Services\GameAccounts\GameAccountQuota;
use App\Services\GameAccountSettings;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function __invoke(
        Request $request,
        GameAccountSettings $settings,
        GameAccountGateway $gateway,
        GameAccountQuota $quota,
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
            ->where('driver', 'l2j_mobius_ct0_interlude')
            ->get()
            ->filter(static fn (GameServer $server): bool => $server->connectionConfigured()
                && $server->loginServer instanceof LoginServer
                && $gateway->supportsLoginServer($server->loginServer))
            ->count();

        return view('account-theme::dashboard', [
            'user' => $user,
            'quotaAccountCount' => $quotaAccountCount,
            'hiddenAccountCount' => max(0, $quotaAccountCount - $availableAccountCount),
            'settings' => $settings->values(),
            'availableServers' => $availableServers,
        ]);
    }
}
