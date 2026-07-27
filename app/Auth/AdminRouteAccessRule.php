<?php

namespace App\Auth;

final readonly class AdminRouteAccessRule
{
    public function __construct(
        public string $pattern,
        public AdminPermission $permission,
        public ?AdminPermission $managePermission = null,
        public bool $prefix = false,
        public bool $markReadOnly = true,
    ) {}

    public function matches(string $routeName): bool
    {
        return $this->prefix
            ? str_starts_with($routeName, $this->pattern)
            : $routeName === $this->pattern;
    }

    public function decision(string $method): AdminAccessDecision
    {
        $isRead = in_array(strtoupper($method), ['GET', 'HEAD'], true);

        if ($this->managePermission === null) {
            return new AdminAccessDecision($this->permission);
        }

        return $isRead
            ? new AdminAccessDecision(
                $this->permission,
                $this->markReadOnly ? $this->managePermission : null,
            )
            : new AdminAccessDecision($this->managePermission);
    }
}
