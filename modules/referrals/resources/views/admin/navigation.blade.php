@push('head')
    <link rel="stylesheet" href="{{ asset('assets/modules/referrals-admin.css') }}?v={{ cms_version() }}" data-navigate-track>
@endpush
<x-admin.tabs :label="__('module-referrals::messages.admin_title')" class="settings-section-tabs">
    @foreach([
        'index' => 'general_settings',
        'invites.index' => 'invitation_journal',
        'grants.index' => 'reward_journal',
    ] as $destination => $label)
        <x-admin.tab wire:navigate :href="route('admin.module-pages.referrals.'.$destination, ['adminPath' => $adminPath])" :active="request()->routeIs('admin.module-pages.referrals.'.$destination)" class="settings-section-tab">{{ __('module-referrals::messages.'.$label) }}</x-admin.tab>
    @endforeach
</x-admin.tabs>
