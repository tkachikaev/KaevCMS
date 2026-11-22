{{-- A native two-step confirmation works without JavaScript or custom dialogs. --}}
<details class="forum-inline-confirm">
    <summary class="forum-button forum-button-compact forum-button-danger">{{ $label }}</summary>
    <form method="POST" action="{{ $action }}" class="forum-confirm-content">
        @csrf
        @if(($method ?? 'POST') !== 'POST')
            @method($method)
        @endif
        @foreach(($hidden ?? []) as $field => $value)
            <input type="hidden" name="{{ $field }}" value="{{ $value }}">
        @endforeach
        <p>{{ $question }}</p>
        <button type="submit" class="forum-button forum-button-danger">
            {{ __('module-forum::messages.confirm_delete_action') }}
        </button>
    </form>
</details>
