@extends('admin.layouts.panel')
@section('title', __('module-news-comments::messages.admin_title'))
@section('description', __('module-news-comments::messages.admin_description'))
@section('content')
@if($settings === null)
    <div class="notice notice-error" role="alert">{{ __('module-news-comments::messages.unavailable') }}</div>
@else
    @php($tabUrl = static fn (string $tab): string => route('admin.module-pages.news-comments.index', ['adminPath' => $adminPath, 'tab' => $tab]))
    <x-admin.tabs :label="__('module-news-comments::messages.tabs_label')" class="news-comments-tabs">
        <x-admin.tab wire:navigate.preserve-scroll :href="$tabUrl('moderation')" :active="$activeTab === 'moderation'">
            {{ __('module-news-comments::messages.tab_moderation') }}
            @if($pendingCount > 0)<span class="admin-num">{{ format_number($pendingCount) }}</span>@endif
        </x-admin.tab>
        <x-admin.tab wire:navigate.preserve-scroll :href="$tabUrl('settings')" :active="$activeTab === 'settings'">
            {{ __('module-news-comments::messages.tab_settings') }}
        </x-admin.tab>
        <x-admin.tab wire:navigate.preserve-scroll :href="$tabUrl('news')" :active="$activeTab === 'news'">
            {{ __('module-news-comments::messages.tab_news') }}
        </x-admin.tab>
    </x-admin.tabs>

    @if(! $settings['enabled'])
        <div class="notice notice-warning" role="status">
            {{ __('module-news-comments::messages.disabled_notice') }}
            @if($canManage && $activeTab !== 'settings')
                <a wire:navigate href="{{ $tabUrl('settings') }}">{{ __('module-news-comments::messages.open_settings') }}</a>
            @endif
        </div>
    @endif

    @switch($activeTab)
        @case('settings')
            @include('module-news-comments::admin.settings')
            @break
        @case('news')
            @include('module-news-comments::admin.news')
            @break
        @default
            @include('module-news-comments::admin.moderation')
    @endswitch
@endif
@endsection

@push('head')
<link rel="stylesheet" href="{{ asset('assets/modules/news-comments-admin.css') }}?v={{ cms_version() }}" data-navigate-track>
@endpush

@push('scripts')
<script src="{{ asset('assets/modules/news-comments-admin.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
