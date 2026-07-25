<?php

namespace App\Livewire\Admin;

use App\Auth\AdminPermission;
use App\Models\Admin;
use App\Models\LoginServer;
use App\Services\Servers\LoginServerAdministration;
use App\Services\Servers\ServerDriverRegistry;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class LoginServerManager extends Component
{
    public bool $drawerOpen = false;

    public string $activeTab = 'general';

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public ?int $confirmingDeleteId = null;

    public string $name = '';

    public string $driver = 'l2j_mobius';

    public string $databaseHost = '127.0.0.1';

    public string $databasePort = '3306';

    public string $databaseName = '';

    public string $databaseUsername = '';

    public string $databasePassword = '';

    public string $databaseCharset = 'utf8mb4';

    public string $serviceHost = '';

    public string $servicePort = '2106';

    /** @var array<string,mixed>|null */
    public ?array $connectionReport = null;

    public ?string $status = null;

    public bool $showChecks = false;

    /** @var array<int,array{state:string,message:string}> */
    public array $cardTestResults = [];

    public function mount(): void
    {
        $this->ensureCanView();
    }

    public function hydrate(): void
    {
        $this->ensureCanView();
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
        $server = LoginServer::query()->findOrFail($serverId);

        $this->resetValidation();
        $this->editingId = $server->id;
        $this->name = $server->name;
        $this->driver = $server->driver;
        $this->databaseHost = $server->database_host;
        $this->databasePort = (string) $server->database_port;
        $this->databaseName = $server->database_name;
        $this->databaseUsername = $server->database_username;
        $this->databasePassword = '';
        $this->databaseCharset = $server->database_charset;
        $this->serviceHost = trim((string) $server->service_host);
        $this->servicePort = (string) ($server->service_port ?? 2106);
        $this->connectionReport = null;
        $this->status = null;
        $this->showChecks = false;
        $this->confirmingDeleteId = null;
        $this->activeTab = 'general';
        $this->drawerOpen = true;
    }

    public function setActiveTab(string $tab): void
    {
        $this->ensureCanManage();

        if (in_array($tab, ['general', 'network'], true)) {
            $this->activeTab = $tab;
        }
    }

    public function closeDrawer(): void
    {
        $this->ensureCanManage();
        $this->drawerOpen = false;
        $this->connectionReport = null;
        $this->showChecks = false;
        $this->confirmingDeleteId = null;
        $this->resetValidation();
    }

    public function testStored(int $serverId): void
    {
        $this->ensureCanManage();
        $server = LoginServer::query()->findOrFail($serverId);
        $report = app(LoginServerAdministration::class)->testStored($server);

        $this->cardTestResults[$server->id] = $this->cardTestResult($report);
    }

    public function testConnection(): void
    {
        $this->ensureCanManage();
        try {
            $validated = $this->validate($this->rules(), [], $this->attributes());
        } catch (ValidationException $exception) {
            $this->activateTabForErrors(array_keys($exception->errors()));

            throw $exception;
        }
        $values = $this->connectionValues($validated);
        $server = $this->editingId !== null
            ? LoginServer::query()->findOrFail($this->editingId)
            : null;

        $this->connectionReport = app(LoginServerAdministration::class)->test(
            $values,
            $this->name,
            $server,
        );
        $this->showChecks = false;
        $this->status = null;
        $this->drawerOpen = true;
    }

    public function save(): void
    {
        $this->ensureCanManage();
        try {
            $validated = $this->validate($this->rules(), [], $this->attributes());
        } catch (ValidationException $exception) {
            $this->activateTabForErrors(array_keys($exception->errors()));

            throw $exception;
        }
        $values = $this->connectionValues($validated);
        $server = $this->editingId !== null
            ? LoginServer::query()->findOrFail($this->editingId)
            : null;
        $result = app(LoginServerAdministration::class)->save($server, $values);
        $server = $result['server'];

        if ($result['created']) {
            $this->editingId = $server->id;
            $this->status = __('LoginServer added.');
        } else {
            $this->status = __('LoginServer settings saved.');
        }

        unset($this->cardTestResults[$server->id]);
        $this->databasePassword = '';
        $this->connectionReport = null;
        $this->confirmingDeleteId = null;
    }

    public function confirmDelete(int $serverId): void
    {
        $this->ensureCanManage();
        $this->confirmingDeleteId = $serverId;
    }

    public function cancelDelete(): void
    {
        $this->ensureCanManage();
        $this->confirmingDeleteId = null;
    }

    public function deleteServer(): void
    {
        $this->ensureCanManage();

        if ($this->confirmingDeleteId === null) {
            return;
        }

        $serverId = $this->confirmingDeleteId;
        $server = LoginServer::query()->findOrFail($serverId);
        $deleted = app(LoginServerAdministration::class)->delete($server);

        if ($deleted === null) {
            $this->addError('loginServer', __('The LoginServer is used by game servers or player accounts and cannot be deleted.'));
            $this->confirmingDeleteId = null;

            return;
        }

        if ($this->editingId === $serverId) {
            $this->closeDrawer();
            $this->resetForm();
        }

        unset($this->cardTestResults[$serverId]);
        $this->status = __('LoginServer :name deleted.', ['name' => $deleted['name']]);
        $this->confirmingDeleteId = null;
    }

    public function render(): View
    {
        $this->ensureCanView();

        return view('livewire.admin.login-server-manager', [
            'servers' => LoginServer::query()
                ->withCount(['gameServers', 'userGameAccounts'])
                ->orderBy('id')
                ->get(),
            'drivers' => app(ServerDriverRegistry::class)->loginDrivers(),
        ]);
    }

    /** @return array<string,mixed> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'driver' => ['required', Rule::in(app(ServerDriverRegistry::class)->loginDriverKeys())],
            'databaseHost' => ['required', 'string', 'max:255'],
            'databasePort' => ['required', 'integer', 'between:1,65535'],
            'databaseName' => ['required', 'string', 'max:64'],
            'databaseUsername' => ['required', 'string', 'max:128'],
            'databasePassword' => ['nullable', 'string', 'max:1024'],
            'databaseCharset' => ['required', Rule::in(['utf8mb4', 'utf8', 'latin1', 'cp1251'])],
            'serviceHost' => ['nullable', 'string', 'max:255'],
            'servicePort' => ['required', 'integer', 'between:1,65535'],
        ];
    }

    /** @return array<string,string> */
    private function attributes(): array
    {
        return [
            'name' => __('LoginServer name'),
            'driver' => __('LoginServer driver'),
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
    private function connectionValues(array $validated): array
    {
        return [
            'name' => trim((string) $validated['name']),
            'driver' => trim((string) $validated['driver']),
            'database_host' => trim((string) $validated['databaseHost']),
            'database_port' => (int) $validated['databasePort'],
            'database_name' => trim((string) $validated['databaseName']),
            'database_username' => trim((string) $validated['databaseUsername']),
            'database_password' => (string) ($validated['databasePassword'] ?? ''),
            'database_charset' => trim((string) $validated['databaseCharset']),
            'service_host' => $this->nullableString($validated['serviceHost'] ?? null),
            'service_port' => (int) $validated['servicePort'],
            'monitor_status' => 'unknown',
            'monitor_failures' => 0,
            'monitor_checked_at' => null,
            'monitor_last_online_at' => null,
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

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->confirmingDeleteId = null;
        $this->name = '';
        $this->driver = 'l2j_mobius';
        $this->databaseHost = '127.0.0.1';
        $this->databasePort = '3306';
        $this->databaseName = '';
        $this->databaseUsername = '';
        $this->databasePassword = '';
        $this->databaseCharset = 'utf8mb4';
        $this->serviceHost = '';
        $this->servicePort = '2106';
        $this->activeTab = 'general';
        $this->connectionReport = null;
        $this->status = null;
        $this->showChecks = false;
    }

    /** @param list<int|string> $errors */
    private function activateTabForErrors(array $errors): void
    {
        foreach ($errors as $error) {
            if (in_array((string) $error, ['serviceHost', 'servicePort'], true)) {
                $this->activeTab = 'network';

                return;
            }
        }

        $this->activeTab = 'general';
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
