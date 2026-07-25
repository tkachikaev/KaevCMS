@extends('theme::layouts.app')
@section('title', __('News').' — '.site_name())
@section('content')
<section class="page-hero">
    <div class="container">
        <p class="eyebrow">{{ __('PROJECT CHRONICLE') }}</p>
        <h1>{{ __('News') }}</h1>
    </div>
</section>
<section class="container page-content">
    <div class="public-news-list">
        @forelse ($news as $item)
            <article class="panel public-news-card">
                <a wire:navigate.hover class="public-news-cover" href="{{ news_url($item) }}" aria-label="{{ $item->titleFor() }}">
                    @if ($item->coverUrl())
                        <img src="{{ $item->coverUrl() }}" alt="">
                    @else
                        <span>LINEAGE II</span>
                    @endif
                </a>
                <div class="public-news-copy">
                    <time>{{ $item->published_at?->format('d.m.Y') }}</time>
                    <h2><a wire:navigate.hover href="{{ news_url($item) }}">{{ $item->titleFor() }}</a></h2>
                    <p>{{ $item->excerptFor() }}</p>
                    <a wire:navigate.hover class="public-news-more" href="{{ news_url($item) }}">{{ __('Read news →') }}</a>
                </div>
            </article>
        @empty
            <div class="panel prose"><p>{{ __('There is no news yet.') }}</p></div>
        @endforelse
    </div>

    @if ($news->hasPages())
        <div class="public-pagination">
            @if (! $news->onFirstPage())
                <a wire:navigate.hover class="button button-ghost" href="{{ $news->previousPageUrl() }}">← {{ __('Back') }}</a>
            @endif
            <span>{{ __('Page :current of :last', ['current' => $news->currentPage(), 'last' => $news->lastPage()]) }}</span>
            @if ($news->hasMorePages())
                <a wire:navigate.hover class="button button-ghost" href="{{ $news->nextPageUrl() }}">{{ __('Next') }} →</a>
            @endif
        </div>
    @endif
</section>
@endsection
