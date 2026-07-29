<?php

namespace App\Support\Modules;

final readonly class ModuleAdminAccessDecision
{
    public function __construct(
        public bool $allowed,
        public bool $readOnly,
    ) {}
}
