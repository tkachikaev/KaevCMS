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
use App\Services\Auth\Social\SocialIdentityService;
use App\Services\Auth\Social\SocialProviders;
use App\Services\Auth\Social\SocialRegistrationSession;
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

final class GoogleSocialAuthController extends Controller
{
    private const PROVIDER = SocialProviders::GOOGLE;

    public function start(Request $request, SocialAuthSettings $settings, SocialFlowState $flows, GoogleIdentityProvider $google): RedirectResponse
    {
        abort_unless($settings->isAvailable(self::PROVIDER), 404);
        abort_if(Auth::guard('web')->check(), 403);
        $request->session()->forget('social_auth.registration');
        $attempt = $flows->start($request, self::PROVIDER, 'login');

        return redirect()->away($google->authorizationUrl($attempt->state, $attempt->codeChallenge, $attempt->nonce));
    }

    public function startLink(Request $request, SocialAuthSettings $settings, SocialFlowState $flows, GoogleIdentityProvider $google): RedirectResponse
    {
        abort_unless($settings->isAvailable(self::PROVIDER), 404);
        $user = Auth::guard('web')->user();
        abort_unless($user instanceof User && $user->is_active && $user->hasVerifiedEmail(), 403);
        if (! $this->stepUp($request, $user)) {
            return back()->withErrors(['social' => __('Confirm your identity before changing sign-in methods.')]);
        }
        $attempt = $flows->start($request, self::PROVIDER, 'link', $user);
        $request->session()->put('social_auth.link_confirmed_at', now()->timestamp);
        $request->session()->put('social_auth.google.continue', [
            'url' => $google->authorizationUrl($attempt->state, $attempt->codeChallenge, $attempt->nonce),
            'issued' => now()->timestamp,
        ]);

        // A local GET hop prevents CSP form-action from treating the external redirect
        // as a continuation of the local POST form submission.
        return redirect()->route('security.social.google.continue');
    }

