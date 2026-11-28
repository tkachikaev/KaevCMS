@php($adminPath = request()->route('adminPath'))
@push('head')
    <link rel="stylesheet" href="{{ asset('assets/modules/donations-admin.css') }}?v={{ cms_version() }}" data-navigate-track>
@endpush
<x-admin.tabs :label="__('module-voting::messages.title')" class="settings-section-tabs" data-testid="voting-section-tabs">
    @foreach([
        'providers' => ['admin.module-pages.voting.index', 'providers'],
        'rewards' => ['admin.module-pages.voting.rewards.index', 'rewards'],
        'history' => ['admin.module-pages.voting.history.index', 'history'],
    ] as $section => [$destination, $label])
        <x-admin.tab
            wire:navigate
            :href="route($destination, ['adminPath' => $adminPath])"
            :active="request()->routeIs($destination, 'admin.module-pages.voting.'.$section.'.*')"
            class="settings-section-tab"
        >{{ __('module-voting::messages.'.$label) }}</x-admin.tab>
    @endforeach
</x-admin.tabs>
