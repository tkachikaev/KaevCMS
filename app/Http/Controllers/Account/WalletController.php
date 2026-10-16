<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WalletEntry;
use App\Services\Wallet\WalletAccountReadService;
use App\Support\Modules\ModuleNavigationRegistry;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Player-owned wallet history. No wallet IDs accepted from the browser. */
final class WalletController extends Controller
{
    public function __invoke(Request $request, WalletAccountReadService $wallets, ModuleNavigationRegistry $navigation): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $wallet = $user->wallet()->first(['id', 'user_id']);
        $entries = WalletEntry::query()
            ->where('wallet_id', $wallet->id ?? 0)
            ->orderByDesc('id')
            ->paginate(20);

        $topUpLink = collect($navigation->accountLinks())->firstWhere('route', 'modules.donations.index');

        return view('account-theme::wallet.index', [
            'user' => $user,
            'walletView' => $wallets->forUser($user),
            'entries' => $entries,
            'topUpUrl' => $topUpLink !== null ? route($topUpLink['route']) : null,
        ]);
    }
}
