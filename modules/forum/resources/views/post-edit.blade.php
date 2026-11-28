@extends('module-forum::layouts.app')
@section('title', __('module-forum::messages.edit_message'))
@section('content')

<nav class="forum-breadcrumb">
    <a href="{{ route('modules.forum.index') }}">{{ __('module-forum::messages.title') }}</a>
    <span>›</span><a href="{{ route('modules.forum.topics.show', $topic) }}">{{ $topic->title }}</a>
    <span>›</span><strong>{{ __('module-forum::messages.edit_message') }}</strong>
</nav>
<div class="forum-heading"><h1>{{ __('module-forum::messages.edit_message') }}</h1></div>

@if($errors->any())
    <div class="forum-notice forum-notice-error" role="alert">
        @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
    </div>
@endif
<div class="forum-panel">
    <form method="POST" action="{{ route('modules.forum.posts.update', $post) }}" class="forum-compose">
        @csrf
        @method('PATCH')
        <input type="hidden" name="version" value="{{ $post->edit_version }}">
        @include('module-forum::partials.editor', [
            'editorId' => 'forum-post-edit',
            'rows' => 8,
            'initialBodyHtml' => $post->body_html,
            'initialBodyText' => $post->body_text,
        ])
        <div class="forum-dialog-actions">
            <a class="forum-button" href="{{ route('modules.forum.topics.show', $topic) }}">{{ __('module-forum::messages.cancel') }}</a>
            <button class="forum-button forum-button-primary" type="submit">{{ __('module-forum::messages.save') }}</button>
        </div>
    </form>
</div>
@endsection
