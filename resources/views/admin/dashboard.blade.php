@extends('admin.layouts.panel')

@section('title', __('Dashboard'))
@section('description', __('Server availability, mail delivery and system operations.'))

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
    $adminUser = auth('admin')->user();
    $canRefreshMonitor = $adminUser->hasPermission(\App\Auth\AdminPermission::DashboardRefresh);
    $canViewServers = $adminUser->hasPermission(\App\Auth\AdminPermission::ServersView);
    $canViewMail = $adminUser->hasPermission(\App\Auth\AdminPermission::MailView);
    $canViewSystem = $adminUser->hasPermission(\App\Auth\AdminPermission::SystemView);
@endphp

<div
    class="admin-dashboard-stack"
    data-server-monitor-dashboard
    data-refresh-url="{{ route('admin.server-monitor.status') }}"
    data-auto-refresh="{{ $monitorRefreshDue ? '1' : '0' }}"
>
    <section class="admin-overview dashboard-monitor-summary">
        <div>
            <span>{{ __('Total online') }}</span>
            <strong data-monitor-total-online>{{ number_format($monitor['total_online'], 0, '.', ' ') }}</strong>
            <small data-monitor-partial @if(! $monitor['partial']) hidden @endif>{{ __('Partial data') }}</small>
        </div>
        <div class="dashboard-monitor-summary-meta">
            <span data-monitor-updated>
                {{ $monitor['checked_at']
                    ? __('Updated :time', ['time' => $monitor['checked_at']->diffForHumans()])
                    : __('Not checked yet') }}
            </span>
            @if($canRefreshMonitor)
                <form method="POST" action="{{ route('admin.server-monitor.refresh') }}">
                    @csrf
                    <button class="button button-secondary button-compact" type="submit">{{ __('Check now') }}</button>
                </form>
            @endif
        </div>
    </section>

    @if($canViewServers || $canViewMail)
    <div class="dashboard-monitor-grid">
        @if($canViewServers)
        <section class="admin-data-card dashboard-monitor-card">
            <header>
                <h2>{{ __('Game servers') }}</h2>
                <a wire:navigate href="{{ route('admin.settings.game-server') }}">{{ __('Settings') }}</a>
            </header>

            <div class="admin-compact-list dashboard-monitor-list">
                @forelse($monitor['game_servers'] as $server)
                    <a wire:navigate class="admin-compact-row dashboard-monitor-row" data-monitor-admin-game="{{ $server['id'] }}" href="{{ route('admin.settings.game-server') }}">
                        <span class="dashboard-monitor-dot {{ $server['state'] }}" data-monitor-dot aria-hidden="true"></span>
                        <span class="dashboard-monitor-name-wrap"><span class="dashboard-monitor-name">{{ $server['name'] }}</span><small data-monitor-details>{{ __('Database: :database · Service: :service', ['database' => $databaseStateLabels[$server['database_state']], 'service' => $serviceStateLabels[$server['service_state']]]) }}</small></span>
                        <span class="dashboard-monitor-state" data-monitor-state>{{ $stateLabels[$server['state']] }}</span>
                        <strong class="dashboard-monitor-online" data-monitor-online>
                            {{ $server['players'] !== null
                                ? __(':count online', ['count' => number_format($server['players'], 0, '.', ' ')])
                                : '—' }}
                        </strong>
                    </a>
                @empty
                    <p class="dashboard-monitor-empty">{{ __('No game servers configured.') }}</p>
                @endforelse
            </div>
        </section>
        @endif

        <div class="dashboard-monitor-side">
        @if($canViewServers)
        <section class="admin-data-card dashboard-monitor-card">
            <header>
                <h2>{{ __('Login servers') }}</h2>
                <a wire:navigate href="{{ route('admin.settings.login-server') }}">{{ __('Settings') }}</a>
            </header>

            <div class="admin-compact-list dashboard-monitor-list">
                @forelse($monitor['login_servers'] as $server)
                    <a wire:navigate class="admin-compact-row dashboard-monitor-row dashboard-monitor-row-login" data-monitor-admin-login="{{ $server['id'] }}" href="{{ route('admin.settings.login-server') }}">
                        <span class="dashboard-monitor-dot {{ $server['state'] }}" data-monitor-dot aria-hidden="true"></span>
                        <span class="dashboard-monitor-name-wrap"><span class="dashboard-monitor-name">{{ $server['name'] }}</span><small data-monitor-details>{{ __('Database: :database · Service: :service', ['database' => $databaseStateLabels[$server['database_state']], 'service' => $serviceStateLabels[$server['service_state']]]) }}</small></span>
                        <span class="dashboard-monitor-state" data-monitor-state>{{ $stateLabels[$server['state']] }}</span>
                    </a>
                @empty
                    <p class="dashboard-monitor-empty">{{ __('No login servers configured.') }}</p>
                @endforelse
            </div>
        </section>
        @endif

        @if($canViewMail)
        <section class="admin-data-card dashboard-monitor-card">
            <header>
                <h2>{{ __('Mail delivery') }}</h2>
                <a wire:navigate href="{{ route('admin.settings.mail') }}">{{ __('Settings') }}</a>
            </header>
            <div class="dashboard-mail-card-body">
                <div class="dashboard-mail-status">
                    <span>{{ __('Mode') }}</span>
                    @if($mailSettings['delivery_mode'] === 'background')
                        <span class="status-badge {{ $mailSettings['background_supported'] ? 'status-badge-success' : 'status-badge-danger' }}">{{ __('Asynchronous') }}</span>
                    @elseif($mailSettings['delivery_mode'] === 'database')
                        <span class="status-badge {{ $mailSettings['database_supported'] ? 'status-badge-success' : 'status-badge-danger' }}">{{ __('Asynchronous with database queue') }}</span>
                    @else
                        <span class="status-badge status-badge-muted">{{ __('Synchronous') }}</span>
                    @endif
                </div>

                <div class="dashboard-mail-metrics">
                    <div class="dashboard-mail-metric"><span>{{ __('Waiting') }}</span><strong>{{ $mailDelivery['pending'] }}</strong></div>
                    <div class="dashboard-mail-metric"><span>{{ __('Errors in 7 days') }}</span><strong>{{ $mailDelivery['failed_recent'] }}</strong></div>
                </div>

                <div class="dashboard-mail-meta">
                    <span>{{ $mailDelivery['oldest_pending_at'] ? __('Oldest waiting: :time', ['time' => $mailDelivery['oldest_pending_at']->diffForHumans()]) : __('No emails are waiting.') }}</span>
                    <span>{{ $mailDelivery['last_sent_at'] ? __('Last successful email: :time', ['time' => $mailDelivery['last_sent_at']->diffForHumans()]) : __('No successful automatic emails recorded yet.') }}</span>
                </div>

                @if($mailDelivery['stale'])
                    <p class="dashboard-mail-warning">{{ __('An email has been waiting for more than two minutes. Check the delivery mode and SMTP settings.') }}</p>
                @elseif($mailSettings['delivery_mode'] === 'background' && ! $mailSettings['background_supported'])
                    <p class="dashboard-mail-warning">{{ __('The selected asynchronous mode has not passed its support test. Switch to synchronous mode.') }}</p>
                @elseif($mailSettings['delivery_mode'] === 'database' && ! $mailSettings['database_supported'])
                    <p class="dashboard-mail-warning">{{ __('The selected asynchronous mode has not passed its support test. Switch to synchronous mode.') }}</p>
                @endif
            </div>
        </section>
        @endif
        </div>
    </div>
    @endif

    @if($canViewSystem && $runtime !== null)
        <section class="admin-data-card dashboard-runtime-card">
            <header>
                <div>
                    <h2>{{ __('System operations') }}</h2>
                    <p>{{ __('Scheduler, queues and jobs that require administrator attention.') }}</p>
                </div>
                <a wire:navigate href="{{ route('admin.settings.system.queue') }}">{{ __('Queue details') }}</a>
            </header>

            <div class="dashboard-runtime-statuses">
                @foreach([
                    __('Laravel scheduler') => $runtime['scheduler'],
                    __('Queue processing') => $runtime['queue'],
                ] as $label => $status)
                    <article class="dashboard-runtime-status">
                        <span class="system-status-dot {{ $status['state'] }}" aria-hidden="true"></span>
                        <div>
                            <strong>{{ $label }}</strong>
                            <small>{{ $status['details'] }}</small>
                        </div>
                        <span class="status-badge status-badge-{{ $status['state'] === 'success' ? 'success' : ($status['state'] === 'danger' ? 'danger' : 'warning') }}">{{ $status['status'] }}</span>
                    </article>
                @endforeach
            </div>

            <div class="dashboard-runtime-metrics">
                <div><span>{{ __('Pending jobs') }}</span><strong>{{ $runtime['jobs']['pending'] }}</strong></div>
                <div><span>{{ __('Failed jobs') }}</span><strong>{{ $runtime['jobs']['failed'] }}</strong></div>
                <div>
                    <span>{{ __('Oldest pending job') }}</span>
                    <strong class="dashboard-runtime-time">{{ $runtime['jobs']['oldest_pending_at'] ? $runtime['jobs']['oldest_pending_at']->diffForHumans() : '—' }}</strong>
                </div>
                <div>
                    <span>{{ __('Last successful job') }}</span>
                    <strong class="dashboard-runtime-time">{{ $runtime['queue']['last_succeeded_at']?->diffForHumans() ?? '—' }}</strong>
                </div>
            </div>

            @if($runtime['warnings'] !== [])
                <div class="dashboard-runtime-warnings">
                    @foreach($runtime['warnings'] as $warning)
                        <p>{{ $warning }}</p>
                    @endforeach
                </div>
            @else
                <p class="dashboard-runtime-ok">{{ __('No Scheduler or queue problems detected.') }}</p>
            @endif
        </section>
    @endif
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets/admin/js/server-monitor.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
