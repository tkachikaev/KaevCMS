<?php

namespace App\Livewire\Admin;

use App\Auth\AdminPermission;
use App\Models\Admin;
use App\Services\AuditLogger;
use App\Support\UniversalDrivers\BundledProfileCatalog;
use App\Support\UniversalDrivers\DriverProfileCatalog;
use App\Support\UniversalDrivers\ProfileEditorDraft;
use App\Support\UniversalDrivers\ProfileFormat;
use App\Support\UniversalDrivers\ProfileValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

final class UniversalDriverManager extends Component
{
    use WithFileUploads;

    public string $tab = 'login';
    public bool $editing = false;
    public bool $confirmDelete = false;
    public bool $importOpen = false;
    public string $section = 'general';
    public string $notice = '';
    public string $error = '';

    #[Locked]
    public ?string $originalType = null;
    #[Locked]
    public ?string $originalId = null;
    #[Locked]
    public ?string $originalSha = null;

    /** @var array<string,mixed> */
    public array $draft = [];
    /** @var array<string,bool> */
    public array $enabled = [];
    public string $newRelation = '';
    public string $newField = '';
    public string $newRanking = '';
    public string $newFeature = '';
    public string $newFixedColumn = '';
    public $importFile = null;

    public function mount(): void
    {
        $this->viewPermission();
    }

    public function hydrate(): void
    {
        $this->viewPermission();
    }

    public function switchType(string $type): void
    {
        $this->viewPermission();
        abort_unless(in_array($type, ['login', 'game'], true), 404);
        $this->tab = $type;
        $this->editing = false;
        $this->confirmDelete = false;
        $this->error = '';
    }

    public function createProfile(string $type): void
    {
        $this->managePermission();
        abort_unless(in_array($type, ['login', 'game'], true), 404);
        $this->resetValidation();
        $this->tab = $type;
        $this->draft = app(ProfileEditorDraft::class)->blank($type);
        $this->originalId = null;
        $this->originalType = null;
        $this->originalSha = null;
        $this->enabled = array_fill_keys($this->availableCapabilities($type), false);
        $this->enabled[$type === 'login' ? 'account_lookup' : 'characters'] = true;
        $this->section = 'general';
        $this->editing = true;
        $this->notice = $this->error = '';
    }

    public function editProfile(string $type, string $id): void
    {
        $this->managePermission();
        $entry = app(DriverProfileCatalog::class)->find($type, $id);
        if ($entry === null) {
            $this->error = __('drivers.not_found');
            return;
        }
        $this->draft = $entry['profile'];
        $this->originalType = $type;
        $this->originalId = $id;
        $this->originalSha = hash('sha256', app(DriverProfileCatalog::class)->canonicalJson($this->draft));
        $this->enabled = [];
        foreach ($this->availableCapabilities($type) as $capability) {
            $this->enabled[$capability] = in_array($capability, $this->draft['config']['capabilities'], true);
        }
        $this->section = 'general';
        $this->editing = true;
        $this->notice = $this->error = '';
        $this->resetValidation();
    }

    public function closeEditor(): void
    {
        $this->viewPermission();
        $this->editing = false;
        $this->resetValidation();
    }

    public function setSection(string $section): void
    {
        $this->viewPermission();
        if (in_array($section, ['general', 'schema', 'mapping', 'features'], true)) {
            $this->section = $section;
        }
    }

