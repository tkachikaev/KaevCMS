@extends('admin.layouts.panel')
@section('title', __('Pages'))
@section('description', __('Create multilingual information pages and add them to website navigation.'))
@section('content')
<div class="admin-overview content-toolbar">
    <div class="admin-overview-stat content-stat"><span>{{ __('Total') }}</span><strong>{{ $totalCount }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('Published') }}</span><strong>{{ $publishedCount }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('Drafts') }}</span><strong>{{ $draftCount }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('In navigation') }}</span><strong>{{ $menuCount }}</strong></div>
    <a wire:navigate class="button button-primary" href="{{ route('admin.pages.create') }}">{{ __('Create page') }}</a>
</div>

@if ($pages->isEmpty())
    <div class="admin-empty-state empty-state">
        <div class="empty-state-mark">P</div>
        <h2>{{ __('No pages yet') }}</h2>
        <p>{{ __('Create rules, contacts, privacy information or any other website page.') }}</p>
        <a wire:navigate class="button button-primary" href="{{ route('admin.pages.create') }}">{{ __('Create first page') }}</a>
    </div>
@else
    <div class="admin-card-list content-list">
        @foreach ($pages as $item)
            @php
                $itemTitle = $item->titleFor();
                $translationCodes = $item->translations->pluck('locale')->all();
            @endphp
            <article class="admin-card-row content-row">
                <a wire:navigate class="content-row-preview page-row-preview" href="{{ route('admin.pages.edit', $item) }}" aria-label="{{ __('Edit: :title', ['title' => $itemTitle]) }}">
                    <span>PAGE</span>
                </a>
                <div class="content-row-main">
                    <a wire:navigate class="content-row-title" href="{{ route('admin.pages.edit', $item) }}">{{ $itemTitle }}</a>
                    <p>/pages/{{ $item->slug }}</p>
                    <div class="content-row-meta">
                        <span>{{ __('Languages') }}: {{ implode(', ', array_map('strtoupper', $translationCodes)) }}</span>
                        <span>{{ __('Order') }}: {{ $item->sort_order }}</span>
                        @if ($item->show_in_header)<span>{{ __('Header') }}</span>@endif
                        @if ($item->show_in_footer)<span>{{ __('Footer') }}</span>@endif
                    </div>
                </div>
                <div class="content-row-publication">
                    <span @class(['publication-state', $item->isLive() ? 'published' : 'draft'])>{{ $item->publicationLabel() }}</span>
                    @if ($item->isLive())
                        <a href="{{ page_url($item) }}" target="_blank" rel="noopener">{{ __('Open page') }} ↗</a>
                    @endif
                </div>
                <div class="admin-row-actions content-row-actions">
                    <a wire:navigate class="button button-primary" href="{{ route('admin.pages.edit', $item) }}">{{ __('Edit') }}</a>
                    <button class="button button-danger" type="button" data-page-delete-open data-page-delete-title="{{ $itemTitle }}" data-page-delete-url="{{ route('admin.pages.destroy', $item) }}">{{ __('Delete') }}</button>
                </div>
            </article>
        @endforeach
    </div>

    @if ($pages->hasPages())
        <nav class="simple-pagination" aria-label="{{ __('Pagination') }}">
            <a wire:navigate @class(['button button-secondary', 'disabled' => $pages->onFirstPage()]) href="{{ $pages->previousPageUrl() ?? '#' }}">← {{ __('Previous') }}</a>
            <span>{{ __('Page :current of :last', ['current' => $pages->currentPage(), 'last' => $pages->lastPage()]) }}</span>
            <a wire:navigate @class(['button button-secondary', 'disabled' => ! $pages->hasMorePages()]) href="{{ $pages->nextPageUrl() ?? '#' }}">{{ __('Next') }} →</a>
        </nav>
    @endif
@endif

<dialog class="confirm-dialog" data-page-delete-dialog aria-labelledby="delete-page-title">
    <div class="confirm-dialog-card">
        <div class="confirm-dialog-copy">
            <span class="confirm-dialog-mark" aria-hidden="true">!</span>
            <div>
                <h2 id="delete-page-title">{{ __('Delete page?') }}</h2>
                <p>{{ __('The selected page and all its translations will be permanently deleted.') }}</p>
                <strong class="confirm-dialog-target" data-page-delete-title></strong>
            </div>
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
