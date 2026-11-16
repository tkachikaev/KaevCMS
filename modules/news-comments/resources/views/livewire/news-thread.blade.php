<section class="news-comments panel" aria-labelledby="news-comments-title">
    <header class="news-comments-heading">
        <h2 id="news-comments-title">{{ __('module-news-comments::messages.public_title') }}</h2>
        <span class="news-comments-total">{{ $visibleTotal }}</span>
    </header>

    @error('action')
        <p class="news-comments-error" role="alert">{{ $message }}</p>
    @enderror

    @if($comments->isEmpty())
        <div class="news-comments-empty">{{ __('module-news-comments::messages.public_empty') }}</div>
    @else
        <ol class="news-comments-list">
            @foreach($comments as $comment)
                <li id="news-comment-{{ $comment->id }}"
                    class="news-comments-entry @if($highlightedCommentId === $comment->id) news-comments-highlighted @endif"
                    wire:key="news-comment-{{ $comment->id }}">
                    @include('module-news-comments::livewire._comment', ['entry' => $comment])
                </li>
            @endforeach
        </ol>
    @endif

    @if($comments->hasPages())
        <nav class="public-pagination news-comments-pages" aria-label="{{ __('module-news-comments::messages.pages_label') }}">
            <button class="button button-ghost" type="button" wire:click="previousPage('commentsPage')"
                @disabled($comments->onFirstPage())>{{ __('module-news-comments::messages.previous') }}</button>
            <span>{{ __('module-news-comments::messages.page_label', ['current' => $comments->currentPage(), 'total' => $comments->lastPage()]) }}</span>
            <button class="button button-ghost" type="button" wire:click="nextPage('commentsPage')"
                @disabled(! $comments->hasMorePages())>{{ __('module-news-comments::messages.next') }}</button>
        </nav>
    @endif

    @if($notice)
        <div class="news-comments-notice" role="status">{{ $notice }}</div>
    @endif
    @if($canWrite)
        <form class="news-comments-form news-comments-composer" wire:submit="send">
            @if($replyToId !== null)
                <div class="news-comments-replying-to">
                    <span>
                        @if($replyTarget !== null)
                            {{ __('module-news-comments::messages.replying_to') }}
                            <button type="button" class="news-comments-action news-comments-reference-link"
                                wire:click="jumpTo({{ $replyTarget->id }})">&#64;{{ $replyTarget->author?->name ?? __('module-news-comments::messages.deleted_user') }}</button>
                        @else
                            {{ __('module-news-comments::messages.reply_target_unavailable') }}
                        @endif
                    </span>
                    <button type="button" class="news-comments-reply-cancel" wire:click="cancelReply"
                        aria-label="{{ __('module-news-comments::messages.cancel_reply') }}"
                        title="{{ __('module-news-comments::messages.cancel_reply') }}">&times;</button>
                </div>
            @endif
            <label for="news_comment_new">{{ $replyToId !== null ? __('module-news-comments::messages.reply') : __('module-news-comments::messages.write_comment') }}</label>
            <textarea id="news_comment_new" rows="4" wire:model="body"
                maxlength="{{ $maximumLength }}"
                placeholder="{{ __('module-news-comments::messages.comment_placeholder') }}"
                required></textarea>
            @error('body')<p class="news-comments-error" role="alert">{{ $message }}</p>@enderror
            <div class="news-comments-form-actions">
                <small>{{ __('module-news-comments::messages.character_limit', ['count' => $maximumLength]) }}</small>
                <button class="button button-gold" type="submit" wire:loading.attr="disabled" wire:target="send"
                    @disabled($replyToId !== null && $replyTarget === null)>
                    {{ __('module-news-comments::messages.publish') }}
                </button>
            </div>
        </form>
    @elseif($viewerId === null)
        <div class="news-comments-guest">
            <p>{{ __('module-news-comments::messages.login_required') }}</p>
            <a class="button button-ghost" href="{{ route('login') }}">{{ __('module-news-comments::messages.login') }}</a>
        </div>
    @else
        <p class="news-comments-info">{{ __('module-news-comments::messages.not_allowed') }}</p>
    @endif
</section>

