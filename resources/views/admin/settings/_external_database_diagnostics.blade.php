<section class="form-card system-external-databases-card" data-testid="external-database-diagnostics">
    <div class="system-section-heading">
        <div>
            <h2>{{ __('external_databases.title') }}</h2>
            <p>{{ __('external_databases.description') }}</p>
        </div>
        @if(! request()->attributes->get('admin_read_only'))
            <form method="POST" action="{{ route('admin.settings.system.external-databases.refresh') }}">
                @csrf
                <button class="button button-secondary" type="submit">{{ __('external_databases.refresh') }}</button>
            </form>
        @endif
    </div>

    <p class="system-external-databases-hint">{{ __('external_databases.refresh_hint') }}</p>

    @foreach([
        __('external_databases.login_servers') => $system['external_databases']['login_servers'],
        __('external_databases.game_servers') => $system['external_databases']['game_servers'],
    ] as $groupLabel => $servers)
        <div class="system-external-database-group">
            <h3>{{ $groupLabel }}</h3>

            @if($servers === [])
                <div class="admin-empty-state compact-empty-state">
                    <p>{{ __('external_databases.none_configured') }}</p>
                </div>
            @else
                <div class="system-external-database-list">
                    @foreach($servers as $server)
                        <article
                            class="system-external-database"
                            data-testid="external-database-row"
                            data-server-type="{{ $server['type'] }}"
                            data-database-status="{{ $server['status'] }}"
                        >
                            <header class="system-external-database-header">
                                <div>
                                    <strong>{{ $server['name'] }}</strong>
                                    <small>{{ $server['driver_label'] }}</small>
                                </div>
                                <span class="status-badge status-badge-{{ $server['status_state'] === 'success' ? 'success' : ($server['status_state'] === 'danger' ? 'danger' : 'muted') }}">
                                    {{ $server['status_label'] }}
                                </span>
                            </header>

                            <dl class="system-definition-list system-external-database-details">
                                <div>
                                    <dt>{{ __('external_databases.service_status') }}</dt>
                                    <dd>
                                        <span class="status-badge status-badge-{{ $server['service_state'] === 'success' ? 'success' : ($server['service_state'] === 'danger' ? 'danger' : 'muted') }}">{{ $server['service_label'] }}</span>
                                    </dd>
                                </div>
                                <div><dt>{{ __('external_databases.last_check') }}</dt><dd>{{ $server['checked_at']?->format('d.m.Y H:i:s') ?? __('external_databases.not_checked') }}</dd></div>
                                <div><dt>{{ __('external_databases.last_success') }}</dt><dd>{{ $server['last_success_at']?->format('d.m.Y H:i:s') ?? __('external_databases.never') }}</dd></div>
                                <div><dt>{{ __('external_databases.query_latency') }}</dt><dd>{{ $server['latency_ms'] !== null ? __('external_databases.milliseconds', ['value' => $server['latency_ms']]) : __('external_databases.not_available') }}</dd></div>
                                <div><dt>{{ __('external_databases.schema_profile') }}</dt><dd>{{ $server['profile_label'] ?? __('external_databases.not_available') }}</dd></div>
                                @if($server['type'] === 'game')
                                    <div>
                                        <dt>{{ __('external_databases.connection_source') }}</dt>
                                        <dd>{{ $server['uses_login_connection'] ? __('external_databases.uses_login_connection') : __('external_databases.uses_own_connection') }}</dd>
                                    </div>
                                @endif
                                @if($server['last_error_class'])
                                    <div>
                                        <dt>{{ __('external_databases.last_error') }}</dt>
                                        <dd>
                                            <code>{{ $server['last_error_class'] }}</code>
                                            @if($server['last_error_at'])
                                                <small class="system-definition-note">{{ $server['last_error_at']->format('d.m.Y H:i:s') }}</small>
                                            @endif
                                            @if($server['error_label'])
                                                <small class="system-definition-note">{{ $server['error_label'] }}</small>
                                            @endif
                                        </dd>
                                    </div>
                                @endif
                            </dl>

                            <div class="system-external-database-section">
                                <strong>{{ __('external_databases.capabilities') }}</strong>
                                @if($server['capability_labels'] === [])
                                    <span class="system-external-database-empty">{{ __('external_databases.not_available') }}</span>
                                @else
                                    <div class="system-external-database-tags">
                                        @foreach($server['capability_labels'] as $capability)
                                            <span class="status-badge status-badge-muted">{{ $capability }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            <div class="system-external-database-section">
                                <strong>{{ __('external_databases.tables') }}</strong>
                                @if($server['tables'] === [])
                                    <span class="system-external-database-empty">{{ __('external_databases.not_available') }}</span>
                                @else
                                    <div class="system-external-database-tables">
                                        @foreach($server['tables'] as $table)
                                            <div class="system-external-database-table">
                                                <div>
                                                    <code>{{ $table['name'] }}</code>
                                                    <small>{{ $table['required'] ? __('external_databases.required') : __('external_databases.optional') }}</small>
                                                    @if($table['missing_columns'] !== [])
                                                        <small>{{ __('external_databases.missing_columns', ['columns' => implode(', ', $table['missing_columns'])]) }}</small>
                                                    @endif
                                                </div>
                                                <span class="status-badge status-badge-{{ $table['state'] === 'success' ? 'success' : ($table['state'] === 'danger' ? 'danger' : 'muted') }}">{{ $table['status'] }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    @endforeach

    <p class="system-external-databases-boundary">{{ __('external_databases.safe_boundary') }}</p>
</section>