    public function saveProfile(): void
    {
        $this->managePermission();
        $this->error = '';
        if (! $this->editing) {
            abort(409);
        }
        $catalog = app(DriverProfileCatalog::class);
        $profile = $this->draft;
        $type = $this->originalType ?? $this->tab;
        $profile['type'] = $type;
        $profile['format_version'] = ProfileFormat::CURRENT_VERSION;
        $profile['id'] = $this->originalId ?? trim((string) ($profile['id'] ?? ''));
        $profile['name'] = trim((string) ($profile['name'] ?? ''));
        $profile['config']['capabilities'] = array_values(array_keys(array_filter($this->enabled, static fn ($v) => $v === true)));
        try {
            if ($this->originalId === null && $catalog->find($type, $profile['id']) !== null) {
                throw new ProfileValidationException('Driver profile ID already exists.');
            }
            if ($this->originalId !== null) {
                $current = $catalog->find($type, $this->originalId);
                if ($current === null || ! hash_equals((string) $this->originalSha, hash('sha256', $catalog->canonicalJson($current['profile'])))) {
                    throw new ProfileValidationException('This driver was changed by another administrator. Reopen it before saving.');
                }
            }
            $profile = app(ProfileEditorDraft::class)->clean($profile);
            if ($this->originalId === null) {
                $catalog->create($profile);
            } else {
                $catalog->save($profile, $this->originalSha);
            }
            $this->audit('save', $type, $profile['id']);
            $this->editing = false;
            $this->tab = $type;
            $this->notice = __('drivers.saved');
        } catch (ProfileValidationException $exception) {
            $this->error = __('drivers.invalid_profile').': '.$exception->getMessage();
        }
    }

    public function cloneProfile(string $type, string $id): void
    {
        $this->managePermission();
        $catalog = app(DriverProfileCatalog::class);
        try {
            $profile = $catalog->import($catalog->export($type, $id));
            $this->audit('copy', $type, $profile['id']);
            $this->notice = __('drivers.copied');
        } catch (ProfileValidationException $exception) {
            $this->error = __('drivers.operation_failed');
        }
    }

    public function exportProfile(string $type, string $id): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->viewPermission();
        $json = app(DriverProfileCatalog::class)->export($type, $id);
        return response()->streamDownload(static function () use ($json): void {
            echo $json;
        }, 'kaevcms-'.$type.'-'.$id.'.json', ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public function importProfile(): void
    {
        $this->managePermission();
        $this->validate(['importFile' => ['required', 'file', 'max:256', 'extensions:json']]);
        try {
            $json = $this->importFile->get();
            $profile = app(DriverProfileCatalog::class)->import($json);
            $this->audit('import', $profile['type'], $profile['id']);
            $this->tab = $profile['type'];
            $this->importOpen = false;
            $this->importFile = null;
            $this->notice = __('drivers.imported');
            $this->error = '';
        } catch (ProfileValidationException $exception) {
            $this->error = __('drivers.invalid_profile').': '.$exception->getMessage();
        }
    }

    public function askDelete(string $type, string $id): void
    {
        $this->managePermission();
        abort_unless(app(DriverProfileCatalog::class)->find($type, $id) !== null, 404);
        $this->originalType = $type;
        $this->originalId = $id;
        $this->confirmDelete = true;
        $this->notice = $this->error = '';
    }

    public function deleteProfile(): void
    {
        $this->managePermission();
        if (! $this->confirmDelete || $this->originalType === null || $this->originalId === null) {
            abort(409);
        }
        try {
            app(DriverProfileCatalog::class)->delete($this->originalType, $this->originalId);
            $this->audit('delete', $this->originalType, $this->originalId);
            $this->notice = __('drivers.deleted');
            $this->confirmDelete = false;
        } catch (ProfileValidationException $exception) {
            $this->error = __('drivers.cannot_delete');
            $this->confirmDelete = false;
        }
    }

    public function restoreProfile(string $type, string $id): void
    {
        $this->managePermission();
        try {
            app(DriverProfileCatalog::class)->restoreBuiltin($type, $id);
            $this->audit('restore', $type, $id);
            $this->notice = __('drivers.restored');
        } catch (ProfileValidationException $exception) {
            $this->error = __('drivers.operation_failed');
        }
    }

    public function addRequirement(): void
    {
        $this->managePermission();
        if (count($this->draft['config']['requirements'] ?? []) >= 32) {
            return;
        }
        $this->draft['config']['requirements'][] = ['table' => '', 'columns' => [''], 'required' => false];
    }

    public function removeRequirement(int $index): void
    {
        $this->managePermission();
        if (count($this->draft['config']['requirements'] ?? []) <= 1) {
            return;
        }
        unset($this->draft['config']['requirements'][$index]);
        $this->draft['config']['requirements'] = array_values($this->draft['config']['requirements']);
    }

    public function addColumn(int $index, string $kind): void
    {
        $this->managePermission();
        if (! in_array($kind, ['columns', 'optional_columns', 'any_columns'], true) || ! isset($this->draft['config']['requirements'][$index])) {
            return;
        }
        $list = &$this->draft['config']['requirements'][$index][$kind];
        $list ??= [];
        if (count($list) < 80) {
            $list[] = '';
        }
    }

    public function removeColumn(int $index, string $kind, int $column): void
    {
        $this->managePermission();
        if (! in_array($kind, ['columns', 'optional_columns', 'any_columns'], true)) {
            return;
        }
        unset($this->draft['config']['requirements'][$index][$kind][$column]);
        $this->draft['config']['requirements'][$index][$kind] = array_values($this->draft['config']['requirements'][$index][$kind] ?? []);
    }

    public function addRelation(): void
    {
        $this->managePermission();
        $name = trim($this->newRelation);
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D', $name) !== 1 || count($this->draft['config']['relations'] ?? []) >= 16 || isset($this->draft['config']['relations'][$name])) {
            return;
        }
        $this->draft['config']['relations'][$name] = ['table' => '', 'local_column' => '', 'foreign_column' => '', 'join' => 'left', 'cardinality' => 'one'];
        $this->newRelation = '';
    }

