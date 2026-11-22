@extends('module-forum::layouts.app')
@section('title', __('module-forum::messages.title'))
@section('content')
<div class="forum-heading forum-heading-search">
    <div class="forum-heading-copy">
        <h1>{{ __('module-forum::messages.title') }}</h1>
        <p class="forum-desc">{{ __('module-forum::messages.forum_description') }}</p>
    </div>
    <div class="forum-heading-side">
        @include('module-forum::partials.search-field')
        @if($actor->isOwner())
            <div class="forum-tools">
                <button type="button" class="forum-button forum-button-primary" data-forum-dialog-open="forum-category-create">
                    + {{ __('module-forum::messages.add_category') }}
                </button>
                <a class="forum-button" href="{{ route('modules.forum.trash') }}">{{ __('module-forum::messages.trash') }}</a>
            </div>
        @endif
    </div>
</div>

@if(session('forum_status'))
    <p class="forum-notice" role="status">{{ session('forum_status') }}</p>
@endif
@if($errors->any() && $actor->isOwner())
    <div class="forum-notice forum-notice-error" role="alert">
        @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
    </div>
@endif

@if($activityTabs !== [])
    @include('module-forum::partials.activity')
@endif

@if($categories->isEmpty())
    <div class="forum-panel">
        <div class="forum-panel-heading">{{ __('module-forum::messages.empty_title') }}</div>
        <div class="forum-panel-body">{{ __('module-forum::messages.empty_description') }}</div>
    </div>
