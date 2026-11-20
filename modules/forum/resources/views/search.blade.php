@extends('module-forum::layouts.app')
@section('title', __('module-forum::messages.forum_search'))
@section('content')
<nav class="forum-breadcrumb">
    <a href="{{ route('modules.forum.index') }}">{{ __('module-forum::messages.title') }}</a>
    <span>›</span><strong>{{ __('module-forum::messages.forum_search') }}</strong>
</nav>
<div class="forum-heading"><h1>{{ __('module-forum::messages.forum_search') }}</h1></div>
@if($errors->any())
    <div class="forum-notice forum-notice-error" role="alert">
        @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
    </div>
@endif
<form class="forum-search-form forum-panel" action="{{ route('modules.forum.search') }}" method="GET" role="search">
    <label for="forum-search-query">{{ __('module-forum::messages.search_query') }}</label>
    <input id="forum-search-query" type="search" name="q" minlength="2" maxlength="80" value="{{ $term }}"
           placeholder="{{ __('module-forum::messages.search_placeholder') }}">
    <button class="forum-button forum-button-primary" type="submit">{{ __('module-forum::messages.forum_search') }}</button>
</form>
<div class="forum-panel">
    <div class="forum-panel-heading">{{ __('module-forum::messages.search_results') }}</div>
    @if($term === '')
        <div class="forum-panel-body">{{ __('module-forum::messages.search_prompt') }}</div>
    @elseif($results === null || $results->isEmpty())
        <div class="forum-panel-body">{{ __('module-forum::messages.search_empty') }}</div>
    @else
        @foreach($results as $topic)
            <div class="forum-topic-row">
                <div class="forum-topic-main">
                    <a class="forum-topic-title" href="{{ route('modules.forum.topics.show', $topic) }}">{{ $topic->title }}</a>
                    <p class="forum-topic-meta">
                        {{ $topic->section->label() }}
                        · {{ __('module-forum::messages.by_author', ['name' => $topic->author_name]) }}
                    </p>
                </div>
                <div class="forum-topic-stats">
                    <span>{{ __('module-forum::messages.replies_count', ['count' => $topic->reply_count]) }}</span>
                    <small>{{ $topic->last_post_at->format('d.m.Y H:i') }}</small>
                </div>
            </div>
        @endforeach
    @endif
</div>
@if($results !== null)
    @include('module-forum::partials.pagination', ['pager' => $results])
@endif
@endsection
