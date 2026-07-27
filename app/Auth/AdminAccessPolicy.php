<?php

namespace App\Auth;

use Illuminate\Http\Request;

final class AdminAccessPolicy
{
    public function __construct(private readonly AdminRouteAccessRegistry $registry) {}

    public function decide(Request $request): AdminAccessDecision
    {
        $name = (string) ($request->route()?->getName() ?? '');

        return $this->registry->decision($name, $request->method())
            ?? new AdminAccessDecision(AdminPermission::AdminPathManage);
    }
}
