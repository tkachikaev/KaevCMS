<nav class="account-nav" aria-label="{{ __('Player account navigation') }}">
    <a wire:navigate.hover wire:current.exact="active" href="{{ public_route('account') }}">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5V21h-6v-6H9v6H3z"></path></svg>
        <span>{{ __('Overview') }}</span>
    </a>
    <a wire:navigate.hover wire:current="active" href="{{ public_route('wallet.index') }}">
        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="6" width="18" height="14" rx="2"></rect><path d="M3 10h18M16 15h2"></path></svg>
        <span>{{ __('My wallet') }}</span>
    </a>
    <a wire:navigate.hover wire:current="active" href="{{ public_route('characters.index') }}">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"></circle><path d="M4 21c0-4 4-6 8-6s8 2 8 6"></path></svg>
        <span>{{ __('Characters') }}</span>
    </a>
    <a wire:navigate.hover wire:current="active" href="{{ public_route('game-accounts.index') }}">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="8" cy="15" r="4"></circle><path d="M11 12l9-9M17 6l3 3"></path></svg>
        <span>{{ __('Game accounts') }}</span>
    </a>
    <a wire:navigate.hover wire:current="active" href="{{ public_route('web-inventory.index') }}">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7l9-4 9 4v10l-9 4-9-4z"></path><path d="M3 7l9 4 9-4M12 11v10"></path></svg>
        <span>{{ __('Web inventory') }}</span>
    </a>

    @foreach(app(\App\Support\Modules\ModuleNavigationRegistry::class)->sidebarAccountLinks() as $moduleLink)
        <a wire:navigate data-account-module-id="{{ $moduleLink['module_id'] }}" @class(['active' => request()->routeIs('modules.'.$moduleLink['module_id'].'.*')]) href="{{ route($moduleLink['route']) }}">
            @switch($moduleLink['module_id'])
                @case('daily-rewards')
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M3 10h18M8 3v4M16 3v4"></path></svg>
                    @break
                @case('promo-codes')
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16v4a2 2 0 0 0 0 4v4H4v-4a2 2 0 0 0 0-4z"></path></svg>
                    @break
                @case('support-tickets')
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v11H9l-5 4z"></path></svg>
                    @break
                @case('donations')
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"></circle><path d="M12 8v8M9 11h6"></path></svg>
                    @break
                @default
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"></path></svg>
            @endswitch
            <span title="{{ __($moduleLink['label_key']) }}">{{ __($moduleLink['label_key']) }}</span>
        </a>
    @endforeach
</nav>
