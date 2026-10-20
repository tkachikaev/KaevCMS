@php($adminPath = request()->route('adminPath'))
<x-admin.tabs :label="__('module-promo-codes::messages.admin_sections')" class="settings-section-tabs promo-code-section-tabs" data-testid="promo-code-section-tabs">
    <x-admin.tab
        wire:navigate
        :href="route('admin.module-pages.promo-codes.index', ['adminPath' => $adminPath])"
        :active="request()->routeIs('admin.module-pages.promo-codes.index', 'admin.module-pages.promo-codes.create', 'admin.module-pages.promo-codes.edit')"
        class="settings-section-tab"
    >{{ __('module-promo-codes::messages.section_codes') }}</x-admin.tab>
    @if(auth('admin')->user()?->hasPermission(\App\Auth\AdminPermission::RewardsView))
        <x-admin.tab
            wire:navigate
            :href="route('admin.module-pages.promo-codes.activations', ['adminPath' => $adminPath])"
            :active="request()->routeIs('admin.module-pages.promo-codes.activations')"
            class="settings-section-tab"
        >{{ __('module-promo-codes::messages.open_journal') }}</x-admin.tab>
    @endif
</x-admin.tabs>
