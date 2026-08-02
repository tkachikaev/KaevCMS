@php($settingsAdmin = auth('admin')->user())
<x-admin.tabs :label="__('Settings sections')" class="settings-section-tabs" data-testid="settings-section-tabs">
    @if($settingsAdmin->hasPermission(\App\Auth\AdminPermission::SettingsView))
        <x-admin.tab wire:navigate :href="route('admin.settings.general')" :active="request()->routeIs('admin.settings.general*')" class="settings-section-tab">
            {{ __('Site') }}
        </x-admin.tab>
    @endif
    @if($settingsAdmin->hasPermission(\App\Auth\AdminPermission::AdminPanelView))
        <x-admin.tab wire:navigate :href="route('admin.settings.admin-panel')" :active="request()->routeIs('admin.settings.admin-panel*')" class="settings-section-tab">
            {{ __('Administrator panel') }}
        </x-admin.tab>
    @endif
    @if($settingsAdmin->hasPermission(\App\Auth\AdminPermission::SettingsView))
        <x-admin.tab wire:navigate :href="route('admin.settings.notifications')" :active="request()->routeIs('admin.settings.notifications*')" class="settings-section-tab">
            {{ __('Notifications') }}
        </x-admin.tab>
        <x-admin.tab wire:navigate :href="route('admin.settings.registration')" :active="request()->routeIs('admin.settings.registration*')" class="settings-section-tab">
            {{ __('Registration') }}
        </x-admin.tab>
        <x-admin.tab wire:navigate :href="route('admin.settings.game-accounts')" :active="request()->routeIs('admin.settings.game-accounts*')" class="settings-section-tab">
            {{ __('Game accounts') }}
        </x-admin.tab>
        <x-admin.tab wire:navigate :href="route('admin.settings.languages')" :active="request()->routeIs('admin.settings.languages*')" class="settings-section-tab">
            {{ __('Languages') }}
        </x-admin.tab>
    @endif
    @if($settingsAdmin->hasPermission(\App\Auth\AdminPermission::SecurityView))
        <x-admin.tab wire:navigate :href="route('admin.settings.security')" :active="request()->routeIs('admin.settings.security*')" class="settings-section-tab">
            {{ __('Security') }}
        </x-admin.tab>
    @endif
    @if($settingsAdmin->hasPermission(\App\Auth\AdminPermission::SystemView))
        <x-admin.tab wire:navigate :href="route('admin.settings.system')" :active="request()->routeIs('admin.settings.system')" class="settings-section-tab">
            {{ __('System information') }}
        </x-admin.tab>
    @endif
    @if($settingsAdmin->hasPermission(\App\Auth\AdminPermission::SystemUpdatesView))
        <x-admin.tab wire:navigate :href="route('admin.settings.system.updates.index')" :active="request()->routeIs('admin.settings.system.updates.*')" class="settings-section-tab">
            {{ __('System updates') }}
        </x-admin.tab>
    @endif
</x-admin.tabs>
