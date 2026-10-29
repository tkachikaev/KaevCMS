<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\SocialRegistrationEmailVerification;
use App\Services\AuditLogger;
use App\Services\Auth\Social\GoogleIdentityProvider;
use App\Services\Auth\Social\SocialAccountConflict;
use App\Services\Auth\Social\SocialAuthSettings;
use App\Services\Auth\Social\SocialFlowState;
use App\Services\Auth\Social\SocialIdentityProvider;
use App\Services\Auth\Social\SocialIdentityService;
use App\Services\Auth\Social\SocialProviders;
use App\Services\Auth\Social\SocialRegistrationSession;
use App\Services\Auth\Social\TelegramIdentityProvider;
use App\Services\Auth\Social\YandexIdentityProvider;
use App\Services\Auth\Social\VkIdentityProvider;
use App\Services\MailSettings;
use App\Services\RegistrationSettings;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

final class SocialOAuthController extends Controller
{
    public function start(Request $request, SocialAuthSettings $settings, SocialFlowState $flows): RedirectResponse
    {
        abort_unless($settings->isAvailable($this->provider($request)), 404);
        abort_if(Auth::guard('web')->check(), 403);
        $request->session()->forget('social_auth.registration');
        $attempt = $flows->start($request, $this->provider($request), 'login');

        return redirect()->away($this->client($request)->authorizationUrl($attempt->state, $attempt->codeChallenge, $attempt->nonce));
    }

    public function startLink(Request $request, SocialAuthSettings $settings, SocialFlowState $flows): RedirectResponse
    {
        abort_unless($settings->isAvailable($this->provider($request)), 404);
        $user = Auth::guard('web')->user();
        abort_unless($user instanceof User && $user->is_active && $user->hasVerifiedEmail(), 403);
        if (! $this->stepUp($request, $user)) {
            return back()->withErrors(['social' => __('Confirm your identity before changing sign-in methods.')]);
        }
        $attempt = $flows->start($request, $this->provider($request), 'link', $user);
        $request->session()->put('social_auth.link_confirmed_at', now()->timestamp);
        $request->session()->put('social_auth.'.$this->provider($request).'.continue', [
            'url' => $this->client($request)->authorizationUrl($attempt->state, $attempt->codeChallenge, $attempt->nonce),
            'issued' => now()->timestamp,
        ]);

        // A local GET hop prevents CSP form-action from treating the external redirect
        // as a continuation of the local POST form submission.
        return redirect()->route('security.social.'.$this->provider($request).'.continue');
    }

    public function continueLink(Request $request, SocialAuthSettings $settings): RedirectResponse
    {
        abort_unless($settings->isAvailable($this->provider($request)) && Auth::guard('web')->check(), 403);
        $data = $request->session()->pull('social_auth.'.$this->provider($request).'.continue');
        if (! is_array($data) || ! is_string($data['url'] ?? null)
            || ! str_starts_with($data['url'], $this->provider($request) === SocialProviders::GOOGLE
                ? 'https://accounts.google.com/o/oauth2/v2/auth?' : ($this->provider($request) === SocialProviders::YANDEX
                    ? 'https://oauth.yandex.ru/authorize?' : ($this->provider($request) === SocialProviders::TELEGRAM
                        ? 'https://oauth.telegram.org/auth?' : 'https://id.vk.ru/authorize?')))
            || ! is_int($data['issued'] ?? null) || $data['issued'] > now()->timestamp
            || now()->timestamp - $data['issued'] > 60) {
            return redirect()->to(public_route('security.edit'))
                ->withErrors(['social' => __('Social sign-in could not be completed. Please try again.')]);
        }

        return redirect()->away($data['url']);
    }

