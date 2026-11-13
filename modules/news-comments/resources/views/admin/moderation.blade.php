<x-admin.card>
    <x-admin.card-heading
        :title="__('module-news-comments::messages.moderation_title')"
        :description="__('module-news-comments::messages.moderation_description')"
    />
    <div class="news-comments-moderation-summary">
        <strong>{{ __('module-news-comments::messages.pending_tasks', ['count' => $pendingCount]) }}</strong>
    </div>

    <form class="news-comments-moderation-filters" method="GET"
          action="{{ route('admin.module-pages.news-comments.index', ['adminPath' => $adminPath]) }}">
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
        <label for="news_comments_news_id">{{ __('module-news-comments::messages.filter_news_id') }}</label>
        <input id="news_comments_news_id" name="news_id" type="number" min="1"
               value="{{ $reviewNewsId }}" placeholder="ID">
        <label for="news_comments_author_id">{{ __('module-news-comments::messages.filter_author_id') }}</label>
        <input id="news_comments_author_id" name="author_id" type="number" min="1"
               value="{{ $reviewAuthorId }}" placeholder="ID">
        <button type="submit" class="button button-secondary">
            {{ __('module-news-comments::messages.apply_filter') }}
        </button>
    </form>

    @if($reviewItems->isEmpty())
        <div class="admin-empty-state empty-state">
            {{ __('module-news-comments::messages.moderation_empty') }}
        </div>
    @else
        <div class="admin-card-list content-list news-comments-moderation-list">
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
                @endphp
                <article class="admin-card-row content-row news-comments-moderation-item">
                    <div class="content-row-main">
                        <strong class="content-row-title">
                            {{ $entry->author?->name ?? __('module-news-comments::messages.deleted_user') }}
                            <span class="news-comments-moderation-id">#{{ $entry->id }}</span>
                        </strong>
                        <div class="content-row-meta">
                            <span>{{ $entry->news?->titleFor() ?? __('module-news-comments::messages.news_unavailable') }} (ID {{ $entry->news_id }})</span>
                            <span>{{ $entry->created_at->format('d.m.Y H:i') }}</span>
                            <span>{{ $entry->parent_id === null ? __('module-news-comments::messages.root_comment') : __('module-news-comments::messages.reply_comment') }}</span>
                            @if($entry->replyTo !== null)
                                <span>{{ __('module-news-comments::messages.replying_to') }}:
                                    {{ $entry->replyTo->author?->name ?? __('module-news-comments::messages.deleted_user') }}
                                    (#{{ $entry->replyTo->id }})</span>
                            @endif
                            <span>{{ __('module-news-comments::messages.filter_author_id') }}: {{ $entry->user_id ?? '—' }}</span>
                        </div>
                        <div class="news-comments-moderation-state">
                            {{ $entry->status === 'pending'
                                ? __('module-news-comments::messages.new_pending')
                                : ($hasEdit
                                    ? __('module-news-comments::messages.review_pending_edit')
                                    : __('module-news-comments::messages.status_'.$entry->status)) }}
                        </div>
                        @if($isTombstone)
                            <p>{{ __('module-news-comments::messages.deleted_label') }}</p>
                        @else
                            <div class="news-comments-moderation-body">
                                @if($hasEdit)
                                    <strong>{{ __('module-news-comments::messages.published_version') }}</strong>
                                @endif
                                <p>{!! nl2br(e($entry->body ?? '')) !!}</p>
                            </div>
                            @if($hasEdit)
                                <div class="news-comments-moderation-body news-comments-moderation-proposal">
                                    <strong>{{ __('module-news-comments::messages.proposed_version') }}</strong>
                                    <p>{!! nl2br(e($entry->pending_body)) !!}</p>
                                </div>
                            @endif
                        @endif
                        @if(($canModerate && ! $isTombstone) || $canDelete)
                            <div class="admin-row-actions content-row-actions news-comments-moderation-actions">
                                @if($canModerate && ! $isTombstone)
                                    @if($entry->status === 'pending' && $entry->deleted_by_author_at === null)
                                        @foreach(['approve' => 'approve_comment', 'reject' => 'reject_comment'] as $action => $label)
                                            <form method="POST" action="{{ route('admin.module-pages.news-comments.comments.'.$action, $returnParams) }}">
                                                @csrf
                                                <button type="submit" class="button {{ $action === 'approve' ? 'button-primary' : 'button-secondary' }}">
                                                    {{ __('module-news-comments::messages.'.$label) }}
                                                </button>
                                            </form>
                                        @endforeach
                                    @elseif($hasEdit && $entry->deleted_by_author_at === null)
                                        @foreach(['approve-edit' => 'approve_revision', 'reject-edit' => 'reject_revision'] as $action => $label)
                                            <form method="POST" action="{{ route('admin.module-pages.news-comments.comments.'.$action, $returnParams) }}">
                                                @csrf
                                                <input type="hidden" name="expected_revision_hash" value="{{ hash('sha256', $entry->pending_body) }}">
                                                <button type="submit" class="button {{ $action === 'approve-edit' ? 'button-primary' : 'button-secondary' }}">
                                                    {{ __('module-news-comments::messages.'.$label) }}
                                                </button>
                                            </form>
                                        @endforeach
                                    @endif
                                    @if($entry->status === 'approved' && $entry->deleted_by_author_at === null)
                                        <form method="POST" action="{{ route('admin.module-pages.news-comments.comments.hide', $returnParams) }}">
                                            @csrf
                                            <button type="submit" class="button button-secondary">
                                                {{ __('module-news-comments::messages.hide_comment') }}
                                            </button>
                                        </form>
                                    @elseif(in_array($entry->status, ['hidden', 'rejected'], true)
                                        && $entry->deleted_by_author_at === null)
                                        <form method="POST" action="{{ route('admin.module-pages.news-comments.comments.restore', $returnParams) }}">
                                            @csrf
                                            <button type="submit" class="button button-secondary">
                                                {{ __('module-news-comments::messages.restore_comment') }}
                                            </button>
                                        </form>
                                    @endif
                                @endif
                                @if($canDelete && (! $isTombstone || $linkedReplies === 0))
                                    <button type="button" class="button button-danger"
                                        data-news-comment-delete-open
                                        data-news-comment-delete-url="{{ route('admin.module-pages.news-comments.comments.destroy', $returnParams) }}"
                                        data-news-comment-delete-root="{{ $linkedReplies > 0 ? '1' : '0' }}">
                                        {{ __('module-news-comments::messages.delete_comment') }}
                                    </button>
                                @endif
                            </div>
                        @endif
                    </div>
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
</x-admin.card>

@if($canDelete)
    <dialog class="confirm-dialog" data-news-comment-delete-dialog aria-labelledby="news-comment-delete-title">
        <div class="confirm-dialog-card">
            <div class="confirm-dialog-copy">
                <span class="confirm-dialog-mark" aria-hidden="true">!</span>
                <div>
                    <h2 id="news-comment-delete-title">{{ __('module-news-comments::messages.delete_confirm_title') }}</h2>
                    <p data-news-comment-delete-text
                       data-root-text="{{ __('module-news-comments::messages.delete_confirm_root') }}"
                       data-reply-text="{{ __('module-news-comments::messages.delete_confirm_reply') }}">
                        {{ __('module-news-comments::messages.delete_confirm_root') }}
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
