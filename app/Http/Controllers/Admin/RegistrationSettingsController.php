<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\InteractsWithSettingsAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveRegistrationSettingsRequest;
use App\Services\AuditLogger;
use App\Services\Auth\Social\SocialAuthSettings;
use App\Services\CmsSettings;
use App\Services\MailSettings;
use App\Services\RegistrationSettings;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegistrationSettingsController extends Controller
{
    use InteractsWithSettingsAudit;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function registration(RegistrationSettings $registrationSettings, MailSettings $mailSettings, SocialAuthSettings $socialAuthSettings): View
    {
        return view('admin.settings.registration', [
            'settings' => $registrationSettings->values(),
            'mailReady' => $mailSettings->isReady(),
            'socialProviders' => $socialAuthSettings->all(),
        ]);
    }

    public function updateRegistration(
        SaveRegistrationSettingsRequest $request,
        RegistrationSettings $registrationSettings,
        SocialAuthSettings $socialSettings,
    ): RedirectResponse|JsonResponse {
        try {
            DB::transaction(function () use ($request, $registrationSettings, $socialSettings): void {
                app(CmsSettings::class)->forget();
                foreach ($request->validated('social_providers', []) as $provider => $enabled) {
                    try {
                        $socialSettings->setEnabled($provider, (bool) $enabled);
                    } catch (DomainException) {
                        throw ValidationException::withMessages(['social_providers.'.$provider => __('Fill in the provider credentials and a valid HTTPS APP_URL first.')]);
                    }
                    $this->auditLogger->success(category: 'admin', action: 'settings.social_provider_toggled', target: $provider, details: ['enabled' => (bool) $enabled]);
                }
                $before = $registrationSettings->values();
                $registrationSettings->update(
                    enabled: $request->boolean('registration_enabled'),
                    emailVerificationRequired: $request->boolean('email_verification_required'),
                    policy: [
                        'username_min' => $request->has('username_min') ? $request->integer('username_min') : $before['username_min'],
                        'username_max' => $request->has('username_max') ? $request->integer('username_max') : $before['username_max'],
                        'username_allow_hyphen' => $request->has('username_allow_hyphen')
                            ? $request->boolean('username_allow_hyphen')
                            : $before['username_allow_hyphen'],
                        'username_allow_underscore' => $request->has('username_allow_underscore')
                            ? $request->boolean('username_allow_underscore')
                            : $before['username_allow_underscore'],
                        'password_min' => $request->has('password_min') ? $request->integer('password_min') : $before['password_min'],
                        'password_letters' => $request->has('password_letters')
                            ? $request->boolean('password_letters')
                            : $before['password_letters'],
                        'password_mixed_case' => $request->has('password_mixed_case')
                            ? $request->boolean('password_mixed_case')
                            : $before['password_mixed_case'],
                        'password_numbers' => $request->has('password_numbers')
                            ? $request->boolean('password_numbers')
                            : $before['password_numbers'],
                        'password_symbols' => $request->has('password_symbols')
                            ? $request->boolean('password_symbols')
                            : $before['password_symbols'],
                    ],
                );
                $after = $registrationSettings->values();

                $this->auditLogger->success(
                    category: 'admin',
                    action: 'settings.registration_updated',
                    target: __('Registration settings'),
                    details: ['changes' => $this->auditChanges($before, $after)],
                );

            });
        } finally {
            app(CmsSettings::class)->forget();
        }
        if ($request->expectsJson()) {
            return response()->json(['message' => __('Registration settings saved.'), 'providers' => $socialSettings->all()]);
        }

        return redirect()
            ->route('admin.settings.registration')
            ->with('status', __('Registration settings saved.'));
    }
}
