<section class="news-comments panel" aria-labelledby="news-comments-title">
    <header class="news-comments-heading">
        <h2 id="news-comments-title">{{ __('module-news-comments::messages.public_title') }}</h2>
        <span class="news-comments-total">{{ $visibleTotal }}</span>
    </header>

    @if($notice)
        <div class="news-comments-notice" role="status">{{ $notice }}</div>
    @endif

    @if($canWrite)
        <form class="news-comments-form" wire:submit="send">
            <label for="news_comment_new">{{ __('module-news-comments::messages.write_comment') }}</label>
            <textarea
                id="news_comment_new"
                rows="4"
                wire:model="body"
                maxlength="{{ $maximumLength }}"
                placeholder="{{ __('module-news-comments::messages.comment_placeholder') }}"
                required
            ></textarea>
            @error('body')<p class="news-comments-error" role="alert">{{ $message }}</p>@enderror
            <div class="news-comments-form-actions">
                <small>{{ __('module-news-comments::messages.character_limit', ['count' => $maximumLength]) }}</small>
                <button class="button button-gold" type="submit" wire:loading.attr="disabled" wire:target="send">
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

    @if($comments->isEmpty())
        <div class="news-comments-empty">{{ __('module-news-comments::messages.public_empty') }}</div>
    @else
        <ol class="news-comments-list">
            @foreach($comments as $comment)
                <li class="news-comments-entry" wire:key="news-comment-root-{{ $comment->id }}">
                    @include('module-news-comments::livewire._comment', ['entry' => $comment, 'isReply' => false])

                    @if($comment->replies->isNotEmpty())
                        <ol class="news-comments-replies">
                            @foreach($comment->replies as $reply)
                                <li class="news-comments-entry" wire:key="news-comment-reply-{{ $reply->id }}">
                                    @include('module-news-comments::livewire._comment', ['entry' => $reply, 'isReply' => true])
                                </li>
                            @endforeach
                        </ol>
                    @endif

                    @if((int) $comment->visible_replies_count > $comment->replies->count())
                        <button class="news-comments-action" type="button" wire:click="moreReplies">
                            {{ __('module-news-comments::messages.more_replies') }}
                        </button>
                    @endif

                    @if($replyToId === $comment->id && $canWrite)
                        <form class="news-comments-form news-comments-reply-form" wire:submit="sendReply" wire:key="news-reply-form-{{ $comment->id }}">
                            <label for="news_comment_reply_{{ $comment->id }}">{{ __('module-news-comments::messages.reply') }}</label>
                            <textarea
                                id="news_comment_reply_{{ $comment->id }}"
                                rows="3"
                                wire:model="replyBody"
                                maxlength="{{ $maximumLength }}"
                                required
                            ></textarea>
                            @error('replyBody')<p class="news-comments-error" role="alert">{{ $message }}</p>@enderror
                            <div class="news-comments-form-actions">
                                <button class="button button-ghost" type="button" wire:click="cancelReply">
                                    {{ __('module-news-comments::messages.cancel') }}
                                </button>
                                <button class="button button-gold" type="submit" wire:loading.attr="disabled" wire:target="sendReply">
                                    {{ __('module-news-comments::messages.publish') }}
                                </button>
                            </div>
                        </form>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif

    @if($comments->hasPages())
        <nav class="public-pagination news-comments-pages" aria-label="{{ __('module-news-comments::messages.pages_label') }}">
            <button class="button button-ghost" type="button" wire:click="previousPage('commentsPage')" @disabled($comments->onFirstPage())>
                {{ __('module-news-comments::messages.previous') }}
            </button>
            <span>{{ __('module-news-comments::messages.page_label', ['current' => $comments->currentPage(), 'total' => $comments->lastPage()]) }}</span>
            <button class="button button-ghost" type="button" wire:click="nextPage('commentsPage')" @disabled(! $comments->hasMorePages())>
                {{ __('module-news-comments::messages.next') }}
            </button>
        </nav>
    @endif
</section>
