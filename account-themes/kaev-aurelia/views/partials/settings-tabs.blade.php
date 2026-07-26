<nav class="account-settings-tabs" aria-label="{{ __('Account settings') }}">
    <a wire:navigate @class(['active' => request()->routeIs('profile.edit', 'localized.profile.edit')]) href="{{ public_route('profile.edit') }}">{{ __('Account settings') }}</a>
    <a wire:navigate @class(['active' => request()->routeIs('security.edit', 'localized.security.edit')]) href="{{ public_route('security.edit') }}">{{ __('Security and password') }}</a>
</nav>
