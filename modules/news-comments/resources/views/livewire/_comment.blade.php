<article class="news-comments-item" aria-label="{{ __('module-news-comments::messages.comment_label') }}">
    <header class="news-comments-meta">
        <strong>{{ $entry->author?->name ?? __('module-news-comments::messages.deleted_user') }}</strong>
        <time datetime="{{ $entry->created_at->toIso8601String() }}" title="{{ $entry->created_at->format('d.m.Y H:i') }}">
            {{ $entry->created_at->format('d.m.Y H:i') }}
        </time>
        @if($entry->edited_at !== null && $entry->deleted_by_author_at === null)
            <span class="news-comments-edited">{{ __('module-news-comments::messages.edited_label') }}</span>
        @endif
        @if($entry->status === 'pending')
            <span class="news-comments-state">{{ __('module-news-comments::messages.pending_label') }}</span>
        @endif
    </header>

    @if($entry->deleted_by_author_at !== null)
        <p class="news-comments-deleted">{{ __('module-news-comments::messages.deleted_label') }}</p>
    @elseif($editingId === $entry->id)
        <form class="news-comments-form news-comments-edit-form" wire:submit="saveEdit">
            <label for="news_comment_edit_{{ $entry->id }}">{{ __('module-news-comments::messages.edit') }}</label>
            <textarea
                id="news_comment_edit_{{ $entry->id }}"
                wire:model="editingBody"
                maxlength="{{ $maximumLength }}"
                rows="4"
                required
            ></textarea>
            @error('editingBody')<p class="news-comments-error" role="alert">{{ $message }}</p>@enderror
            <div class="news-comments-form-actions">
                <button class="button button-ghost" type="button" wire:click="cancelEdit">
                    {{ __('module-news-comments::messages.cancel') }}
                </button>
                <button class="button button-gold" type="submit" wire:loading.attr="disabled" wire:target="saveEdit">
                    {{ __('module-news-comments::messages.save_edit') }}
                </button>
            </div>
        </form>
    @else
        <p class="news-comments-body">{!! nl2br(e($entry->body ?? '')) !!}</p>
    @endif

    @if($entry->pending_body !== null && $entry->user_id === $viewerId && $entry->deleted_by_author_at === null)
        <div class="news-comments-review" role="status">
            <small>{{ __('module-news-comments::messages.edit_pending_label') }}</small>
            <p>{!! nl2br(e($entry->pending_body)) !!}</p>
        </div>
    @endif

    @if($canWrite && $entry->deleted_by_author_at === null)
        <footer class="news-comments-actions">
            @if(! $isReply && $entry->status === 'approved')
                <button type="button" class="news-comments-action" wire:click="openReply({{ $entry->id }})">
                    {{ __('module-news-comments::messages.reply') }}
                </button>
            @endif
            @if($entry->user_id === $viewerId)
                @if($canEdit && $entry->status === 'approved' && $entry->pending_body === null)
                    <button type="button" class="news-comments-action" wire:click="openEdit({{ $entry->id }})">
                        {{ __('module-news-comments::messages.edit') }}
                    </button>
                @endif
                <button type="button" class="news-comments-action news-comments-delete"
                    wire:click="deleteOwn({{ $entry->id }})"
                    wire:confirm="{{ __('module-news-comments::messages.player_delete_confirm') }}">
                    {{ __('module-news-comments::messages.delete') }}
                </button>
            @endif
        </footer>
    @endif
</article>
