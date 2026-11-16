@php
    $moderationUrl = route('admin.module-pages.news-comments.index', ['adminPath' => $adminPath]);
    $filtered = $activeReview !== 'pending' || $reviewNewsId !== null || $reviewAuthorId !== null;
@endphp

<x-admin.filter-bar :action="$moderationUrl" class="news-comments-filters">
    <div>
        <label for="news_comments_review_status">{{ __('module-news-comments::messages.moderation_filter') }}</label>
        <select id="news_comments_review_status" name="review">
            @foreach([
                'pending' => 'filter_pending',
                'new' => 'filter_new',
                'edits' => 'filter_edits',
                'approved' => 'filter_approved',
                'rejected' => 'filter_rejected',
                'hidden' => 'filter_hidden',
                'all' => 'filter_all',
            ] as $value => $label)
                <option value="{{ $value }}" @selected($activeReview === $value)>
                    {{ __('module-news-comments::messages.'.$label) }}
                </option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="news_comments_news_id">{{ __('module-news-comments::messages.filter_news_id') }}</label>
        <input id="news_comments_news_id" name="news_id" type="number" min="1" inputmode="numeric" value="{{ $reviewNewsId }}">
    </div>
    <div>
        <label for="news_comments_author_id">{{ __('module-news-comments::messages.filter_author_id') }}</label>
        <input id="news_comments_author_id" name="author_id" type="number" min="1" inputmode="numeric" value="{{ $reviewAuthorId }}">
    </div>
    <div class="admin-row-actions">
        <x-admin.button type="submit" variant="primary">{{ __('module-news-comments::messages.apply_filter') }}</x-admin.button>
        @if($filtered)
            <x-admin.button wire:navigate :href="$moderationUrl">{{ __('module-news-comments::messages.reset_filter') }}</x-admin.button>
        @endif
    </div>
</x-admin.filter-bar>

@if($reviewItems->isEmpty())
    <div class="admin-empty-state empty-state">
        <h2>{{ $filtered ? __('module-news-comments::messages.moderation_empty') : __('module-news-comments::messages.moderation_clear') }}</h2>
        <p>{{ $filtered ? __('module-news-comments::messages.moderation_empty_hint') : __('module-news-comments::messages.moderation_clear_hint') }}</p>
    </div>
