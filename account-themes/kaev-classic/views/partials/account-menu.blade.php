<div class="account-profile-dropdown-head">
    <strong>{{ $user->name }}</strong>
    <small>{{ $user->email }}</small>
</div>
<a wire:navigate class="account-profile-balance account-wallet-menu-link" href="{{ public_route('wallet.index') }}" aria-label="{{ __('My wallet') }}: {{ $walletView['label'] }}">
    <span class="account-profile-balance-icon" aria-hidden="true"><x-account-wallet-icon :url="$walletView['icon_url']" /></span>
    <span><small>{{ __('My wallet') }}</small><strong>{{ $walletView['label'] }}</strong></span>
</a>
<a wire:navigate href="{{ public_route('profile.edit') }}">{{ __('Account settings') }}</a>
<a wire:navigate href="{{ public_route('security.edit') }}">{{ __('Security and password') }}</a>
<a href="{{ public_route('home') }}">{{ __('Back to website') }}</a>
<form method="POST" action="{{ public_route('logout') }}">
    @csrf
    <button type="submit">{{ __('Sign out') }}</button>
</form>