    public function removeRelation(string $name): void
    {
        $this->managePermission();
        unset($this->draft['config']['relations'][$name]);
    }

    public function addField(): void
    {
        $this->managePermission();
        $field = $this->newField;
        if (! in_array($field, ProfileFormat::FIELD_NAMES, true)) {
            return;
        }
        $this->draft['config']['characters']['fields'][$field] ??= ['kind' => 'column', 'column' => ''];
        $this->newField = '';
    }

    public function changeFieldKind(string $field, string $kind): void
    {
        $this->managePermission();
        if (! in_array($field, ProfileFormat::FIELD_NAMES, true) ||
            (in_array($field, ['id', 'name'], true) && $kind !== 'column')) { return; }
        $this->draft['config']['characters']['fields'][$field] = match ($kind) {
            'related' => ['kind' => 'related', 'relation' => '', 'column' => ''],
            'eav' => ['kind' => 'eav', 'relation' => '', 'key_column' => '', 'value_column' => '', 'key_value' => ''],
            'choice' => ['kind' => 'choice', 'columns' => ['', ''], 'preference' => 'first_present'],
            'operator' => ['kind' => 'operator', 'operator' => 'rusacis_hero_active'],
            default => ['kind' => 'column', 'column' => ''],
        };
    }

    public function addChoiceColumn(string $field): void
    {
        $this->managePermission();
        if (! in_array($field, ProfileFormat::FIELD_NAMES, true)) {
            return;
        }
        $definition = &$this->draft['config']['characters']['fields'][$field];
        if (($definition['kind'] ?? '') === 'choice' && count($definition['columns']) < 4) {
            $definition['columns'][] = '';
        }
    }

    public function removeChoiceColumn(string $field, int $index): void
    {
        $this->managePermission();
        if (! in_array($field, ProfileFormat::FIELD_NAMES, true)) {
            return;
        }
        $definition = &$this->draft['config']['characters']['fields'][$field];
        if (($definition['kind'] ?? '') === 'choice' && count($definition['columns']) > 1) {
            unset($definition['columns'][$index]);
            $definition['columns'] = array_values($definition['columns']);
        }
    }

