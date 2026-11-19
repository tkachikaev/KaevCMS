<?php

namespace KaevCMS\Modules\Forum\Services;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class ForumActorResolver
{
    private const ATTRIBUTE = 'kaevcms.forum.actor';

    private const SESSION_MODE = 'forum.author_mode';

    /** Resolve the author once per request. A saved but revoked identity never falls back silently. */
    public function resolve(Request $request): ForumActor
    {
        $cached = $request->attributes->get(self::ATTRIBUTE);
        if ($cached instanceof ForumActor) {
            return $cached;
        }

        $identities = $this->identities($request);
        $mode = $request->session()->get(self::SESSION_MODE);

        if ($mode === null) {
            $mode = $identities['admin'] !== null ? 'admin'
                : ($identities['user'] !== null ? 'user' : 'guest');
            if ($mode !== 'guest') {
                $request->session()->put(self::SESSION_MODE, $mode);
            }
        }

        $actor = match ($mode) {
            'admin' => $identities['admin'] instanceof Admin
                ? new ForumActor('admin', admin: $identities['admin'])
                : new ForumActor('guest'),
            'user' => $identities['user'] instanceof User
                ? new ForumActor('user', user: $identities['user'])
                : new ForumActor('guest'),
            default => new ForumActor('guest'),
        };

        $request->attributes->set(self::ATTRIBUTE, $actor);

        return $actor;
    }

    /** @return array{admin: Admin|null, user: User|null} */
    public function identities(Request $request): array
    {
        return ['admin' => $this->admin($request), 'user' => $this->user()];
    }

    public function select(Request $request, string $mode): bool
    {
        $available = $this->identities($request);
        if (! in_array($mode, ['admin', 'user'], true) || $available[$mode] === null) {
            return false;
        }

        $request->session()->put(self::SESSION_MODE, $mode);
        $request->attributes->remove(self::ATTRIBUTE);

        return true;
    }

    private function admin(Request $request): ?Admin
    {
        $guard = Auth::guard('admin');
        $admin = $guard->user();

        if (! $admin instanceof Admin
            || ! $admin->is_active
            || ! in_array($admin->role, [AdminRole::Owner, AdminRole::Administrator], true)) {
            return null;
        }

        $version = $request->session()->get('admin_session_version');

        // Match the core's RequireAdminAuthentication behaviour for remember-me logins.
        if ($version === null && $guard->viaRemember()) {
            $version = $admin->session_version;
            $request->session()->put('admin_session_version', $version);
        }

        if (! is_int($version) && (! is_string($version) || ! ctype_digit($version))) {
            return null;
        }

        return (int) $version === $admin->session_version ? $admin : null;
    }

    private function user(): ?User
    {
        $user = Auth::guard('web')->user();

        return $user instanceof User && $user->is_active ? $user : null;
    }
}
