@extends('account-theme::layouts.app')
@section('title', __('module-referrals::messages.account_title'))
@section('description', __('module-referrals::messages.account_description'))
@section('content')
<x-account-page>
    <x-account-panel class="account-section reward-inventory-shell">
        <div class="account-section-heading reward-inventory-heading">
            <span class="account-chip">{{ $allServers ? __('module-referrals::messages.all_servers') : $server->nameFor() }}</span>
        </div>
        @if($servers->count() > 1 || $allServers)
            <nav class="reward-server-tabs account-tabs" aria-label="{{ __('Game servers') }}" data-referral-servers>
                @if($activeTab === 'history')
                    <a wire:navigate @class(['active' => $allServers]) @if($allServers) aria-current="page" @endif href="{{ route('modules.referrals.index', ['server' => 'all', 'tab' => $activeTab]) }}">{{ __('module-referrals::messages.all_servers') }}</a>
                @endif
                @foreach($servers as $choice)
                    <a wire:navigate @class(['active' => ! $allServers && $choice->id === $server->id]) @if(! $allServers && $choice->id === $server->id) aria-current="page" @endif href="{{ route('modules.referrals.index', ['server' => $choice->id, 'tab' => $activeTab]) }}">{{ $choice->nameFor() }}</a>
                @endforeach
            </nav>
        @endif
        <nav class="reward-view-tabs account-tabs" aria-label="{{ __('module-referrals::messages.account_sections') }}" data-referral-tabs>
            @foreach(['invite' => 'tab_invite', 'progress' => 'tab_progress', 'history' => 'reward_history'] as $tab => $label)
                <a wire:navigate @class(['active' => $activeTab === $tab]) @if($activeTab === $tab) aria-current="page" @endif href="{{ route('modules.referrals.index', ['server' => $tab === 'history' && $allServers ? 'all' : $server->id, 'tab' => $tab]) }}">{{ __('module-referrals::messages.'.$label) }}</a>
            @endforeach
        </nav>
    @if($activeTab !== 'history' && ($settings === null || $settings->checks_paused))
        <div class="account-inline-warning" role="status">{{ __('module-referrals::messages.server_paused_notice') }}</div>
    @endif
    @if($activeTab === 'invite')

    <section>
        <x-account-panel-header :title="__('module-referrals::messages.your_invitation_link')" :description="__('module-referrals::messages.link_help')" />
        @if($link !== null)
            <div class="account-copy-field">
                <input id="referral-link" type="text" readonly value="{{ $link }}" aria-label="{{ __('module-referrals::messages.your_invitation_link') }}" autocomplete="off" spellcheck="false">
                <button type="button" class="account-button secondary account-copy-icon" aria-label="{{ __('module-referrals::messages.copy_link') }}" title="{{ __('module-referrals::messages.copy_link') }}" data-account-copy-target="referral-link" data-copied-label="{{ __('module-referrals::messages.copied') }}" data-copy-failed-label="{{ __('module-referrals::messages.copy_failed') }}">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="8" y="8" width="12" height="13" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/></svg>
                </button>
                <span class="account-copy-toast" role="status" aria-live="polite" data-account-copy-status data-account-copy-toast hidden></span>
            </div>
        @else
            <div class="account-inline-warning">{{ __('module-referrals::messages.link_unavailable') }}</div>
        @endif
    </section>

    <section>
        <x-account-panel-header :title="__('module-referrals::messages.game_server_rewards')" />
        @if($rules->isEmpty())
            <x-account-empty class="account-empty reward-empty">
                <span class="account-empty-symbol" aria-hidden="true">◇</span>
                <h2>{{ __('module-referrals::messages.no_rewards_title') }}</h2>
                <p>{{ __('module-referrals::messages.no_rewards_description') }}</p>
            </x-account-empty>
        @else
            @foreach(['inviter' => 'you_receive', 'invitee' => 'friend_receives'] as $audience => $label)
            @if($rules->where('audience', $audience)->isNotEmpty())
            <h3>{{ __('module-referrals::messages.'.$label) }}</h3>
            <div class="reward-history-list">
                @foreach($rules->where('audience', $audience) as $rule)
                        <div class="reward-history-card">
                            <div class="reward-history-main">
                                <span class="reward-item-icon" aria-hidden="true">
                                    @if($items[$rule->item_id]['icon'] ?? null)<img src="{{ $items[$rule->item_id]['icon'] }}" alt="" width="38" height="38">@else◇@endif
                                </span>
                                <div><small>{{ __('module-referrals::messages.friend_level', ['level' => format_number($rule->level)]) }}</small><h3>{{ $items[$rule->item_id]['name'] ?? __('module-referrals::messages.account_unknown_item', ['id' => format_number($rule->item_id)]) }}</h3></div>
                            </div>
                            <strong class="reward-item-amount">× {{ format_number($rule->amount) }}</strong>
                        </div>
                @endforeach
            </div>
            @endif
            @endforeach
        @endif
        <p>{{ __('module-referrals::messages.rewards_to_inventory') }}</p>
    </section>

    @endif
    @if($activeTab === 'progress')
    <section>
        <x-account-panel-header :title="__('module-referrals::messages.progress_title')" />
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
                                <small>{{ (int) $entry->invite->inviter_user_id === (int) $user->id ? __('module-referrals::messages.your_friend') : __('module-referrals::messages.you_were_invited') }} · {{ __('module-referrals::messages.invitation_number', ['number' => format_number($entry->invite_id)]) }}</small>
                                <h3>{{ __('module-referrals::messages.progress_max_level', ['level' => format_number($entry->max_seen_level)]) }}</h3>
                                <p>{{ $entry->invite->status === 'frozen' ? __('module-referrals::messages.frozen') : __('module-referrals::messages.account_progress_state_'.$entry->status) }}</p>
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
    </section>

    @endif
    @if($activeTab === 'history')
    <section>
        <x-account-panel-header :title="__('module-referrals::messages.reward_history')" />
        @if($rewards->isEmpty())
            <x-account-empty class="account-empty reward-empty"><span class="account-empty-symbol" aria-hidden="true">◇</span><h2>{{ __('module-referrals::messages.no_history_title') }}</h2><p>{{ __('module-referrals::messages.no_history_description') }}</p></x-account-empty>
        @else
            <div class="reward-history-list">
                @foreach($rewards as $reward)
                    <article class="reward-history-card">
                        <div class="reward-history-main">
                            <span class="reward-item-icon" aria-hidden="true">
                                @if($rewardItems[$reward->id]['icon'] ?? null)<img src="{{ $rewardItems[$reward->id]['icon'] }}" alt="" width="38" height="38">@else◇@endif
                            </span>
                            <div>
                                <small>@if($allServers){{ $reward->gameServer?->nameFor() }} · @endif{{ __('module-referrals::messages.level_required', ['level' => format_number($reward->level)]) }} · {{ __('module-referrals::messages.audience_'.$reward->audience) }}</small>
                                <h3>{{ $rewardItems[$reward->id]['name'] ?? __('module-referrals::messages.account_unknown_item', ['id' => format_number($reward->item_id)]) }} × {{ format_number($reward->amount) }}</h3>
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
    </section>
    @endif
    </x-account-panel>
</x-account-page>
@endsection