    public function callback(
        Request $request,
        SocialAuthSettings $settings,
        SocialFlowState $flows,
        SocialIdentityService $identities,
        SocialRegistrationSession $registrations,
        RegistrationSettings $registrationSettings,
        AuditLogger $audit,
    ): RedirectResponse {
        // Clear a pending registration from a previous attempt before processing a fresh callback.
        $link = $request->session()->get('social_auth.pending.purpose') === 'link';
        try {
            if (! $settings->isAvailable($this->provider($request))) {
                throw new RuntimeException('Provider disabled.');
            }
            $callback = $this->callbackParameters($request);
            $flow = $flows->consume($request, $this->provider($request), $callback['state'] ?? '', Auth::guard('web')->user());
            if (isset($callback['error']) || ! is_string($callback['code'] ?? null) || $callback['code'] === '') {
                throw new RuntimeException('Authorization denied.');
            }
            $identity = $this->client($request)->verifyAuthorizationCode($callback['code'], $flow['code_verifier'], $flow['nonce'], $callback);
            if ($flow['purpose'] === 'link') {
                $user = Auth::guard('web')->user();
                if (! $user instanceof User || ! $this->recentLinkConfirmation($request)) {
                    throw new RuntimeException('Link confirmation expired.');
                }
                $identities->link($user, $identity, (int) $flow['session_version']);
                $request->session()->forget('social_auth.link_confirmed_at');
                $audit->success('user', 'social.linked', $user, $user, ['provider' => $this->provider($request)]);

                return redirect()->to(public_route('security.edit'))->with('status', __('Social sign-in has been connected.'));
            }
            if (Auth::guard('web')->check()) {
                throw new RuntimeException('Already authenticated.');
            }
            $user = $identities->findLinkedUser($identity);
            if ($user instanceof User) {
                if (! $user->is_active) {
                    throw new RuntimeException('Account is inactive.');
                }
                $user->forceFill(['last_login_at' => now(), 'locale' => app()->getLocale()])->save();
                Auth::guard('web')->login($user);
                $request->session()->regenerate();
                $request->session()->put('auth_session_version_web', (int) $user->auth_session_version);
                $request->session()->put('social_authenticated_at', now()->timestamp);
                $audit->success('user', 'social.login', $user, $user, ['provider' => $this->provider($request)]);

                return redirect()->to(public_route('account'));
            }
            if (! $registrationSettings->enabled()) {
                throw new RuntimeException('Registration disabled.');
            }
            $registrations->start($request, $identity);
            if ($identity->emailOwnershipAssured && User::query()->where('email', $identity->email)->exists()) {
                $request->session()->forget('social_auth.registration');

                return redirect()->to(public_route('login'))->withErrors(['social' => __('An account already uses this email. Sign in normally or reset your password, then connect the provider in Security.')]);
            }
            if (! $identity->emailOwnershipAssured) {
                return redirect()->route('social.'.$this->provider($request).'.register')->with('status', __('Confirm your email to continue registration.'));
            }

            return redirect()->route('social.'.$this->provider($request).'.register');
        } catch (Throwable $exception) {
            $request->session()->forget(['social_auth.link_confirmed_at', 'social_auth.registration']);
            $audit->failed('user', 'social.login_failed', null, __('Account area'), ['provider' => $this->provider($request), 'reason' => $exception::class]);

            return redirect()->to($link ? public_route('security.edit') : public_route('login'))
                ->withErrors(['social' => __('Social sign-in could not be completed. Please try again.')]);
        }
    }

    public function registrationForm(Request $request, SocialRegistrationSession $registrations, SocialAuthSettings $settings, RegistrationSettings $policy): View|RedirectResponse
    {
        $pending = $registrations->get($request);
        if ($pending === null || $pending['provider'] !== $this->provider($request)
            || ! $settings->isAvailable($this->provider($request)) || ! $policy->enabled()) {
            return redirect()->to(public_route('login'));
        }

        return view('theme::auth.social-register', [
            'pending' => $pending,
            'usernamePattern' => $policy->usernameHtmlPattern(),
            'usernameRequirements' => $policy->usernameRequirements(),
            'registrationPolicy' => $policy->values(),
        ]);
    }

