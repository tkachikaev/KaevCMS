@extends('module-forum::layouts.app')
@section('title', __('module-forum::messages.moderation_queue'))
@section('content')
<nav class="forum-breadcrumb">
    <a href="{{ route('modules.forum.index') }}">{{ __('module-forum::messages.title') }}</a>
    <span>›</span>
    <a href="{{ route('modules.forum.sections.show', $section) }}">{{ $section->label() }}</a>
    <span>›</span><strong>{{ __('module-forum::messages.moderation_queue') }}</strong>
</nav>
<div class="forum-heading"><h1>{{ __('module-forum::messages.moderation_queue') }}</h1></div>
<p class="forum-desc">{{ __('module-forum::messages.moderation_queue_hint') }}</p>
@if(session('forum_status'))
    <p class="forum-notice" role="status">{{ session('forum_status') }}</p>
@endif
@if($errors->any())
    <div class="forum-notice forum-notice-error" role="alert">
        @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
    </div>
@endif
<div class="forum-panel">
    <div class="forum-panel-heading">{{ $section->label() }}</div>
    @forelse($topics as $topic)
        <div class="forum-topic-row">
            <div class="forum-topic-main">
                @if($topic->trashed())
                    <strong class="forum-topic-title">{{ $topic->title }}</strong>
                    <span class="forum-tag">{{ __('module-forum::messages.deleted_topic') }}</span>
                @else
                    <a class="forum-topic-title" href="{{ route('modules.forum.topics.show', $topic->id) }}">{{ $topic->title }}</a>
                    <span class="forum-tag">{{ __('module-forum::messages.hidden') }}</span>
                @endif
                <p class="forum-topic-meta">
                    {{ __('module-forum::messages.by_author', ['name' => $topic->author_name]) }}
                    · {{ $topic->created_at->format('d.m.Y H:i') }}
                </p>
            </div>
            <div class="forum-tools">
                @if($topic->trashed())
                    <form method="POST" action="{{ route('modules.forum.topics.moderate', ['topic' => $topic->id, 'action' => 'restore']) }}">
                        @csrf
                        <button class="forum-button forum-button-compact" type="submit">{{ __('module-forum::messages.action_restore') }}</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('modules.forum.topics.moderate', ['topic' => $topic->id, 'action' => 'unhide']) }}">
                        @csrf
                        <button class="forum-button forum-button-compact" type="submit">{{ __('module-forum::messages.action_unhide') }}</button>
                    </form>
                    <form method="POST" action="{{ route('modules.forum.topics.moderate', ['topic' => $topic->id, 'action' => 'delete']) }}">
                        @csrf
                        <button class="forum-button forum-button-compact forum-button-danger" type="submit">{{ __('module-forum::messages.action_delete') }}</button>
                    </form>
                @endif
            </div>
        </div>
    @empty
        <div class="forum-panel-body">{{ __('module-forum::messages.moderation_queue_empty') }}</div>
    @endforelse
</div>
@include('module-forum::partials.pagination', ['pager' => $topics])
@endsection
