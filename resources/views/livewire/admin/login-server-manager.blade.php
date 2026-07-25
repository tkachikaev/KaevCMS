<div class="server-manager">
    @if($status)
        <div class="notice notice-success" role="status">{{ $status }}</div>
    @endif

    @error('loginServer')
        <div class="notice notice-error" role="alert">{{ $message }}</div>
    @enderror

    <div class="server-manager-toolbar">
        <div>
            <span>{{ __('Login server count') }}</span>
            <strong>{{ $servers->count() }}</strong>
        </div>
        <button class="button button-primary" type="button" wire:click="create">+ {{ __('Add login server') }}</button>
    </div>

    @if($servers->isEmpty())
        <div class="empty-state">
            <div class="empty-state-mark" aria-hidden="true">L</div>
            <h2>{{ __('No login servers configured') }}</h2>
            <p>{{ __('Add a login server before connecting a game server database.') }}</p>
        </div>
    @else
        <div class="server-card-grid">
            @foreach($servers as $server)
                @php($driver = $drivers[$server->driver] ?? null)
                @php($cardTestResult = $cardTestResults[$server->id] ?? null)
                @php($cardConfigured = $server->database_status === 'configured')
                @php($databasePending = $server->database_status === 'unknown')
                <article class="server-summary-card" wire:key="login-server-{{ $server->id }}">
                    <div class="server-summary-head">
                        <div class="server-summary-icon" aria-hidden="true">L</div>
                        <div class="server-summary-title">
                            <h2>{{ $server->name }}</h2>
                            <p>{{ $driver['label'] ?? $server->driver }}</p>
                        </div>
                        <span @class(['status-badge', 'status-badge-success' => $cardConfigured, 'status-badge-muted' => $databasePending, 'status-badge-danger' => ! $cardConfigured && ! $databasePending])>
                            {{ $cardConfigured ? __('Configured') : ($databasePending ? __('Status pending') : __('Not configured')) }}
                        </span>
                    </div>

                    <dl class="server-summary-meta">
                        <div>
                            <dt>{{ __('Database') }}</dt>
                            <dd>{{ $server->database_host }}:{{ $server->database_port }} / {{ $server->database_name }}</dd>
                        </div>
                        <div>
                            <dt>{{ __('Usage') }}</dt>
                            <dd>{{ trans_choice(':count game server|:count game servers', $server->game_servers_count, ['count' => $server->game_servers_count]) }} · {{ trans_choice(':count player account|:count player accounts', $server->user_game_accounts_count, ['count' => $server->user_game_accounts_count]) }}</dd>
                        </div>
                        <div>
                            <dt>{{ __('Database status') }}</dt>
                            <dd>{{ $server->database_status === 'configured' ? __('Connected') : ($server->database_status === 'not_configured' ? __('Connection failed') : __('Status pending')) }}</dd>
                        </div>
                        <div>
                            <dt>{{ __('Service status') }}</dt>
                            <dd>{{ $server->monitor_status === 'online' ? __('Running') : ($server->monitor_status === 'offline' ? __('Unavailable') : __('Status pending')) }}</dd>
                        </div>
                        @if($server->hasDatabasePassword())
                            <div>
                                <dt>{{ __('Password') }}</dt>
                                <dd>{{ __('A database password is saved.') }}</dd>
                            </div>
                        @endif
                    </dl>

                    @if(is_array($cardTestResult))
                        <div @class(['server-card-test-result', 'success' => $cardTestResult['state'] === 'success', 'failed' => $cardTestResult['state'] !== 'success']) role="status">
                            <span aria-hidden="true"></span>
                            <strong>{{ $cardTestResult['message'] }}</strong>
                        </div>
                    @endif

                    <div class="server-summary-actions">
                        <button class="button button-secondary" type="button" wire:click="testStored({{ $server->id }})" wire:loading.attr="disabled" wire:target="testStored({{ $server->id }})">
                            <span wire:loading.remove wire:target="testStored({{ $server->id }})">{{ __('Test connection') }}</span>
                            <span wire:loading wire:target="testStored({{ $server->id }})">{{ __('Checking…') }}</span>
                        </button>
                        <button class="button button-primary" type="button" wire:click="edit({{ $server->id }})">{{ __('Configure') }}</button>
                        <details class="server-card-menu">
                            <summary aria-label="{{ __('More actions') }}">⋯</summary>
                            <button type="button" wire:click="confirmDelete({{ $server->id }})" @disabled($server->game_servers_count > 0 || $server->user_game_accounts_count > 0)>{{ __('Delete') }}</button>
                        </details>
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    <div @class(['server-drawer-backdrop', 'open' => $drawerOpen]) @if(!$drawerOpen) hidden @endif wire:cloak wire:pointerdown.self="closeDrawer">
        <section class="server-drawer" role="dialog" aria-modal="true" aria-labelledby="login-server-drawer-title">
            <header class="server-drawer-header">
                <div>
                    <span>{{ $editingId === null ? __('New connection') : __('Connection settings') }}</span>
                    <h2 id="login-server-drawer-title">{{ $editingId === null ? __('Add login server') : $name }}</h2>
                </div>
                <button class="server-drawer-close" type="button" wire:click="closeDrawer" aria-label="{{ __('Close') }}">×</button>
            </header>

            <nav class="server-drawer-tabs" role="tablist" aria-label="{{ __('Login server settings sections') }}">
                <button
                    @class(['server-drawer-tab', 'active' => $activeTab === 'general'])
                    type="button"
                    role="tab"
                    aria-selected="{{ $activeTab === 'general' ? 'true' : 'false' }}"
                    aria-controls="login-server-tab-general"
                    wire:click="setActiveTab('general')"
                >
                    <span aria-hidden="true">●</span>{{ __('Basic settings') }}
                </button>
                <button
                    @class(['server-drawer-tab', 'active' => $activeTab === 'network'])
                    type="button"
                    role="tab"
                    aria-selected="{{ $activeTab === 'network' ? 'true' : 'false' }}"
                    aria-controls="login-server-tab-network"
                    wire:click="setActiveTab('network')"
                >
                    <span aria-hidden="true">↔</span>{{ __('Network settings') }}
                </button>
            </nav>

            <div class="server-drawer-body">
                @if($activeTab === 'general')
                    <div id="login-server-tab-general" role="tabpanel">
                        <section class="server-drawer-section">
                            <div class="server-drawer-section-title">
                                <h3>{{ __('General') }}</h3>
                                <p>{{ __('Name and database driver.') }}</p>
                            </div>
                            <div class="server-form-grid">
                                <div class="form-group">
                                    <label for="live_login_name">{{ __('Name') }}</label>
                                    <input id="live_login_name" type="text" maxlength="100" wire:model="name" required>
                                    @error('name')<small class="field-error">{{ $message }}</small>@enderror
                                </div>
                                <div class="form-group">
                                    <label for="live_login_driver">{{ __('LoginServer driver') }}</label>
                                    <select id="live_login_driver" wire:model="driver" required>
                                        @foreach($drivers as $key => $driverOption)
                                            <option value="{{ $key }}">{{ $driverOption['label'] }}@if(!$driverOption['ready']) — {{ __('placeholder') }}@endif</option>
                                        @endforeach
                                    </select>
                                    @error('driver')<small class="field-error">{{ $message }}</small>@enderror
                                </div>
                            </div>
                        </section>

                        <section class="server-drawer-section">
                            <div class="server-drawer-section-title">
                                <h3>{{ __('Database connection') }}</h3>
                                <p>{{ __('Credentials are encrypted with APP_KEY before storage.') }}</p>
                            </div>
                            <div class="server-form-grid">
                                <div class="form-group">
                                    <label for="live_login_host">{{ __('Database host') }}</label>
                                    <input id="live_login_host" type="text" maxlength="255" wire:model="databaseHost" placeholder="127.0.0.1">
                                    @error('databaseHost')<small class="field-error">{{ $message }}</small>@enderror
                                </div>
                                <div class="form-group server-form-port">
                                    <label for="live_login_port">{{ __('Database port') }}</label>
                                    <input id="live_login_port" type="number" min="1" max="65535" wire:model="databasePort">
                                    @error('databasePort')<small class="field-error">{{ $message }}</small>@enderror
                                </div>
                                <div class="form-group">
                                    <label for="live_login_database">{{ __('Database name') }}</label>
                                    <input id="live_login_database" type="text" maxlength="64" wire:model="databaseName">
                                    @error('databaseName')<small class="field-error">{{ $message }}</small>@enderror
                                </div>
                                <div class="form-group">
                                    <label for="live_login_username">{{ __('Database username') }}</label>
                                    <input id="live_login_username" type="text" maxlength="128" autocomplete="off" wire:model="databaseUsername">
                                    @error('databaseUsername')<small class="field-error">{{ $message }}</small>@enderror
                                </div>
                                <div class="form-group">
                                    <label for="live_login_password">{{ __('Database password') }}</label>
                                    <input id="live_login_password" type="password" maxlength="1024" autocomplete="new-password" wire:model="databasePassword">
                                    <small>{{ $editingId !== null ? __('Leave empty to keep the saved database password.') : __('The password is encrypted with APP_KEY before it is stored.') }}</small>
                                    @error('databasePassword')<small class="field-error">{{ $message }}</small>@enderror
                                </div>
                                <div class="form-group server-form-charset">
                                    <label for="live_login_charset">{{ __('Database charset') }}</label>
                                    <select id="live_login_charset" wire:model="databaseCharset">
                                        @foreach(['utf8mb4', 'utf8', 'latin1', 'cp1251'] as $charset)
                                            <option value="{{ $charset }}">{{ $charset }}</option>
                                        @endforeach
                                    </select>
                                    @error('databaseCharset')<small class="field-error">{{ $message }}</small>@enderror
                                </div>
                            </div>
                        </section>
                    </div>
                @else
                    <div id="login-server-tab-network" role="tabpanel">
                        <section class="server-drawer-section">
                            <div class="server-drawer-section-title">
                                <h3>{{ __('Additional network settings') }}</h3>
                                <p>{{ __('Used to check whether the LoginServer process is actually listening. Leave the host empty to use the database host.') }}</p>
                            </div>
                            <div class="server-form-grid">
                                <div class="form-group">
                                    <label for="live_login_service_host">{{ __('Service host') }}</label>
                                    <input id="live_login_service_host" type="text" maxlength="255" wire:model="serviceHost" placeholder="{{ $databaseHost }}">
                                    @error('serviceHost')<small class="field-error">{{ $message }}</small>@enderror
                                </div>
                                <div class="form-group server-form-port">
                                    <label for="live_login_service_port">{{ __('Service port') }}</label>
                                    <input id="live_login_service_port" type="number" min="1" max="65535" wire:model="servicePort">
                                    @error('servicePort')<small class="field-error">{{ $message }}</small>@enderror
                                </div>
                            </div>
                        </section>
                    </div>
                @endif

                @include('livewire.admin._database-report', ['report' => $connectionReport])
            </div>

            <footer class="server-drawer-footer">
                <button class="button button-secondary" type="button" wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection">
                    <span wire:loading.remove wire:target="testConnection">{{ __('Test connection') }}</span>
                    <span wire:loading wire:target="testConnection">{{ __('Checking…') }}</span>
                </button>
                <button class="button button-primary" type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ __('Save changes') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                </button>
            </footer>
        </section>
    </div>

    @if($confirmingDeleteId !== null)
        <div class="server-confirm-backdrop" wire:cloak>
            <div class="server-confirm-card" role="alertdialog" aria-modal="true">
                <h2>{{ __('Delete login server?') }}</h2>
                <p>{{ __('The connection can only be deleted when no game servers or player accounts use it.') }}</p>
                <div>
                    <button class="button button-secondary" type="button" wire:click="cancelDelete">{{ __('Cancel') }}</button>
                    <button class="button button-danger" type="button" wire:click="deleteServer" wire:loading.attr="disabled" wire:target="deleteServer">{{ __('Delete') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