    public function removeField(string $field): void
    {
        $this->managePermission();
        if (in_array($field, ['id', 'name'], true)) {
            return;
        }
        unset($this->draft['config']['characters']['fields'][$field]);
    }

    public function addFilter(): void
    {
        $this->managePermission();
        if (count($this->draft['config']['filters'] ?? []) < 16) {
            $this->draft['config']['filters'][] = ['column' => '', 'op' => 'eq', 'value' => '0'];
        }
    }

    public function removeFilter(int $index): void
    {
        $this->managePermission();
        unset($this->draft['config']['filters'][$index]);
        $this->draft['config']['filters'] = array_values($this->draft['config']['filters']);
    }

    public function addRanking(): void
    {
        $this->managePermission();
        if (! in_array($this->newRanking, ['level', 'pvp', 'pk', 'play_time'], true)) {
            return;
        }
        $this->draft['config']['rankings'][$this->newRanking] ??= ['column' => '', 'secondary' => null, 'positive_only' => true];
        $this->newRanking = '';
    }

    public function removeRanking(string $name): void
    {
        $this->managePermission();
        unset($this->draft['config']['rankings'][$name]);
    }

    public function addFeature(): void
    {
        $this->managePermission();
        if ($this->newFeature === 'heroes') {
            $this->draft['config']['features']['heroes'] ??= ['relation' => ''];
        } elseif ($this->newFeature === 'castles') {
            $this->draft['config']['features']['castles'] ??= ['relation' => '', 'name_source' => 'catalog'];
        }
        $this->newFeature = '';
    }

    public function removeFeature(string $name): void
    {
        $this->managePermission();
        unset($this->draft['config']['features'][$name]);
    }

    public function addFixedValue(): void
    {
        $this->managePermission();
        $key = trim($this->newFixedColumn);
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D', $key) !== 1 || count($this->draft['config']['accounts']['create']['fixed_values'] ?? []) >= 16) {
            return;
        }
        $this->draft['config']['accounts']['create']['fixed_values'][$key] = '0';
        $this->newFixedColumn = '';
    }

    public function removeFixedValue(string $key): void
    {
        $this->managePermission();
        unset($this->draft['config']['accounts']['create']['fixed_values'][$key]);
    }

    /** @return list<string> */
    public function availableCapabilities(string $type): array
    {
        return $type === 'login' ? ProfileFormat::LOGIN_CAPABILITIES : ProfileFormat::GAME_CAPABILITIES;
    }

    public function render(): View
    {
        $this->viewPermission();
        $catalog = app(DriverProfileCatalog::class);
        $entries = $catalog->all();
        $builtin = app(BundledProfileCatalog::class)->all();
        $hidden = [];
        foreach ($builtin as $key => $profile) {
            if (! isset($entries[$key])) {
                $hidden[$key] = $profile;
            }
        }
        return view('livewire.admin.universal-driver-manager', [
            'profiles' => $entries,
            'hiddenProfiles' => $hidden,
            'canManage' => $this->mayManage(),
            'fieldNames' => ProfileFormat::FIELD_NAMES,
            'capabilities' => $this->availableCapabilities($this->editing ? ($this->originalType ?? $this->tab) : $this->tab),
        ]);
    }

    private function audit(string $action, string $type, string $id): void
    {
        app(AuditLogger::class)->success('servers', 'universal_driver_'.$action, auth('admin')->user(), $type.':'.$id);
    }

    private function mayManage(): bool
    {
        $admin = auth('admin')->user();
        return $admin instanceof Admin && ! $admin->isReadOnly() && $admin->hasPermission(AdminPermission::ServersManage);
    }

    private function viewPermission(): void
    {
        $admin = auth('admin')->user();
        abort_unless($admin instanceof Admin && $admin->hasPermission(AdminPermission::ServersView), 403);
    }

    private function managePermission(): void
    {
        abort_unless($this->mayManage(), 403);
    }
}
