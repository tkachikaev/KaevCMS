<div class="account-character-directory">
    <div class="account-character-directory-head">
        <div>
            <span class="account-eyebrow">{{ __('Characters') }}</span>
            <h2>{{ __('My characters') }}</h2>
            <p>{{ __('Switch between the server hierarchy and one combined character list.') }}</p>
        </div>
        <div class="account-character-summary" aria-label="{{ __('Character summary') }}">
            <span><strong>{{ $counts['characters'] }}</strong><small>{{ __('Characters') }}</small></span>
            <span class="online"><strong>{{ $counts['online'] }}</strong><small>{{ __('Online now') }}</small></span>
        </div>
    </div>

    <div class="account-character-tabs" role="tablist" aria-label="{{ __('Character display mode') }}">
        <button type="button" role="tab" @class(['active' => $viewMode === 'grouped']) aria-selected="{{ $viewMode === 'grouped' ? 'true' : 'false' }}" wire:click="setViewMode('grouped')">
            <span aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="4" width="6" height="6" rx="1"></rect><rect x="14" y="4" width="6" height="6" rx="1"></rect><rect x="4" y="14" width="6" height="6" rx="1"></rect><rect x="14" y="14" width="6" height="6" rx="1"></rect></svg></span>{{ __('By servers') }}
        </button>
        <button type="button" role="tab" @class(['active' => $viewMode === 'all']) aria-selected="{{ $viewMode === 'all' ? 'true' : 'false' }}" wire:click="setViewMode('all')">
            <span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 6h12M8 12h12M8 18h12"></path><circle cx="4" cy="6" r="1"></circle><circle cx="4" cy="12" r="1"></circle><circle cx="4" cy="18" r="1"></circle></svg></span>{{ __('All characters') }}
        </button>
    </div>

    @if($viewMode === 'grouped')
        <div class="account-character-groups" role="tabpanel">
            @forelse($visibleServers as $server)
                <section class="account-server-group" wire:key="character-server-{{ $server['id'] }}">
                    <header class="account-group-heading">
                        <button type="button" class="account-group-toggle" wire:click="toggleServer({{ $server['id'] }})" aria-expanded="{{ in_array($server['id'], $expandedServerIds, true) ? 'true' : 'false' }}">
                            <span class="account-group-chevron" aria-hidden="true">{{ in_array($server['id'], $expandedServerIds, true) ? '−' : '+' }}</span>
                            <span class="account-group-emblem" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"></circle><path d="M4 12h16M12 4a13 13 0 0 1 0 16M12 4a13 13 0 0 0 0 16"></path></svg></span>
                            <span class="account-group-copy"><strong>{{ $server['name'] }}</strong><small>{{ collect([$server['chronicle'], $server['rates']])->filter()->implode(' · ') }}</small></span>
                            <em>{{ trans_choice(':count account|:count accounts', count($server['accounts']), ['count' => count($server['accounts'])]) }}</em>
                        </button>
                        <button type="button" class="account-group-hide" wire:click="hideServer({{ $server['id'] }})">{{ __('Hide') }}</button>
                    </header>

                    @if(in_array($server['id'], $expandedServerIds, true))
                        <div class="account-server-accounts">
                            @forelse($server['accounts'] as $account)
                                <section class="account-game-account-group" wire:key="character-account-{{ $server['id'] }}-{{ $account['id'] }}">
                                    <header class="account-group-heading account-group-heading-nested">
                                        <button type="button" class="account-group-toggle" wire:click="toggleAccount({{ $account['id'] }})" aria-expanded="{{ in_array($account['id'], $expandedAccountIds, true) ? 'true' : 'false' }}">
                                            <span class="account-group-chevron" aria-hidden="true">{{ in_array($account['id'], $expandedAccountIds, true) ? '−' : '+' }}</span>
                                            <x-game-account-icon class="account-group-account-icon" aria-hidden="true" />
                                            <span class="account-group-copy"><strong>{{ $account['login'] }}</strong><small>{{ __('Game account') }}</small></span>
                                            <em>{{ trans_choice(':count character|:count characters', count($account['characters']), ['count' => count($account['characters'])]) }}</em>
                                        </button>
                                        <div class="account-group-actions">
                                            <a wire:navigate href="{{ public_route('game-accounts.show', ['gameAccount' => $account['id']]) }}">{{ __('Manage') }}</a>
                                            <button type="button" wire:click="hideAccount({{ $account['id'] }})">{{ __('Hide') }}</button>
                                        </div>
                                    </header>

                                    @if(in_array($account['id'], $expandedAccountIds, true))
                                        @if(!$account['available'])
                                            <div class="world-empty">{{ __('Character data is temporarily unavailable.') }}</div>
                                        @elseif($account['characters'] === [])
                                            <div class="world-empty">{{ __('No characters on this world.') }}</div>
                                        @else
                                            <div class="account-character-list">
                                                @foreach($account['characters'] as $character)
                                                    @include('account-theme::components.character-row', ['character' => $character, 'showContext' => false, 'hiddenContext' => false])
                                                @endforeach
                                            </div>
                                        @endif
                                    @endif
                                </section>
                            @empty
                                <div class="world-empty">{{ __('All accounts on this server are hidden.') }}</div>
                            @endforelse
                        </div>
                    @endif
                </section>
            @empty
                <div class="account-empty small"><h3>{{ __('No visible character groups') }}</h3><p>{{ __('Restore a hidden server or account to show it here again.') }}</p></div>
            @endforelse

            @if($hiddenServers !== [] || $hiddenAccounts !== [])
                <details class="account-hidden-groups">
                    <summary>{{ __('Show hidden groups') }} <span>{{ count($hiddenServers) + count($hiddenAccounts) }}</span></summary>
                    <div>
                        @foreach($hiddenServers as $server)
                            <button type="button" wire:click="restoreServer({{ $server['id'] }})"><strong>{{ $server['name'] }}</strong><small>{{ __('Server') }}</small><span>{{ __('Restore') }}</span></button>
                        @endforeach
                        @foreach($hiddenAccounts as $account)
                            <button type="button" wire:click="restoreAccount({{ $account['id'] }})"><strong>{{ $account['login'] }}</strong><small>{{ $account['server_name'] }}</small><span>{{ __('Restore') }}</span></button>
                        @endforeach
                    </div>
                </details>
            @endif
        </div>
    @else
        <div role="tabpanel">
            <div class="account-character-filters">
                <label class="account-character-search"><span>{{ __('Search') }}</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Character, class, clan, server or account') }}"></label>
                <label><span>{{ __('Server') }}</span><select wire:model.live="serverFilter"><option value="all">{{ __('All servers') }}</option>@foreach($serverOptions as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
                <label><span>{{ __('Game account') }}</span><select wire:model.live="accountFilter"><option value="all">{{ __('All accounts') }}</option>@foreach($accountOptions as $id => $login)<option value="{{ $id }}">{{ $login }}</option>@endforeach</select></label>
                <label><span>{{ __('Sort') }}</span><select wire:model.live="sortMode"><option value="priority">{{ __('Online and level') }}</option><option value="level">{{ __('By level') }}</option><option value="name">{{ __('By name') }}</option><option value="server">{{ __('By server and account') }}</option></select></label>
                <label class="account-filter-check"><input type="checkbox" wire:model.live="onlineOnly"><span>{{ __('Online only') }}</span></label>
                @if($hiddenServers !== [] || $hiddenAccounts !== [])<label class="account-filter-check"><input type="checkbox" wire:model.live="showHiddenInAll"><span>{{ __('Include hidden groups') }}</span></label>@endif
            </div>

            @if($allCharacters === [])
                <div class="account-empty small"><h3>{{ __('No characters found') }}</h3><p>{{ __('Change the filters or return to the grouped view.') }}</p><button class="account-button secondary" type="button" wire:click="resetFilters">{{ __('Reset filters') }}</button></div>
            @else
                <div class="account-character-list account-character-list-flat">
                    @foreach($allCharacters as $character)
                        @include('account-theme::components.character-row', [
                            'character' => $character,
                            'showContext' => true,
                            'hiddenContext' => in_array($character['server_id'], $hiddenServerIds, true) || in_array($character['account_id'], $hiddenAccountIds, true),
                        ])
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</div>
