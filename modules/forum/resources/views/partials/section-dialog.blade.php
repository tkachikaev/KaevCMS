<dialog class="forum-dialog" id="{{ $id }}">
    <form method="POST" action="{{ $action }}">
        @csrf
        @if($method === 'PUT') @method('PUT') @endif
        <h2>{{ $item ? __('module-forum::messages.edit_section') : __('module-forum::messages.add_section') }}</h2>
        @include('module-forum::partials.names', ['item' => $item])
        @if($item)
            <label class="forum-field">
                <span>{{ __('module-forum::messages.category') }}</span>
                <select name="category_id" required>
                    @foreach($categories as $choice)
                        <option value="{{ $choice->id }}" @selected($item->category_id === $choice->id)>{{ $choice->label() }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @include('module-forum::partials.permission-fields', ['item' => $item])
        <div class="forum-dialog-actions">
            <button type="button" class="forum-button" data-dialog-close>{{ __('module-forum::messages.cancel') }}</button>
            <button type="submit" class="forum-button forum-button-primary">{{ __('module-forum::messages.save') }}</button>
        </div>
    </form>
</dialog>
