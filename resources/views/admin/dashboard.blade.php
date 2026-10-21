@extends('admin.layouts.panel')

@section('title', __('Dashboard'))
@section('description', __('Accounts, servers, payments and what needs attention.'))

@section('content')
@php
    $stateLabels = [
        'maintenance' => __('Maintenance'),
        'online' => __('Server online'),
        'configured' => __('Configured'),
        'not_configured' => __('Not configured'),
        'unknown' => __('Status pending'),
    ];
    $databaseStateLabels = [
        'configured' => __('Connected'),
        'not_configured' => __('Connection failed'),
        'unknown' => __('Status pending'),
    ];
    $serviceStateLabels = [
        'online' => __('Running'),
        'offline' => __('Unavailable'),
        'unknown' => __('Status pending'),
    ];
    $canRefreshMonitor = ! $admin->isReadOnly() && $admin->hasPermission(\App\Auth\AdminPermission::DashboardRefresh);
    $canViewServers = $admin->hasPermission(\App\Auth\AdminPermission::ServersView);
    $canViewMail = $admin->hasPermission(\App\Auth\AdminPermission::MailView);
    $canViewSystem = $admin->hasPermission(\App\Auth\AdminPermission::SystemView);

    $masterAccounts = $overview['master_accounts'];
    $gameAccounts = $overview['game_accounts'];
    $latestMasterAccounts = $overview['latest_master_accounts'];
    $donations = $overview['donations'];
    $characters = $playerOverview['characters'] ?? null;
    $onlineNow = $canViewServers
        ? collect($monitor['game_servers'])->pluck('players')->filter(static fn ($players) => $players !== null)
        : collect();
    $mainTotal = $donations['totals'][0] ?? null;
@endphp

<div
    class="dash"
    data-server-monitor-dashboard
    data-refresh-url="{{ route('admin.server-monitor.status') }}"
    data-auto-refresh="{{ $monitorRefreshDue && ! $admin->isReadOnly() ? '1' : '0' }}"
