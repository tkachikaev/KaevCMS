<dialog class="forum-dialog forum-dialog-small" id="{{ $id }}">
    <form method="POST" action="{{ $action }}">
        @csrf
        @method('DELETE')
        <h2>{{ __('module-forum::messages.delete_title') }}</h2>
        <p>{{ __('module-forum::messages.delete_question', ['name' => $name]) }}</p>
        <label class="forum-confirm"><input type="checkbox" name="confirm" value="1" required> {{ __('module-forum::messages.delete_confirm') }}</label>
        <div class="forum-dialog-actions">
            <button type="button" class="forum-button" data-dialog-close>{{ __('module-forum::messages.cancel') }}</button>
            <button type="submit" class="forum-button forum-button-danger">{{ __('module-forum::messages.delete') }}</button>
        </div>
    </form>
</dialog>
