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
    $canRefreshMonitor = ! $adminUser->isReadOnly() && $adminUser->hasPermission(\App\Auth\AdminPermission::DashboardRefresh);
    $canViewServers = $adminUser->hasPermission(\App\Auth\AdminPermission::ServersView);
    $canViewMail = $adminUser->hasPermission(\App\Auth\AdminPermission::MailView);
    $canViewSystem = $adminUser->hasPermission(\App\Auth\AdminPermission::SystemView);
    $canViewUsers = $adminUser->hasPermission(\App\Auth\AdminPermission::UsersView);
    $hasDashboardMain = ($canViewSystem && $storageOverview !== null) || ($canViewUsers && $playerOverview !== null);
    $hasDashboardSide = $canViewServers || $canViewMail;
@endphp

<div
    class="admin-dashboard-stack"
    data-server-monitor-dashboard
    data-refresh-url="{{ route('admin.server-monitor.status') }}"
    data-auto-refresh="{{ $monitorRefreshDue && ! $adminUser->isReadOnly() ? '1' : '0' }}"
>
    <div class="dashboard-monitor-toolbar" data-testid="dashboard-monitor-toolbar">
        <small data-monitor-partial @if(! $monitor['partial']) hidden @endif>{{ __('Partial data') }}</small>
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

    @if($hasDashboardMain || $hasDashboardSide)
    <div class="dashboard-monitor-grid {{ ! $hasDashboardMain || ! $hasDashboardSide ? 'dashboard-monitor-grid-single' : '' }}">
        @if($hasDashboardMain)
        <div class="dashboard-monitor-main">
            @if($canViewSystem && $storageOverview !== null)
            @php
                $disk = $storageOverview['disk'];
                $databaseStorage = $storageOverview['database'];
                $diskPercent = $disk['used_percent'] !== null
                    ? number_format($disk['used_percent'], 1, app()->getLocale() === 'ru' ? ',' : '.', '')
                    : null;
                $diskPercentCss = $disk['used_percent'] !== null
                    ? number_format($disk['used_percent'], 1, '.', '')
                    : '0';
            @endphp
            <section class="admin-data-card dashboard-monitor-card dashboard-storage-card" data-testid="dashboard-storage-card">
                <header>
                    <h2>{{ __('Storage') }}</h2>
                    <a wire:navigate href="{{ route('admin.settings.system') }}">{{ __('System information') }}</a>
                </header>

                <div class="dashboard-storage-body">
                    <article class="dashboard-storage-section" data-testid="dashboard-disk-storage">
                        <div class="dashboard-storage-heading">
                            <div>
                                <strong>{{ __('Server disk') }}</strong>
                                <small>{{ __('Space used by KaevCMS files, logs, backups and other data on this filesystem.') }}</small>
                            </div>
                            @if($disk['available'])
                                <span class="status-badge status-badge-{{ $disk['state'] === 'danger' ? 'danger' : ($disk['state'] === 'warning' ? 'warning' : 'success') }}">{{ $diskPercent }}%</span>
                            @else
                                <span class="status-badge status-badge-muted">{{ __('Unavailable') }}</span>
                            @endif
                        </div>

                        @if($disk['available'])
                            <progress
                                class="dashboard-storage-progress dashboard-storage-progress-{{ $disk['state'] }}"
                                data-testid="dashboard-disk-progress"
                                role="progressbar"
                                aria-label="{{ __('Server disk usage') }}"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                aria-valuenow="{{ $diskPercentCss }}"
                                max="100"
                                value="{{ $diskPercentCss }}"
                            >{{ $diskPercent }}%</progress>
                            <div class="dashboard-storage-summary">
                                <span>{{ __('Used :used of :total', ['used' => $disk['used'], 'total' => $disk['total']]) }}</span>
                                <strong>{{ __('Free: :free', ['free' => $disk['free']]) }}</strong>
                            </div>
                        @else
                            <p class="dashboard-storage-unavailable">{{ __('Disk space statistics are unavailable on this server.') }}</p>
                        @endif
                    </article>

                    <article class="dashboard-storage-section" data-testid="dashboard-database-storage">
                        <div class="dashboard-storage-heading">
                            <div>
                                <strong>{{ __('KaevCMS database') }}</strong>
                                <small>
                                    {{ $databaseStorage['driver_label'] }}
                                    @if($databaseStorage['version'])
                                        · {{ __('Version :version', ['version' => $databaseStorage['version']]) }}
                                    @endif
                                </small>
                            </div>
                            <span class="status-badge {{ $databaseStorage['connected'] ? 'status-badge-success' : 'status-badge-danger' }}">
                                {{ $databaseStorage['connected'] ? __('Connected') : __('Connection failed') }}
                            </span>
                        </div>

                        @if($databaseStorage['connected'] && $databaseStorage['statistics_available'])
                            <div class="dashboard-storage-metrics">
                                <div><span>{{ __('Total size') }}</span><strong>{{ $databaseStorage['total'] ?? '—' }}</strong></div>
                                <div><span>{{ __('Data') }}</span><strong>{{ $databaseStorage['data'] ?? '—' }}</strong></div>
                                <div><span>{{ __('Indexes') }}</span><strong>{{ $databaseStorage['indexes'] ?? '—' }}</strong></div>
                                <div><span>{{ __('Tables') }}</span><strong>{{ $databaseStorage['table_count'] !== null ? number_format($databaseStorage['table_count'], 0, '.', ' ') : '—' }}</strong></div>
                            </div>
                            @if($databaseStorage['driver'] === 'sqlite' && ($databaseStorage['data'] === null || $databaseStorage['indexes'] === null))
                                <p class="dashboard-storage-note">{{ __('Separate data and index sizes are not available for this SQLite build.') }}</p>
                            @endif
                        @elseif($databaseStorage['connected'])
                            <p class="dashboard-storage-unavailable">{{ __('Database size statistics are unavailable with the current hosting permissions.') }}</p>
                        @else
                            <p class="dashboard-storage-unavailable dashboard-storage-unavailable-danger">{{ __('Could not read database storage statistics because the CMS database is unavailable.') }}</p>
                        @endif
                    </article>
                </div>
            </section>
            @endif

            @if($canViewUsers && $playerOverview !== null)
            <section class="admin-data-card dashboard-monitor-card dashboard-players-card" data-testid="dashboard-players-card">
                <header>
                    <h2>{{ __('Players') }}</h2>
                    <a wire:navigate href="{{ route('admin.users.index') }}">{{ __('Users') }}</a>
                </header>

                <div class="dashboard-storage-section">
                    <div class="dashboard-storage-metrics dashboard-player-metrics">
                        <div>
                            <span>{{ __('Registered users') }}</span>
                            <strong>{{ number_format($playerOverview['registered_users'], 0, '.', ' ') }}</strong>
                        </div>
                        <div>
                            <span>{{ __('Game accounts') }}</span>
                            <strong>{{ number_format($playerOverview['game_accounts'], 0, '.', ' ') }}</strong>
                        </div>
                        <div>
                            <span>{{ __('Characters') }}</span>
                            <strong>{{ $playerOverview['characters'] !== null ? number_format($playerOverview['characters'], 0, '.', ' ') : '—' }}</strong>
                        </div>
                        @if($playerOverview['support_attention'] !== null && $playerOverview['support_route'])
                            <a wire:navigate class="dashboard-player-metric-link" href="{{ route($playerOverview['support_route']) }}">
                                <span>{{ __('Support requests requiring attention') }}</span>
                                <strong>{{ number_format($playerOverview['support_attention'], 0, '.', ' ') }}</strong>
                            </a>
                        @endif
                    </div>

                    @if($playerOverview['characters_partial'])
                        <p class="dashboard-storage-note">{{ __('Character count is shown only for game databases currently available to KaevCMS.') }}</p>
                    @elseif($playerOverview['characters'] === null)
                        <p class="dashboard-storage-note">{{ __('Character statistics will appear after a game database is configured and available.') }}</p>
                    @endif
                </div>
            </section>
            @endif
        </div>
        @endif

        @if($hasDashboardSide)
        <div class="dashboard-monitor-side">
        @if($canViewServers)
        <section class="admin-data-card dashboard-monitor-card" data-testid="dashboard-game-servers-card">
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

        <section class="admin-data-card dashboard-monitor-card" data-testid="dashboard-login-servers-card">
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
        @endif
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
