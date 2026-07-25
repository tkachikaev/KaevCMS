<nav class="admin-tabs settings-section-tabs" aria-label="{{ __('Settings sections') }}">
    <a wire:navigate @class(['admin-tab', 'settings-section-tab', 'active' => request()->routeIs('admin.settings.general*')]) href="{{ route('admin.settings.general') }}">
        {{ __('Site') }}
    </a>
    <a wire:navigate @class(['admin-tab', 'settings-section-tab', 'active' => request()->routeIs('admin.settings.admin-panel*')]) href="{{ route('admin.settings.admin-panel') }}">
        {{ __('Administrator panel') }}
    </a>
    <a wire:navigate @class(['admin-tab', 'settings-section-tab', 'active' => request()->routeIs('admin.settings.registration*')]) href="{{ route('admin.settings.registration') }}">
        {{ __('Registration') }}
    </a>
    <a wire:navigate @class(['admin-tab', 'settings-section-tab', 'active' => request()->routeIs('admin.settings.game-accounts*')]) href="{{ route('admin.settings.game-accounts') }}">
        {{ __('Game accounts') }}
    </a>
    <a wire:navigate @class(['admin-tab', 'settings-section-tab', 'active' => request()->routeIs('admin.settings.languages*')]) href="{{ route('admin.settings.languages') }}">
        {{ __('Languages') }}
    </a>
    <a wire:navigate @class(['admin-tab', 'settings-section-tab', 'active' => request()->routeIs('admin.settings.security*')]) href="{{ route('admin.settings.security') }}">
        {{ __('Security') }}
    </a>
    <a wire:navigate @class(['admin-tab', 'settings-section-tab', 'active' => request()->routeIs('admin.settings.system')]) href="{{ route('admin.settings.system') }}">
        {{ __('System information') }}
    </a>
    @if(auth('admin')->user()?->isOwner() === true)
        <a wire:navigate @class(['admin-tab', 'settings-section-tab', 'active' => request()->routeIs('admin.settings.system.updates.*')]) href="{{ route('admin.settings.system.updates.index') }}">
            {{ __('System updates') }}
        </a>
    @endif
</nav>
