@extends('module-forum::layouts.app')
@section('title', __('module-forum::messages.new_topic'))
@section('content')
@push('forum-editor-scripts')
    <script src="{{ asset('assets/admin/js/news-editor.js') }}?v={{ cms_version() }}" defer></script>
@endpush
<nav class="forum-breadcrumb">
    <a href="{{ route('modules.forum.index') }}">{{ __('module-forum::messages.title') }}</a>
    <span>›</span><a href="{{ route('modules.forum.sections.show', $section) }}">{{ $section->label() }}</a>
    <span>›</span><strong>{{ __('module-forum::messages.new_topic') }}</strong>
</nav>
<div class="forum-heading"><h1>{{ __('module-forum::messages.new_topic') }}</h1></div>
<p class="forum-desc">{{ __('module-forum::messages.posting_as', ['name' => $actor->name()]) }}</p>
@if($errors->any())
    <div class="forum-notice forum-notice-error" role="alert">
        @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
    </div>
@endif
<div class="forum-panel">
    <form method="POST" action="{{ route('modules.forum.topics.store', $section) }}" class="forum-compose">
        @csrf
        <input type="hidden" name="submission_key" value="{{ old('submission_key', $submissionKey) }}">
        <label class="forum-compose-field">
            <span>{{ __('module-forum::messages.topic_title') }}</span>
            <input type="text" name="title" maxlength="160" minlength="3" required value="{{ old('title') }}">
        </label>
        @include('module-forum::partials.editor', ['editorId' => 'forum-topic-editor', 'rows' => 10])
        <p class="forum-help">{{ __('module-forum::messages.rich_text_hint') }}</p>
        <div class="forum-dialog-actions">
            <a class="forum-button" href="{{ route('modules.forum.sections.show', $section) }}">{{ __('module-forum::messages.cancel') }}</a>
            <button class="forum-button forum-button-primary" type="submit">{{ __('module-forum::messages.publish_topic') }}</button>
        </div>
    </form>
</div>
@endsection
