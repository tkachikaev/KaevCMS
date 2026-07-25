<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\InteractsWithSettingsAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveRegistrationSettingsRequest;
use App\Services\AuditLogger;
use App\Services\MailSettings;
use App\Services\RegistrationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RegistrationSettingsController extends Controller
{
    use InteractsWithSettingsAudit;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function registration(RegistrationSettings $registrationSettings, MailSettings $mailSettings): View
    {
        return view('admin.settings.registration', [
            'settings' => $registrationSettings->values(),
            'mailReady' => $mailSettings->isReady(),
        ]);
    }

    public function updateRegistration(
        SaveRegistrationSettingsRequest $request,
        RegistrationSettings $registrationSettings,
    ): RedirectResponse {
        $before = $registrationSettings->values();
        $registrationSettings->update(
            enabled: $request->boolean('registration_enabled'),
            emailVerificationRequired: $request->boolean('email_verification_required'),
        );
        $after = $registrationSettings->values();

        $this->auditLogger->success(
            category: 'admin',
            action: 'settings.registration_updated',
            target: __('Registration settings'),
            details: ['changes' => $this->auditChanges($before, $after)],
        );

        return redirect()
            ->route('admin.settings.registration')
            ->with('status', __('Registration settings saved.'));
    }
}
