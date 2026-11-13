<section class="news-comments panel" aria-labelledby="news-comments-title">
    <header class="news-comments-heading">
        <h2 id="news-comments-title">{{ __('module-news-comments::messages.public_title') }}</h2>
        <span class="news-comments-total">{{ $visibleTotal }}</span>
    </header>

    @if($notice)
        <div class="news-comments-notice" role="status">{{ $notice }}</div>
    @endif
    @error('action')
        <p class="news-comments-error" role="alert">{{ $message }}</p>
    @enderror

    @if($canWrite)
        <form class="news-comments-form" wire:submit="send">
            <label for="news_comment_new">{{ __('module-news-comments::messages.write_comment') }}</label>
            <textarea id="news_comment_new" rows="4" wire:model="body"
                maxlength="{{ $maximumLength }}"
                placeholder="{{ __('module-news-comments::messages.comment_placeholder') }}"
                required></textarea>
            @error('body')<p class="news-comments-error" role="alert">{{ $message }}</p>@enderror
            <div class="news-comments-form-actions">
                <small>{{ __('module-news-comments::messages.character_limit', ['count' => $maximumLength]) }}</small>
                <button class="button button-gold" type="submit" wire:loading.attr="disabled" wire:target="send">
                    {{ __('module-news-comments::messages.publish') }}
                </button>
            </div>
        </form>

        @if($replyToId !== null)
            <form class="news-comments-form news-comments-reply-form" wire:submit="sendReply">
                <div class="news-comments-replying-to">
                    @if($replyTarget !== null)
                        {{ __('module-news-comments::messages.replying_to') }}
                        <strong>&#64;{{ $replyTarget->author?->name ?? __('module-news-comments::messages.deleted_user') }}</strong>
                    @else
                        {{ __('module-news-comments::messages.reply_target_unavailable') }}
                    @endif
                </div>
                <label for="news_comment_reply">{{ __('module-news-comments::messages.reply') }}</label>
                <textarea id="news_comment_reply" rows="3" wire:model="replyBody"
                    maxlength="{{ $maximumLength }}" required></textarea>
                @error('replyBody')<p class="news-comments-error" role="alert">{{ $message }}</p>@enderror
                <div class="news-comments-form-actions">
                    <button class="button button-ghost" type="button" wire:click="cancelReply">
                        {{ __('module-news-comments::messages.cancel') }}
                    </button>
                    <button class="button button-gold" type="submit"
                        wire:loading.attr="disabled" wire:target="sendReply"
                        @disabled($replyTarget === null)>
                        {{ __('module-news-comments::messages.publish') }}
                    </button>
                </div>
            </form>
        @endif
    @elseif($viewerId === null)
        <div class="news-comments-guest">
            <p>{{ __('module-news-comments::messages.login_required') }}</p>
            <a class="button button-ghost" href="{{ route('login') }}">{{ __('module-news-comments::messages.login') }}</a>
        </div>
    @else
        <p class="news-comments-info">{{ __('module-news-comments::messages.not_allowed') }}</p>
    @endif

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
</section>

@script
<script>
    $wire.on('news-comments:reply', () => {
        requestAnimationFrame(() => requestAnimationFrame(() => {
            document.getElementById('news_comment_reply')?.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
            document.getElementById('news_comment_reply')?.focus({ preventScroll: true });
        }));
    });
    $wire.on('news-comments:jump', ({ commentId }) => {
        requestAnimationFrame(() => requestAnimationFrame(() => {
            document.getElementById('news-comment-' + commentId)?.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        }));
    });
</script>
@endscript
