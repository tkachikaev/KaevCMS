@extends('module-forum::layouts.app')
@section('title', $topic->title)
@section('content')
@if($canReply)
@push('forum-editor-scripts')
    <script src="{{ asset('assets/admin/js/news-editor.js') }}?v={{ cms_version() }}" defer></script>
@endpush
@endif
<nav class="forum-breadcrumb">
    <a href="{{ route('modules.forum.index') }}">{{ __('module-forum::messages.title') }}</a>
    <span>›</span><a href="{{ route('modules.forum.sections.show', $section) }}">{{ $section->label() }}</a>
    <span>›</span><strong>{{ $topic->title }}</strong>
</nav>
<div class="forum-heading"><h1>{{ $topic->title }}</h1></div>
<p class="forum-desc">{{ __('module-forum::messages.replies_count', ['count' => $topic->reply_count]) }}</p>
@if($errors->any())
    <div class="forum-notice forum-notice-error" role="alert">
        @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
    </div>
@endif
<div class="forum-panel forum-post-list">
    @foreach($posts as $post)
        <article class="forum-post" id="post-{{ $post->id }}">
            <header class="forum-post-header">
                <span class="forum-post-author">{{ $post->author_name }}</span>
                @if($post->author_role === 'administrator')
                    <span class="forum-tag">{{ __('module-forum::messages.administrator') }}</span>
                @elseif($post->author_role === 'moderator')
                    <span class="forum-tag">{{ __('module-forum::messages.moderator') }}</span>
                @endif
                <span class="forum-post-date">{{ $post->created_at->format('d.m.Y H:i') }}</span>
            </header>
            @if($post->body_html !== null)
                <div class="forum-post-body forum-post-rich">{!! app(\App\Services\Html\SafeHtmlSanitizer::class)->sanitize($post->body_html, \App\Services\Html\SafeHtmlSanitizer::PROFILE_FORUM) !!}</div>
            @else
                <div class="forum-post-body">{{ $post->body_text }}</div>
            @endif
        </article>
    @endforeach
</div>
@include('module-forum::partials.pagination', ['pager' => $posts])
@if($canReply)
    <div class="forum-panel forum-reply-panel" id="reply">
        <div class="forum-panel-heading">{{ __('module-forum::messages.write_reply') }}</div>
        <form class="forum-compose" method="POST" action="{{ route('modules.forum.topics.reply', $topic) }}">
            @csrf
            <input type="hidden" name="submission_key" value="{{ old('submission_key', $submissionKey) }}">
            <p class="forum-posting-as">{{ __('module-forum::messages.posting_as', ['name' => $actor->name()]) }}</p>
            @include('module-forum::partials.editor', ['editorId' => 'forum-reply-editor', 'rows' => 6])
            <div class="forum-dialog-actions">
                <button class="forum-button forum-button-primary" type="submit">{{ __('module-forum::messages.send_reply') }}</button>
            </div>
        </form>
    </div>
@else
    <p class="forum-read-only">{{ __('module-forum::messages.read_only') }}</p>
@endif
@endsection
