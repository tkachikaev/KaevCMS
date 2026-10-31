<?php

namespace KaevCMS\Modules\Referrals\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use KaevCMS\Modules\Referrals\Services\ReferralInvitationService;

final class ReferralLinkController extends Controller
{
    public function show(Request $request, string $code, ReferralInvitationService $invitations): View|RedirectResponse
    {
        if (Auth::guard('web')->check()) {
            $invitations->forget();

            return redirect()->to(public_route('account'));
        }

        $referralCode = $invitations->activeCode($code);
        abort_if($referralCode === null, 404);

        $invitations->remember($referralCode, $request);

        return view('module-referrals::invite.landing', ['expiresInDays' => $invitations->ttlDays()]);
    }

    public function cancel(ReferralInvitationService $invitations): RedirectResponse
    {
        $invitations->forget();

        return redirect()->to(public_route('register'));
    }
}