@else
    @foreach($categories as $category)
        <section class="forum-category">
            <div class="forum-category-heading">
                <div>
                    <h2>{{ $category->label() }}</h2>
                    @if($category->description())<p>{{ $category->description() }}</p>@endif
                </div>
                @if($actor->isOwner())
                    <div class="forum-tools" aria-label="{{ __('module-forum::messages.category_actions') }}">
                        <button class="forum-button forum-button-compact" type="button" data-forum-dialog-open="forum-section-create-{{ $category->id }}" title="{{ __('module-forum::messages.add_section') }}">+ {{ __('module-forum::messages.section') }}</button>
                        <button class="forum-button forum-button-compact" type="button" data-forum-dialog-open="forum-category-edit-{{ $category->id }}">{{ __('module-forum::messages.edit') }}</button>
                        @foreach(['up' => '↑', 'down' => '↓'] as $direction => $arrow)
                            <form method="POST" action="{{ route('modules.forum.categories.move', $category) }}">
                                @csrf
                                <input type="hidden" name="direction" value="{{ $direction }}">
                                <button type="submit" class="forum-button forum-button-compact" title="{{ __('module-forum::messages.move_'.$direction) }}">{{ $arrow }}</button>
                            </form>
                        @endforeach
                        @if($category->sections->isEmpty())
                            <button class="forum-button forum-button-compact" type="button" data-forum-dialog-open="forum-category-delete-{{ $category->id }}">{{ __('module-forum::messages.delete') }}</button>
                        @endif
                    </div>
                @endif
            </div>
            <div class="forum-panel forum-section-list">
                @forelse($category->sections as $section)
                    @php($sectionActivity = $activity[$section->id] ?? ['posts' => 0, 'topic' => null, 'post' => null, 'user' => null, 'page' => 1])
                    <div class="forum-section-row">
                        <div class="forum-section-main">
                            <span class="forum-section-icon" aria-hidden="true">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linejoin="round" stroke-linecap="round">
                                    <path d="M20 13a3 3 0 0 1-3 3H9l-5 4v-4.8A3 3 0 0 1 3 13V6a3 3 0 0 1 3-3h11a3 3 0 0 1 3 3z"/>
                                    <path d="M7.5 7.5h8m-8 4h5"/>
                                </svg>
                            </span>
                            <div class="forum-section-copy">
                                <a class="forum-section-title" href="{{ route('modules.forum.sections.show', $section) }}">{{ $section->label() }}</a>
                                @if($section->description())<p>{{ $section->description() }}</p>@endif
                                @if($actor->isOwner())
                                    <div class="forum-tools forum-section-tools">
                                        <button class="forum-button forum-button-compact" type="button" data-forum-dialog-open="forum-section-edit-{{ $section->id }}">{{ __('module-forum::messages.edit') }}</button>
                                        @foreach(['up' => '↑', 'down' => '↓'] as $direction => $arrow)
                                            <form method="POST" action="{{ route('modules.forum.sections.move', $section) }}">
                                                @csrf
                                                <input type="hidden" name="direction" value="{{ $direction }}">
                                                <button class="forum-button forum-button-compact" type="submit" title="{{ __('module-forum::messages.move_'.$direction) }}">{{ $arrow }}</button>
                                            </form>
                                        @endforeach
                                        <button class="forum-button forum-button-compact" type="button" data-forum-dialog-open="forum-section-delete-{{ $section->id }}">{{ __('module-forum::messages.delete') }}</button>
                                    </div>
                                @endif
                            </div>
                        </div>
                        <dl class="forum-section-stats">
                            <div><dt>{{ __('module-forum::messages.stat_topics') }}</dt><dd>{{ \KaevCMS\Modules\Forum\Support\ForumFormat::count((int) $section->topics_count) }}</dd></div>
                            <div><dt>{{ __('module-forum::messages.stat_posts') }}</dt><dd>{{ \KaevCMS\Modules\Forum\Support\ForumFormat::count($sectionActivity['posts']) }}</dd></div>
                        </dl>
                        <div class="forum-section-last">
                            @if($sectionActivity['topic'] && $sectionActivity['post'])
                                @php($lastPost = $sectionActivity['post'])
                                @if($sectionActivity['user'])
                                    <x-account-avatar :user="$sectionActivity['user']" :fallback="$lastPost->author_name" class="forum-avatar forum-section-last-avatar" />
                                @else
                                    <span class="account-avatar forum-avatar forum-section-last-avatar" aria-hidden="true"><span>{{ mb_strtoupper(mb_substr($lastPost->author_name, 0, 1)) }}</span></span>
                                @endif
                                <div class="forum-section-last-copy">
                                    <a href="{{ route('modules.forum.topics.show', ['topic' => $sectionActivity['topic']->id, 'page' => $sectionActivity['page']]) }}#post-{{ $lastPost->id }}" title="{{ $sectionActivity['topic']->title }}">{{ $sectionActivity['topic']->title }}</a>
                                    <small>{{ \KaevCMS\Modules\Forum\Support\ForumFormat::when($lastPost->created_at) }} · <span>{{ $lastPost->author_name }}</span></small>
                                </div>
                            @else
                                <small class="forum-section-last-empty">{{ __('module-forum::messages.no_posts_yet') }}</small>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="forum-panel-body">{{ __('module-forum::messages.category_empty') }}</div>
                @endforelse
            </div>
        </section>
    @endforeach
@endif

@if($actor->isOwner())
    @include('module-forum::partials.category-dialog', ['id' => 'forum-category-create',
        'action' => route('modules.forum.categories.create'), 'method' => 'POST', 'item' => null])
    @foreach($categories as $category)
        @include('module-forum::partials.category-dialog', ['id' => 'forum-category-edit-'.$category->id,
            'action' => route('modules.forum.categories.update', $category), 'method' => 'PUT', 'item' => $category])
        @include('module-forum::partials.delete-dialog', ['id' => 'forum-category-delete-'.$category->id,
            'action' => route('modules.forum.categories.delete', $category), 'name' => $category->label()])
        @include('module-forum::partials.section-dialog', ['id' => 'forum-section-create-'.$category->id,
            'action' => route('modules.forum.sections.create', $category), 'method' => 'POST', 'item' => null])
        @foreach($category->sections as $section)
            @include('module-forum::partials.section-dialog', ['id' => 'forum-section-edit-'.$section->id,
                'action' => route('modules.forum.sections.update', $section), 'method' => 'PUT', 'item' => $section])
            @include('module-forum::partials.delete-dialog', ['id' => 'forum-section-delete-'.$section->id,
                'action' => route('modules.forum.sections.delete', $section), 'name' => $section->label()])
        @endforeach
    @endforeach
@endif
@endsection
