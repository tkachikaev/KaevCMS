@php($adminPath = request()->route('adminPath'))
<x-admin.tabs :label="__('module-daily-rewards::messages.admin_sections')" class="settings-section-tabs daily-reward-section-tabs" data-testid="daily-reward-section-tabs">
    <x-admin.tab
        wire:navigate
        :href="route('admin.module-pages.daily-rewards.index', ['adminPath' => $adminPath])"
        :active="request()->routeIs('admin.module-pages.daily-rewards.index', 'admin.module-pages.daily-rewards.create', 'admin.module-pages.daily-rewards.edit')"
        class="settings-section-tab"
    >{{ __('module-daily-rewards::messages.section_calendars') }}</x-admin.tab>
    @if(auth('admin')->user()?->hasPermission(\App\Auth\AdminPermission::RewardsView))
        <x-admin.tab
            wire:navigate
            :href="route('admin.module-pages.daily-rewards.claims', ['adminPath' => $adminPath])"
            :active="request()->routeIs('admin.module-pages.daily-rewards.claims')"
            class="settings-section-tab"
        >{{ __('module-daily-rewards::messages.open_journal') }}</x-admin.tab>
    @endif
</x-admin.tabs>
