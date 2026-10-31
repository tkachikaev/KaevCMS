@extends('account-theme::layouts.app')
@section('title', __('module-referrals::messages.account_title'))
@section('description', __('module-referrals::messages.account_description'))
@section('content')
<x-account-page>
    <x-account-panel class="account-section account-surface">
        <x-account-panel-header :title="__('module-referrals::messages.your_invitation_link')" :description="__('module-referrals::messages.link_help')">
            <x-slot:actions>
                <a wire:navigate class="account-button secondary" href="{{ public_route('web-inventory.index') }}">{{ __('module-referrals::messages.open_inventory') }}</a>
            </x-slot:actions>
        </x-account-panel-header>
        <div class="account-panel-stats">
            <div class="account-stat"><span>{{ __('module-referrals::messages.friends_registered') }}</span><strong>{{ format_number($invitedCount) }}</strong></div>
            <div class="account-stat"><span>{{ __('module-referrals::messages.rewards_received') }}</span><strong>{{ format_number($grantedCount) }}</strong></div>
            @if($wasInvited)
                <div class="account-stat"><span>{{ __('module-referrals::messages.your_invitation') }}</span><strong>{{ __('module-referrals::messages.invitation_accepted') }}</strong></div>
            @endif
        </div>
        @if($link !== null)
            <div class="account-form-grid">
                <label for="referral-link">
                    <span>{{ __('module-referrals::messages.your_invitation_link') }}</span>
                    <div class="account-field-control"><input id="referral-link" type="text" readonly value="{{ $link }}" autocomplete="off" spellcheck="false"></div>
                </label>
                <div class="account-form-actions">
                    <button type="button" class="account-button primary" data-account-copy-target="referral-link" data-copied-label="{{ __('module-referrals::messages.copied') }}" data-copy-failed-label="{{ __('module-referrals::messages.copy_failed') }}">{{ __('module-referrals::messages.copy_link') }}</button>
                    <span role="status" aria-live="polite" data-account-copy-status></span>
                </div>
            </div>
        @else
            <div class="account-inline-warning">{{ __('module-referrals::messages.link_unavailable') }}</div>
        @endif
    </x-account-panel>

    <x-account-panel class="account-section account-surface">
        <x-account-panel-header :title="__('module-referrals::messages.game_server_rewards')" :description="__('module-referrals::messages.rewards_explanation')" />
        @if($servers->count() > 1)
            <nav class="reward-server-tabs account-tabs" aria-label="{{ __('module-referrals::messages.game_server_rewards') }}">
                @foreach($servers as $choice)
                    <a wire:navigate @class(['active' => $choice->id === $server->id]) @if($choice->id === $server->id) aria-current="page" @endif href="{{ route('modules.referrals.index', ['server' => $choice->id]) }}">{{ $choice->nameFor() }}</a>
                @endforeach
            </nav>
        @else
            <span class="account-chip">{{ $server->nameFor() }}</span>
        @endif
        @if($settings === null || $settings->checks_paused)
            <div class="account-inline-warning" role="status">{{ __('module-referrals::messages.server_paused_notice') }}</div>
        @endif
        @if($rules->isEmpty())
            <x-account-empty class="account-empty reward-empty">
                <span class="account-empty-symbol" aria-hidden="true">◇</span>
                <h2>{{ __('module-referrals::messages.no_rewards_title') }}</h2>
                <p>{{ __('module-referrals::messages.no_rewards_description') }}</p>
            </x-account-empty>
        @else
            <p>{{ __('module-referrals::messages.rules_current_notice') }}</p>
            <div class="reward-history-list">
                @foreach($rules as $rule)
                        <div class="reward-history-card">
                            <div class="reward-history-main">
                                <span class="reward-item-icon" aria-hidden="true">
                                    @if($items[$rule->item_id]['icon'] ?? null)<img src="{{ $items[$rule->item_id]['icon'] }}" alt="" width="38" height="38">@else◇@endif
                                </span>
                                <div><small>{{ __('module-referrals::messages.audience_'.$rule->audience) }} · {{ __('module-referrals::messages.level_required', ['level' => format_number($rule->level)]) }}</small><h3>{{ $items[$rule->item_id]['name'] ?? __('module-referrals::messages.account_unknown_item', ['id' => format_number($rule->item_id)]) }}</h3></div>
                            </div>
                            <strong class="reward-item-amount">× {{ format_number($rule->amount) }}</strong>
                        </div>
                @endforeach
            </div>
        @endif
        <p>{{ __('module-referrals::messages.inventory_delivery_help') }}</p>
    </x-account-panel>

    <x-account-panel class="account-section account-surface">
        <x-account-panel-header :title="__('module-referrals::messages.progress_title')" :description="__('module-referrals::messages.progress_help')" />
        @if(session('referral_check'))
            <div class="account-notice {{ in_array(session('referral_check'), ['unknown', 'cooldown', 'disabled', 'busy', 'waiting', 'not_due'], true) ? 'warning' : 'success' }}" role="status">
                <span>{{ __('module-referrals::messages.check_result_'.session('referral_check')) }}</span>
            </div>
        @endif
        @if($progress->isEmpty())
            <x-account-empty class="account-empty reward-empty"><span class="account-empty-symbol" aria-hidden="true">◇</span><h2>{{ __('module-referrals::messages.no_progress_title') }}</h2><p>{{ __('module-referrals::messages.no_progress_description') }}</p></x-account-empty>
        @else
            <div class="reward-history-list">
                @foreach($progress as $entry)
                    <article class="reward-history-card">
                        <div class="reward-history-main">
                            <span class="reward-item-icon" aria-hidden="true">{{ format_number($entry->max_seen_level) }}</span>
                            <div>
                                <small>{{ (int) $entry->invite->inviter_user_id === (int) $user->id ? __('module-referrals::messages.audience_inviter') : __('module-referrals::messages.audience_invitee') }} · {{ __('module-referrals::messages.invitation_number', ['number' => format_number($entry->invite_id)]) }}</small>
                                <h3>{{ __('module-referrals::messages.progress_max_level', ['level' => format_number($entry->max_seen_level)]) }}</h3>
                                <p>{{ $entry->invite->status === 'frozen' ? __('module-referrals::messages.frozen') : __('module-referrals::messages.account_progress_state_'.$entry->status) }}</p>
                                @if($entry->last_success_at)<small>{{ __('module-referrals::messages.last_success', ['date' => $entry->last_success_at->format('d.m.Y H:i')]) }}</small>@endif
                            </div>
                        </div>
                        @if($entry->invite->status === 'active' && $settings && ! $settings->checks_paused && $entry->status !== 'completed')
                            <form method="POST" action="{{ route('modules.referrals.check', ['invite' => $entry->invite_id, 'server' => $server->id]) }}">
                                @csrf
                                <button class="account-button secondary" type="submit">{{ __('module-referrals::messages.check_progress') }}</button>
                            </form>
                        @endif
                    </article>
                @endforeach
            </div>
            @if($progress->count() === 20)<p>{{ __('module-referrals::messages.latest_progress_only') }}</p>@endif
        @endif
    </x-account-panel>

    <x-account-panel class="account-section account-surface">
        <x-account-panel-header :title="__('module-referrals::messages.reward_history')" :description="__('module-referrals::messages.reward_history_help')">
            <x-slot:actions><a wire:navigate class="account-button secondary" href="{{ public_route('web-inventory.index') }}">{{ __('module-referrals::messages.open_inventory') }}</a></x-slot:actions>
        </x-account-panel-header>
        @if($rewards->isEmpty())
            <x-account-empty class="account-empty reward-empty"><span class="account-empty-symbol" aria-hidden="true">◇</span><h2>{{ __('module-referrals::messages.no_history_title') }}</h2><p>{{ __('module-referrals::messages.no_history_description') }}</p></x-account-empty>
        @else
            <div class="reward-history-list">
                @foreach($rewards as $reward)
                    <article class="reward-history-card">
                        <div class="reward-history-main">
                            <span class="reward-item-icon" aria-hidden="true">
                                @if($items[$reward->item_id]['icon'] ?? null)<img src="{{ $items[$reward->item_id]['icon'] }}" alt="" width="38" height="38">@else◇@endif
                            </span>
                            <div>
                                <small>{{ __('module-referrals::messages.level_required', ['level' => format_number($reward->level)]) }} · {{ __('module-referrals::messages.audience_'.$reward->audience) }}</small>
                                <h3>{{ $items[$reward->item_id]['name'] ?? __('module-referrals::messages.account_unknown_item', ['id' => format_number($reward->item_id)]) }} × {{ format_number($reward->amount) }}</h3>
                                <p>{{ __('module-referrals::messages.invitation_number', ['number' => format_number($reward->invite_id)]) }}</p>
                            </div>
                        </div>
                        <div class="reward-history-status">
                            <span @class(['reward-status', 'reward-status-delivered' => $reward->status === 'granted', 'reward-status-pending' => $reward->status !== 'granted'])>{{ __('module-referrals::messages.reward_status_'.$reward->status) }}</span>
                            @if($reward->granted_at || $reward->earned_at)<small>{{ ($reward->granted_at ?? $reward->earned_at)->format('d.m.Y H:i') }}</small>@endif
                        </div>
                    </article>
                @endforeach
            </div>
            @if($rewards->hasPages())
                <nav class="simple-pagination" aria-label="{{ __('module-referrals::messages.reward_history') }}">
                    @if($rewards->onFirstPage())<span class="account-button ghost disabled">← {{ __('Back') }}</span>@else<a wire:navigate class="account-button ghost" href="{{ $rewards->previousPageUrl() }}">← {{ __('Back') }}</a>@endif
                    @if($rewards->hasMorePages())<a wire:navigate class="account-button ghost" href="{{ $rewards->nextPageUrl() }}">{{ __('Next') }} →</a>@else<span class="account-button ghost disabled">{{ __('Next') }} →</span>@endif
                </nav>
            @endif
        @endif
    </x-account-panel>
</x-account-page>
@endsection
