@extends('module-forum::layouts.app')
@section('title', $section->label())
@section('content')
<nav class="forum-breadcrumb">
    <a href="{{ route('modules.forum.index') }}">{{ __('module-forum::messages.title') }}</a>
    <span>›</span><span>{{ $category->label() }}</span><span>›</span><strong>{{ $section->label() }}</strong>
</nav>
<div class="forum-heading">
    <h1>{{ $section->label() }}</h1>
    @if($canStart)
        <a class="forum-button forum-button-primary" href="{{ route('modules.forum.topics.create', $section) }}">
            + {{ __('module-forum::messages.new_topic') }}
        </a>
    @endif
</div>
@if($section->description())<p class="forum-desc">{{ $section->description() }}</p>@endif
<div class="forum-panel">
    <div class="forum-panel-heading">{{ __('module-forum::messages.topics') }}</div>
    @forelse($topics as $topic)
        <div class="forum-topic-row">
            <div class="forum-topic-main">
                <a class="forum-topic-title" href="{{ route('modules.forum.topics.show', $topic) }}">{{ $topic->title }}</a>
                <p class="forum-topic-meta">{{ __('module-forum::messages.by_author', ['name' => $topic->author_name]) }}
                    · {{ $topic->created_at->format('d.m.Y H:i') }}</p>
            </div>
            <div class="forum-topic-stats">
                <span>{{ __('module-forum::messages.replies_count', ['count' => $topic->reply_count]) }}</span>
                <small>{{ __('module-forum::messages.last_reply', ['name' => $topic->last_author_name]) }}<br>
                    {{ $topic->last_post_at->format('d.m.Y H:i') }}</small>
            </div>
        </div>
    @empty
        <div class="forum-panel-body">{{ __('module-forum::messages.no_topics') }}</div>
    @endforelse
</div>
@include('module-forum::partials.pagination', ['pager' => $topics])
@endsection
