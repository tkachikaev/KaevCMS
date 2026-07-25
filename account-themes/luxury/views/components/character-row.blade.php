@php
    $showContext = $showContext ?? false;
    $hiddenContext = $hiddenContext ?? false;
@endphp
<article class="account-character-row" wire:key="character-{{ $character['server_id'] }}-{{ $character['account_id'] }}-{{ $character['id'] }}">
    <x-game-character-avatar :character="$character" @class(['account-character-avatar', 'hero' => $character['hero']]) />
    <div class="account-character-identity">
        <div class="account-character-name">
            <strong>{{ $character['name'] }}</strong>
            @if($character['hero'])<span class="character-badge hero">{{ __('Hero') }}</span>@endif
            @if($character['noble'])<span class="character-badge noble">{{ __('Noble') }}</span>@endif
            @if($character['reputation'] !== 0)<span class="character-badge reputation">{{ __('Reputation') }} {{ $character['reputation'] }}</span>@endif
            @if($hiddenContext)<span class="character-badge muted">{{ __('Hidden group') }}</span>@endif
        </div>
        <span>{{ __('Level :level', ['level' => $character['level']]) }} · {{ $character['class_name'] }}</span>
        <small>{{ $character['race_name'] }} · {{ $character['gender_name'] }}@if($character['clan']) · {{ __('Clan: :clan', ['clan' => $character['clan']]) }}@endif</small>
        @if($showContext)<small class="account-character-context"><b>{{ $character['server_name'] }}</b><i>•</i>{{ __('Account: :account', ['account' => $character['account_login']]) }}</small>@endif
    </div>
    <div class="account-character-metrics">
        <span class="online-state {{ $character['online'] ? 'online' : '' }}"><i></i>{{ $character['online'] ? __('Online') : __('Offline') }}</span>
        <span><small>{{ __('In game') }}</small><strong>{{ $character['play_time_label'] }}</strong></span>
        <span><small>PvP</small><strong>{{ $character['pvp_kills'] }}</strong></span>
        <span><small>PK</small><strong>{{ $character['pk_kills'] }}</strong></span>
    </div>
    <details class="account-character-details">
        <summary>{{ __('Details') }} <span aria-hidden="true">⌄</span></summary>
        <dl>
            @if($character['title'])<div><dt>{{ __('Title') }}</dt><dd>{{ $character['title'] }}</dd></div>@endif
            <div><dt>{{ __('Last login') }}</dt><dd>{{ $character['last_seen_label'] ?? '—' }}</dd></div>
            @if($character['created_at_label'])<div><dt>{{ __('Created') }}</dt><dd>{{ $character['created_at_label'] }}</dd></div>@endif
            <div><dt>{{ __('Game server') }}</dt><dd>{{ $character['server_name'] }}</dd></div>
            <div><dt>{{ __('Game account') }}</dt><dd>{{ $character['account_login'] }}</dd></div>
        </dl>
    </details>
</article>
