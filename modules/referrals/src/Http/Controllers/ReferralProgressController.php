<?php

namespace KaevCMS\Modules\Referrals\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\GameServer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use KaevCMS\Modules\Referrals\Models\ReferralInvite;
use KaevCMS\Modules\Referrals\Services\ReferralLevelScanner;

final class ReferralProgressController extends Controller
{
    /**
     * An explicit, per-invitation manual scan. The browser never supplies a level.
     * No route to force scans of other players or arbitrary GameServers.
     */
    public function check(
        Request $request,
        ReferralInvite $invite,
        GameServer $server,
        ReferralLevelScanner $scanner,
    ): RedirectResponse {
        $user = $request->user('web');
        abort_unless($user !== null && $user->is_active, 403);
        abort_unless(
            (int) $invite->inviter_user_id === (int) $user->id
            || (int) $invite->invitee_user_id === (int) $user->id,
            404,
        );
        abort_unless($scanner->enabled(), 404);

        // Cache::add is atomic, unlike a read-then-write flag. Do not let a
        // repeated browser POST bypass the cooldown or launch another scan.
        $key = 'referrals:manual-check:'.$invite->id.':'.$server->id;
        if (! Cache::add($key, true, now()->addMinutes(5))) {
            return back()->with('referral_check', 'cooldown');
        }

        $result = $scanner->scan($invite, $server, true);

        return back()->with('referral_check', $result);
    }
}
