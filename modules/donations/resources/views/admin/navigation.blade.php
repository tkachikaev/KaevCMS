@push('head')
    <link rel="stylesheet" href="{{ asset('assets/modules/donations-admin.css') }}?v={{ cms_version() }}" data-navigate-track>
@endpush
<x-admin.tabs :label="__('module-donations::messages.admin_sections')" class="settings-section-tabs donations-section-tabs" data-testid="donations-section-tabs">
    @foreach([
        'settings' => ['admin.module-pages.donations.index', 'settings_tab'],
        'providers' => ['admin.module-pages.donations.providers.index', 'providers_title'],
        'exchange' => ['admin.module-pages.donations.exchange.index', 'exchange_title'],
        'orders' => ['admin.module-pages.donations.orders.index', 'orders_admin_title'],
    ] as $section => [$destination, $label])
        <x-admin.tab
            wire:navigate
            :href="route($destination, ['adminPath' => $adminPath])"
            :active="$section === 'settings' ? request()->routeIs($destination) : request()->routeIs('admin.module-pages.donations.'.$section.'.*')"
            class="settings-section-tab"
        >{{ __('module-donations::messages.'.$label) }}</x-admin.tab>
    @endforeach
</x-admin.tabs>