    public function sendVerification(Request $request, SocialRegistrationSession $registrations, SocialAuthSettings $settings, RegistrationSettings $policy, MailSettings $mail): RedirectResponse
    {
        abort_unless($settings->isAvailable($this->provider($request)) && $policy->enabled(), 404);
        $pending = $registrations->get($request);
        if ($pending === null || $pending['provider'] !== $this->provider($request) || $pending['assured']) {
            return redirect()->to(public_route('login'));
        }
        $request->merge(['email' => Str::lower(trim((string) $request->input('email', '')))]);
        $validated = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
        if (! $mail->isReady()) {
            return back()->withErrors(['email' => __('Email delivery is unavailable. Contact the site administrator.')]);
        }
        $email = $validated['email'];
        $token = $registrations->requestEmailVerification($request, $email);
        $path = URL::temporarySignedRoute('social.'.$this->provider($request).'.email.verify', now()->addMinutes(15), ['token' => $token], absolute: false);
        $url = rtrim((string) config('app.url'), '/').$path;
        try {
            Notification::route('mail', $email)->notify(new SocialRegistrationEmailVerification($url));
        } catch (Throwable) {
            return back()->withErrors(['email' => __('The email could not be sent. Try again later.')]);
        }

        return redirect()->route('social.'.$this->provider($request).'.register')->with('status', __('Check your email for a confirmation link.'));
    }

    public function verifyEmail(Request $request, SocialRegistrationSession $registrations): RedirectResponse
    {
        try {
            $pending = $registrations->get($request);
            if ($pending === null || $pending['provider'] !== $this->provider($request)) {
                throw new RuntimeException('Wrong provider registration.');
            }
            $registrations->verifyEmail($request, (string) $request->query('token', ''));
        } catch (RuntimeException) {
            return redirect()->to(public_route('login'))->withErrors(['social' => __('Email confirmation expired. Start social sign-in again.')]);
        }

        $pending = $registrations->get($request);
        if ($pending !== null && $pending['provider'] === $this->provider($request) && User::query()->where('email', $pending['email'])->exists()) {
            $request->session()->forget('social_auth.registration');

            return redirect()->to(public_route('login'))->withErrors(['social' => __('An account already uses this email. Sign in normally or reset your password, then connect the provider in Security.')]);
        }

        return redirect()->route('social.'.$this->provider($request).'.register')->with('status', __('Email confirmed. Choose a username.'));
    }

    public function register(Request $request, SocialRegistrationSession $registrations, RegistrationSettings $settings, SocialAuthSettings $socialSettings, AuditLogger $audit): RedirectResponse
    {
        abort_unless($settings->enabled() && $socialSettings->isAvailable($this->provider($request)), 403);
        $pending = $registrations->get($request);
        if ($pending === null || $pending['provider'] !== $this->provider($request) || ! $pending['assured'] || ! is_string($pending['email'] ?? null)) {
            return redirect()->to(public_route('login'));
        }
        $policy = $settings->values();
        $request->merge(['name' => Str::lower(trim((string) $request->input('name', '')))]);
        $validated = $request->validate(['name' => [
            'required', 'string', 'min:'.$policy['username_min'], 'max:'.$policy['username_max'],
            'regex:'.$settings->usernameValidationRule(), 'unique:users,name',
        ]]);
        // Avoid revealing whether an arbitrary address exists before proof of ownership.
        if (User::query()->where('email', $pending['email'])->exists()) {
            return redirect()->to(public_route('login'))->withErrors(['social' => __('An account already uses this email. Sign in normally or reset your password, then connect the provider in Security.')]);
        }
        try {
            $registration = $registrations->consume($request);
            $user = DB::transaction(static function () use ($validated, $registration): User {
                $user = User::query()->create([
                    'name' => $validated['name'],
                    'email' => $registration['email'],
                    'password' => Hash::make(Str::random(96)),
                    'locale' => app()->getLocale(),
                ]);
                $user->forceFill(['password_set_at' => null, 'email_verified_at' => now(), 'last_login_at' => now()])->save();
                $user->socialAccounts()->create([
                    'provider' => $registration['provider'],
                    'provider_user_id' => $registration['subject'],
                ]);

                return $user;
            });
        } catch (QueryException $exception) {
            if (! in_array((string) $exception->getCode(), ['23000', '23505', '19'], true)) {
                throw $exception;
            }

            return redirect()->to(public_route('login'))->withErrors(['social' => __('This account could not be created. Please sign in or try again.')]);
        }
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('auth_session_version_web', (int) $user->auth_session_version);
        $request->session()->put('social_authenticated_at', now()->timestamp);
        $audit->success('user', 'social.registered', $user, $user, ['provider' => $this->provider($request)]);

        return redirect()->to(public_route('account'))->with('status', __('Your account was created using a social account.'));
    }

