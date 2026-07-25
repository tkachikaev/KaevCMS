<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\MailSettings;
use App\Services\RegistrationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailVerificationPromptController extends Controller
{
    public function __invoke(
        Request $request,
        RegistrationSettings $registrationSettings,
        MailSettings $mailSettings,
    ): View|RedirectResponse {
        if (! $registrationSettings->emailVerificationRequired() || $request->user()?->hasVerifiedEmail()) {
            return redirect()->to(public_route('account'));
        }

        return view('theme::auth.verify-email', [
            'mailReady' => $mailSettings->isReady(),
        ]);
    }
}