>
    {{-- Key figures: master account → game accounts → characters, then money --}}
    <div class="dash-kpis" data-testid="dashboard-kpis">
        @if($masterAccounts !== null)
            <article class="dash-card dash-kpi">
                <header>
                    <h2 class="dash-kpi-label">{{ __('Master accounts') }}</h2>
                    @if($masterAccounts['new_today'] > 0)
                        <span class="dash-badge dash-badge-success">{{ __('+:count today', ['count' => format_number($masterAccounts['new_today'])]) }}</span>
                    @endif
                </header>
                <strong class="dash-kpi-value admin-num">{{ format_number($masterAccounts['total']) }}</strong>
                <footer class="dash-kpi-foot">
                    <span>{{ __('Email confirmed') }}</span>
                    <strong class="dash-accent-success">{{ $masterAccounts['verified_percent'] !== null ? format_percent($masterAccounts['verified_percent'], 0) : '—' }}</strong>
                </footer>
            </article>
        @endif

        @if($gameAccounts !== null)
            <article class="dash-card dash-kpi">
                <header>
                    <h2 class="dash-kpi-label">{{ __('Game accounts') }}</h2>
                    @if($gameAccounts['new_today'] > 0)
                        <span class="dash-badge dash-badge-success">{{ __('+:count today', ['count' => format_number($gameAccounts['new_today'])]) }}</span>
                    @endif
                </header>
                <strong class="dash-kpi-value admin-num">{{ format_number($gameAccounts['total']) }}</strong>
                <footer class="dash-kpi-foot">
                    <span>{{ __('Per master account') }}</span>
                    <strong class="dash-accent-primary">{{ $gameAccounts['per_player'] !== null ? format_number($gameAccounts['per_player'], 1) : '—' }}</strong>
                </footer>
            </article>
        @endif

        @if($playerOverview !== null || $canViewServers)
            <article class="dash-card dash-kpi">
                <header>
                    <h2 class="dash-kpi-label">{{ __('Characters online / total') }}</h2>
                    @if(($overview['online']['peak'] ?? null) !== null)
                        <span class="dash-badge dash-badge-primary">{{ __('Peak: :count', ['count' => format_number($overview['online']['peak'])]) }}</span>
                    @endif
                </header>
                <strong class="dash-kpi-value admin-num">
                    <span class="dash-accent-success">{{ $onlineNow->isNotEmpty() ? format_number($onlineNow->sum()) : '—' }}</span>
                    <span class="dash-kpi-total">/ {{ $characters !== null ? format_number($characters) : '—' }}</span>
                </strong>
                <footer class="dash-kpi-foot">
                    <span>{{ $characters !== null ? __('Characters on game servers') : __('Appears after a game database is connected') }}</span>
                    @if($canViewServers && $onlineNow->isNotEmpty())
                        <strong class="dash-accent-success"><span class="dash-live" aria-hidden="true"></span>{{ __('Live') }}</strong>
                    @endif
                </footer>
            </article>
        @endif

        @if($donations !== null)
            <article class="dash-card dash-kpi">
                <header>
                    <h2 class="dash-kpi-label">{{ __('Top-ups in 30 days') }}</h2>
                    @if($mainTotal !== null && $mainTotal['previous'] > 0)
                        @php $change = ($mainTotal['amount'] - $mainTotal['previous']) / $mainTotal['previous'] * 100; @endphp
                        <span class="dash-badge {{ $change < 0 ? 'dash-badge-danger' : 'dash-badge-success' }}">{{ ($change >= 0 ? '+' : '−').format_percent(abs($change), 0) }}</span>
                    @endif
                </header>
                <strong class="dash-kpi-value admin-num">{{ $mainTotal !== null ? format_money($mainTotal['amount'], $mainTotal['currency']) : '—' }}</strong>
                <footer class="dash-kpi-foot">
                    <span>{{ trans_choice('{1} :count payment|[2,*] :count payments', $donations['payments'], ['count' => format_number($donations['payments'])]) }}</span>
                    <strong>{{ $donations['average'] !== null ? __('average :amount', ['amount' => format_money($donations['average'], $donations['currency'])]) : '' }}</strong>
                </footer>
            </article>
        @endif
    </div>

    {{-- One small chart per metric; lines inside a widget share a unit and a scale --}}
    @if($chartWidgets !== [])
    <div class="dash-row dash-row-charts" data-testid="dashboard-trends">
        @foreach($chartWidgets as $widget)
            <section class="dash-card dash-widget" data-testid="dashboard-chart-{{ $widget['key'] }}" data-dash-widget>
                <header class="dash-widget-header">
                    <h2>{{ $widget['title'] }}</h2>
                    <div class="dash-segmented" role="group" aria-label="{{ __('Period') }}">
                        <button type="button" data-dash-period="30" aria-pressed="true">{{ __('30 days') }}</button>
                        <button type="button" data-dash-period="7">{{ __('7 days') }}</button>
                        <button type="button" data-dash-period="24">{{ __('24 h') }}</button>
                    </div>
                </header>
                <div class="dash-widget-series" data-dash-legend role="group" aria-label="{{ __('Visible lines') }}"></div>
                <div class="dash-widget-chart" data-dash-chart></div>
                <script type="application/json" data-dash-widget-data>@json($widget)</script>
            </section>
        @endforeach
    </div>
    @endif

    {{-- What needs a decision, live servers, newest accounts --}}
    <div class="dash-row dash-row-three">        <section class="dash-card dash-attention" data-testid="dashboard-attention">
            <header class="dash-card-header">
                <h2>{{ __('Needs attention') }}</h2>
                @if($attention !== [])
                    <span class="dash-badge dash-badge-{{ $attention[0]['level'] }} admin-num">{{ format_number(count($attention)) }}</span>
                @endif
            </header>
            @if($attention === [])
                <div class="dash-attention-ok">
                    <span class="dash-attention-ok-mark" aria-hidden="true">✓</span>
                    <div>
                        <strong>{{ __('Everything is fine') }}</strong>
                        <p>{{ __('Servers, mail, background jobs and player requests do not need you right now.') }}</p>
                    </div>
                </div>
            @else
                <ul class="dash-attention-list">
                    @foreach($attention as $item)
                        <li>
                            <a wire:navigate href="{{ $item['url'] }}" class="dash-attention-item dash-attention-{{ $item['level'] }}">
                                <span class="dash-attention-dot" aria-hidden="true"></span>
                                <span>{{ $item['title'] }}</span>
                                <span class="dash-attention-arrow" aria-hidden="true">→</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if($canViewServers)
            <section class="dash-card" data-testid="dashboard-game-servers-card">
                <header class="dash-card-header">
                    <h2>{{ __('Servers') }}</h2>
                    <div class="dash-monitor" data-testid="dashboard-monitor-toolbar">
                        <small data-monitor-partial @if(! $monitor['partial']) hidden @endif>{{ __('Partial data') }}</small>
                        <small data-monitor-updated>{{ $monitor['checked_at'] ? __('Updated :time', ['time' => $monitor['checked_at']->diffForHumans()]) : __('Not checked yet') }}</small>
                        @if($canRefreshMonitor)
                            <form method="POST" action="{{ route('admin.server-monitor.refresh') }}">
                                @csrf
                                <button class="dash-icon-button" type="submit" title="{{ __('Check now') }}" aria-label="{{ __('Check now') }}">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7"/></svg>
                                </button>
                            </form>
                        @endif
                    </div>
                </header>
                <div class="dash-list" data-testid="dashboard-login-servers-card">
                    @forelse($monitor['game_servers'] as $server)
                        <a wire:navigate class="dash-list-row" data-monitor-admin-game="{{ $server['id'] }}" href="{{ route('admin.settings.game-server') }}">
                            <span class="dashboard-monitor-dot {{ $server['state'] }}" data-monitor-dot aria-hidden="true"></span>
                            <span class="dash-list-main">
                                <strong>{{ $server['name'] }}</strong>
                                <small data-monitor-details>{{ __('Database: :database · Service: :service', ['database' => $databaseStateLabels[$server['database_state']], 'service' => $serviceStateLabels[$server['service_state']]]) }}</small>
                            </span>
                            <span class="dash-list-side">
                                <strong class="admin-num" data-monitor-online>{{ $server['players'] !== null ? __(':count online', ['count' => format_number($server['players'])]) : '—' }}</strong>
                                <small data-monitor-state>{{ $stateLabels[$server['state']] }}</small>
                            </span>
                        </a>
                    @empty
                        <p class="dash-empty">{{ __('No game servers configured.') }}</p>
                    @endforelse
                    @foreach($monitor['login_servers'] as $server)
                        <a wire:navigate class="dash-list-row" data-monitor-admin-login="{{ $server['id'] }}" href="{{ route('admin.settings.login-server') }}">
                            <span class="dashboard-monitor-dot {{ $server['state'] }}" data-monitor-dot aria-hidden="true"></span>
                            <span class="dash-list-main">
                                <strong>{{ $server['name'] }}</strong>
                                <small data-monitor-details>{{ __('Database: :database · Service: :service', ['database' => $databaseStateLabels[$server['database_state']], 'service' => $serviceStateLabels[$server['service_state']]]) }}</small>
                            </span>
                            <span class="dash-list-side">
                                <strong>{{ __('Login server') }}</strong>
                                <small data-monitor-state>{{ $stateLabels[$server['state']] }}</small>
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        @if($latestMasterAccounts !== null)
            <section class="dash-card" data-testid="dashboard-players-card">
                <header class="dash-card-header">
                    <h2>{{ __('New master accounts') }}</h2>
                    <a wire:navigate href="{{ route('admin.users.index') }}">{{ __('All') }}</a>
                </header>
                <div class="dash-list">
                    @forelse($latestMasterAccounts as $account)
                        <a wire:navigate class="dash-list-row" href="{{ route('admin.users.show', $account['id']) }}">
                            <span class="dash-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($account['name'], 0, 1)) }}</span>
                            <span class="dash-list-main">
                                <strong>{{ $account['name'] }}</strong>
                                <small>{{ $account['email'] }}</small>
                            </span>
                            <span class="dash-list-side">
                                <small>{{ $account['created_at']->diffForHumans() }}</small>
                                @if(! $account['verified'])
                                    <span class="dash-badge dash-badge-warning">{{ __('Email not confirmed') }}</span>
                                @elseif($account['game_accounts'] === 0)
                                    <span class="dash-badge dash-badge-muted">{{ __('No game account') }}</span>
                                @else
                                    <span class="dash-badge dash-badge-success">{{ trans_choice('{1} :count game account|[2,*] :count game accounts', $account['game_accounts'], ['count' => format_number($account['game_accounts'])]) }}</span>
                                @endif
                            </span>
                        </a>
                    @empty
                        <p class="dash-empty">{{ __('No master accounts yet. They appear after the first registration on the site.') }}</p>
                    @endforelse
                </div>
            </section>
        @endif
    </div>
    {{-- Money and the system summary --}}
    <div class="dash-row dash-row-pair">
        @if($donations !== null)
            <section class="dash-card" data-testid="dashboard-donations-card">
                <header class="dash-card-header">
                    <h2>{{ __('Latest top-ups') }}</h2>
                    <a wire:navigate href="{{ route($donations['orders_route'] ?? $donations['route']) }}">{{ __('All') }}</a>
                </header>
                <div class="dash-list">
                    @forelse($donations['latest'] as $payment)
                        <div class="dash-list-row">
                            <span class="dash-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($payment['player'], 0, 1)) }}</span>
                            <span class="dash-list-main">
                                <strong>{{ $payment['player'] }}</strong>
                                <small>{{ $payment['credited_at']->diffForHumans() }}</small>
                            </span>
                            <span class="dash-list-side">
                                <strong class="dash-amount admin-num">{{ format_money($payment['amount'], $payment['currency']) }}</strong>
                            </span>
                        </div>
                    @empty
                        <p class="dash-empty">{{ __('No payments yet. They appear here once players top up their balance.') }}</p>
                    @endforelse
                </div>
            </section>
        @endif

        {{-- System summary: details live on the system pages --}}
        @if(($canViewSystem && ($runtime !== null || $storageOverview !== null)) || $canViewMail)
            <section class="dash-card dash-system" data-testid="dashboard-system">
                <header class="dash-card-header">
                    <h2>{{ __('System') }}</h2>
                    @if($canViewSystem)
                        <a wire:navigate href="{{ route('admin.settings.system') }}">{{ __('System information') }}</a>
                    @endif
                </header>
                <ul class="dash-system-items">
                    @if($canViewSystem && $runtime !== null)
                        @foreach([__('Laravel scheduler') => $runtime['scheduler'], __('Queue processing') => $runtime['queue']] as $label => $status)
                            <li class="dash-system-item">
                                <span class="dash-system-dot dash-system-{{ $status['state'] === 'success' ? 'success' : ($status['state'] === 'danger' ? 'danger' : 'warning') }}" aria-hidden="true"></span>
                                <span><small>{{ $label }}</small><strong>{{ $status['status'] }}</strong></span>
                            </li>
                        @endforeach
                    @endif
                    @if($canViewMail)
                        <li class="dash-system-item">
                            <span class="dash-system-dot dash-system-{{ $mailDelivery['stale'] ? 'danger' : ($mailDelivery['failed_recent'] > 0 ? 'warning' : 'success') }}" aria-hidden="true"></span>
                            <span>
                                <small>{{ __('Mail') }}</small>
                                <strong>{{ $mailDelivery['pending'] > 0 ? trans_choice('{1} :count waiting|[2,*] :count waiting', $mailDelivery['pending'], ['count' => format_number($mailDelivery['pending'])]) : __('Nothing waiting') }}</strong>
                            </span>
                        </li>
                    @endif
                    @if($canViewSystem && $storageOverview !== null)
                        @php $disk = $storageOverview['disk']; $database = $storageOverview['database']; @endphp
                        <li class="dash-system-item">
                            <span class="dash-system-dot dash-system-{{ ! $disk['available'] ? 'muted' : ($disk['state'] === 'danger' ? 'danger' : ($disk['state'] === 'warning' ? 'warning' : 'success')) }}" aria-hidden="true"></span>
                            <span>
                                <small>{{ __('Disk') }}</small>
                                <strong>{{ $disk['available'] ? __(':free free', ['free' => $disk['free']]) : __('Unavailable') }}</strong>
                            </span>
                        </li>
                        <li class="dash-system-item">
                            <span class="dash-system-dot dash-system-{{ $database['connected'] ? 'success' : 'danger' }}" aria-hidden="true"></span>
                            <span>
                                <small>{{ __('Database') }} · {{ $database['driver_label'] }}</small>
                                <strong>{{ $database['connected'] ? ($database['total'] ?? __('Connected')) : __('Connection failed') }}</strong>
                            </span>
                        </li>
                    @endif
                </ul>
            </section>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets/admin/js/server-monitor.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
<script src="{{ asset('assets/admin/js/dashboard-charts.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
