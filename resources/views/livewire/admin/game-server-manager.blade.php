<div class="server-manager">
    @if($status)
        <div class="notice notice-success" role="status">{{ $status }}</div>
    @endif

    <div class="server-manager-toolbar">
        <div>
            <span>{{ __('Game server count') }}</span>
            <strong>{{ count($servers) }}</strong>
        </div>
        <div class="server-manager-toolbar-actions">
            <label class="server-manager-online-toggle" for="show_public_online">
                <span>{{ __('Show online on public website') }}</span>
                <span class="switch-control">
                    <input id="show_public_online" type="checkbox" @checked($showPublicOnline) wire:change="setShowPublicOnline($event.target.checked)">
                    <span aria-hidden="true"></span>
                </span>
            </label>
            <button class="button button-primary" type="button" wire:click="create">+ {{ __('Add game server') }}</button>
        </div>
    </div>

    @if($servers === [])
        <div class="empty-state">
            <div class="empty-state-mark" aria-hidden="true">S</div>
            <h2>{{ __('No game servers added') }}</h2>
            <p>{{ __('Add the first game world. The server block is hidden in the default theme while the list is empty.') }}</p>
        </div>
    @else
        <div class="server-card-grid">
            @foreach($servers as $server)
                @php
                    $driver = $server['driver'] !== null ? ($gameDrivers[$server['driver']] ?? null) : null;
                    $cardTestResult = $cardTestResults[$server['id']] ?? null;
                    $cardMaintenanceEnabled = $server['maintenance_enabled'];
                    $cardConfigured = $server['database_status'] === 'configured';
                    $databasePending = $server['database_status'] === 'unknown';
                    $endpoint = $server['use_login_server_connection']
                        ? $server['login_server_name']
                        : trim($server['database_host'].':'.($server['database_port'] ?? '').' / '.$server['database_name'], ' /');
                @endphp
                <article class="server-summary-card" wire:key="game-server-{{ $server['id'] }}">
                    <div class="server-summary-head">
                        <div class="server-summary-icon" aria-hidden="true">G</div>
                        <div class="server-summary-title">
                            <h2>{{ $server['name'] }}</h2>
                            <p>
                                @foreach(array_filter([$server['rates'], $server['chronicle'], $server['mode']]) as $meta)
                                    @if(!$loop->first) · @endif{{ $meta }}
                                @endforeach
                            </p>
                        </div>
                        <span @class([
                            'status-badge',
                            'status-badge-warning' => $cardMaintenanceEnabled,
                            'status-badge-success' => ! $cardMaintenanceEnabled && $cardConfigured,
                            'status-badge-muted' => ! $cardMaintenanceEnabled && $databasePending,
                            'status-badge-danger' => ! $cardMaintenanceEnabled && ! $cardConfigured && ! $databasePending,
                        ])>
                            {{ $cardMaintenanceEnabled ? __('Maintenance') : ($cardConfigured ? __('Configured') : ($databasePending ? __('Status pending') : __('Not configured'))) }}
                        </span>
                    </div>

                    <dl class="server-summary-meta">
                        <div>
                            <dt>{{ __('Login server') }}</dt>
                            <dd>{{ $server['login_server_name'] ?: __('Not selected') }}</dd>
                        </div>
                        <div>
                            <dt>{{ __('GameServer driver') }}</dt>
                            <dd>{{ $driver['label'] ?? __('Not selected') }}</dd>
                        </div>
                        @if($server['connection_configured'])
                            <div>
                                <dt>{{ __('Database') }}</dt>
                                <dd>{{ $endpoint ?: __('Inherited from login server') }}</dd>
                            </div>
                        @endif
                        <div>
                            <dt>{{ __('Public statistics') }}</dt>
                            <dd>{{ $server['statistics_enabled'] ? __('Enabled') : __('Disabled') }}</dd>
                        </div>
                        <div>
                            <dt>{{ __('Database status') }}</dt>
                            <dd>{{ $server['database_status'] === 'configured' ? __('Connected') : ($server['database_status'] === 'not_configured' ? __('Connection failed') : __('Status pending')) }}</dd>
                        </div>
                        <div>
                            <dt>{{ __('Service status') }}</dt>
                            <dd>{{ $server['service_status'] === 'online' ? __('Running') : ($server['service_status'] === 'offline' ? __('Unavailable') : __('Status pending')) }}</dd>
                        </div>
                        @if($cardMaintenanceEnabled && $server['maintenance_message'] !== '')
                            <div>
                                <dt>{{ __('Maintenance message') }}</dt>
                                <dd>{{ $server['maintenance_message'] }}</dd>
                            </div>
                        @endif
                        @if($server['database_password_saved'])
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
                        <button class="button button-secondary" type="button" wire:click="testStored({{ $server['id'] }})" wire:loading.attr="disabled" wire:target="testStored({{ $server['id'] }})" @disabled(!$server['connection_configured'])>
                            <span wire:loading.remove wire:target="testStored({{ $server['id'] }})">{{ __('Test connection') }}</span>
                            <span wire:loading wire:target="testStored({{ $server['id'] }})">{{ __('Checking…') }}</span>
                        </button>
                        <button class="button button-primary" type="button" wire:click="edit({{ $server['id'] }})">{{ __('Configure') }}</button>
                        <details class="server-card-menu">
                            <summary aria-label="{{ __('More actions') }}">⋯</summary>
                            <button type="button" wire:click="confirmDelete({{ $server['id'] }})">{{ __('Delete') }}</button>
                        </details>
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    <div @class(['server-drawer-backdrop', 'open' => $drawerOpen]) @if(!$drawerOpen) hidden @endif wire:cloak wire:pointerdown.self="closeDrawer">
        <section class="server-drawer server-drawer-wide" role="dialog" aria-modal="true" aria-labelledby="game-server-drawer-title">
            <header class="server-drawer-header">
                <div>
                    <span>{{ $editingId === null ? __('New game server') : __('Game server settings') }}</span>
                    <h2 id="game-server-drawer-title">{{ $editingId === null ? __('Add game server') : ($translations[$defaultLocale] ?? __('Game server')) }}</h2>
                </div>
                <button class="server-drawer-close" type="button" wire:click="closeDrawer" aria-label="{{ __('Close') }}">×</button>
            </header>

            <nav class="server-drawer-tabs" role="tablist" aria-label="{{ __('Game server settings sections') }}">
                <button
                    @class(['server-drawer-tab', 'active' => $activeTab === 'general'])
                    type="button"
                    role="tab"
                    aria-selected="{{ $activeTab === 'general' ? 'true' : 'false' }}"
                    aria-controls="game-server-tab-general"
                    wire:click="setActiveTab('general')"
                >
                    <span aria-hidden="true">●</span>{{ __('Basic settings') }}
                </button>
                <button
                    @class(['server-drawer-tab', 'active' => $activeTab === 'statistics'])
                    type="button"
                    role="tab"
                    aria-selected="{{ $activeTab === 'statistics' ? 'true' : 'false' }}"
                    aria-controls="game-server-tab-statistics"
                    wire:click="setActiveTab('statistics')"
                >
                    <span aria-hidden="true">▥</span>{{ __('Statistics') }}
                </button>
                <button
                    @class(['server-drawer-tab', 'active' => $activeTab === 'miscellaneous'])
                    type="button"
                    role="tab"
                    aria-selected="{{ $activeTab === 'miscellaneous' ? 'true' : 'false' }}"
                    aria-controls="game-server-tab-miscellaneous"
                    wire:click="setActiveTab('miscellaneous')"
                >
                    <span aria-hidden="true">•••</span>{{ __('Miscellaneous') }}
                </button>
            </nav>

            <div class="server-drawer-body">
                @if($activeTab === 'general')
                    <div id="game-server-tab-general" role="tabpanel">
                        <section class="server-drawer-section">
                            <div class="server-drawer-section-title">
                                <h3>{{ __('General') }}</h3>
                                <p>{{ __('Public name and characteristics of the game world.') }}</p>
                            </div>

                            <div class="server-language-grid">
                                @foreach($languages as $code => $language)
                                    <div class="form-group">
                                        <label for="live_game_name_{{ $code }}">
                                            {{ __('Server name') }} — {{ $language['native_name'] }}
                                            @if($code === $defaultLocale)<span class="compact-default-badge">{{ __('Default locale marker') }}</span>@endif
                                        </label>
                                        <input id="live_game_name_{{ $code }}" type="text" maxlength="100" wire:model="translations.{{ $code }}" @if($code === $defaultLocale) required @endif>
                                        @error('translations.'.$code)<small class="field-error">{{ $message }}</small>@enderror
                                    </div>
                                @endforeach
                            </div>

                            <div class="server-form-grid server-form-grid-three">
                                <div class="form-group">
                                    <label for="live_game_rates">{{ __('Server rates') }}</label>
                                    <input id="live_game_rates" type="text" maxlength="100" wire:model="serverRates" placeholder="x5">
                                    @error('serverRates')<small class="field-error">{{ $message }}</small>@enderror
                                </div>
                                <div class="form-group">
                                    <label for="live_game_chronicle">{{ __('Chronicle') }}</label>
                                    <input id="live_game_chronicle" type="text" maxlength="100" wire:model="serverChronicle" placeholder="Interlude">
                                    @error('serverChronicle')<small class="field-error">{{ $message }}</small>@enderror
                                </div>
                                <div class="form-group">
                                    <label for="live_game_mode">{{ __('Mode') }}</label>
                                    <input id="live_game_mode" type="text" maxlength="100" wire:model="serverMode" placeholder="PvP, PvE, Craft">
                                    @error('serverMode')<small class="field-error">{{ $message }}</small>@enderror
                                </div>
                            </div>
                        </section>

                        <section class="server-drawer-section">
                            <label class="server-connection-enable">
                                <span>
                                    <strong>{{ __('Database connection') }}</strong>
                                    <small>{{ __('Connect this game world to an external GameServer database.') }}</small>
                                </span>
                                <span class="switch-control">
                                    <input type="checkbox" wire:model.live="connectionEnabled">
                                    <span></span>
                                </span>
                            </label>

                            @if($connectionEnabled)
                                @if($loginServers->isEmpty())
                                    <div class="settings-disabled-notice">
                                        {{ __('Create a login server first, then return here to configure the game server database.') }}
                                        <a href="{{ route('admin.settings.login-server') }}">{{ __('Open login servers') }}</a>
                                    </div>
                                @else
                                    <div class="server-form-grid">
                                        <div class="form-group">
                                            <label for="live_game_login_server">{{ __('Login server') }}</label>
                                            <select id="live_game_login_server" wire:model="loginServerId" required>
                                                <option value="">— {{ __('Select login server') }} —</option>
                                                @foreach($loginServers as $loginServer)
                                                    <option value="{{ $loginServer->id }}">{{ $loginServer->name }}</option>
                                                @endforeach
                                            </select>
                                            @error('loginServerId')<small class="field-error">{{ $message }}</small>@enderror
                                        </div>
                                        <div class="form-group">
                                            <label for="live_game_driver">{{ __('GameServer driver') }}</label>
                                            <select id="live_game_driver" wire:model.live="driver" required>
                                                @foreach($gameDrivers as $key => $driverOption)
                                                    <option value="{{ $key }}">{{ $driverOption['label'] }}@if(!$driverOption['ready']) — {{ __('placeholder') }}@endif</option>
                                                @endforeach
                                            </select>
                                            @error('driver')<small class="field-error">{{ $message }}</small>@enderror
                                        </div>
                                    </div>

                                    <label class="server-connection-toggle compact-toggle">
                                        <input type="checkbox" wire:model.live="useLoginServerConnection">
                                        <span>
                                            <strong>{{ __('Use the selected LoginServer database parameters') }}</strong>
                                            <small>{{ __('Host, port, database, user, password and charset will be taken from the LoginServer.') }}</small>
                                        </span>
                                    </label>

                                    @if(!$useLoginServerConnection)
                                        <div class="server-form-grid">
                                            <div class="form-group">
                                                <label for="live_game_host">{{ __('Database host') }}</label>
                                                <input id="live_game_host" type="text" maxlength="255" wire:model="databaseHost">
                                                @error('databaseHost')<small class="field-error">{{ $message }}</small>@enderror
                                            </div>
                                            <div class="form-group server-form-port">
                                                <label for="live_game_port">{{ __('Database port') }}</label>
                                                <input id="live_game_port" type="number" min="1" max="65535" wire:model="databasePort">
                                                @error('databasePort')<small class="field-error">{{ $message }}</small>@enderror
                                            </div>
                                            <div class="form-group">
                                                <label for="live_game_database">{{ __('Database name') }}</label>
                                                <input id="live_game_database" type="text" maxlength="64" wire:model="databaseName">
                                                @error('databaseName')<small class="field-error">{{ $message }}</small>@enderror
                                            </div>
                                            <div class="form-group">
                                                <label for="live_game_username">{{ __('Database username') }}</label>
                                                <input id="live_game_username" type="text" maxlength="128" autocomplete="off" wire:model="databaseUsername">
                                                @error('databaseUsername')<small class="field-error">{{ $message }}</small>@enderror
                                            </div>
                                            <div class="form-group">
                                                <label for="live_game_password">{{ __('Database password') }}</label>
                                                <input id="live_game_password" type="password" maxlength="1024" autocomplete="new-password" wire:model="databasePassword">
                                                <small>{{ $editingId !== null ? __('Leave empty to keep the saved database password.') : __('The password is encrypted with APP_KEY before it is stored.') }}</small>
                                                @error('databasePassword')<small class="field-error">{{ $message }}</small>@enderror
                                            </div>
                                            <div class="form-group server-form-charset">
                                                <label for="live_game_charset">{{ __('Database charset') }}</label>
                                                <select id="live_game_charset" wire:model="databaseCharset">
                                                    @foreach(['utf8mb4', 'utf8', 'latin1', 'cp1251'] as $charset)
                                                        <option value="{{ $charset }}">{{ $charset }}</option>
                                                    @endforeach
                                                </select>
                                                @error('databaseCharset')<small class="field-error">{{ $message }}</small>@enderror
                                            </div>
                                        </div>
                                    @endif

                                    @include('livewire.admin._database-report', ['report' => $connectionReport])
                                @endif
                            @endif
                        </section>
                    </div>
                @elseif($activeTab === 'statistics')
                    <div id="game-server-tab-statistics" role="tabpanel">
                        @if($statisticsCapabilities !== [])
                            <section class="server-drawer-section">
                                <label class="server-connection-enable">
                                    <span>
                                        <strong>{{ __('Public statistics') }}</strong>
                                        <small>{{ __('Show read-only character rankings, current heroes and castle owners on the public website.') }}</small>
                                    </span>
                                    <span class="switch-control">
                                        <input type="checkbox" wire:model.live="statisticsEnabled">
                                        <span></span>
                                    </span>
                                </label>
                                @error('statisticsEnabled')<small class="field-error">{{ $message }}</small>@enderror

                                <div @class(['server-statistics-disabled' => ! $statisticsEnabled])>
                                    <div class="server-statistics-group">
                                        <div class="server-statistics-heading">
                                            <div>
                                                <h3>{{ __('Character rankings') }}</h3>
                                                <p>{{ __('Set an individual public row limit for each ranking.') }}</p>
                                            </div>
                                            <span>{{ __('Quantity') }}</span>
                                        </div>

                                        <div class="server-statistics-rows">
                                            <div class="server-statistics-row">
                                                <label class="server-connection-toggle compact-toggle">
                                                    <input type="checkbox" wire:model.live="statisticsLevelEnabled" @disabled(!$statisticsEnabled)>
                                                    <span><strong>{{ __('Level ranking') }}</strong></span>
                                                </label>
                                                <div class="server-statistics-quantity">
                                                    <input aria-label="{{ __('Level ranking limit') }}" type="number" min="1" max="100" wire:model="statisticsLevelLimit" @disabled(!$statisticsEnabled || !$statisticsLevelEnabled)>
                                                    @error('statisticsLevelLimit')<small class="field-error">{{ $message }}</small>@enderror
                                                </div>
                                            </div>
                                            <div class="server-statistics-row">
                                                <label class="server-connection-toggle compact-toggle">
                                                    <input type="checkbox" wire:model.live="statisticsPvpEnabled" @disabled(!$statisticsEnabled)>
                                                    <span><strong>{{ __('PvP ranking') }}</strong></span>
                                                </label>
                                                <div class="server-statistics-quantity">
                                                    <input aria-label="{{ __('PvP ranking limit') }}" type="number" min="1" max="100" wire:model="statisticsPvpLimit" @disabled(!$statisticsEnabled || !$statisticsPvpEnabled)>
                                                    @error('statisticsPvpLimit')<small class="field-error">{{ $message }}</small>@enderror
                                                </div>
                                            </div>
                                            <div class="server-statistics-row">
                                                <label class="server-connection-toggle compact-toggle">
                                                    <input type="checkbox" wire:model.live="statisticsPkEnabled" @disabled(!$statisticsEnabled)>
                                                    <span><strong>{{ __('PK ranking') }}</strong></span>
                                                </label>
                                                <div class="server-statistics-quantity">
                                                    <input aria-label="{{ __('PK ranking limit') }}" type="number" min="1" max="100" wire:model="statisticsPkLimit" @disabled(!$statisticsEnabled || !$statisticsPkEnabled)>
                                                    @error('statisticsPkLimit')<small class="field-error">{{ $message }}</small>@enderror
                                                </div>
                                            </div>
                                            <div class="server-statistics-row">
                                                <label class="server-connection-toggle compact-toggle">
                                                    <input type="checkbox" wire:model.live="statisticsPlayTimeEnabled" @disabled(!$statisticsEnabled)>
                                                    <span><strong>{{ __('Play time ranking') }}</strong></span>
                                                </label>
                                                <div class="server-statistics-quantity">
                                                    <input aria-label="{{ __('Play time ranking limit') }}" type="number" min="1" max="100" wire:model="statisticsPlayTimeLimit" @disabled(!$statisticsEnabled || !$statisticsPlayTimeEnabled)>
                                                    @error('statisticsPlayTimeLimit')<small class="field-error">{{ $message }}</small>@enderror
                                                </div>
                                            </div>
                                            <div class="server-statistics-row">
                                                <label class="server-connection-toggle compact-toggle">
                                                    <input type="checkbox" wire:model="statisticsHeroesEnabled" @disabled(!$statisticsEnabled)>
                                                    <span><strong>{{ __('Current heroes') }}</strong></span>
                                                </label>
                                                <span class="server-statistics-fixed-value">{{ __('All current heroes') }}</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="server-statistics-group">
                                        <div class="server-statistics-heading">
                                            <div>
                                                <h3>{{ __('Clans and holdings') }}</h3>
                                                <p>{{ __('Ownership lists are shown in full and are not limited.') }}</p>
                                            </div>
                                        </div>
                                        <div class="server-statistics-rows">
                                            <div class="server-statistics-row">
                                                <label class="server-connection-toggle compact-toggle">
                                                    <input type="checkbox" wire:model="statisticsCastlesEnabled" @disabled(!$statisticsEnabled)>
                                                    <span><strong>{{ __('Castle owners') }}</strong></span>
                                                </label>
                                                <span class="server-statistics-fixed-value">{{ __('All owners') }}</span>
                                            </div>
                                            <div class="server-statistics-row disabled" aria-disabled="true">
                                                <label class="server-connection-toggle compact-toggle">
                                                    <input type="checkbox" disabled>
                                                    <span><strong>{{ __('Fortress owners') }}</strong></span>
                                                </label>
                                                <span class="server-statistics-fixed-value">{{ __('Not supported') }}</span>
                                            </div>
                                        </div>
                                    </div>

                                    <small class="server-statistics-cache-note">{{ __('Statistics are cached for five minutes to protect the game database.') }} {{ __('Allowed quantity is from 1 to 100.') }}</small>
                                </div>
                            </section>
                        @else
                            <section class="server-drawer-section">
                                <div class="server-statistics-unavailable">
                                    <strong>{{ __('Public statistics') }}</strong>
                                    <p>{{ __('The selected GameServer driver does not support public statistics.') }}</p>
                                </div>
                            </section>
                        @endif
                    </div>
                @else
                    <div id="game-server-tab-miscellaneous" role="tabpanel">
                        <section class="server-drawer-section">
                            <label class="server-connection-enable">
                                <span>
                                    <strong>{{ __('Maintenance mode') }}</strong>
                                    <small>{{ __('The public website will show an orange maintenance status. Database and service monitoring will continue.') }}</small>
                                </span>
                                <span class="switch-control">
                                    <input type="checkbox" @checked($maintenanceEnabled) wire:change="setMaintenanceEnabled($event.target.checked)">
                                    <span></span>
                                </span>
                            </label>

                            @if($maintenanceEnabled)
                                <div class="server-language-grid">
                                    @foreach($languages as $code => $language)
                                        <div class="form-group" wire:key="maintenance-message-{{ $code }}">
                                            <label for="live_game_maintenance_message_{{ $code }}">
                                                {{ __('Maintenance message') }} — {{ $language['native_name'] }}
                                                @if($code === $defaultLocale)<span class="compact-default-badge">{{ __('Default locale marker') }}</span>@endif
                                            </label>
                                            <input id="live_game_maintenance_message_{{ $code }}" type="text" maxlength="255" wire:model="maintenanceMessages.{{ $code }}" placeholder="{{ __('Installing an update') }}">
                                            @error('maintenanceMessages.'.$code)<small class="field-error">{{ $message }}</small>@enderror
                                        </div>
                                    @endforeach
                                </div>
                                <small>{{ __('Maintenance messages are stored separately for every enabled language. Newly enabled languages appear automatically.') }}</small>
                            @endif
                        </section>

                        <section class="server-drawer-section">
                            <div class="server-drawer-section-title">
                                <h3>{{ __('Additional network settings') }}</h3>
                                <p>{{ __('Used to check whether the GameServer process is actually listening. Leave the host empty to use the database host.') }}</p>
                            </div>

                            @if($connectionEnabled)
                                <div class="server-form-grid">
                                    <div class="form-group">
                                        <label for="live_game_service_host">{{ __('Service host') }}</label>
                                        <input id="live_game_service_host" type="text" maxlength="255" wire:model="serviceHost" placeholder="127.0.0.1">
                                        @error('serviceHost')<small class="field-error">{{ $message }}</small>@enderror
                                    </div>
                                    <div class="form-group server-form-port">
                                        <label for="live_game_service_port">{{ __('Service port') }}</label>
                                        <input id="live_game_service_port" type="number" min="1" max="65535" wire:model="servicePort">
                                        @error('servicePort')<small class="field-error">{{ $message }}</small>@enderror
                                    </div>
                                </div>
                            @else
                                <div class="settings-disabled-notice">
                                    {{ __('Enable the database connection on the General tab to configure service monitoring.') }}
                                </div>
                            @endif
                        </section>
                    </div>
                @endif
            </div>

            <footer class="server-drawer-footer">
                @if($connectionEnabled && !$loginServers->isEmpty())
                    <button class="button button-secondary" type="button" wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection">
                        <span wire:loading.remove wire:target="testConnection">{{ __('Test connection') }}</span>
                        <span wire:loading wire:target="testConnection">{{ __('Checking…') }}</span>
                    </button>
                @endif
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
                @if($deleteImpactRequiresConfirmation)
                    <h2>{{ __('Delete the last game server?') }}</h2>
                    <p>{{ __('This is the last GameServer connected to LoginServer :name.', ['name' => $deleteImpactLoginServerName ?: '—']) }}</p>
                    <p><strong>{{ __('Game accounts that will be unavailable: :count', ['count' => $deleteImpactAccountCount]) }}</strong></p>
                    <p>{{ __('The game accounts and characters will not be deleted. Their access in the personal account will be restored automatically after a replacement GameServer is connected to this LoginServer.') }}</p>
                @else
                    <h2>{{ __('Delete game server?') }}</h2>
                    <p>{{ __('The server will be removed from settings and the public website. This action cannot be undone.') }}</p>
                @endif
                @if($deleteImpactWarning)
                    <div class="notice notice-warning" role="alert">{{ $deleteImpactWarning }}</div>
                @endif
                <div>
                    <button class="button button-secondary" type="button" wire:click="cancelDelete">{{ __('Cancel') }}</button>
                    <button class="button button-danger" type="button" wire:click="deleteServer" wire:loading.attr="disabled" wire:target="deleteServer">
                        {{ $deleteImpactRequiresConfirmation ? __('Delete and hide accounts (:count)', ['count' => $deleteImpactAccountCount]) : __('Delete') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
