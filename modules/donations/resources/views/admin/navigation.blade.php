@push('head')
    <link rel="stylesheet" href="{{ asset('assets/modules/donations-admin.css') }}?v={{ cms_version() }}" data-navigate-track>
@endpush
<nav class="donations-navigation" aria-label="{{ __('module-donations::messages.admin_title') }}">
    <div class="donations-navigation-tabs">
        @foreach([
            'settings' => ['admin.module-pages.donations.index', 'settings_tab'],
            'providers' => ['admin.module-pages.donations.providers.index', 'providers_title'],
            'orders' => ['admin.module-pages.donations.orders.index', 'orders_admin_title'],
        ] as $section => [$destination, $label])
            @php($selected = $section === 'settings' ? request()->routeIs($destination) : request()->routeIs('admin.module-pages.donations.'.$section.'.*'))
            <a wire:navigate href="{{ route($destination, ['adminPath' => $adminPath]) }}" @if($selected) aria-current="page" @endif>{{ __('module-donations::messages.'.$label) }}</a>
        @endforeach
    </div>
    <a class="donations-back" wire:navigate href="{{ route('admin.modules.index', ['adminPath' => $adminPath]) }}">← {{ __('module-donations::messages.back_to_modules') }}</a>
</nav>