    public function continueLink(Request $request, SocialAuthSettings $settings): RedirectResponse
    {
        abort_unless($settings->isAvailable(self::PROVIDER) && Auth::guard('web')->check(), 403);
        $data = $request->session()->pull('social_auth.google.continue');
        if (! is_array($data) || ! is_string($data['url'] ?? null)
            || ! str_starts_with($data['url'], 'https://accounts.google.com/o/oauth2/v2/auth?')
            || ! is_int($data['issued'] ?? null) || $data['issued'] > now()->timestamp
            || now()->timestamp - $data['issued'] > 60) {
            return redirect()->to(public_route('security.edit'))
                ->withErrors(['social' => __('Google sign-in could not be completed. Please try again.')]);
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
        GoogleIdentityProvider $google,
    ): RedirectResponse {
        // Clear a pending registration from a previous attempt before processing a fresh callback.
        $link = $request->session()->get('social_auth.pending.purpose') === 'link';
        try {
            if (! $settings->isAvailable(self::PROVIDER)) {
                throw new RuntimeException('Provider disabled.');
            }
            $flow = $flows->consume($request, self::PROVIDER, (string) $request->query('state', ''), Auth::guard('web')->user());
            if ($request->query('error') !== null || ! is_string($request->query('code')) || $request->query('code') === '') {
                throw new RuntimeException('Authorization denied.');
            }
            $identity = $google->verifyAuthorizationCode((string) $request->query('code'), $flow['code_verifier'], $flow['nonce']);
            if ($flow['purpose'] === 'link') {
                $user = Auth::guard('web')->user();
                if (! $user instanceof User || ! $this->recentLinkConfirmation($request)) {
                    throw new RuntimeException('Link confirmation expired.');
                }
                $identities->link($user, $identity, (int) $flow['session_version']);
                $request->session()->forget('social_auth.link_confirmed_at');
                $audit->success('user', 'social.linked', $user, $user, ['provider' => self::PROVIDER]);

                return redirect()->to(public_route('security.edit'))->with('status', __('Google sign-in has been connected.'));
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
                $audit->success('user', 'social.login', $user, $user, ['provider' => self::PROVIDER]);

                return redirect()->to(public_route('account'));
            }
            if (! $registrationSettings->enabled()) {
                throw new RuntimeException('Registration disabled.');
            }
            $registrations->start($request, $identity);
            if ($identity->emailOwnershipAssured && User::query()->where('email', $identity->email)->exists()) {
                $request->session()->forget('social_auth.registration');

                return redirect()->to(public_route('login'))->withErrors(['social' => __('An account already uses this email. Sign in normally or reset your password, then connect Google in Security.')]);
            }
            if (! $identity->emailOwnershipAssured) {
                return redirect()->route('social.google.register')->with('status', __('Confirm your email to continue registration.'));
            }

            return redirect()->route('social.google.register');
        } catch (Throwable $exception) {
            $request->session()->forget(['social_auth.link_confirmed_at', 'social_auth.registration']);
            $audit->failed('user', 'social.login_failed', null, __('Account area'), ['provider' => self::PROVIDER, 'reason' => $exception::class]);

            return redirect()->to($link ? public_route('security.edit') : public_route('login'))
                ->withErrors(['social' => __('Google sign-in could not be completed. Please try again.')]);
        }
    }

    public function registrationForm(Request $request, SocialRegistrationSession $registrations, SocialAuthSettings $settings, RegistrationSettings $policy): View|RedirectResponse
    {
        $pending = $registrations->get($request);
        if ($pending === null || ! $settings->isAvailable(self::PROVIDER) || ! $policy->enabled()) {
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
        abort_unless($settings->isAvailable(self::PROVIDER) && $policy->enabled(), 404);
        $pending = $registrations->get($request);
        if ($pending === null || $pending['assured']) {
            return redirect()->to(public_route('login'));
        }
        $request->merge(['email' => Str::lower(trim((string) $request->input('email', '')))]);
        $validated = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
        if (! $mail->isReady()) {
            return back()->withErrors(['email' => __('Email delivery is unavailable. Contact the site administrator.')]);
        }
        $email = $validated['email'];
        $token = $registrations->requestEmailVerification($request, $email);
        $path = URL::temporarySignedRoute('social.google.email.verify', now()->addMinutes(15), ['token' => $token], absolute: false);
        $url = rtrim((string) config('app.url'), '/').$path;
        try {
            Notification::route('mail', $email)->notify(new SocialRegistrationEmailVerification($url));
        } catch (Throwable) {
            return back()->withErrors(['email' => __('The email could not be sent. Try again later.')]);
        }

        return redirect()->route('social.google.register')->with('status', __('Check your email for a confirmation link.'));
    }

    public function verifyEmail(Request $request, SocialRegistrationSession $registrations): RedirectResponse
    {
        try {
            $registrations->verifyEmail($request, (string) $request->query('token', ''));
        } catch (RuntimeException) {
            return redirect()->to(public_route('login'))->withErrors(['social' => __('Email confirmation expired. Start Google sign-in again.')]);
        }

        $pending = $registrations->get($request);
        if ($pending !== null && User::query()->where('email', $pending['email'])->exists()) {
            $request->session()->forget('social_auth.registration');

            return redirect()->to(public_route('login'))->withErrors(['social' => __('An account already uses this email. Sign in normally or reset your password, then connect Google in Security.')]);
        }

        return redirect()->route('social.google.register')->with('status', __('Email confirmed. Choose a username.'));
    }

    public function register(Request $request, SocialRegistrationSession $registrations, RegistrationSettings $settings, SocialAuthSettings $socialSettings, AuditLogger $audit): RedirectResponse
    {
        abort_unless($settings->enabled() && $socialSettings->isAvailable(self::PROVIDER), 403);
        $pending = $registrations->get($request);
        if ($pending === null || ! $pending['assured'] || ! is_string($pending['email'] ?? null)) {
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
            return redirect()->to(public_route('login'))->withErrors(['social' => __('An account already uses this email. Sign in normally or reset your password, then connect Google in Security.')]);
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
        $audit->success('user', 'social.registered', $user, $user, ['provider' => self::PROVIDER]);

        return redirect()->to(public_route('account'))->with('status', __('Your account was created with Google.'));
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
                $linked = $locked->socialAccounts()->where('provider', self::PROVIDER)->first();
                if ($linked === null) {
                    throw new SocialAccountConflict('No Google link.');
                }
                if ($locked->password_set_at === null) {
                    $alternatives = $locked->socialAccounts()->where('provider', '!=', self::PROVIDER)->pluck('provider');
                    if (! $alternatives->contains(fn ($provider) => $settings->isAvailable((string) $provider))) {
                        throw new SocialAccountConflict('Last available login method.');
                    }
                }
                $linked->delete();
            });
        } catch (SocialAccountConflict) {
            return back()->withErrors(['social' => __('Set a password or connect another available sign-in method before disconnecting Google.')]);
        }
        $audit->success('user', 'social.unlinked', $user, $user, ['provider' => self::PROVIDER]);

        return redirect()->to(public_route('security.edit'))->with('status', __('Google sign-in has been disconnected.'));
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
