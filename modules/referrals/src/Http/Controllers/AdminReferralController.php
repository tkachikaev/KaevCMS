<?php

namespace KaevCMS\Modules\Referrals\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\GameServer;
use Illuminate\View\View;
use KaevCMS\Modules\Referrals\Models\ReferralEntitlement;
use KaevCMS\Modules\Referrals\Models\ReferralInvite;
use KaevCMS\Modules\Referrals\Models\ReferralServerSetting;

final class AdminReferralController extends Controller
{
    public function __invoke(): View
    {
        /** @var view-string $view */
        $view = 'module-referrals::admin.index';

        return view($view, [
            'serverCount' => GameServer::query()->count(),
            'configuredServerCount' => ReferralServerSetting::query()->count(),
            'inviteCount' => ReferralInvite::query()->count(),
            'grantCount' => ReferralEntitlement::query()->where('status', 'granted')->count(),
        ]);
    }
}
