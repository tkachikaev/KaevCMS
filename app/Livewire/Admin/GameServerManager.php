<?php

namespace App\Livewire\Admin;

use App\Auth\AdminPermission;
use App\Exceptions\GameServerDeletionConfirmationRequired;
use App\Exceptions\GameServerHasRewardData;
use App\Models\Admin;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Services\AuditLogger;
use App\Services\GameServerFeatures\GameServerFeatureSettings;
use App\Services\GameServerSettings;
use App\Services\Localization\LanguageManager;
use App\Services\Servers\GameServerAdministration;
use App\Services\Servers\GameServerFormSchema;
use App\Services\Servers\GameServerFormState;
use App\Services\Servers\ServerDriverRegistry;
use App\Services\SiteSettings;
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

    public bool $characterRescueEnabled = false;

    public string $characterRescueLocationName = 'Giran';

    public string $characterRescueX = '83400';

    public string $characterRescueY = '148600';

    public string $characterRescueZ = '-3400';

    public string $characterRescueOfflineDelayMinutes = '5';

    public string $characterRescueCooldownHours = '12';

    #[Locked]
    public string $characterRescueCapabilityState = 'unavailable';

    /** @var list<string> */
    #[Locked]
    public array $characterRescueMissingColumns = [];

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

    public string $driver = ServerDriverRegistry::MOBIUS_DRIVER;

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
        $this->ensureCanView();
        $this->resetValidation();
        $this->resetForm();
        $this->activeTab = 'general';
        $this->drawerOpen = true;
    }

    public function edit(int $serverId): void
    {
        $this->ensureCanView();
        $server = GameServer::query()
            ->with(['translations', 'loginServer', 'features'])
            ->findOrFail($serverId);

        $this->resetValidation();
        $this->fill(app(GameServerFormState::class)->fromServer($server));
        $this->clearDeleteConfirmation();
        $this->activeTab = 'general';
        $this->drawerOpen = true;
    }

    public function setActiveTab(string $tab): void
    {
        $this->ensureCanView();

        if (in_array($tab, ['general', 'statistics', 'miscellaneous', 'features'], true)
            && ($tab !== 'features' || $this->editingId !== null)) {
            $this->activeTab = $tab;
        }
    }

    public function closeDrawer(): void
    {
        $this->ensureCanView();
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

    public function setCharacterRescueEnabled(bool $enabled): void
    {
        $this->ensureCanManage();
        $this->activeTab = 'features';

        if ($enabled && $this->characterRescueCapabilityState !== 'supported') {
            $this->addError('characterRescueEnabled', __('Character rescue unavailable'));

            return;
        }

        $this->characterRescueEnabled = $enabled;
        $this->resetValidation([
            'characterRescueEnabled',
            'characterRescueLocationName',
            'characterRescueX',
            'characterRescueY',
            'characterRescueZ',
            'characterRescueOfflineDelayMinutes',
            'characterRescueCooldownHours',
        ]);
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
        $schema = app(GameServerFormSchema::class);
        $validated = $this->validate(
            $schema->connectionRules($this->useLoginServerConnection),
            [],
            $schema->connectionAttributes(),
        );
        $loginServer = LoginServer::query()->findOrFail((int) $validated['loginServerId']);
        $server = $this->editingId !== null
            ? GameServer::query()->findOrFail($this->editingId)
            : null;
        $values = $schema->connectionValues($validated);
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

        $schema = app(GameServerFormSchema::class);

        try {
            $general = $this->validate(
                $schema->generalRules(),
                [],
                $schema->generalAttributes(),
            );
        } catch (ValidationException $exception) {
            $this->activateTabForErrors(array_keys($exception->errors()));

            throw $exception;
        }

        if ($this->statisticsCapabilities() !== [] && $this->statisticsEnabled && ! $this->hasEnabledStatisticsSection()) {
            $this->activeTab = 'statistics';
            $this->addError('statisticsEnabled', __('Enable at least one public statistics section.'));

            return;
        }

        $server = $this->editingId !== null
            ? GameServer::query()->with('features')->findOrFail($this->editingId)
            : null;
        $featureBefore = null;
        $featureAfter = null;

        if ($server instanceof GameServer) {
            try {
                $feature = $this->validate(
                    $schema->featureRules(),
                    [],
                    $schema->featureAttributes(),
                );
            } catch (ValidationException $exception) {
                $this->activateTabForErrors(array_keys($exception->errors()));

                throw $exception;
            }

            $featureBefore = app(GameServerFeatureSettings::class)->characterRescue($server);
            $featureAfter = $schema->featureValues($feature);

            if ($featureAfter['enabled']
                && ! $featureBefore['enabled']
                && $this->characterRescueCapabilityState !== 'supported') {
                $this->activeTab = 'features';
                $this->addError('characterRescueEnabled', __('Character rescue unavailable'));

                return;
            }
        }

        try {
            $connection = $this->connectionEnabled
                ? $this->validate(
                    $schema->connectionRules($this->useLoginServerConnection),
                    [],
                    $schema->connectionAttributes(),
                )
                : null;
        } catch (ValidationException $exception) {
            $this->activateTabForErrors(array_keys($exception->errors()));

            throw $exception;
        }

        $mode = $this->connectionEnabled
            ? GameServerAdministration::CONNECTION_CONNECT
            : GameServerAdministration::CONNECTION_DISCONNECT;
        $result = app(GameServerAdministration::class)->save(
            $server,
            $schema->generalValues($general, $this->driver),
            $mode,
            is_array($connection) ? $schema->connectionValues($connection) : null,
        );
        $server = $result['server'];

        if ($featureBefore !== null) {
            app(GameServerFeatureSettings::class)->updateCharacterRescue($server, $featureAfter);

            if ($featureBefore !== $featureAfter) {
                app(AuditLogger::class)->success(
                    category: 'server',
                    action: 'game_server.character_rescue_settings_updated',
                    target: $server,
                    details: ['before' => $featureBefore, 'after' => $featureAfter],
                );
            }
        }

        $server->load('features');
        $this->loadCharacterRescue($server);

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

            if (str_starts_with($field, 'characterRescue')) {
                $this->activeTab = 'features';

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
        return app(GameServerFormSchema::class)->statisticsCapabilities($this->driver);
    }

    private function initializeTranslations(): void
    {
        [$this->translations, $this->maintenanceMessages] = app(GameServerFormState::class)->localizedFields([], []);
    }

    private function syncEnabledLanguageFields(): void
    {
        [$this->translations, $this->maintenanceMessages] = app(GameServerFormState::class)->localizedFields(
            $this->translations,
            $this->maintenanceMessages,
        );
    }

    private function loadCharacterRescue(GameServer $server): void
    {
        $this->fill(app(GameServerFormState::class)->characterRescueState($server));
    }

    private function resetForm(): void
    {
        $this->fill(app(GameServerFormState::class)->defaults());
        $this->clearDeleteConfirmation();
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
            $admin instanceof Admin
                && ! $admin->isReadOnly()
                && $admin->hasPermission(AdminPermission::ServersManage),
            403,
        );
    }
}
