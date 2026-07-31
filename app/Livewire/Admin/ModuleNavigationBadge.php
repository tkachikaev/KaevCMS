<?php

namespace App\Livewire\Admin;

use App\Models\Admin;
use App\Support\Modules\ModuleNavigationRegistry;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

final class ModuleNavigationBadge extends Component
{
    #[Locked]
    public string $moduleId;

    public int $count = 0;

    public function mount(string $moduleId, int $initialCount = 0): void
    {
        $this->moduleId = $moduleId;
        $this->count = $this->boundedCount($initialCount);
    }

    public function refreshBadge(): void
    {
        $admin = auth('admin')->user();
        $this->count = $admin instanceof Admin
            ? app(ModuleNavigationRegistry::class)->adminBadgeFor($this->moduleId, $admin)
            : 0;
    }

    #[On('module-admin-badge-refresh')]
    public function refreshFromEvent(string $moduleId = ''): void
    {
        if ($moduleId !== '' && $moduleId !== $this->moduleId) {
            return;
        }

        $this->refreshBadge();
    }

    public function render(): View
    {
        return view('livewire.admin.module-navigation-badge');
    }

    private function boundedCount(int $count): int
    {
        return min(999999, max(0, $count));
    }
}
