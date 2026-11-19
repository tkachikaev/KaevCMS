<?php

namespace KaevCMS\Modules\Forum\Services;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\User;

final readonly class ForumActor
{
    public function __construct(
        public string $mode,
        public ?User $user = null,
        public ?Admin $admin = null,
    ) {}

    public function isOwner(): bool
    {
        return $this->mode === 'admin'
            && $this->admin?->role === AdminRole::Owner;
    }

    public function isAdministrator(): bool
    {
        return $this->mode === 'admin'
            && $this->admin?->role === AdminRole::Administrator;
    }

    public function isGuest(): bool
    {
        return $this->mode === 'guest';
    }

    public function name(): ?string
    {
        return match ($this->mode) {
            'admin' => $this->admin?->name,
            'user' => $this->user?->publicName(),
            default => null,
        };
    }

    public function label(): ?string
    {
        return $this->mode === 'admin'
            ? __('module-forum::messages.administrator')
            : null;
    }
}
