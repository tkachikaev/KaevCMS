@extends('module-forum::layouts.app')
@section('title', $section->label())
@section('content')
<nav class="forum-breadcrumb">
    <a href="{{ route('modules.forum.index') }}">{{ __('module-forum::messages.title') }}</a>
    <span>›</span><span>{{ $category->label() }}</span><span>›</span><strong>{{ $section->label() }}</strong>
</nav>
<div class="forum-heading">
    <h1>{{ $section->label() }}</h1>
    <div class="forum-tools">
        @if($canModerate)
            <a class="forum-button" href="{{ route('modules.forum.sections.moderation', $section) }}">{{ __('module-forum::messages.moderation_queue') }}</a>
        @endif
        @if($canStart)
            <a class="forum-button forum-button-primary" href="{{ route('modules.forum.topics.create', $section) }}">
                + {{ __('module-forum::messages.new_topic') }}
            </a>
        @endif
    </div>
</div>
@if($section->description())<p class="forum-desc">{{ $section->description() }}</p>@endif
<div class="forum-panel">
    <div class="forum-panel-heading">{{ __('module-forum::messages.topics') }}</div>
    @forelse($topics as $topic)
        @php($row = $activity[$topic->id] ?? ['author' => null, 'last' => null, 'lastUser' => null, 'pages' => 1])
        @php($lastName = $row['last']?->author_name ?? $topic->last_author_name)
        <div class="forum-topic-row forum-topic-row-rich">
            @if($row['author'])
                <x-account-avatar :user="$row['author']" :fallback="$topic->author_name" class="forum-avatar forum-topic-avatar" />
            @else
                <span class="account-avatar forum-avatar forum-topic-avatar" aria-hidden="true"><span>{{ mb_strtoupper(mb_substr($topic->author_name, 0, 1)) }}</span></span>
            @endif
            <div class="forum-topic-main">
                <a class="forum-topic-title" href="{{ route('modules.forum.topics.show', $topic) }}">{{ $topic->title }}</a>
                @if($topic->is_pinned)<span class="forum-tag">{{ __('module-forum::messages.pinned') }}</span>@endif
                @if($topic->status === 'closed')<span class="forum-tag">{{ __('module-forum::messages.closed') }}</span>@endif
                <p class="forum-topic-meta">
                    <span class="forum-topic-author">{{ $topic->author_name }}</span> · {{ $topic->created_at->format('d.m.Y') }}
                    @if($row['pages'] > 1)
                        <span class="forum-topic-pages" aria-label="{{ __('module-forum::messages.topic_pages') }}">
                            @foreach(range(max(2, $row['pages'] - 2), $row['pages']) as $pageNumber)
                                <a href="{{ route('modules.forum.topics.show', ['topic' => $topic->id, 'page' => $pageNumber]) }}">{{ $pageNumber }}</a>
                            @endforeach
                        </span>
                    @endif
                </p>
            </div>
            <dl class="forum-topic-counts">
                <div><dt>{{ __('module-forum::messages.stat_replies') }}</dt><dd>{{ \KaevCMS\Modules\Forum\Support\ForumFormat::count((int) $topic->reply_count) }}</dd></div>
                <div><dt>{{ __('module-forum::messages.stat_views') }}</dt><dd>{{ \KaevCMS\Modules\Forum\Support\ForumFormat::count((int) $topic->view_count) }}</dd></div>
            </dl>
            <div class="forum-topic-last">
                <div class="forum-topic-last-copy">
                    @if($row['last'])
                        <a href="{{ route('modules.forum.topics.show', ['topic' => $topic->id, 'page' => $row['pages']]) }}#post-{{ $row['last']->id }}">{{ \KaevCMS\Modules\Forum\Support\ForumFormat::when($topic->last_post_at) }}</a>
                    @else
                        <span>{{ \KaevCMS\Modules\Forum\Support\ForumFormat::when($topic->last_post_at) }}</span>
                    @endif
                    <small>{{ $lastName }}</small>
                </div>
                @if($row['lastUser'])
                    <x-account-avatar :user="$row['lastUser']" :fallback="$lastName" class="forum-avatar forum-topic-last-avatar" />
                @else
                    <span class="account-avatar forum-avatar forum-topic-last-avatar" aria-hidden="true"><span>{{ mb_strtoupper(mb_substr((string) $lastName, 0, 1)) }}</span></span>
                @endif
            </div>
        </div>
    @empty
        <div class="forum-panel-body">{{ __('module-forum::messages.no_topics') }}</div>
    @endforelse
</div>
@include('module-forum::partials.pagination', ['pager' => $topics])
@endsection
