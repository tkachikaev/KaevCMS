<nav class="account-nav" aria-label="{{ __('Player account navigation') }}">
    <span class="account-nav-label">{{ __('Main') }}</span>
    <a wire:navigate.hover wire:current.exact="active" href="{{ public_route('account') }}">
        <span class="account-nav-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6h-4v6H5a1 1 0 0 1-1-1z"></path></svg>
        </span>
        <span><strong>{{ __('Overview') }}</strong><small>{{ __('Account summary and quick access') }}</small></span>
    </a>

    <span class="account-nav-label">{{ __('Game') }}</span>
    <a wire:navigate.hover wire:current="active" href="{{ public_route('characters.index') }}">
        <span class="account-nav-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"></circle><path d="M5 20a7 7 0 0 1 14 0M4 5l3-2M20 5l-3-2"></path></svg>
        </span>
        <span><strong>{{ __('Characters') }}</strong><small>{{ __('All characters in one place') }}</small></span>
    </a>

    <a wire:navigate.hover wire:current="active" href="{{ public_route('game-accounts.index') }}">
        <span class="account-nav-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="14" rx="4"></rect><path d="M8 12h4M10 10v4M16.5 10.5h.01M18 13h.01"></path></svg>
        </span>
        <span><strong>{{ __('Game accounts') }}</strong><small>{{ __('Accounts and passwords') }}</small></span>
    </a>

    <a wire:navigate.hover wire:current="active" href="{{ public_route('web-inventory.index') }}">
        <span class="account-nav-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M4 8.5h16v10a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"></path><path d="M7 8.5V6a5 5 0 0 1 10 0v2.5M9 13h6"></path></svg>
        </span>
        <span><strong>{{ __('Web inventory') }}</strong><small>{{ __('Rewards and transfers') }}</small></span>
    </a>

    @foreach(app(\App\Support\Modules\ModuleNavigationRegistry::class)->accountLinks() as $moduleLink)
        <a wire:navigate.hover @class(['active' => request()->routeIs('modules.'.$moduleLink['module_id'].'.*')]) href="{{ route($moduleLink['route']) }}">
            <span class="account-nav-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M7 4h10l3 4-8 12L4 8z"></path><path d="m7 4 5 16 5-16M4 8h16"></path></svg>
            </span>
            <span><strong>{{ __($moduleLink['label_key']) }}</strong><small>{{ __($moduleLink['description_key']) }}</small></span>
        </a>
    @endforeach
</nav>
