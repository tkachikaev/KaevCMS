<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use KaevCMS\Modules\Referrals\Http\Controllers\ReferralLinkController;
use KaevCMS\Modules\Referrals\Services\ReferralInvitationService;

/** Permanent short-link endpoint: the module gate runs before resolving module code. */
final class ReferralShortLinkController extends Controller
{
    public function __invoke(Request $request, string $code): View|RedirectResponse
    {
        return app(ReferralLinkController::class)->show(
            $request,
            $code,
            app(ReferralInvitationService::class),
        );
    }
}
