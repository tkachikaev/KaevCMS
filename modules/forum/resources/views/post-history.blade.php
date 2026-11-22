@extends('module-forum::layouts.app')
@section('title', __('module-forum::messages.revision_history'))
@section('content')
<nav class="forum-breadcrumb">
    <a href="{{ route('modules.forum.index') }}">{{ __('module-forum::messages.title') }}</a>
    <span>›</span><a href="{{ route('modules.forum.topics.show', $topic) }}">{{ $topic->title }}</a>
    <span>›</span><strong>{{ __('module-forum::messages.revision_history') }}</strong>
</nav>
<div class="forum-heading"><h1>{{ __('module-forum::messages.revision_history') }}</h1></div>
<p class="forum-desc">{{ __('module-forum::messages.revision_privacy') }}</p>

<div class="forum-panel forum-post-list">
    @forelse($revisions as $revision)
        <article class="forum-post">
            <header class="forum-post-header">
                <strong>{{ __('module-forum::messages.revision_'.$revision->action) }}</strong>
                <span>{{ $revision->changed_by_name }}</span>
                <span class="forum-post-date">{{ \Illuminate\Support\Carbon::parse($revision->created_at)->format('d.m.Y H:i') }}</span>
            </header>
            @if($revision->body_html !== null)
                <div class="forum-post-body forum-post-rich">{!! \KaevCMS\Modules\Forum\Support\ForumMedia::render(app(\App\Services\Html\SafeHtmlSanitizer::class)->sanitize($revision->body_html, \App\Services\Html\SafeHtmlSanitizer::PROFILE_FORUM), request()->getHost()) !!}</div>
            @else
                <div class="forum-post-body">{{ $revision->body_text }}</div>
            @endif
        </article>
    @empty
        <div class="forum-post">{{ __('module-forum::messages.no_revisions') }}</div>
    @endforelse
</div>
@include('module-forum::partials.pagination', ['pager' => $revisions])
<p><a class="forum-button" href="{{ route('modules.forum.topics.show', $topic) }}">{{ __('module-forum::messages.back_to_topic') }}</a></p>
@endsection
