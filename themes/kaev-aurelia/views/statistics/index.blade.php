@extends('theme::layouts.app')

@section('title', __('Statistics').' — '.site_name())
@section('meta_description', __('Character rankings, current heroes and castle owners.'))

@section('content')
<section class="page-hero compact-page-hero">
    <div class="container">
        <p class="eyebrow">{{ __('GAME WORLD') }}</p>
        <h1>{{ __('Statistics') }}</h1>
        <p>{{ __('Character rankings, current heroes and castle owners.') }}</p>
    </div>
</section>

<section class="container statistics-page" data-statistics-page>
    <div class="statistics-partial-progress" data-statistics-progress role="status" aria-live="polite" aria-label="{{ __('Statistics') }}" aria-hidden="true">
        <span aria-hidden="true"></span>
    </div>
    @if($servers->isEmpty() || !$selectedServer)
        <div class="panel statistics-empty-state">
            <span aria-hidden="true"><i>◎</i></span>
            <h2>{{ __('Statistics are not available yet.') }}</h2>
            <p>{{ __('The administrator has not enabled public statistics for a connected game server.') }}</p>
        </div>
    @else
        <div class="statistics-toolbar">
            <div>
                <span>{{ __('Game server') }}</span>
                <strong>{{ $selectedServer->nameFor() }}</strong>
            </div>

            @if($servers->count() > 1)
                <nav class="statistics-server-switcher" aria-label="{{ __('Select game server') }}">
                    @foreach($servers as $serverOption)
                        <a data-statistics-link @class(['active' => $serverOption->is($selectedServer)]) @if($serverOption->is($selectedServer)) aria-current="page" @endif href="{{ public_route('statistics.show', ['gameServer' => $serverOption->id, 'section' => $activeSection]) }}">
                            {{ $serverOption->nameFor() }}
                        </a>
                    @endforeach
                </nav>
            @endif
        </div>

        @if(!$statisticsSourceAvailable)
            <div class="panel statistics-empty-state statistics-error-state">
                <span aria-hidden="true"><i>!</i></span>
                <h2>{{ __('Game data is temporarily unavailable.') }}</h2>
                <p>{{ __('The game database could not be read. Try again later.') }}</p>
            </div>
        @elseif($sections === [] || $activeSection === null)
            <div class="panel statistics-empty-state">
                <span aria-hidden="true"><i>◎</i></span>
                <h2>{{ __('Statistics sections are disabled.') }}</h2>
                <p>{{ __('Enable at least one statistics section in the game server settings.') }}</p>
            </div>
        @else
            <nav class="statistics-tabs" aria-label="{{ __('Statistics sections') }}">
                @foreach($sections as $sectionKey => $sectionLabel)
                    <a data-statistics-link @class(['active' => $activeSection === $sectionKey]) @if($activeSection === $sectionKey) aria-current="page" @endif href="{{ public_route('statistics.show', ['gameServer' => $selectedServer->id, 'section' => $sectionKey]) }}">
                        {{ $sectionLabel }}
                    </a>
                @endforeach
            </nav>

            @if(!$statisticsAvailable)
                <div class="panel statistics-empty-state statistics-error-state">
                    <span aria-hidden="true"><i>!</i></span>
                    <h2>{{ __('Game data is temporarily unavailable.') }}</h2>
                    <p>{{ __('The game database could not be read. Try again later.') }}</p>
                </div>
            @elseif($rows === [])
                <div class="panel statistics-empty-state">
                    <span aria-hidden="true"><i>—</i></span>
                    <h2>{{ __('No records found.') }}</h2>
                    <p>{{ __('This section does not contain any records yet.') }}</p>
                </div>
            @elseif($activeSection === 'castles')
                <div class="castle-owner-grid">
                    @foreach($rows as $row)
                        <article class="panel castle-owner-card">
                            <div class="castle-owner-mark" aria-hidden="true">♜</div>
                            <div>
                                <span>{{ __('Castle') }}</span>
                                <h2>{{ $row['castle_name'] }}</h2>
                                <strong>{{ $row['clan_name'] }}</strong>
                                <dl>
                                    <div><dt>{{ __('Leader') }}</dt><dd>{{ $row['leader_name'] !== '' ? $row['leader_name'] : '—' }}</dd></div>
                                    <div><dt>{{ __('Clan level') }}</dt><dd>{{ $row['clan_level'] }}</dd></div>
                                    <div><dt>{{ __('Reputation') }}</dt><dd>{{ number_format($row['reputation_score']) }}</dd></div>
                                </dl>
                            </div>
                        </article>
                    @endforeach
                </div>
            @else
                <div class="panel statistics-table-card">
                    <div class="statistics-table-wrap">
                        <table class="statistics-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>{{ __('Character') }}</th>
                                    <th>{{ __('Level') }}</th>
                                    <th>{{ __('Class') }}</th>
                                                                        <th>{{ __('Clan') }}</th>
                                    @if($activeSection !== 'level' || $showOnlineStatus)
                                        <th>{{ match($activeSection) {
                                            'pvp' => __('PvP ranking'),
                                            'pk' => __('PK ranking'),
                                            'play_time' => __('Play time'),
                                            'heroes' => __('Hero'),
                                            default => __('Status'),
                                        } }}</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($rows as $row)
                                    <tr>
                                        <td><span class="statistics-rank">{{ $loop->iteration }}</span></td>
                                        <td>
                                            <div class="statistics-character">
                                                <x-game-character-avatar :character="$row" class="statistics-character-avatar" />
                                                <span>
                                                    <strong>{{ $row['name'] }}</strong>
                                                    <small>{{ $row['gender_name'] }}@if(trim((string) ($row['title'] ?? '')) !== '') · {{ $row['title'] }}@endif</small>
                                                </span>
                                            </div>
                                        </td>
                                        <td>{{ $row['level'] }}</td>
                                        <td>{{ $row['class_name'] }}</td>
                                        <td>{{ trim((string) ($row['clan_name'] ?? '')) !== '' ? $row['clan_name'] : __('No clan') }}</td>
                                        @if($activeSection !== 'level' || $showOnlineStatus)
                                            <td>
                                                @if($activeSection === 'pvp')
                                                    <strong class="statistics-value">{{ number_format((int) $row['pvp_kills']) }}</strong>
                                                @elseif($activeSection === 'pk')
                                                    <strong class="statistics-value danger">{{ number_format((int) $row['pk_kills']) }}</strong>
                                                @elseif($activeSection === 'play_time')
                                                    <strong class="statistics-value">{{ __(':count h', ['count' => number_format((int) $row['play_time_hours'])]) }}</strong>
                                                @elseif($activeSection === 'heroes')
                                                    <span class="statistics-hero-badge">{{ __('Hero') }}</span>
                                                @else
                                                    <span @class(['statistics-online', 'online' => $row['online']])>{{ $row['online'] ? __('Online') : __('Offline') }}</span>
                                                @endif
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        @endif
    @endif
</section>
@endsection
