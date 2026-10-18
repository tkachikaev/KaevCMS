@props(['walletView'])
@php($walletTopUp = collect(app(\App\Support\Modules\ModuleNavigationRegistry::class)->accountLinks())->firstWhere('route', 'modules.donations.index'))
<details class="account-profile-menu account-wallet-menu" data-account-wallet-menu>
    <summary class="account-topbar-wallet" aria-label="{{ __('Wallet menu') }}: {{ $walletView['label'] }}">
        <span class="account-topbar-wallet-icon" aria-hidden="true"><x-account-wallet-icon :url="$walletView['icon_url']" /></span>
        <span class="account-topbar-wallet-amount" data-wallet-balance-amount>{{ $walletView['formatted'] }}</span>
        <span class="account-profile-chevron" aria-hidden="true">⌄</span>
    </summary>
    <div class="account-profile-dropdown account-wallet-dropdown">
        <div class="account-profile-dropdown-head"><small>{{ __('Available balance') }}</small><strong data-wallet-balance-label>{{ $walletView['label'] }}</strong></div>
        @if($walletTopUp !== null)
            <a wire:navigate href="{{ route($walletTopUp['route']) }}" data-wallet-topup>{{ __('Top up') }}</a>
        @endif
        @foreach(app(\App\Support\Modules\ModuleNavigationRegistry::class)->walletLinks() as $walletLink)
            <a wire:navigate href="{{ route($walletLink['route']) }}" data-wallet-exchange>{{ __($walletLink['label_key']) }}</a>
        @endforeach
        <a wire:navigate href="{{ public_route('wallet.index') }}" data-wallet-journal>{{ __('History') }}</a>
    </div>
</details>
