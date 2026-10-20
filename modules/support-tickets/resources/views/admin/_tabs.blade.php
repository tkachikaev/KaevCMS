@php($adminPath = request()->route('adminPath'))
<x-admin.tabs :label="__('module-support-tickets::messages.admin_sections')" class="settings-section-tabs support-section-tabs" data-testid="support-section-tabs">
    <x-admin.tab
        wire:navigate
        :href="route('admin.module-pages.support-tickets.index', ['adminPath' => $adminPath])"
        :active="request()->routeIs('admin.module-pages.support-tickets.index', 'admin.module-pages.support-tickets.show')"
        class="settings-section-tab"
    >{{ __('module-support-tickets::messages.section_tickets') }}</x-admin.tab>
    @if(auth('admin')->user()?->isOwner())
        <x-admin.tab
            wire:navigate
            :href="route('admin.module-pages.support-tickets.settings', ['adminPath' => $adminPath])"
            :active="request()->routeIs('admin.module-pages.support-tickets.settings')"
            class="settings-section-tab"
        >{{ __('module-support-tickets::messages.settings') }}</x-admin.tab>
    @endif
</x-admin.tabs>
