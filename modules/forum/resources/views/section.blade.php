@extends('module-forum::layouts.app')
@section('title', $section->label())
@section('content')
<nav class="forum-breadcrumb">
    <a href="{{ route('modules.forum.index') }}">{{ __('module-forum::messages.title') }}</a>
    <span>›</span><span>{{ $category->label() }}</span><span>›</span><strong>{{ $section->label() }}</strong>
</nav>
<div class="forum-heading"><h1>{{ $section->label() }}</h1></div>
@if($section->description())<p class="forum-desc">{{ $section->description() }}</p>@endif
<div class="forum-panel">
    <div class="forum-panel-heading">{{ __('module-forum::messages.topics') }}</div>
    <div class="forum-panel-body">{{ __('module-forum::messages.no_topics') }}</div>
</div>
@endsection
