<?php

namespace App\Livewire\Admin;

use App\Auth\AdminPermission;
use App\Exceptions\GameServerDeletionConfirmationRequired;
use App\Exceptions\GameServerHasRewardData;
use App\Models\Admin;
use App\Models\GameServer;
use App\Models\GameServerTranslation;
use App\Models\LoginServer;
use App\Services\AuditLogger;
use App\Services\GameServerSettings;
use App\Services\Localization\LanguageManager;
use App\Services\Servers\GameServerAdministration;
use App\Services\Servers\ServerDriverRegistry;
use App\Services\SiteSettings;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class GameServerManager extends Component
{
    public bool $drawerOpen = false;

    public string $activeTab = 'general';

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public ?int $confirmingDeleteId = null;

    #[Locked]
    public ?string $deleteImpactFingerprint = null;

    #[Locked]
    public bool $deleteImpactRequiresConfirmation = false;

    #[Locked]
    public int $deleteImpactAccountCount = 0;

    #[Locked]
    public ?string $deleteImpactLoginServerName = null;

    #[Locked]
    public ?string $deleteImpactWarning = null;

    /** @var array<string,string> */
    public array $translations = [];

    /** @var array<string,string> */
    public array $maintenanceMessages = [];

    public bool $maintenanceEnabled = false;

    public bool $statisticsEnabled = false;

    public bool $statisticsLevelEnabled = true;

    public bool $statisticsPvpEnabled = true;

    public bool $statisticsPkEnabled = true;

    public bool $statisticsPlayTimeEnabled = true;

    public bool $statisticsHeroesEnabled = true;

    public bool $statisticsCastlesEnabled = true;

    public string $statisticsLevelLimit = '10';

    public string $statisticsPvpLimit = '10';

    public string $statisticsPkLimit = '10';

    public string $statisticsPlayTimeLimit = '10';

    public bool $showPublicOnline = true;

    public string $serverRates = '';

    public string $serverChronicle = '';

    public string $serverMode = '';

    public bool $connectionEnabled = false;

    public string $loginServerId = '';

    public string $driver = 'l2j_mobius_ct0_interlude';

    public bool $useLoginServerConnection = true;

    public string $databaseHost = '127.0.0.1';

    public string $databasePort = '3306';

    public string $databaseName = '';

    public string $databaseUsername = '';

    public string $databasePassword = '';

    public string $databaseCharset = 'utf8mb4';

    public string $serviceHost = '';

    public string $servicePort = '7777';

    /** @var array<string,mixed>|null */
    public ?array $connectionReport = null;

    public ?string $status = null;

    public bool $showChecks = false;

    /** @var array<int,array{state:string,message:string}> */
    public array $cardTestResults = [];

    public function mount(): void
    {
        $this->ensureCanView();
        $this->showPublicOnline = app(SiteSettings::class)->showPublicOnline();
        $this->initializeTranslations();
    }

    public function hydrate(): void
    {
        $this->ensureCanView();
        $this->syncEnabledLanguageFields();
    }

    public function create(): void
    {
        $this->ensureCanManage();
        $this->resetValidation();
        $this->resetForm();
        $this->activeTab = 'general';
        $this->drawerOpen = true;
    }

    public function edit(int $serverId): void
    {
        $this->ensureCanManage();
        $server = GameServer::query()
            ->with(['translations', 'loginServer'])
            ->findOrFail($serverId);
        $languages = app(LanguageManager::class);

        $this->resetValidation();
        $this->editingId = $server->id;
        $this->translations = [];
        $this->maintenanceMessages = [];
        foreach ($languages->enabledCodes() as $locale) {
            $translation = $server->translations->firstWhere('locale', $locale);
            $this->translations[$locale] = $translation instanceof GameServerTranslation
                ? trim((string) $translation->name)
                : ($locale === $languages->default() ? trim((string) $server->name) : '');
            $this->maintenanceMessages[$locale] = $translation instanceof GameServerTranslation
                ? trim((string) $translation->maintenance_message)
                : '';
        }

        $this->maintenanceEnabled = (bool) $server->maintenance_enabled;
        $this->statisticsEnabled = (bool) $server->statistics_enabled;
        $this->statisticsLevelEnabled = (bool) $server->statistics_level_enabled;
        $this->statisticsPvpEnabled = (bool) $server->statistics_pvp_enabled;
        $this->statisticsPkEnabled = (bool) $server->statistics_pk_enabled;
        $this->statisticsPlayTimeEnabled = (bool) $server->statistics_play_time_enabled;
        $this->statisticsHeroesEnabled = (bool) $server->statistics_heroes_enabled;
        $this->statisticsCastlesEnabled = (bool) $server->statistics_castles_enabled;
        $this->statisticsLevelLimit = (string) $server->statistics_level_limit;
        $this->statisticsPvpLimit = (string) $server->statistics_pvp_limit;
        $this->statisticsPkLimit = (string) $server->statistics_pk_limit;
        $this->statisticsPlayTimeLimit = (string) $server->statistics_play_time_limit;
        $this->serverRates = trim((string) $server->rates);
        $this->serverChronicle = trim((string) $server->chronicle);
        $this->serverMode = trim((string) $server->mode);
        $this->connectionEnabled = $server->connectionConfigured();
        $this->loginServerId = $server->login_server_id !== null ? (string) $server->login_server_id : '';
        $this->driver = $server->driver ?? 'l2j_mobius_ct0_interlude';
        $this->useLoginServerConnection = $server->connectionConfigured()
            ? (bool) $server->use_login_server_connection
            : true;
        $this->databaseHost = trim((string) $server->database_host) !== '' ? trim((string) $server->database_host) : '127.0.0.1';
        $this->databasePort = (string) ($server->database_port ?? 3306);
        $this->databaseName = trim((string) $server->database_name);
        $this->databaseUsername = trim((string) $server->database_username);
        $this->databasePassword = '';
        $this->databaseCharset = trim((string) $server->database_charset) !== '' ? trim((string) $server->database_charset) : 'utf8mb4';
        $this->serviceHost = trim((string) $server->service_host);
        $this->servicePort = (string) ($server->service_port ?? 7777);
        $this->connectionReport = null;
        $this->status = null;
        $this->showChecks = false;
        $this->clearDeleteConfirmation();
        $this->activeTab = 'general';
        $this->drawerOpen = true;
    }

    public function setActiveTab(string $tab): void
    {
        $this->ensureCanManage();

        if (in_array($tab, ['general', 'statistics', 'miscellaneous'], true)) {
            $this->activeTab = $tab;
        }
    }

    public function closeDrawer(): void
    {
        $this->ensureCanManage();
        $this->drawerOpen = false;
        $this->connectionReport = null;
        $this->showChecks = false;
        $this->clearDeleteConfirmation();
        $this->resetValidation();
    }

    public function setMaintenanceEnabled(bool $enabled): void
    {
        $this->ensureCanManage();
        $this->activeTab = 'miscellaneous';
        $this->maintenanceEnabled = $enabled;
        $this->syncEnabledLanguageFields();
        $this->resetValidation('maintenanceMessages');
    }

    public function setShowPublicOnline(bool $enabled): void
    {
        $this->ensureCanManage();
        $settings = app(SiteSettings::class);
        $previous = $settings->showPublicOnline();

        $settings->setShowPublicOnline($enabled);
        $this->showPublicOnline = $enabled;
        $this->status = $enabled
            ? __('Public online count enabled.')
            : __('Public online count disabled.');

        if ($previous !== $enabled) {
            app(AuditLogger::class)->success(
                category: 'admin',
                action: 'settings.public_online_visibility_updated',
                target: __('Game servers'),
                details: [
                    'before' => $previous,
                    'after' => $enabled,
                ],
            );
        }
    }

    public function testStored(int $serverId): void
    {
        $this->ensureCanManage();
        $server = GameServer::query()->with('loginServer')->findOrFail($serverId);
        $report = app(GameServerAdministration::class)->testStored($server);

        if (($report['error'] ?? null) === 'login_server_missing') {
            $this->cardTestResults[$server->id] = [
                'state' => 'failed',
                'message' => __('Select a LoginServer before testing the connection.'),
            ];

            return;
        }

        $this->cardTestResults[$server->id] = $this->cardTestResult($report);
    }

    public function testConnection(): void
    {
        $this->ensureCanManage();
        $this->connectionEnabled = true;
        $validated = $this->validate($this->connectionRules(), [], $this->connectionAttributes());
        $loginServer = LoginServer::query()->findOrFail((int) $validated['loginServerId']);
        $server = $this->editingId !== null
            ? GameServer::query()->findOrFail($this->editingId)
            : null;
        $values = $this->connectionValues($validated);
        $targetName = $this->translations[app(LanguageManager::class)->default()] ?? __('New game server');

        $this->connectionReport = app(GameServerAdministration::class)->test(
            $values,
            $loginServer,
            $server,
            $targetName,
        );
        $this->showChecks = false;
        $this->status = null;
        $this->drawerOpen = true;
    }

    public function save(): void
    {
        $this->ensureCanManage();
        try {
            $general = $this->validate($this->generalRules(), [], $this->generalAttributes());
        } catch (ValidationException $exception) {
            $this->activateTabForErrors(array_keys($exception->errors()));

            throw $exception;
        }
        if ($this->statisticsCapabilities() !== [] && $this->statisticsEnabled && ! $this->hasEnabledStatisticsSection()) {
            $this->activeTab = 'statistics';
            $this->addError('statisticsEnabled', __('Enable at least one public statistics section.'));

            return;
        }

        try {
            $connection = $this->connectionEnabled
                ? $this->validate($this->connectionRules(), [], $this->connectionAttributes())
                : null;
        } catch (ValidationException $exception) {
            $this->activateTabForErrors(array_keys($exception->errors()));

            throw $exception;
        }
        $server = $this->editingId !== null
            ? GameServer::query()->findOrFail($this->editingId)
            : null;
        $mode = $this->connectionEnabled
            ? GameServerAdministration::CONNECTION_CONNECT
            : GameServerAdministration::CONNECTION_DISCONNECT;
        $result = app(GameServerAdministration::class)->save(
            $server,
            $this->generalValues($general),
            $mode,
            is_array($connection) ? $this->connectionValues($connection) : null,
        );
        $server = $result['server'];

        if ($result['created']) {
            $this->editingId = $server->id;
            $this->status = __('Game server added.');
        } else {
            $this->status = __('Game server settings saved.');
        }

        unset($this->cardTestResults[$server->id]);
        $this->databasePassword = '';
        $this->connectionReport = null;
        $this->clearDeleteConfirmation();
    }

    public function confirmDelete(int $serverId): void
    {
        $this->ensureCanManage();
        $server = GameServer::query()->with('loginServer')->findOrFail($serverId);
        $this->applyDeleteImpact(app(GameServerAdministration::class)->analyzeDeletion($server));
        $this->confirmingDeleteId = $serverId;
        $this->deleteImpactWarning = null;
    }

    public function cancelDelete(): void
    {
        $this->ensureCanManage();
        $this->clearDeleteConfirmation();
    }

    public function deleteServer(): void
    {
        $this->ensureCanManage();

        if ($this->confirmingDeleteId === null) {
            return;
        }

        $server = GameServer::query()->with(['translations', 'loginServer'])->findOrFail($this->confirmingDeleteId);
        $name = $server->name;
        $serverId = $server->id;

        try {
            app(GameServerAdministration::class)->delete($server, $this->deleteImpactFingerprint);
        } catch (GameServerDeletionConfirmationRequired $exception) {
            $this->applyDeleteImpact($exception->impact);
            $this->deleteImpactWarning = __('The deletion impact changed. Review the updated account count and confirm again.');

            return;
        } catch (GameServerHasRewardData) {
            $this->deleteImpactWarning = __('This GameServer contains web inventory rewards or delivery history. Deletion is blocked to prevent reward data loss.');

            return;
        }

        if ($this->editingId === $this->confirmingDeleteId) {
            $this->closeDrawer();
            $this->resetForm();
        }

        unset($this->cardTestResults[$serverId]);
        $this->status = __('Game server :name deleted.', ['name' => $name]);
        $this->clearDeleteConfirmation();
    }

    public function render(): View
    {
        $this->ensureCanView();

        return view('livewire.admin.game-server-manager', [
            'servers' => app(GameServerSettings::class)->all(),
            'loginServers' => LoginServer::query()->orderBy('name')->orderBy('id')->get(),
            'gameDrivers' => app(ServerDriverRegistry::class)->gameDrivers(),
            'statisticsCapabilities' => $this->statisticsCapabilities(),
            'languages' => app(LanguageManager::class)->enabled(),
            'defaultLocale' => app(LanguageManager::class)->default(),
        ]);
    }

    /** @return array<string,mixed> */
    private function generalRules(): array
    {
        $languages = app(LanguageManager::class);
        $rules = [
            'translations' => ['required', 'array'],
            'serverRates' => ['nullable', 'string', 'max:100'],
            'serverChronicle' => ['nullable', 'string', 'max:100'],
            'serverMode' => ['nullable', 'string', 'max:100'],
            'maintenanceEnabled' => ['required', 'boolean'],
            'maintenanceMessages' => ['required', 'array'],
            'statisticsEnabled' => ['required', 'boolean'],
            'statisticsLevelEnabled' => ['required', 'boolean'],
            'statisticsPvpEnabled' => ['required', 'boolean'],
            'statisticsPkEnabled' => ['required', 'boolean'],
            'statisticsPlayTimeEnabled' => ['required', 'boolean'],
            'statisticsHeroesEnabled' => ['required', 'boolean'],
            'statisticsCastlesEnabled' => ['required', 'boolean'],
            'statisticsLevelLimit' => ['required', 'integer', 'between:1,100'],
            'statisticsPvpLimit' => ['required', 'integer', 'between:1,100'],
            'statisticsPkLimit' => ['required', 'integer', 'between:1,100'],
            'statisticsPlayTimeLimit' => ['required', 'integer', 'between:1,100'],
        ];

        foreach ($languages->enabledCodes() as $locale) {
            $rules['translations.'.$locale] = $locale === $languages->default()
                ? ['required', 'string', 'max:100']
                : ['nullable', 'string', 'max:100'];
            $rules['maintenanceMessages.'.$locale] = ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }

    /** @return array<string,mixed> */
    private function connectionRules(): array
    {
        $rules = [
            'loginServerId' => ['required', 'integer', 'exists:login_servers,id'],
            'driver' => ['required', Rule::in(app(ServerDriverRegistry::class)->gameDriverKeys())],
            'useLoginServerConnection' => ['required', 'boolean'],
            'databasePassword' => ['nullable', 'string', 'max:1024'],
            'serviceHost' => ['nullable', 'string', 'max:255'],
            'servicePort' => ['required', 'integer', 'between:1,65535'],
        ];

        if (! $this->useLoginServerConnection) {
            $rules += [
                'databaseHost' => ['required', 'string', 'max:255'],
                'databasePort' => ['required', 'integer', 'between:1,65535'],
                'databaseName' => ['required', 'string', 'max:64'],
                'databaseUsername' => ['required', 'string', 'max:128'],
                'databaseCharset' => ['required', Rule::in(['utf8mb4', 'utf8', 'latin1', 'cp1251'])],
            ];
        }

        return $rules;
    }

    /** @return array<string,string> */
    private function generalAttributes(): array
    {
        $attributes = [
            'serverRates' => __('Server rates validation attribute'),
            'serverChronicle' => __('Chronicle validation attribute'),
            'serverMode' => __('server mode'),
            'statisticsLevelLimit' => __('Level ranking limit'),
            'statisticsPvpLimit' => __('PvP ranking limit'),
            'statisticsPkLimit' => __('PK ranking limit'),
            'statisticsPlayTimeLimit' => __('Play time ranking limit'),
        ];

        foreach (app(LanguageManager::class)->enabledCodes() as $locale) {
            $attributes['translations.'.$locale] = __('Server name validation attribute');
            $attributes['maintenanceMessages.'.$locale] = __('Maintenance message validation attribute');
        }

        return $attributes;
    }

    /** @return array<string,string> */
    private function connectionAttributes(): array
    {
        return [
            'loginServerId' => __('LoginServer'),
            'driver' => __('GameServer driver'),
            'useLoginServerConnection' => __('Use LoginServer database connection'),
            'databaseHost' => __('Database host'),
            'databasePort' => __('Database port'),
            'databaseName' => __('Database name'),
            'databaseUsername' => __('Database username'),
            'databasePassword' => __('Database password'),
            'databaseCharset' => __('Database charset'),
            'serviceHost' => __('Service host'),
            'servicePort' => __('Service port'),
        ];
    }

    /** @param array<string,mixed> $validated @return array<string,mixed> */
    private function generalValues(array $validated): array
    {
        $translations = [];
        foreach ((array) $validated['translations'] as $locale => $name) {
            $translations[(string) $locale] = trim((string) $name);
        }

        $maintenanceMessages = [];
        foreach ((array) $validated['maintenanceMessages'] as $locale => $message) {
            $maintenanceMessages[(string) $locale] = trim((string) $message);
        }
        $defaultLocale = app(LanguageManager::class)->default();

        $statisticsSupported = $this->statisticsCapabilities() !== [];

        return [
            'name' => $translations[$defaultLocale] ?? '',
            'rates' => trim((string) ($validated['serverRates'] ?? '')),
            'chronicle' => trim((string) ($validated['serverChronicle'] ?? '')),
            'mode' => trim((string) ($validated['serverMode'] ?? '')),
            'translations' => $translations,
            'maintenance_enabled' => (bool) $validated['maintenanceEnabled'],
            'maintenance_messages' => $maintenanceMessages,
            'statistics_enabled' => $statisticsSupported && (bool) $validated['statisticsEnabled'],
            'statistics_level_enabled' => (bool) $validated['statisticsLevelEnabled'],
            'statistics_pvp_enabled' => (bool) $validated['statisticsPvpEnabled'],
            'statistics_pk_enabled' => (bool) $validated['statisticsPkEnabled'],
            'statistics_play_time_enabled' => (bool) $validated['statisticsPlayTimeEnabled'],
            'statistics_heroes_enabled' => (bool) $validated['statisticsHeroesEnabled'],
            'statistics_castles_enabled' => (bool) $validated['statisticsCastlesEnabled'],
            'statistics_level_limit' => (int) $validated['statisticsLevelLimit'],
            'statistics_pvp_limit' => (int) $validated['statisticsPvpLimit'],
            'statistics_pk_limit' => (int) $validated['statisticsPkLimit'],
            'statistics_play_time_limit' => (int) $validated['statisticsPlayTimeLimit'],
        ];
    }

    /** @param array<string,mixed> $validated @return array<string,mixed> */
    private function connectionValues(array $validated): array
    {
        return [
            'login_server_id' => (int) $validated['loginServerId'],
            'driver' => trim((string) $validated['driver']),
            'use_login_server_connection' => (bool) $validated['useLoginServerConnection'],
            'database_host' => trim((string) ($validated['databaseHost'] ?? '')),
            'database_port' => (int) ($validated['databasePort'] ?? 3306),
            'database_name' => trim((string) ($validated['databaseName'] ?? '')),
            'database_username' => trim((string) ($validated['databaseUsername'] ?? '')),
            'database_password' => (string) ($validated['databasePassword'] ?? ''),
            'database_charset' => trim((string) ($validated['databaseCharset'] ?? 'utf8mb4')),
            'service_host' => $this->nullableString($validated['serviceHost'] ?? null),
            'service_port' => (int) ($validated['servicePort'] ?? 7777),
        ];
    }

    /** @param array<string,mixed> $report @return array{state:string,message:string} */
    private function cardTestResult(array $report): array
    {
        if (! ($report['connected'] ?? false)) {
            return ['state' => 'failed', 'message' => __('Database connection failed.')];
        }

        if (($report['driver_ready'] ?? true) && ($report['compatible'] ?? null) === false) {
            return [
                'state' => 'failed',
                'message' => __('The database is reachable, but required driver tables or columns are missing.'),
            ];
        }

        return ['state' => 'success', 'message' => __('Database connection established.')];
    }

    /** @param list<int|string> $errors */
    private function activateTabForErrors(array $errors): void
    {
        foreach ($errors as $error) {
            $field = (string) $error;

            if (str_starts_with($field, 'statistics')) {
                $this->activeTab = 'statistics';

                return;
            }

            if ($field === 'maintenanceEnabled'
                || str_starts_with($field, 'maintenanceMessages.')
                || in_array($field, ['serviceHost', 'servicePort'], true)) {
                $this->activeTab = 'miscellaneous';

                return;
            }
        }

        $this->activeTab = 'general';
    }

    private function hasEnabledStatisticsSection(): bool
    {
        return $this->statisticsLevelEnabled
            || $this->statisticsPvpEnabled
            || $this->statisticsPkEnabled
            || $this->statisticsPlayTimeEnabled
            || $this->statisticsHeroesEnabled
            || $this->statisticsCastlesEnabled;
    }

    /** @return list<string> */
    private function statisticsCapabilities(): array
    {
        $driver = app(ServerDriverRegistry::class)->gameDriver($this->driver);

        return is_array($driver) ? ($driver['statistics'] ?? []) : [];
    }

    private function initializeTranslations(): void
    {
        $this->translations = [];
        $this->maintenanceMessages = [];
        $this->syncEnabledLanguageFields();
    }

    private function syncEnabledLanguageFields(): void
    {
        $enabled = array_fill_keys(app(LanguageManager::class)->enabledCodes(), true);

        $this->translations = array_intersect_key($this->translations, $enabled);
        $this->maintenanceMessages = array_intersect_key($this->maintenanceMessages, $enabled);

        foreach (array_keys($enabled) as $locale) {
            $this->translations[$locale] ??= '';
            $this->maintenanceMessages[$locale] ??= '';
        }
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->clearDeleteConfirmation();
        $this->initializeTranslations();
        $this->maintenanceEnabled = false;
        $this->statisticsEnabled = false;
        $this->statisticsLevelEnabled = true;
        $this->statisticsPvpEnabled = true;
        $this->statisticsPkEnabled = true;
        $this->statisticsPlayTimeEnabled = true;
        $this->statisticsHeroesEnabled = true;
        $this->statisticsCastlesEnabled = true;
        $this->statisticsLevelLimit = '10';
        $this->statisticsPvpLimit = '10';
        $this->statisticsPkLimit = '10';
        $this->statisticsPlayTimeLimit = '10';
        $this->serverRates = '';
        $this->serverChronicle = '';
        $this->serverMode = '';
        $this->connectionEnabled = false;
        $this->loginServerId = '';
        $this->driver = 'l2j_mobius_ct0_interlude';
        $this->useLoginServerConnection = true;
        $this->databaseHost = '127.0.0.1';
        $this->databasePort = '3306';
        $this->databaseName = '';
        $this->databaseUsername = '';
        $this->databasePassword = '';
        $this->databaseCharset = 'utf8mb4';
        $this->serviceHost = '';
        $this->servicePort = '7777';
        $this->connectionReport = null;
        $this->status = null;
        $this->showChecks = false;
    }

    /**
     * @param array{
     *     game_server_id:int,
     *     login_server_id:int|null,
     *     login_server_name:string|null,
     *     replacement_game_server_id:int|null,
     *     login_server_account_count:int,
     *     accounts_becoming_unavailable:int,
     *     unavailable_after_deletion:int,
     *     requires_confirmation:bool,
     *     fingerprint:string
     * } $impact
     */
    private function applyDeleteImpact(array $impact): void
    {
        $this->deleteImpactFingerprint = $impact['fingerprint'];
        $this->deleteImpactRequiresConfirmation = $impact['requires_confirmation'];
        $this->deleteImpactAccountCount = $impact['accounts_becoming_unavailable'];
        $this->deleteImpactLoginServerName = $impact['login_server_name'];
    }

    private function clearDeleteConfirmation(): void
    {
        $this->confirmingDeleteId = null;
        $this->deleteImpactFingerprint = null;
        $this->deleteImpactRequiresConfirmation = false;
        $this->deleteImpactAccountCount = 0;
        $this->deleteImpactLoginServerName = null;
        $this->deleteImpactWarning = null;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function ensureCanView(): void
    {
        $admin = auth('admin')->user();

        abort_unless(
            $admin instanceof Admin && $admin->hasPermission(AdminPermission::ServersView),
            403,
        );
    }

    private function ensureCanManage(): void
    {
        $admin = auth('admin')->user();

        abort_unless(
            $admin instanceof Admin && $admin->hasPermission(AdminPermission::ServersManage),
            403,
        );
    }
}