@else
    <div class="admin-card-list news-comments-moderation-list">
        @foreach($reviewItems as $entry)
            @php
                $hasEdit = $entry->status === 'approved' && $entry->pending_body !== null;
                $linkedReplies = (int) $entry->replies_count;
                $isTombstone = $entry->deleted_by_author_at !== null || $entry->deleted_by_admin_at !== null;
                $returnParams = [
                    'adminPath' => $adminPath,
                    'comment' => $entry->id,
                    'review' => $activeReview,
                    'reviewPage' => $reviewItems->currentPage(),
                ];
                [$stateLabel, $stateTone] = match (true) {
                    $isTombstone => [__('module-news-comments::messages.deleted_label'), 'muted'],
                    $entry->status === 'pending' => [__('module-news-comments::messages.badge_new'), 'warning'],
                    $hasEdit => [__('module-news-comments::messages.badge_edit'), 'warning'],
                    $entry->status === 'approved' => [__('module-news-comments::messages.status_approved'), 'success'],
                    $entry->status === 'rejected' => [__('module-news-comments::messages.status_rejected'), 'danger'],
                    default => [__('module-news-comments::messages.status_'.$entry->status), 'muted'],
                };
            @endphp
            <article class="admin-card-row news-comments-moderation-item">
                <header class="news-comments-moderation-head">
                    <div class="news-comments-moderation-author">
                        <strong>{{ $entry->author?->name ?? __('module-news-comments::messages.deleted_user') }}</strong>
                        <small>
                            {{ __('module-news-comments::messages.filter_author_id') }} <span class="admin-num">{{ $entry->user_id ?? '—' }}</span>
                            · #<span class="admin-num">{{ $entry->id }}</span>
                            · <time datetime="{{ $entry->created_at?->toAtomString() }}">{{ $entry->created_at?->format('d.m.Y H:i') }}</time>
                        </small>
                    </div>
                    <span @class(['status-badge', 'status-badge-'.$stateTone])>{{ $stateLabel }}</span>
                </header>

                <p class="news-comments-moderation-context">
                    {{ $entry->news?->titleFor() ?? __('module-news-comments::messages.news_unavailable') }}
                    <span>ID <span class="admin-num">{{ $entry->news_id }}</span></span>
                    @if($entry->replyTo !== null)
                        <span>
                            {{ __('module-news-comments::messages.replying_to') }}
                            {{ $entry->replyTo->author?->name ?? __('module-news-comments::messages.deleted_user') }}
                            (#{{ $entry->replyTo->id }})
                        </span>
                    @endif
                </p>

                @unless($isTombstone)
                    <div class="news-comments-moderation-body">
                        @if($hasEdit)
                            <span class="news-comments-moderation-label">{{ __('module-news-comments::messages.published_version') }}</span>
                        @endif
                        <p>{!! nl2br(e($entry->body ?? '')) !!}</p>
                    </div>
                    @if($hasEdit)
                        <div class="news-comments-moderation-body news-comments-moderation-proposal">
                            <span class="news-comments-moderation-label">{{ __('module-news-comments::messages.proposed_version') }}</span>
                            <p>{!! nl2br(e($entry->pending_body)) !!}</p>
                        </div>
                    @endif
                @endunless

                @if(($canModerate && ! $isTombstone) || $canDelete)
                    <div class="admin-row-actions news-comments-moderation-actions">
                        @if($canModerate && ! $isTombstone)
                            @if($entry->status === 'pending' && $entry->deleted_by_author_at === null)
                                @foreach(['approve' => 'approve_comment', 'reject' => 'reject_comment'] as $action => $label)
                                    <form method="POST" action="{{ route('admin.module-pages.news-comments.comments.'.$action, $returnParams) }}">
                                        @csrf
                                        <x-admin.button type="submit" :variant="$action === 'approve' ? 'primary' : 'secondary'">{{ __('module-news-comments::messages.'.$label) }}</x-admin.button>
                                    </form>
                                @endforeach
                            @elseif($hasEdit && $entry->deleted_by_author_at === null)
                                @foreach(['approve-edit' => 'approve_revision', 'reject-edit' => 'reject_revision'] as $action => $label)
                                    <form method="POST" action="{{ route('admin.module-pages.news-comments.comments.'.$action, $returnParams) }}">
                                        @csrf
                                        <input type="hidden" name="expected_revision_token" value="{{ $entry->pending_revision_token }}">
                                        <x-admin.button type="submit" :variant="$action === 'approve-edit' ? 'primary' : 'secondary'">{{ __('module-news-comments::messages.'.$label) }}</x-admin.button>
                                    </form>
                                @endforeach
                            @endif
                            @if($entry->status === 'approved' && $entry->deleted_by_author_at === null)
                                <form method="POST" action="{{ route('admin.module-pages.news-comments.comments.hide', $returnParams) }}">
                                    @csrf
                                    <x-admin.button type="submit">{{ __('module-news-comments::messages.hide_comment') }}</x-admin.button>
                                </form>
                            @elseif(in_array($entry->status, ['hidden', 'rejected'], true) && $entry->deleted_by_author_at === null)
                                <form method="POST" action="{{ route('admin.module-pages.news-comments.comments.restore', $returnParams) }}">
                                    @csrf
                                    <x-admin.button type="submit">{{ __('module-news-comments::messages.restore_comment') }}</x-admin.button>
                                </form>
                            @endif
                        @endif
                        @if($canDelete && (! $isTombstone || $linkedReplies === 0))
                            <button type="button" class="button button-danger news-comments-delete"
                                data-news-comment-delete-open
                                data-news-comment-delete-url="{{ route('admin.module-pages.news-comments.comments.destroy', $returnParams) }}"
                                data-news-comment-delete-referenced="{{ $linkedReplies > 0 ? '1' : '0' }}">
                                {{ __('module-news-comments::messages.delete_comment') }}
                            </button>
                        @endif
                    </div>
                @endif
            </article>
        @endforeach
    </div>
    <x-admin.pagination
        :paginator="$reviewItems"
        :aria-label="__('module-news-comments::messages.pages_label')"
        :pages-label="__('module-news-comments::messages.pages_label')"
        :previous-label="__('module-news-comments::messages.previous')"
        :next-label="__('module-news-comments::messages.next')"
        numbered
    />
@endif

@if($canDelete)
    <dialog class="confirm-dialog" data-news-comment-delete-dialog aria-labelledby="news-comment-delete-title">
        <div class="confirm-dialog-card">
            <div class="confirm-dialog-copy">
                <span class="confirm-dialog-mark" aria-hidden="true">!</span>
                <div>
                    <h2 id="news-comment-delete-title">{{ __('module-news-comments::messages.delete_confirm_title') }}</h2>
                    <p data-news-comment-delete-text
                       data-referenced-text="{{ __('module-news-comments::messages.delete_confirm_referenced') }}"
                       data-unreferenced-text="{{ __('module-news-comments::messages.delete_confirm_unreferenced') }}">
                        {{ __('module-news-comments::messages.delete_confirm_unreferenced') }}
                    </p>
                </div>
            </div>
            <div class="confirm-dialog-actions">
                <button class="button button-secondary" type="button" data-news-comment-delete-cancel>
                    {{ __('module-news-comments::messages.cancel') }}
                </button>
                <form method="POST" action="" data-news-comment-delete-form>
                    @csrf
                    @method('DELETE')
                    <button class="button button-danger" type="submit">
                        {{ __('module-news-comments::messages.confirm_delete') }}
                    </button>
                </form>
            </div>
        </div>
    </dialog>
@endif
