<div class="account-profile-dropdown-head">
    <strong>{{ $user->name }}</strong>
    <small>{{ $user->email }}</small>
</div>
<div class="account-profile-balance" aria-label="{{ __('Future coin balance') }}">
    <span class="account-profile-balance-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"></circle><path d="M9.5 9.5h4a1.8 1.8 0 0 1 0 3.6h-3a1.8 1.8 0 0 0 0 3.6h4"></path><path d="M12 7.5v9"></path></svg>
    </span>
    <span><small>{{ __('Coins') }}</small><strong>{{ __('Not connected') }}</strong></span>
</div>
<a wire:navigate href="{{ public_route('profile.edit') }}">{{ __('Account settings') }}</a>
<a wire:navigate href="{{ public_route('security.edit') }}">{{ __('Security and password') }}</a>
<a href="{{ public_route('home') }}">{{ __('Back to website') }}</a>
<form method="POST" action="{{ public_route('logout') }}">
    @csrf
    <button type="submit">{{ __('Sign out') }}</button>
</form>
