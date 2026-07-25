@extends('account-theme::layouts.app')
@section('title', __('module-daily-rewards::messages.account_title'))
@section('content')
<section class="account-section account-surface daily-reward-account-section">
    <div class="account-section-heading">
        <div>
            <span class="account-eyebrow">{{ __('module-daily-rewards::messages.rewards') }}</span>
            <h1>{{ __('module-daily-rewards::messages.account_title') }}</h1>
            <p>{{ __('module-daily-rewards::messages.account_description') }}</p>
        </div>
        <a wire:navigate class="account-button secondary" href="{{ public_route('web-inventory.index') }}">{{ __('module-daily-rewards::messages.open_inventory') }}</a>
    </div>

    @if($calendars->isEmpty() || ! $calendar)
        <div class="account-empty reward-empty">
            <span class="account-empty-symbol" aria-hidden="true">31</span>
            <h2>{{ __('module-daily-rewards::messages.no_calendars_title') }}</h2>
            <p>{{ __('module-daily-rewards::messages.no_calendars_description') }}</p>
        </div>
    @else
        <form class="daily-reward-account-filters" method="GET" action="{{ route('modules.daily-rewards.index') }}">
            <label>
                <span>{{ __('module-daily-rewards::messages.game_server') }}</span>
                <select name="calendar" onchange="this.form.submit()">
                    @foreach($calendars as $availableCalendar)
                        <option value="{{ $availableCalendar->id }}" @selected($calendar->id === $availableCalendar->id)>{{ $availableCalendar->gameServer->nameFor() }} · {{ $availableCalendar->periodLabel() }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>{{ __('module-daily-rewards::messages.game_account') }}</span>
                <select name="account" onchange="this.form.submit()" @disabled($accounts->isEmpty())>
                    @if($accounts->isEmpty())
                        <option value="">{{ __('module-daily-rewards::messages.select_account') }}</option>
                    @else
                        @foreach($accounts as $account)
                            <option value="{{ $account->id }}" @selected($selectedAccount?->id === $account->id)>{{ $account->game_login }}</option>
                        @endforeach
                    @endif
                </select>
            </label>
            <noscript><button class="account-button secondary" type="submit">{{ __('module-daily-rewards::messages.filter') }}</button></noscript>
        </form>

        @if($accounts->isEmpty() || ! $selectedAccount)
            <div class="account-empty reward-empty">
                <span class="account-empty-symbol" aria-hidden="true">@</span>
                <h2>{{ __('module-daily-rewards::messages.no_accounts_title') }}</h2>
                <p>{{ __('module-daily-rewards::messages.no_accounts_description') }}</p>
            </div>
        @else
            <div class="daily-reward-account-summary">
                <div><span>{{ __('module-daily-rewards::messages.current_calendar', ['period' => $calendar->periodLabel()]) }}</span><strong>{{ $calendar->gameServer->nameFor() }}</strong></div>
                <p>{{ __('module-daily-rewards::messages.account_help') }}</p>
            </div>

            <div class="daily-reward-weekdays" aria-hidden="true">
                @foreach(['monday','tuesday','wednesday','thursday','friday','saturday','sunday'] as $weekday)
                    <span>{{ __('module-daily-rewards::messages.'.$weekday) }}</span>
                @endforeach
            </div>
            <div class="daily-reward-calendar-grid">
                @for($blank = 0; $blank < $weekdayOffset; $blank++)
                    <span class="daily-reward-calendar-blank" aria-hidden="true"></span>
                @endfor
                @foreach($calendar->days as $day)
                    @php($state = $dayStates[$day->id] ?? ['status' => 'disabled', 'claim' => null])
                    <article @class(['daily-reward-calendar-day', 'state-'.$state['status']])>
                        <div class="daily-reward-calendar-day-head">
                            <strong>{{ $day->day_number }}</strong>
                            <span>{{ __('module-daily-rewards::messages.status_'.$state['status']) }}</span>
                        </div>
                        <div class="daily-reward-calendar-items">
                            @forelse($day->items as $item)
                                <div class="daily-reward-calendar-item">
                                    @if($iconUrls[$day->id][$item->item_id] ?? null)
                                        <img src="{{ $iconUrls[$day->id][$item->item_id] }}" alt="" width="32" height="32">
                                    @else
                                        <span class="daily-reward-calendar-item-placeholder" aria-hidden="true">◇</span>
                                    @endif
                                    <span><strong>{{ $item->displayName($calendar->gameServer) }}</strong><small>× {{ number_format($item->amount, 0, '.', ' ') }}</small></span>
                                </div>
                            @empty
                                <small>{{ __('module-daily-rewards::messages.no_reward') }}</small>
                            @endforelse
                        </div>
                        @if($state['status'] === 'available')
                            <form method="POST" action="{{ route('modules.daily-rewards.claim') }}" class="daily-reward-claim-form">
                                @csrf
                                <input type="hidden" name="calendar_id" value="{{ $calendar->id }}">
                                <input type="hidden" name="user_game_account_id" value="{{ $selectedAccount->id }}">
                                <input type="hidden" name="request_token" value="{{ $requestToken }}">
                                <button class="account-button primary" type="submit">{{ __('module-daily-rewards::messages.claim') }}</button>
                            </form>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    @endif
</section>

<section class="account-section account-surface">
    <div class="account-section-heading"><div><h2>{{ __('module-daily-rewards::messages.recent_claims') }}</h2><p>{{ __('module-daily-rewards::messages.recent_claims_help') }}</p></div></div>
    @if($recentClaims->isEmpty())
        <div class="account-empty"><span class="account-empty-symbol" aria-hidden="true">◇</span><h2>{{ __('module-daily-rewards::messages.no_claims_title') }}</h2><p>{{ __('module-daily-rewards::messages.no_claims_description') }}</p></div>
    @else
        <div class="reward-history-list">
            @foreach($recentClaims as $claim)
                <article class="reward-history-card">
                    <div class="reward-history-main">
                        <span class="reward-item-icon" aria-hidden="true">{{ $claim->day->day_number }}</span>
                        <div><small>{{ $claim->calendar->gameServer->nameFor() }} · {{ $claim->game_account_login }}</small><h3>{{ $claim->calendar->periodLabel() }}</h3><p>@foreach((array) $claim->items_snapshot as $item){{ $item['name'] ?? __('module-daily-rewards::messages.unknown_item') }} × {{ number_format((int) ($item['amount'] ?? 0), 0, '.', ' ') }}@if(! $loop->last), @endif @endforeach</p></div>
                    </div>
                    <div class="reward-history-status"><span class="reward-status reward-status-delivered">{{ __('module-daily-rewards::messages.added_to_inventory') }}</span><small>{{ $claim->claimed_at?->format('d.m.Y H:i') }}</small></div>
                </article>
            @endforeach
        </div>
    @endif
</section>
@endsection
@push('head')
<link rel="stylesheet" href="{{ asset('assets/modules/daily-rewards.css') }}?v={{ cms_version() }}" data-navigate-track>
@endpush
