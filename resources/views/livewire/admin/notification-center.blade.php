<div
    class="admin-notification-center"
    data-admin-notification-center
    wire:poll.30s="refreshCenter"
>
    <details class="admin-notification-menu" data-admin-notification-menu wire:ignore.self>
        <summary
            class="admin-notification-trigger"
            aria-label="{{ $unreadCount > 0 ? __('Notifications, unread: :count', ['count' => $unreadCount]) : __('Notifications') }}"
            title="{{ __('Notifications') }}"
        >
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path>
                <path d="M10 21h4"></path>
            </svg>
            @if($unreadCount > 0)
                <span class="admin-notification-count" data-testid="notification-unread-count">{{ $unreadLabel }}</span>
            @endif
        </summary>

        <div class="admin-notification-dropdown" role="region" aria-label="{{ __('Notification center') }}">
            <div class="admin-notification-heading">
                <div>
                    <strong>{{ __('Notifications') }}</strong>
                    <small>{{ trans_choice(':count unread notification|:count unread notifications', $unreadCount, ['count' => $unreadCount]) }}</small>
                </div>
                <div class="admin-notification-filters" role="group" aria-label="{{ __('Notification filter') }}">
                    <button
                        type="button"
                        @class(['active' => $filter === 'all'])
                        wire:click="setFilter('all')"
                        aria-pressed="{{ $filter === 'all' ? 'true' : 'false' }}"
                    >{{ __('All') }}</button>
                    <button
                        type="button"
                        @class(['active' => $filter === 'unread'])
                        wire:click="setFilter('unread')"
                        aria-pressed="{{ $filter === 'unread' ? 'true' : 'false' }}"
                    >{{ __('Unread') }}</button>
                </div>
            </div>

            @if($notice)
                <p class="admin-notification-notice" role="status">{{ $notice }}</p>
            @endif

            <div class="admin-notification-list" data-testid="notification-list">
                @forelse($items as $item)
                    <button
                        type="button"
                        class="admin-notification-item severity-{{ $item->severity->value }} {{ $item->read_at === null ? 'unread' : 'read' }}"
                        wire:click="openNotification({{ $item->id }})"
                        wire:key="admin-notification-{{ $item->id }}"
                    >
                        <span class="admin-notification-icon" aria-hidden="true">
                            @switch($item->severity->value)
                                @case('warning')
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3 2.5 20h19L12 3Z"></path><path d="M12 9v5"></path><path d="M12 17.5h.01"></path></svg>
                                    @break
                                @case('error')
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"></circle><path d="m9 9 6 6m0-6-6 6"></path></svg>
                                    @break
                                @case('success')
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"></circle><path d="m8 12 2.5 2.5L16 9"></path></svg>
                                    @break
                                @default
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"></circle><path d="M12 11v5"></path><path d="M12 8h.01"></path></svg>
                            @endswitch
                        </span>
                        <span class="admin-notification-copy">
                            <span class="admin-notification-title-row">
                                <strong>{{ $item->title() }}</strong>
                                @if($item->read_at === null)
                                    <span class="admin-notification-unread-dot" aria-label="{{ __('Unread') }}"></span>
                                @endif
                            </span>
                            @if($item->message())
                                <span class="admin-notification-message">{{ $item->message() }}</span>
                            @endif
                            <span class="admin-notification-meta">
                                <time datetime="{{ $item->last_occurred_at->toIso8601String() }}" title="{{ $item->last_occurred_at->format('d.m.Y H:i:s') }}">
                                    {{ $item->last_occurred_at->diffForHumans() }}
                                </time>
                                @if($item->occurrences > 1)
                                    <span>{{ __('Repeated :count times', ['count' => $item->occurrences]) }}</span>
                                @endif
                            </span>
                        </span>
                    </button>
                @empty
                    <div class="admin-notification-empty">
                        <strong>{{ $filter === 'unread' ? __('No unread notifications') : __('No notifications') }}</strong>
                        <span>{{ __('Actionable CMS events will appear here.') }}</span>
                    </div>
                @endforelse
            </div>

            <div class="admin-notification-actions">
                <button type="button" wire:click="markAllRead" @disabled($unreadCount === 0)>{{ __('Mark all as read') }}</button>
                <button type="button" wire:click="clearRead" @disabled($readCount === 0)>{{ __('Clear read') }}</button>
                <button
                    type="button"
                    class="danger"
                    wire:click="clearAll"
                    wire:confirm="{{ __('Delete all notifications from your list?') }}"
                    @disabled($totalCount === 0)
                >{{ __('Clear all') }}</button>
            </div>
        </div>
    </details>
</div>
