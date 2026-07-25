@extends('theme::layouts.app')

@php
    $heroDetails = $server
        ? array_values(array_filter([
            $server['show_chronicle'] ? $server['chronicle'] : null,
            $server['show_rates'] ? $server['rates'] : null,
        ], static fn ($value) => is_string($value) && $value !== ''))
        : [];

    $features = [
        ['⚔', __('Fair play'), __('No paid advantages')],
        ['◆', __('Stable operation'), __('Isolated infrastructure and backups')],
        ['♜', __('Classic world'), __('Familiar mechanics without unnecessary changes')],
        ['◎', __('Active community'), __('Open development and transparent rules')],
        ['✦', __('Regular events'), __('New content without turning the game into a store')],
        ['⛨', __('Security'), __('Modern authentication and action auditing')],
    ];
@endphp

@section('title', site_name().($server && $server['show_chronicle'] ? ' — '.$server['chronicle'] : ''))

@section('content')
<section class="hero">
    <div class="hero-overlay"></div>
    <div class="container hero-content">
        <p class="eyebrow">{{ __('CLASSIC WORLD · YOUR LEGEND') }}</p>
        <h1>LINEAGE II</h1>
        @if ($heroDetails !== [])
            <h2>{{ implode(' · ', $heroDetails) }}</h2>
        @endif
        @if (site_description() !== '')
            <p class="hero-copy">{{ site_description() }}</p>
        @endif
        <div class="hero-actions">
            @auth
                <a class="button button-gold button-large" href="{{ public_route('account') }}">{{ __('Personal account') }}</a>
            @elseif (registration_available())
                <a class="button button-gold button-large" href="{{ public_route('register') }}">{{ __('Register') }}</a>
            @else
                <a class="button button-gold button-large" href="{{ public_route('login') }}">{{ __('Log in') }}</a>
            @endauth
            <a class="button button-ghost button-large" href="{{ public_route('downloads') }}">{{ __('Download client') }}</a>
        </div>
    </div>
</section>