    public function unlink(Request $request, SocialAuthSettings $settings, AuditLogger $audit): RedirectResponse
    {
        $user = Auth::guard('web')->user();
        abort_unless($user instanceof User && $user->is_active, 403);
        if (! $this->stepUp($request, $user)) {
            return back()->withErrors(['social' => __('Confirm your identity before changing sign-in methods.')]);
        }
        try {
            DB::transaction(function () use ($user, $settings): void {
                $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                $linked = $locked->socialAccounts()->where('provider', $this->provider($request))->first();
                if ($linked === null) {
                    throw new SocialAccountConflict('No provider link.');
                }
                if ($locked->password_set_at === null) {
                    $alternatives = $locked->socialAccounts()->where('provider', '!=', $this->provider($request))->pluck('provider');
                    if (! $alternatives->contains(fn ($provider) => $settings->isAvailable((string) $provider))) {
                        throw new SocialAccountConflict('Last available login method.');
                    }
                }
                $linked->delete();
            });
        } catch (SocialAccountConflict) {
            return back()->withErrors(['social' => __('Set a password or connect another available sign-in method before disconnecting this provider.')]);
        }
        $audit->success('user', 'social.unlinked', $user, $user, ['provider' => $this->provider($request)]);

        return redirect()->to(public_route('security.edit'))->with('status', __('Social sign-in has been disconnected.'));
    }

    private function provider(Request $request): string
    {
        $provider = $request->route('social_provider');
        if (! in_array($provider, [SocialProviders::GOOGLE, SocialProviders::YANDEX, SocialProviders::VK, SocialProviders::TELEGRAM], true)) {
            abort(404);
        }

        return $provider;
    }

    private function client(Request $request): SocialIdentityProvider
    {
        return match ($this->provider($request)) {
            SocialProviders::GOOGLE => app(GoogleIdentityProvider::class),
            SocialProviders::YANDEX => app(YandexIdentityProvider::class),
            SocialProviders::VK => app(VkIdentityProvider::class),
            SocialProviders::TELEGRAM => app(TelegramIdentityProvider::class),
        };
    }

    /** @return array<string, string> */
    private function callbackParameters(Request $request): array
    {
        $payload = $request->query('payload');
        if ($payload !== null) {
            if ($this->provider($request) !== SocialProviders::VK || ! is_string($payload)
                || strlen($payload) > 8192 || $request->query('state') !== null
                || $request->query('code') !== null || $request->query('device_id') !== null) {
                throw new RuntimeException('Invalid authorization payload.');
            }
            $data = json_decode($payload, true);
            if (! is_array($data) || array_is_list($data)) {
                throw new RuntimeException('Invalid authorization payload.');
            }
        } else {
            $data = $request->query();
        }
        $callback = [];
        foreach (['state', 'code', 'device_id', 'error'] as $key) {
            if (array_key_exists($key, $data)) {
                if (! is_string($data[$key]) || strlen($data[$key]) > 4096) {
                    throw new RuntimeException('Invalid authorization parameter.');
                }
                $callback[$key] = $data[$key];
            }
        }

        return $callback;
    }

    private function stepUp(Request $request, User $user): bool
    {
        if ($user->password_set_at !== null) {
            return is_string($request->input('current_password')) && Hash::check((string) $request->input('current_password'), $user->password);
        }

        $time = (int) $request->session()->get('social_authenticated_at', 0);

        return $time > 0 && now()->timestamp - $time <= 600 && $time <= now()->timestamp;
    }

    private function recentLinkConfirmation(Request $request): bool
    {
        $time = (int) $request->session()->get('social_auth.link_confirmed_at', 0);

        return $time > 0 && $time <= now()->timestamp && now()->timestamp - $time <= 600;
    }
}
