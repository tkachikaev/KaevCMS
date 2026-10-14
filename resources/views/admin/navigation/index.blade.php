@extends('admin.layouts.panel')
@section('title', __('Navigation'))
@section('description', __('Add custom links to the public website header and footer.'))
@section('content')
<div class="admin-overview content-toolbar">
    <div class="admin-overview-stat content-stat"><span>{{ __('Total') }}</span><strong>{{ $links->total() }}</strong></div>
    <a wire:navigate class="button button-primary" href="{{ route('admin.navigation.create') }}">{{ __('Add link') }}</a>
</div>
@if ($links->isEmpty())
    <div class="admin-empty-state empty-state">
        <div class="empty-state-mark">↗</div>
        <h2>{{ __('No navigation links yet') }}</h2>
        <p>{{ __('Add a forum, Discord or another website link without editing the theme files.') }}</p>
        <a wire:navigate class="button button-primary" href="{{ route('admin.navigation.create') }}">{{ __('Add first link') }}</a>
    </div>
@else
    <div class="admin-card-list content-list">
        @foreach ($links as $item)
            <article class="admin-card-row content-row">
                <a wire:navigate class="content-row-preview page-row-preview navigation-link-preview" href="{{ route('admin.navigation.edit', $item) }}" aria-label="{{ __('Edit: :title', ['title' => $item->titleFor()]) }}"><svg class="navigation-link-preview-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.07 0l3-3a5 5 0 0 0-7.07-7.07l-1.71 1.71"/><path d="M14 11a5 5 0 0 0-7.07 0l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></a>
                <div class="content-row-main">
                    <a wire:navigate class="content-row-title" href="{{ route('admin.navigation.edit', $item) }}">{{ $item->titleFor() }}</a>
                    <p>{{ $item->url }}</p>
                    <div class="content-row-meta">
                        <span>{{ __('Order') }}: {{ $item->sort_order }}</span>
                        @if ($item->show_in_header)<span>{{ __('Header') }}</span>@endif
                        @if ($item->show_in_footer)<span>{{ __('Footer') }}</span>@endif
                        @if ($item->open_new_tab)<span>{{ __('New tab') }}</span>@endif
                    </div>
                </div>
                <div class="content-row-publication">
                    <span @class(['publication-state', $item->is_active ? 'published' : 'draft'])>{{ $item->is_active ? __('Enabled') : __('Disabled') }}</span>
                    @if (\App\Models\NavigationLink::isSafeUrl($item->url))
                        <a href="{{ $item->url }}" target="_blank" rel="noopener noreferrer">{{ __('Open link') }} ↗</a>
                    @endif
                </div>
                <div class="admin-row-actions content-row-actions">
                    <a wire:navigate class="button button-primary" href="{{ route('admin.navigation.edit', $item) }}">{{ __('Edit') }}</a>
                    <button class="button button-danger" type="button" data-page-delete-open data-page-delete-title="{{ $item->titleFor() }}" data-page-delete-url="{{ route('admin.navigation.destroy', $item) }}">{{ __('Delete') }}</button>
                </div>
            </article>
        @endforeach
    </div>
    <x-admin.pagination :paginator="$links" :aria-label="__('Pagination')" :previous-label="__('Previous')" :next-label="__('Next')" />
@endif
<dialog class="confirm-dialog" data-page-delete-dialog aria-labelledby="delete-navigation-title">
    <div class="confirm-dialog-card">
        <div class="confirm-dialog-copy">
            <span class="confirm-dialog-mark" aria-hidden="true">!</span>
            <div><h2 id="delete-navigation-title">{{ __('Delete this navigation link?') }}</h2><strong class="confirm-dialog-target" data-page-delete-title></strong></div>
        </div>
        <div class="confirm-dialog-actions">
            <button class="button button-secondary" type="button" data-page-delete-cancel>{{ __('Cancel') }}</button>
            <form method="POST" action="" data-page-delete-form>
                @csrf
                @method('DELETE')
                <button class="button button-danger" type="submit">{{ __('Yes, delete') }}</button>
            </form>
        </div>
    </div>
</dialog>
@endsection
@push('scripts')
<script src="{{ asset('assets/admin/js/page-actions.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