<section class="container dashboard">
    @if ($servers !== [])
        <article
            class="panel server-panel"
            data-server-monitor
            data-refresh-url="{{ public_route('server-monitor.refresh') }}"
            data-auto-refresh="{{ $monitorRefreshDue ? '1' : '0' }}"
        >
            <div class="panel-title">
                <h2>{{ count($servers) > 1 ? __('Game servers') : __('Server status') }}</h2>
            </div>

            <div class="server-list">
                @foreach ($servers as $currentServer)
                    @php
                        $statusLabel = match ($currentServer['availability_state']) {
                            'maintenance' => __('Maintenance'),
                            'online' => __('Available'),
                            'offline' => __('Unavailable'),
                            default => __('Status pending'),
                        };
                    @endphp
                    <div class="server-row" data-monitor-game-server="{{ $currentServer['id'] }}">
                        <div class="server-summary">
                            <strong>{{ $currentServer['name'] }}</strong>
                            <span class="status {{ $currentServer['availability_state'] }}" data-monitor-public-state>{{ $statusLabel }}</span>
                            @if($publicOnlineVisible)
                                <small data-monitor-public-online aria-live="polite" @if($currentServer['availability_state'] === 'maintenance') hidden @endif>
                                    {{ $currentServer['public_players'] !== null
                                        ? __('Online: :count', ['count' => number_format($currentServer['public_players'], 0, '.', ' ')])
                                        : __('Online temporarily unavailable') }}
                                </small>
                            @endif
                            <small class="server-maintenance-message" data-monitor-maintenance-message @if($currentServer['availability_state'] !== 'maintenance' || $currentServer['maintenance_message'] === '') hidden @endif>
                                {{ $currentServer['maintenance_message'] }}
                            </small>
                        </div>

                        @if ($currentServer['show_chronicle'] || $currentServer['show_rates'] || $currentServer['show_mode'])
                            <dl>
                                @if ($currentServer['show_chronicle'])
                                    <div><dt>{{ __('Chronicle') }}</dt><dd>{{ $currentServer['chronicle'] }}</dd></div>
                                @endif
                                @if ($currentServer['show_rates'])
                                    <div><dt>{{ __('Rates') }}</dt><dd>{{ $currentServer['rates'] }}</dd></div>
                                @endif
                                @if ($currentServer['show_mode'])
                                    <div><dt>{{ __('Mode') }}</dt><dd>{{ $currentServer['mode'] }}</dd></div>
                                @endif
                            </dl>
                        @endif
                    </div>
                @endforeach
            </div>
        </article>
    @endif

    <div class="content-grid">
        <section class="panel news-panel">
            <div class="panel-title"><h2>{{ __('Latest news') }}</h2><a href="{{ public_route('news.index') }}">{{ __('All news →') }}</a></div>
            <div class="news-list">
                @forelse($news as $item)
                    <article class="news-item">
                        <a class="news-thumb" href="{{ news_url($item) }}" aria-label="{{ $item->titleFor() }}">
                            @if ($item->coverUrl())
                                <img src="{{ $item->coverUrl() }}" alt="">
                            @endif
                        </a>
                        <div><time>{{ $item->published_at?->format('d.m.Y') }}</time><h3><a href="{{ news_url($item) }}">{{ $item->titleFor() }}</a></h3><p>{{ $item->excerptFor() }}</p></div>
                    </article>
                @empty
                    <p class="empty">{{ __('There is no news yet.') }}</p>
                @endforelse
            </div>
        </section>

        <section class="panel features-panel">
            <div class="panel-title"><h2>{{ __('Server features') }}</h2></div>
            <div class="features-grid">
                @foreach($features as [$icon, $title, $text])
                    <article><span class="feature-icon">{{ $icon }}</span><div><h3>{{ $title }}</h3><p>{{ $text }}</p></div></article>
                @endforeach
            </div>
        </section>

        <aside class="side-column">
            <section class="panel login-panel">
                <div class="panel-title"><h2>{{ auth()->check() ? __('Personal account') : __('Authentication') }}</h2></div>
                @auth
                    <div class="login-panel-user">
                        <span>{{ __('You are signed in as') }}</span>
                        <strong>{{ auth()->user()->name }}</strong>
                        <a class="button button-gold" href="{{ public_route('account') }}">{{ __('Open account') }}</a>
                    </div>
                @else
                    <form method="POST" action="{{ public_route('login.store') }}">
                        @csrf
                        <label><span>{{ __('Username or email') }}</span><input name="login" required autocomplete="username" placeholder="user@example.com"></label>
                        <label><span>{{ __('Password') }}</span><input name="password" required type="password" autocomplete="current-password" placeholder="••••••••"></label>
                        <button class="button button-gold" type="submit">{{ __('Log in') }}</button>
                        <p>
                            <a href="{{ public_route('password.request') }}">{{ __('Forgot your password?') }}</a>
                            @if (registration_available())
                                · <a href="{{ public_route('register') }}">{{ __('Register') }}</a>
                            @endif
                        </p>
                    </form>
                @endauth
            </section>
            <section id="rating" class="panel rating-panel">
                <div class="panel-title"><h2>{{ __('Top characters') }}</h2></div>
                @if($topCharacters === [])
                    <p class="rating-empty">{{ __('No ranking data is available yet.') }}</p>
                @else
                    <table><thead><tr><th>#</th><th>{{ __('Character') }}</th><th>{{ __('Class') }}</th><th>{{ __('Level') }}</th></tr></thead><tbody>@foreach($topCharacters as $character)<tr><td>{{ $loop->iteration }}</td><td><span class="rating-character"><x-game-character-avatar :character="$character" class="rating-character-avatar" /><strong>{{ $character['name'] }}</strong></span></td><td>{{ $character['class'] }}</td><td>{{ $character['level'] }}</td></tr>@endforeach</tbody></table>
                @endif
            </section>
        </aside>
    </div>
</section>
@endsection
