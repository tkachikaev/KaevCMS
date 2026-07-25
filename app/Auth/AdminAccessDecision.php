<?php

namespace App\Auth;

final readonly class AdminAccessDecision
{
    public function __construct(
        public AdminPermission $permission,
        public ?AdminPermission $managePermission = null,
    ) {}
}
