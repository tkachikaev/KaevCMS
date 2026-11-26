@php($adminPath = request()->route('adminPath'))
<x-admin.tabs :label="__('module-voting::messages.title')" class="settings-section-tabs">
    <x-admin.tab wire:navigate :href="route('admin.module-pages.voting.index', ['adminPath' => $adminPath])" :active="request()->routeIs('admin.module-pages.voting.index', 'admin.module-pages.voting.providers.*')" class="settings-section-tab">{{ __('module-voting::messages.providers') }}</x-admin.tab>
    <x-admin.tab wire:navigate :href="route('admin.module-pages.voting.rewards.index', ['adminPath' => $adminPath])" :active="request()->routeIs('admin.module-pages.voting.rewards.*')" class="settings-section-tab">{{ __('module-voting::messages.rewards') }}</x-admin.tab>
    <x-admin.tab wire:navigate :href="route('admin.module-pages.voting.history.index', ['adminPath' => $adminPath])" :active="request()->routeIs('admin.module-pages.voting.history.*')" class="settings-section-tab">{{ __('module-voting::messages.history') }}</x-admin.tab>
</x-admin.tabs>
