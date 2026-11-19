@extends('module-forum::layouts.app')
@section('title', __('module-forum::messages.title'))
@section('content')
<div class="forum-heading">
    <h1>{{ __('module-forum::messages.title') }}</h1>
</div>
<p class="forum-desc">{{ __('module-forum::messages.empty_description') }}</p>

<div class="forum-panel">
    <div class="forum-panel-heading">{{ __('module-forum::messages.empty_title') }}</div>
    <div class="forum-panel-body">
        @if($actor->isOwner())
            <p>{{ __('module-forum::messages.owner_hint') }}</p>
        @elseif($actor->isGuest())
            <p>{{ __('module-forum::messages.no_author') }}</p>
        @else
            <p>{{ __('module-forum::messages.your_name') }}: <strong>{{ $actor->name() }}</strong></p>
        @endif

        @if($identities['admin'] !== null || $identities['user'] !== null)
            <div class="forum-actor">
                <span>{{ __('module-forum::messages.author') }}:</span>
                @foreach(['admin', 'user'] as $mode)
                    @if($identities[$mode] !== null && $actor->mode !== $mode)
                        <form method="POST" action="{{ route('modules.forum.author.choose') }}">
                            @csrf
                            <input type="hidden" name="mode" value="{{ $mode }}">
                            <button class="forum-button" type="submit">
                                {{ __('module-forum::messages.switch') }}:
                                {{ $mode === 'admin' ? $identities['admin']->name : $identities['user']->publicName() }}
                            </button>
                        </form>
                    @endif
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
