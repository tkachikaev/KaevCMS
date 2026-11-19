@if($identities['admin'] !== null && $identities['user'] !== null)
    <div class="forum-actor-switch">
        <span>{{ __('module-forum::messages.author') }}: <strong>{{ $actor->name() ?? __('module-forum::messages.guest') }}</strong></span>
        @foreach(['admin', 'user'] as $mode)
            @if($actor->mode !== $mode)
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
