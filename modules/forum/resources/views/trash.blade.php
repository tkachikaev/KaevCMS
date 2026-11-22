@extends('module-forum::layouts.app')
@section('title', __('module-forum::messages.trash'))
@section('content')
<nav class="forum-breadcrumb"><a href="{{ route('modules.forum.index') }}">{{ __('module-forum::messages.title') }}</a> › {{ __('module-forum::messages.trash') }}</nav>
<div class="forum-heading"><h1>{{ __('module-forum::messages.trash') }}</h1></div>
<p class="forum-desc">{{ __('module-forum::messages.trash_hint') }}</p>
<div class="forum-panel">
    <div class="forum-panel-heading">{{ __('module-forum::messages.categories') }}</div>
    @forelse($categories as $category)
        <div class="forum-section-row"><strong>{{ $category->label() }}</strong>
            <form method="POST" action="{{ route('modules.forum.categories.restore', $category->id) }}">
                @csrf
                <button class="forum-button" type="submit">{{ __('module-forum::messages.restore') }}</button>
            </form>
        </div>
    @empty
        <div class="forum-panel-body">{{ __('module-forum::messages.no_deleted') }}</div>
    @endforelse
</div>
<div class="forum-panel forum-trash-panel">
    <div class="forum-panel-heading">{{ __('module-forum::messages.sections') }}</div>
    @forelse($sections as $section)
        <div class="forum-section-row"><strong>{{ $section->label() }}</strong>
            <form method="POST" action="{{ route('modules.forum.sections.restore', $section->id) }}">
                @csrf
                <button class="forum-button" type="submit">{{ __('module-forum::messages.restore') }}</button>
            </form>
        </div>
    @empty
        <div class="forum-panel-body">{{ __('module-forum::messages.no_deleted') }}</div>
    @endforelse
</div>
<div class="forum-panel forum-trash-panel">
    <div class="forum-panel-heading">{{ __('module-forum::messages.deleted_topics') }}</div>
    @forelse($topics as $deletedTopic)
        <div class="forum-section-row">
            <div class="forum-trash-topic">
                <strong>{{ $deletedTopic->title }}</strong>
                <small>{{ $deletedTopic->section?->label() }} · {{ $deletedTopic->author_name }} · {{ __('module-forum::messages.deleted_at', ['date' => \KaevCMS\Modules\Forum\Support\ForumFormat::when($deletedTopic->deleted_at)]) }}</small>
            </div>
            <form method="POST" action="{{ route('modules.forum.topics.moderate', ['topic' => $deletedTopic->id, 'action' => 'restore']) }}">
                @csrf
                <button class="forum-button" type="submit">{{ __('module-forum::messages.restore') }}</button>
            </form>
        </div>
    @empty
        <div class="forum-panel-body">{{ __('module-forum::messages.no_deleted') }}</div>
    @endforelse
</div>
@endsection
