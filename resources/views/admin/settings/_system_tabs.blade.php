<nav class="admin-tabs settings-section-tabs" aria-label="{{ __('Settings sections') }}" data-testid="settings-section-tabs">
    <a wire:navigate @class(['admin-tab', 'settings-section-tab', 'active' => request()->routeIs('admin.settings.general*')]) href="{{ route('admin.settings.general') }}" @if(request()->routeIs('admin.settings.general*')) aria-current="page" @endif>
        {{ __('Site') }}
    </a>
    <a wire:navigate @class(['admin-tab', 'settings-section-tab', 'active' => request()->routeIs('admin.settings.admin-panel*')]) href="{{ route('admin.settings.admin-panel') }}" @if(request()->routeIs('admin.settings.admin-panel*')) aria-current="page" @endif>
        {{ __('Administrator panel') }}
    </a>
    <a wire:navigate @class(['admin-tab', 'settings-section-tab', 'active' => request()->routeIs('admin.settings.registration*')]) href="{{ route('admin.settings.registration') }}" @if(request()->routeIs('admin.settings.registration*')) aria-current="page" @endif>
        {{ __('Registration') }}
    </a>
    <a wire:navigate @class(['admin-tab', 'settings-section-tab', 'active' => request()->routeIs('admin.settings.game-accounts*')]) href="{{ route('admin.settings.game-accounts') }}" @if(request()->routeIs('admin.settings.game-accounts*')) aria-current="page" @endif>
        {{ __('Game accounts') }}
    </a>
    <a wire:navigate @class(['admin-tab', 'settings-section-tab', 'active' => request()->routeIs('admin.settings.languages*')]) href="{{ route('admin.settings.languages') }}" @if(request()->routeIs('admin.settings.languages*')) aria-current="page" @endif>
        {{ __('Languages') }}
    </a>
    <a wire:navigate @class(['admin-tab', 'settings-section-tab', 'active' => request()->routeIs('admin.settings.security*')]) href="{{ route('admin.settings.security') }}" @if(request()->routeIs('admin.settings.security*')) aria-current="page" @endif>
        {{ __('Security') }}
    </a>
    <a wire:navigate @class(['admin-tab', 'settings-section-tab', 'active' => request()->routeIs('admin.settings.system')]) href="{{ route('admin.settings.system') }}" @if(request()->routeIs('admin.settings.system')) aria-current="page" @endif>
        {{ __('System information') }}
    </a>
    @if(auth('admin')->user()?->isOwner() === true || auth('admin')->user()?->isReadOnly() === true)
        <a wire:navigate @class(['admin-tab', 'settings-section-tab', 'active' => request()->routeIs('admin.settings.system.updates.*')]) href="{{ route('admin.settings.system.updates.index') }}" @if(request()->routeIs('admin.settings.system.updates.*')) aria-current="page" @endif>
            {{ __('System updates') }}
        </a>
    @endif
</nav>
