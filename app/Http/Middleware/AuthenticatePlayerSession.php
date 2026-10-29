<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the player ("web" guard) login to the password it was made with, so a password
 * change or reset signs out every other browser.
 *
 * Laravel's AuthenticateSession follows the *default* guard and flushes the whole session.
 * Here players and administrators share one session cookie while administrators have their
 * own session_version check, so this variant always watches the web guard and only ends the
 * player login, leaving any administrator login in the same browser untouched.
 */
final class AuthenticatePlayerSession
{
    private const SESSION_KEY = 'password_hash_web';

    private const VERSION_KEY = 'auth_session_version_web';

    public function __construct(private readonly AuthFactory $auth) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        // Player session state must never interfere with the independent admin guard.
        if ($request->routeIs('admin.*') && $this->auth->guard('admin')->check()) {
            return $next($request);
        }

        $guard = $this->auth->guard('web');
        if (! $request->hasSession() || ! $guard instanceof SessionGuard) {
            return $next($request);
        }

        $user = $guard->user();
        $password = $user?->getAuthPassword();
        if (! is_string($password) || $password === '') {
            return $next($request);
        }

        $currentHash = $guard->hashPasswordForCookie($password);

        if ($guard->viaRemember()) {
            $recaller = (string) $request->cookies->get($guard->getRecallerName());
            $cookieHash = explode('|', $recaller)[2] ?? '';
            if (! $this->matches($cookieHash, $currentHash, $password)) {
                $this->signOut($request, $guard);
            }
        }

        $session = $request->session();
        $version = (int) $user->auth_session_version;
        if (! $session->has(self::VERSION_KEY)) {
            // A valid remember-cookie was checked above against the current password
            // and remember_token. It can safely establish a new web session.
            // Ordinary pre-upgrade sessions cannot silently adopt a new version.
            if ($guard->viaRemember()) {
                $session->put(self::VERSION_KEY, $version);
            } else {
                $this->signOut($request, $guard);
            }
        } elseif ((int) $session->get(self::VERSION_KEY) !== $version) {
            $this->signOut($request, $guard);
        }

        if (! $session->has(self::SESSION_KEY)) {
            // Sessions created before this protection existed adopt the current hash.
            $session->put(self::SESSION_KEY, $currentHash);
        } elseif (! $this->matches((string) $session->get(self::SESSION_KEY), $currentHash, $password)) {
            $this->signOut($request, $guard);
        }

        $response = $next($request);

        // Keep the current browser signed in after it changed the password itself.
        $freshUser = $guard->user();
        $freshPassword = $freshUser?->getAuthPassword();
        if (is_string($freshPassword) && $freshPassword !== '') {
            $session->put(self::SESSION_KEY, $guard->hashPasswordForCookie($freshPassword));
            // Do not promote a stale session by refreshing the version here.
        } else {
            $session->forget([self::SESSION_KEY, self::VERSION_KEY]);
        }

        return $response;
    }

    private function matches(string $stored, string $currentHash, string $password): bool
    {
        // Laravel stores an HMAC of the password hash; older values may hold the raw hash.
        return $stored !== '' && (hash_equals($currentHash, $stored) || hash_equals($password, $stored));
    }

    /** @throws AuthenticationException */
    private function signOut(Request $request, SessionGuard $guard): never
    {
        $guard->logoutCurrentDevice();
        $request->session()->forget([self::SESSION_KEY, self::VERSION_KEY]);
        $request->session()->regenerateToken();

        throw new AuthenticationException('Unauthenticated.', ['web'], public_route('login'));
    }
}
