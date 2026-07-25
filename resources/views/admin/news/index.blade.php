@extends('admin.layouts.panel')

@section('title', __('News'))
@section('description', __('Create, format, edit and publish news for the public website.'))

@section('content')
<div class="admin-overview content-toolbar">
    <div class="admin-overview-stat content-stat"><span>{{ __('Total') }}</span><strong>{{ $totalCount }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('Published') }}</span><strong>{{ $publishedCount }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('Scheduled') }}</span><strong>{{ $scheduledCount }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('Drafts') }}</span><strong>{{ $draftCount }}</strong></div>
    <a wire:navigate class="button button-primary" href="{{ route('admin.news.create') }}">{{ __('Create news') }}</a>
</div>

@if ($news->isEmpty())
    <div class="admin-empty-state empty-state">
        <div class="empty-state-mark" aria-hidden="true">N</div>
        <h2>{{ __('No news yet') }}</h2>
        <p>{{ __('Create the first article, add an image and publish it on the website.') }}</p>
        <a wire:navigate class="button button-primary" href="{{ route('admin.news.create') }}">{{ __('Create first news') }}</a>
    </div>
@else
    <div class="admin-card-list content-list">
        @foreach ($news as $item)
            @php
                $itemTitle = $item->titleFor(app()->getLocale());
                $itemExcerpt = $item->excerptFor(app()->getLocale());
            @endphp
            <article class="admin-card-row content-row">
                <a wire:navigate class="content-row-preview" href="{{ route('admin.news.edit', $item) }}" aria-label="{{ __('Edit: :title', ['title' => $itemTitle]) }}">
                    @if ($item->coverUrl())
                        <img src="{{ $item->coverUrl() }}" alt="">
                    @else
                        <span>{{ __('No image') }}</span>
                    @endif
                </a>

                <div class="content-row-main">
                    <a wire:navigate class="content-row-title" href="{{ route('admin.news.edit', $item) }}">{{ $itemTitle }}</a>
                    <p>{{ $itemExcerpt ?: __('No short description.') }}</p>
                    <div class="content-row-meta">
                        <span>/{{ app()->getLocale() }}/news/{{ $item->slugFor(app()->getLocale()) }}</span>
                        <span>{{ __('Updated :date', ['date' => $item->updated_at->format('d.m.Y H:i')]) }}</span>
                    </div>
                </div>

                <div class="content-row-publication">
                    <span class="publication-state {{ $item->publicationState() }}">{{ $item->publicationLabel() }}</span>
                    <time>{{ $item->published_at?->format('d.m.Y H:i') ?: __('Date not set') }}</time>
                </div>

                <div class="admin-row-actions content-row-actions">
                    <a wire:navigate class="button button-primary" href="{{ route('admin.news.edit', $item) }}">{{ __('Edit') }}</a>
                    <button class="button button-danger" type="button" data-news-delete-open data-news-delete-title="{{ $itemTitle }}" data-news-delete-url="{{ route('admin.news.destroy', $item) }}">{{ __('Delete') }}</button>
                </div>
            </article>
        @endforeach
    </div>

    @if ($news->hasPages())
        @php
            $firstPage = max(1, $news->currentPage() - 2);
            $lastPage = min($news->lastPage(), $news->currentPage() + 2);
        @endphp
        <nav class="simple-pagination" aria-label="{{ __('Page navigation') }}">
            @if ($news->onFirstPage())
                <span class="button button-secondary disabled">← {{ __('Back') }}</span>
            @else
                <a wire:navigate class="button button-secondary" href="{{ $news->previousPageUrl() }}" rel="prev">← {{ __('Back') }}</a>
            @endif
            <div class="pagination-pages" aria-label="{{ __('Pages') }}">
                @foreach ($news->getUrlRange($firstPage, $lastPage) as $page => $url)
                    @if ($page === $news->currentPage())
                        <span class="pagination-page active" aria-current="page">{{ $page }}</span>
                    @else
                        <a wire:navigate class="pagination-page" href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            </div>
            @if ($news->hasMorePages())
                <a wire:navigate class="button button-secondary" href="{{ $news->nextPageUrl() }}" rel="next">{{ __('Next') }} →</a>
            @else
                <span class="button button-secondary disabled">{{ __('Next') }} →</span>
            @endif
        </nav>
    @endif

    <dialog class="confirm-dialog" data-news-delete-dialog aria-labelledby="delete-news-title">
        <div class="confirm-dialog-card">
            <div class="confirm-dialog-copy">
                <span class="confirm-dialog-mark" aria-hidden="true">!</span>
                <div>
                    <h2 id="delete-news-title">{{ __('Delete news?') }}</h2>
                    <p>{!! __('The news “<strong data-news-delete-title></strong>” and its unused images will be deleted. This action cannot be undone.') !!}</p>
                </div>
            </div>
            <div class="confirm-dialog-actions">
                <button class="button button-secondary" type="button" data-news-delete-cancel>{{ __('Cancel') }}</button>
                <form method="POST" action="" data-news-delete-form>
                    @csrf
                    @method('DELETE')
                    <button class="button button-danger" type="submit">{{ __('Yes, delete') }}</button>
                </form>
            </div>
        </div>
    </dialog>
@endif
@endsection

@push('scripts')
<script src="{{ asset('assets/admin/js/news-actions.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
