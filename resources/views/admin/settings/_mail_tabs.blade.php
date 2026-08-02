<x-admin.tabs :label="__('Mail sections')" class="mail-template-tabs">
    <x-admin.tab wire:navigate :href="route('admin.settings.mail')" :active="request()->routeIs('admin.settings.mail')" class="mail-template-tab">
        {{ __('Connection') }}
    </x-admin.tab>

    <x-admin.tab wire:navigate :href="route('admin.settings.mail.delivery')" :active="request()->routeIs('admin.settings.mail.delivery')" class="mail-template-tab">
        {{ __('Delivery') }}
    </x-admin.tab>

    @foreach ($mailTemplates as $templateKey => $item)
        <x-admin.tab
            wire:navigate
            :href="route('admin.settings.mail.template', ['template' => $templateKey, 'locale' => $templateLocale ?? app()->getLocale()])"
            :active="request()->routeIs('admin.settings.mail.template') && request()->route('template') === $templateKey"
            class="mail-template-tab"
        >
            {{ $item['title'] }}
        </x-admin.tab>
    @endforeach

    <x-admin.tab wire:navigate :href="route('admin.settings.mail.custom')" :active="request()->routeIs('admin.settings.mail.custom') || request()->routeIs('admin.settings.mail.custom.send')" class="mail-template-tab">
        {{ __('Send email') }}
    </x-admin.tab>
</x-admin.tabs>
