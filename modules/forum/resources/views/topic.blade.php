@extends('module-forum::layouts.app')
@section('title', $topic->title)
@section('content')
@if($canReply)
@push('forum-editor-scripts')
    <script src="{{ asset('assets/modules/forum/editor.js') }}?v={{ cms_version() }}" defer></script>
    <script src="{{ asset('assets/admin/js/news-editor.js') }}?v={{ cms_version() }}" defer></script>
@endpush
@endif
<nav class="forum-breadcrumb">
    <a href="{{ route('modules.forum.index') }}">{{ __('module-forum::messages.title') }}</a>
    <span>›</span><a href="{{ route('modules.forum.sections.show', $section) }}">{{ $section->label() }}</a>
    <span>›</span><strong>{{ $topic->title }}</strong>
</nav>
<div class="forum-heading">
    <h1>{{ $topic->title }}</h1>
    <div class="forum-tools">
        @if($topic->is_pinned)<span class="forum-tag">{{ __('module-forum::messages.pinned') }}</span>@endif
        @if($topic->status === 'closed')<span class="forum-tag">{{ __('module-forum::messages.closed') }}</span>@endif
        @if($topic->hidden_at !== null)<span class="forum-tag">{{ __('module-forum::messages.hidden') }}</span>@endif
    </div>
</div>
<p class="forum-desc">{{ __('module-forum::messages.replies_count', ['count' => $topic->reply_count]) }}</p>
@if(session('forum_status'))<p class="forum-notice" role="status">{{ session('forum_status') }}</p>@endif
@if($topic->hidden_at !== null)
    <p class="forum-notice forum-notice-warning">{{ __('module-forum::messages.hidden_moderator_notice') }}</p>
@endif
@if($canModerate)
    <details class="forum-topic-moderation forum-panel">
        <summary class="forum-panel-heading">{{ __('module-forum::messages.topic_actions') }}</summary>
        <div class="forum-topic-moderation-content">
            <div class="forum-tools">
                @foreach([
                    $topic->status === 'closed' ? 'reopen' : 'close',
                    $topic->is_pinned ? 'unpin' : 'pin',
                    $topic->hidden_at !== null ? 'unhide' : 'hide',
                    'delete',
                ] as $action)
                    @if($action === 'delete')
                        @include('module-forum::partials.confirm-action', [
                            'action' => route('modules.forum.topics.moderate', ['topic' => $topic->id, 'action' => 'delete']),
                            'label' => __('module-forum::messages.action_delete'),
                            'question' => __('module-forum::messages.confirm_delete_topic'),
                        ])
                    @else
                        <form method="POST" action="{{ route('modules.forum.topics.moderate', ['topic' => $topic->id, 'action' => $action]) }}">
                            @csrf
                            <button type="submit" class="forum-button forum-button-compact">
                                {{ __('module-forum::messages.action_'.$action) }}
                            </button>
                        </form>
                    @endif
                @endforeach
            </div>
            @if($moveSections->isNotEmpty())
                <form class="forum-move-form" method="POST" action="{{ route('modules.forum.topics.move', $topic->id) }}">
                    @csrf
                    <label for="forum-move-target">{{ __('module-forum::messages.move_to_section') }}</label>
                    <select id="forum-move-target" name="section_id" required>
                        <option value="">{{ __('module-forum::messages.choose_section') }}</option>
                        @foreach($moveSections as $destination)
                            <option value="{{ $destination->id }}" @selected((int) old('section_id') === (int) $destination->id)>
                                {{ $destination->label() }}
                            </option>
                        @endforeach
                    </select>
                    @if($actor->isOwner())
                        <label class="forum-move-confirm">
                            <input type="checkbox" name="confirm_exposure" value="1">
                            {{ __('module-forum::messages.confirm_visibility_expansion') }}
                        </label>
                    @endif
                    <button class="forum-button forum-button-primary" type="submit">{{ __('module-forum::messages.action_move') }}</button>
                </form>
            @endif
        </div>
    </details>
@endif
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
            @if($post->removed_at !== null)
                <div class="forum-post-body forum-post-removed">{{ __('module-forum::messages.deleted_by_'.($post->removed_by ?? 'moderator')) }}</div>
            @elseif($post->body_html !== null)
                <div class="forum-post-body forum-post-rich">{!! app(\App\Services\Html\SafeHtmlSanitizer::class)->sanitize($post->body_html, \App\Services\Html\SafeHtmlSanitizer::PROFILE_FORUM) !!}</div>
            @else
                <div class="forum-post-body">{{ $post->body_text }}</div>
            @endif
            @if($post->edited_at !== null && $post->removed_at === null)
                <small class="forum-post-edited">{{ __('module-forum::messages.edited') }}</small>
            @endif
            @if($changes->canChange($actor, $post, $topic, $section) || $canViewHistory)
                <div class="forum-post-tools">
                    @if($changes->canChange($actor, $post, $topic, $section))
                        <a class="forum-button forum-button-compact" href="{{ route('modules.forum.posts.edit', $post) }}">{{ __('module-forum::messages.edit') }}</a>
                        @include('module-forum::partials.confirm-action', [
                            'action' => route('modules.forum.posts.destroy', $post),
                            'method' => 'DELETE',
                            'hidden' => ['version' => $post->edit_version],
                            'label' => __('module-forum::messages.delete'),
                            'question' => __('module-forum::messages.confirm_delete_post'),
                        ])
                    @endif
                    @if($canViewHistory)
                        <a class="forum-button forum-button-compact" href="{{ route('modules.forum.posts.history', $post) }}">{{ __('module-forum::messages.revision_history') }}</a>
                    @endif
                </div>
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
