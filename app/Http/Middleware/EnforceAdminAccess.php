<?php

namespace App\Http\Middleware;

use App\Auth\AdminAccessPolicy;
use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceAdminAccess
{
    public function __construct(private readonly AdminAccessPolicy $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Admin|null $admin */
        $admin = $request->user('admin');
        abort_unless($admin !== null, 403);

        if ($this->isOwnProfileRoute($request, $admin)) {
            return $next($request);
        }

        $decision = $this->policy->decide($request);
        abort_unless($admin->hasPermission($decision->permission), 403);

        if ($decision->managePermission !== null && ! $admin->hasPermission($decision->managePermission)) {
            $request->attributes->set('admin_read_only', true);
        }

        return $next($request);
    }

    private function isOwnProfileRoute(Request $request, Admin $admin): bool
    {
        $name = (string) ($request->route()?->getName() ?? '');

        if (! in_array($name, [
            'admin.administrators.edit',
            'admin.administrators.update',
            'admin.administrators.password',
        ], true)) {
            return false;
        }

        $administrator = $request->route('administrator');

        return $administrator instanceof Admin && $administrator->is($admin);
    }
}
