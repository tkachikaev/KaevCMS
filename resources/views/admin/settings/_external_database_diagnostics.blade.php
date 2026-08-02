@php
    $externalDatabaseSummaries = $system['external_databases']['summaries'];
@endphp

<section class="system-compact-connections" data-testid="external-database-diagnostics">
    <div class="system-section-heading system-compact-connections-heading">
        <div>
            <h2>{{ __('external_databases.summary_title') }}</h2>
            <p>{{ __('external_databases.summary_description') }}</p>
        </div>
        @if(! request()->attributes->get('admin_read_only'))
            <form method="POST" action="{{ route('admin.settings.system.external-databases.refresh') }}">
                @csrf
                <button class="button button-secondary" type="submit">{{ __('external_databases.refresh') }}</button>
            </form>
        @endif
    </div>

    <div class="system-compact-card-grid">
        <article class="form-card system-card system-compact-card" data-testid="system-app-key-card">
            <header class="system-compact-card-header">
                <h2>{{ __('APP_KEY encryption') }}</h2>
                <span class="status-badge status-badge-{{ $system['security']['encryption']['state'] === 'success' ? 'success' : 'danger' }}">{{ $system['security']['encryption']['status'] }}</span>
            </header>
            <div class="system-compact-card-metrics">
                <div><strong>{{ $system['security']['encryption']['encrypted_values_total'] }}</strong><span>{{ __('Encrypted values') }}</span></div>
                <div><strong>{{ $system['security']['encryption']['invalid_values_total'] }}</strong><span>{{ __('Unavailable values') }}</span></div>
            </div>
            <p>{{ $system['security']['encryption']['details'] }}</p>
        </article>

        @foreach([
            'login' => [
                'label' => __('LoginServer'),
                'summary' => $externalDatabaseSummaries['login'],
                'route' => route('admin.settings.login-server'),
            ],
            'game' => [
                'label' => __('GameServer'),
                'summary' => $externalDatabaseSummaries['game'],
                'route' => route('admin.settings.game-server'),
            ],
        ] as $type => $connection)
            <article
                class="form-card system-card system-compact-card system-connection-summary-card"
                data-testid="system-connection-card"
                data-server-type="{{ $type }}"
                data-connection-state="{{ $connection['summary']['state'] }}"
            >
                <header class="system-compact-card-header">
                    <h2>{{ $connection['label'] }}</h2>
                    <span class="status-badge status-badge-{{ $connection['summary']['badge'] }}">{{ $connection['summary']['status'] }}</span>
                </header>
                <div class="system-compact-card-metrics">
                    <div><strong>{{ $connection['summary']['total'] }}</strong><span>{{ __('external_databases.summary.configured') }}</span></div>
                    <div><strong>{{ $connection['summary']['available'] }}</strong><span>{{ __('external_databases.summary.available') }}</span></div>
                </div>
                <p>{{ $connection['summary']['details'] }}</p>
                <footer class="system-compact-card-footer">
                    <small>{{ __('external_databases.summary.last_check', ['value' => $connection['summary']['checked_at']?->format('d.m.Y H:i') ?? __('external_databases.not_checked')]) }}</small>
                    <a wire:navigate href="{{ $connection['route'] }}">{{ __('Settings') }} →</a>
                </footer>
            </article>
        @endforeach
    </div>

    <p class="system-external-databases-boundary">{{ __('external_databases.safe_boundary') }}</p>
</section>
