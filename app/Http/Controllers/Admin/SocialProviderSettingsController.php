<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveSocialProviderSettingsRequest;
use App\Http\Requests\Admin\ToggleSocialProviderRequest;
use App\Services\AuditLogger;
use App\Services\Auth\Social\SocialAuthSettings;
use App\Services\Auth\Social\SocialProviders;
use App\Services\Auth\Social\TelegramIdTokenVerifier;
use DomainException;
use Illuminate\Http\RedirectResponse;

final class SocialProviderSettingsController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function update(
        SaveSocialProviderSettingsRequest $request,
        string $provider,
        SocialAuthSettings $settings,
    ): RedirectResponse {
        $this->assertProvider($provider);
        $validated = $request->validated();
        $secret = $validated['client_secret'] ?? null;
        $changes = $settings->updateCredentials(
            $provider,
            (string) $validated['client_id'],
            is_string($secret) ? $secret : null,
        );
        $this->auditLogger->success(
            category: 'admin',
            action: 'settings.social_provider_updated',
            target: $provider,
            details: $changes,
        );

        return $this->redirectWithStatus($changes['disabled_on_id_change']
            ? __('Provider settings saved. Sign-in was disabled because Client ID changed.')
            : __('Provider settings saved. Enable sign-in separately.'));
    }

    public function toggle(
        ToggleSocialProviderRequest $request,
        string $provider,
        SocialAuthSettings $settings,
    ): RedirectResponse {
        $this->assertProvider($provider);
        $enabled = $request->boolean('enabled');
        try {
            $settings->setEnabled($provider, $enabled);
        } catch (DomainException) {
            if ($provider === SocialProviders::TELEGRAM && ! app(TelegramIdTokenVerifier::class)->available()) {
                return back()->withErrors(['enabled' => __('Telegram sign-in cannot be enabled until a verified JWT/JWKS library is approved and installed.')]);
            }

            return back()->withErrors(['enabled' => __('Fill in the provider credentials and a valid HTTPS APP_URL first.')]);
        }
        $this->auditLogger->success(
            category: 'admin',
            action: 'settings.social_provider_toggled',
            target: $provider,
            details: ['enabled' => $enabled],
        );

        return $this->redirectWithStatus($enabled
            ? (in_array($provider, [SocialProviders::GOOGLE, SocialProviders::YANDEX, SocialProviders::VK, SocialProviders::TELEGRAM], true)
                ? __('Social sign-in provider enabled.')
                : __('Social sign-in provider enabled. The login integration is not yet implemented.'))
            : __('Social sign-in provider disabled. Existing player links are retained.'));
    }

    public function clearSecret(string $provider, SocialAuthSettings $settings): RedirectResponse
    {
        $this->assertProvider($provider);
        $settings->clearSecret($provider);
        $this->auditLogger->success(
            category: 'admin',
            action: 'settings.social_provider_secret_cleared',
            target: $provider,
            details: ['enabled' => false, 'secret_saved' => false],
        );

        return $this->redirectWithStatus(__('Saved provider secret removed and social sign-in disabled.'));
    }

    private function assertProvider(string $provider): void
    {
        abort_unless(in_array($provider, SocialProviders::all(), true), 404);
    }

    private function redirectWithStatus(string $message): RedirectResponse
    {
        return redirect()->route('admin.settings.registration')->with('status', $message);
    }
}
