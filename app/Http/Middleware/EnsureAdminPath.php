<?php

namespace App\Http\Middleware;

use App\Services\Admin\AdminPathSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAdminPath
{
    public function __construct(private readonly AdminPathSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $path = is_object($route) ? $route->parameter('adminPath') : null;

        abort_unless(is_string($path) && $this->settings->matches($path), 404);

        // This parameter is only an infrastructure prefix. Removing it prevents
        // positional controller arguments from receiving it instead of their own route values.
        $route->forgetParameter('adminPath');

        return $next($request);
    }
}
