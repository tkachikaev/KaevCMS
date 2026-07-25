@extends('account-theme::layouts.app')
@section('title', __('Web inventory'))
@section('content')
<section class="account-section account-surface reward-inventory-shell">
    <div class="account-section-heading reward-inventory-heading">
        <div><span class="account-eyebrow">{{ __('Rewards') }}</span><h1>{{ __('Web inventory') }}</h1><p>{{ __('Rewards from promo codes, donations and future modules are stored here until you transfer them to a character.') }}</p></div>
        @if($selectedServer)<span class="account-chip">{{ $selectedServer->nameFor() }}</span>@endif
    </div>

    @if($servers->count() > 1)
        <nav class="reward-server-tabs" aria-label="{{ __('Game servers') }}">
            @foreach($servers as $server)
                <a wire:navigate @class(['active' => $selectedServer?->id === $server->id]) href="{{ public_route('web-inventory.index', ['server' => $server->id, 'view' => $activeView]) }}">{{ $server->nameFor() }}</a>
            @endforeach
        </nav>
    @endif

    <nav class="reward-view-tabs" aria-label="{{ __('Web inventory sections') }}">
        <a wire:navigate @class(['active' => $activeView === 'available']) href="{{ public_route('web-inventory.index', array_filter(['server' => $selectedServer?->id])) }}">{{ __('Available rewards') }} <span>{{ $availableItems->count() }}</span></a>
        <a wire:navigate @class(['active' => $activeView === 'history']) href="{{ public_route('web-inventory.index', array_filter(['server' => $selectedServer?->id, 'view' => 'history'])) }}">{{ __('Transfer history') }} <span>{{ $deliveries->total() }}</span></a>
    </nav>

    @if(! $selectedServer)
        <div class="account-empty reward-empty"><span class="account-empty-symbol" aria-hidden="true">◇</span><h2>{{ __('Your web inventory is empty') }}</h2><p>{{ __('Rewards will appear here after they are granted by promo codes, donations or other modules.') }}</p></div>
    @elseif($activeView === 'history')
        @if($deliveries->isEmpty())
            <div class="account-empty reward-empty"><span class="account-empty-symbol" aria-hidden="true">↗</span><h2>{{ __('No transfers yet') }}</h2><p>{{ __('Transfers written to the GameServer queue, confirmed failures and uncertain writes will be shown here.') }}</p></div>
        @else
            <div class="reward-history-list">
                @foreach($deliveries as $delivery)
                    <article class="reward-history-card">
                        <div class="reward-history-main"><span class="reward-item-icon" aria-hidden="true">{{ mb_strtoupper(mb_substr($delivery->character_name, 0, 1)) }}</span><div><small>{{ $delivery->gameServer->nameFor() }}</small><h3>{{ $delivery->character_name }}</h3><p class="reward-history-items">@foreach($delivery->items as $item)<span>@if($deliveryItemIconUrls[$item->id] ?? null)<img src="{{ $deliveryItemIconUrls[$item->id] }}" alt="" width="24" height="24">@endif{{ $item->displayName($delivery->game_server_id) }} × {{ number_format($item->amount, 0, '.', ' ') }}</span>@endforeach</p></div></div>
                        <div class="reward-history-status"><span class="reward-status reward-status-{{ $delivery->status }}">{{ $delivery->statusLabel() }}</span><small>{{ $delivery->requested_at?->format('d.m.Y H:i') }}</small></div>
                    </article>
                @endforeach
            </div>
            @if($deliveries->hasPages())
                <nav class="simple-pagination" aria-label="{{ __('Reward queue transfer page navigation') }}">
                    @if($deliveries->onFirstPage())<span class="account-button ghost disabled">← {{ __('Back') }}</span>@else<a wire:navigate class="account-button ghost" href="{{ $deliveries->previousPageUrl() }}">← {{ __('Back') }}</a>@endif
                    @if($deliveries->hasMorePages())<a wire:navigate class="account-button ghost" href="{{ $deliveries->nextPageUrl() }}">{{ __('Next') }} →</a>@else<span class="account-button ghost disabled">{{ __('Next') }} →</span>@endif
                </nav>
            @endif
        @endif
    @elseif($availableItems->isEmpty())
        <div class="account-empty reward-empty"><span class="account-empty-symbol" aria-hidden="true">◇</span><h2>{{ __('No available rewards on this server') }}</h2><p>{{ __('New rewards for this server will appear here automatically.') }}</p></div>
    @else
        @if(! $capabilities->supported)
            <div class="account-inline-warning">{{ $deliveryUnavailableMessage }}</div>
        @elseif($characters === [])
            <div class="account-inline-warning">{{ __('No characters are available on this server. Create a character or check the GameServer connection.') }}</div>
        @endif

        <form class="reward-transfer-form" method="POST" action="{{ public_route('web-inventory.transfers.store') }}">
            @csrf
            <input type="hidden" name="game_server_id" value="{{ $selectedServer->id }}">
            <input type="hidden" name="request_token" value="{{ old('request_token', $requestToken) }}">

            <div class="reward-item-list">
                @foreach($availableItems as $item)
                    <label class="reward-item-row">
                        <input type="checkbox" name="inventory_item_ids[]" value="{{ $item->id }}" @checked(in_array($item->id, array_map('intval', (array) old('inventory_item_ids', [])), true)) @disabled(! $capabilities->supported || $characters === [])>
                        <span class="reward-item-check" aria-hidden="true">✓</span>
                        <span class="reward-item-icon" aria-hidden="true">
                            @if($itemIconUrls[$item->id] ?? null)
                                <img src="{{ $itemIconUrls[$item->id] }}" alt="" width="38" height="38">
                            @else
                                {{ mb_strtoupper(mb_substr($item->displayName(), 0, 1)) }}
                            @endif
                        </span>
                        <span class="reward-item-copy"><strong>{{ $item->displayName() }}</strong><small>{{ $item->grant->source_label ?: __('Source: :source', ['source' => $item->grant->source_type]) }}</small></span>
                        <strong class="reward-item-amount">× {{ number_format($item->amount, 0, '.', ' ') }}</strong>
                    </label>
                @endforeach
            </div>

            <div class="reward-transfer-panel">
                <div><span class="account-eyebrow">{{ __('Transfer to GameServer queue') }}</span><h2>{{ __('Choose a character') }}</h2><p>{{ __('Only characters belonging to your game accounts on :server are shown.', ['server' => $selectedServer->nameFor()]) }}</p><p>{{ __('KaevCMS writes the selected data to kaev_reward_queue. Actual item delivery is handled by the GameServer administrator.') }}</p></div>
                <label class="reward-character-select"><span>{{ __('Character') }}</span><select name="character_id" @disabled(! $capabilities->supported || $characters === [])><option value="">{{ __('Select character') }}</option>@foreach($characters as $character)<option value="{{ $character['id'] }}" data-avatar-url="{{ $character['avatar_url'] ?? '' }}" data-character-race="{{ $character['race_key'] ?? 'unknown' }}" data-character-gender="{{ $character['gender_key'] ?? 'neutral' }}" data-character-archetype="{{ $character['archetype'] ?? 'default' }}" @selected((int) old('character_id') === $character['id'])>{{ $character['name'] }} — {{ __('Level :level', ['level' => $character['level']]) }}{{ $character['online'] ? ' · '.__('Online') : '' }}</option>@endforeach</select></label>
                <button class="account-button primary" type="submit" @disabled(! $capabilities->supported || $characters === [])>{{ __('Send selected rewards to queue') }}</button>
            </div>
        </form>
    @endif
</section>
@endsection
