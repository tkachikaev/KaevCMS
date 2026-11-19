<dialog class="forum-dialog" id="{{ $id }}">
    <form method="POST" action="{{ $action }}">
        @csrf
        @if($method === 'PUT') @method('PUT') @endif
        <h2>{{ $item ? __('module-forum::messages.edit_category') : __('module-forum::messages.add_category') }}</h2>
        @include('module-forum::partials.names', ['item' => $item])
        <div class="forum-dialog-actions">
            <button type="button" class="forum-button" data-dialog-close>{{ __('module-forum::messages.cancel') }}</button>
            <button type="submit" class="forum-button forum-button-primary">{{ __('module-forum::messages.save') }}</button>
        </div>
    </form>
</dialog>
