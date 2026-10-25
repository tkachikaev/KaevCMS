<?php

namespace App\Livewire\Admin;

use App\Auth\AdminPermission;
use App\Models\Admin;
use App\Services\AuditLogger;
use App\Support\UniversalDrivers\BundledProfileCatalog;
use App\Support\UniversalDrivers\DriverProfileCatalog;
use App\Support\UniversalDrivers\GameProfileEditor;
use App\Support\UniversalDrivers\LoginProfileEditor;
use App\Support\UniversalDrivers\ProfileEditorDraft;
use App\Support\UniversalDrivers\ProfileFormat;
use App\Support\UniversalDrivers\ProfileValidationException;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    #[Locked]
    public string $originalAccountsTable = '';

    /** @var array<string,mixed> */
    #[Locked]
    public array $originalGameConfig = [];

    #[Locked]
    public string $charactersTableForRelations = '';

    /** @var array<string,mixed> */
    public array $draft = [];

    /** @var array<string,bool> */
    public array $enabled = [];

    public string $newRelation = '';

    public string $newField = '';

    public string $newRanking = '';

    public string $newFeature = '';

    public string $newFixedColumn = '';

    public string $templateId = '';

    /** @var list<array{column:string,value:mixed}> */
    public array $registrationRows = [];

    public ?TemporaryUploadedFile $importFile = null;

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

    public function createProfile(string $type, ?string $baseId = null): void
    {
        $this->managePermission();
        abort_unless(in_array($type, ['login', 'game'], true), 404);
        $this->resetValidation();
        $this->importOpen = false;
        $this->confirmDelete = false;
        $this->tab = $type;
        $this->draft = app(ProfileEditorDraft::class)->blank($type);
        if ($baseId !== null) {
            $entry = app(DriverProfileCatalog::class)->find($type, $baseId);
            abort_unless($entry !== null, 404);
            $this->draft = $entry['profile'];
            $this->draft['id'] = '';
            $this->draft['name'] = mb_substr($this->draft['name'], 0, 75).' — '.__('drivers.new_copy');
        } elseif ($type === 'login') {
            $this->draft['config']['accounts']['fields'] += [
                'id' => null, 'email' => 'email', 'created_at' => 'created_time',
                'last_active' => 'lastactive', 'access_level' => 'accessLevel', 'last_server' => 'lastServer',
            ];
        } else {
            foreach (['level' => 'level', 'experience' => 'exp', 'class_id' => 'classid', 'race' => 'race', 'gender' => 'sex', 'title' => 'title', 'online' => 'online', 'last_seen_at_ms' => 'lastAccess', 'play_time_seconds' => 'onlinetime', 'pvp_kills' => 'pvpkills', 'pk_kills' => 'pkkills', 'reputation' => 'karma', 'noble' => 'nobless', 'clan_id' => 'clanid'] as $field => $column) {
                $this->draft['config']['characters']['fields'][$field] = ['kind' => 'column', 'column' => $column];
            }
        }
        $this->originalGameConfig = $type === 'game' ? $this->draft['config'] : [];
        $this->charactersTableForRelations = (string) ($this->draft['config']['characters']['table'] ?? '');
        $this->initializeGameDisplay();
        $this->templateId = $baseId ?? '';
        $this->loadRegistrationRows();
        $this->originalId = null;
        $this->originalType = null;
        $this->originalSha = null;
        $this->enabled = array_fill_keys($this->availableCapabilities($type), false);
        foreach ($this->draft['config']['capabilities'] as $capability) {
            $this->enabled[$capability] = true;
        }
        $this->originalAccountsTable = (string) ($this->draft['config']['accounts']['table'] ?? '');
        if ($type === 'login') {
            $this->enabled = array_fill_keys(ProfileFormat::LOGIN_CAPABILITIES, true);
        }
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
        $this->originalGameConfig = $type === 'game' ? $this->draft['config'] : [];
        $this->charactersTableForRelations = (string) ($this->draft['config']['characters']['table'] ?? '');
        $this->loadRegistrationRows();
        $this->originalAccountsTable = (string) ($this->draft['config']['accounts']['table'] ?? '');
        $this->originalType = $type;
        $this->originalId = $id;
        $this->originalSha = hash('sha256', app(DriverProfileCatalog::class)->canonicalJson($this->draft));
        $this->initializeGameDisplay();
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

    public function nextLoginStep(): void
    {
        $this->managePermission();
        abort_unless($this->editing && $this->draft['type'] === 'login', 409);
        $this->validateLoginDetails();
        $this->section = 'mapping';
    }

    public function useLoginTemplate(): void
    {
        $this->managePermission();
        abort_unless($this->editing && $this->draft['type'] === 'login' && $this->originalId === null, 409);
        $this->validate(['templateId' => ['required', 'string']], ['required' => __('drivers.choose_template')]);
        $this->createProfile('login', $this->templateId);
    }

    public function useGameTemplate(): void
    {
        $this->managePermission();
        abort_unless($this->editing && $this->draft['type'] === 'game' && $this->originalId === null, 409);
        $this->validate(['templateId' => ['required', 'string']], ['required' => __('drivers.choose_template')]);
        $this->createProfile('game', $this->templateId);
    }

    private function initializeGameDisplay(): void
    {
        if (($this->draft['type'] ?? '') !== 'game') {
            return;
        }
        foreach (['level' => 'level', 'pvp' => 'pvp_kills', 'pk' => 'pk_kills', 'play_time' => 'play_time_seconds'] as $rank => $field) {
            if (($this->draft['config']['characters']['fields'][$field]['kind'] ?? '') === 'column') {
                $this->draft['config']['rankings'][$rank] ??= ['column' => $this->draft['config']['characters']['fields'][$field]['column'], 'secondary' => null, 'positive_only' => $rank !== 'level'];
            }
        }
        if (isset($this->draft['config']['features']['heroes'])) {
            $hero = &$this->draft['config']['features']['heroes'];
            $hero += ['played_column' => 'played', 'count_column' => 'count', 'claimed_column' => $hero['active_column'] ?? 'claimed'];
        }
        if (isset($this->draft['config']['features']['castles'])) {
            $this->draft['config']['features']['castles'] += ['clan_relation' => 'clan', 'leader_relation' => 'leader', 'clan_columns' => ['id' => 'clan_id', 'name' => 'clan_name', 'level' => 'clan_level', 'reputation' => 'reputation_score'], 'leader_name_column' => 'char_name'];
        }
    }

    public function nextGameStep(string $target = 'mapping'): void
    {
        $this->managePermission();
        abort_unless($this->editing && $this->draft['type'] === 'game' && in_array($target, ['mapping', 'features'], true), 409);
        $this->validateGameDetails($target === 'features');
        if ($target === 'features') {
            $this->draft['config'] = app(GameProfileEditor::class)->alignReferences($this->draft['config'], $this->originalGameConfig);
        }
        $this->section = $target;
    }

    private function validateGameDetails(bool $mappings): void
    {
        $identifier = 'regex:/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D';
        $rules = [
            'draft.name' => ['required', 'string', 'max:100'],
            'draft.id' => ['nullable', 'string', 'max:64', 'regex:/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/D'],
            'draft.config.database.charset' => ['required', 'string', $identifier],
            'draft.config.database.collation' => ['required', 'string', $identifier],
        ];
        $names = ['draft.name' => __('drivers.name'), 'draft.id' => __('drivers.short_name'), 'draft.config.database.charset' => __('drivers.charset'), 'draft.config.database.collation' => __('drivers.collation')];
        if ($mappings) {
            foreach (['characters.table' => 'characters_table', 'characters.owner.column' => 'character_owner', 'characters.fields.id.column' => 'field_id_game', 'characters.fields.name.column' => 'field_name'] as $path => $label) {
                $rules['draft.config.'.$path] = ['required', 'string', $identifier];
                $names['draft.config.'.$path] = __('drivers.'.$label);
            }
        }
        $this->validate($rules, ['required' => __('drivers.validation_required'), 'regex' => __('drivers.validation_identifier'), 'string' => __('drivers.validation_string'), 'max' => __('drivers.validation_max')], $names);
    }

    public function updatedDraftConfigCharactersTable(string $value): void
    {
        $this->managePermission();
        foreach ($this->draft['config']['relations'] ?? [] as $key => $relation) {
            if ($relation['table'] === $this->charactersTableForRelations) {
                $this->draft['config']['relations'][$key]['table'] = $value;
            }
        }
        $this->charactersTableForRelations = $value;
    }

    private function validateLoginDetails(bool $includeMappings = false): void
    {
        $rules = [
            'draft.name' => ['required', 'string', 'max:100'],
            'draft.id' => ['nullable', 'string', 'max:64', 'regex:/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/D'],
            'draft.config.database.charset' => ['required', 'string', 'regex:/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D'],
            'draft.config.database.collation' => ['required', 'string', 'regex:/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D'],
            'draft.config.password.scheme' => ['required', 'in:'.implode(',', ProfileFormat::PASSWORD_SCHEMES)],
        ];
        $attributes = [
            'draft.name' => __('drivers.name'), 'draft.id' => __('drivers.short_name'),
            'draft.config.database.charset' => __('drivers.charset'),
            'draft.config.database.collation' => __('drivers.collation'),
            'draft.config.password.scheme' => __('drivers.password_scheme'),
        ];
        if (($this->draft['config']['password']['scheme'] ?? '') === 'bcrypt_2a') {
            $rules['draft.config.password.cost'] = ['nullable', 'integer', 'min:4', 'max:16'];
            $attributes['draft.config.password.cost'] = __('drivers.bcrypt_cost');
        }
        if ($includeMappings) {
            $rules['draft.config.accounts.table'] = ['required', 'string', 'regex:/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D'];
            $attributes['draft.config.accounts.table'] = __('drivers.accounts_table');
            foreach (['login', 'password', 'id', 'email', 'created_at', 'last_active', 'access_level', 'last_server'] as $field) {
                $required = in_array($field, ['login', 'password'], true)
                    || ($field === 'id' && ($this->draft['config']['accounts']['ownership']['identity'] ?? '') === 'id');
                $path = 'draft.config.accounts.fields.'.$field;
                $rules[$path] = [$required ? 'required' : 'nullable', 'string', 'regex:/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D'];
                $attributes[$path] = __('drivers.field_'.$field);
            }
        }
        $this->validate($rules, [
            'required' => __('drivers.validation_required'), 'regex' => __('drivers.validation_identifier'),
            'string' => __('drivers.validation_string'), 'max' => __('drivers.validation_max'),
            'in' => __('drivers.validation_choice'),
            'draft.config.password.cost.integer' => __('drivers.validation_bcrypt_cost'),
            'draft.config.password.cost.min' => __('drivers.validation_bcrypt_cost'),
            'draft.config.password.cost.max' => __('drivers.validation_bcrypt_cost'),
        ], $attributes);
    }

    public function saveProfile(): void
    {
        $this->managePermission();
        $this->error = '';
        if (! $this->editing) {
            abort(409);
        }
        if ($this->draft['type'] === 'login') {
            $this->validateLoginDetails(includeMappings: true);
            $this->validate([
                'registrationRows' => ['array', 'max:16'],
                'registrationRows.*.column' => ['required', 'string', 'regex:/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D', 'distinct:ignore_case'],
                'registrationRows.*.value' => ['present'],
            ], [
                'registrationRows.*.column.required' => __('drivers.registration_column_required'),
                'registrationRows.*.column.regex' => __('drivers.registration_column_invalid'),
                'registrationRows.*.column.distinct' => __('drivers.registration_column_duplicate'),
            ]);
            $fixed = [];
            foreach ($this->registrationRows as $row) {
                $fixed[$row['column']] = $row['value'];
            }
            $this->draft['config']['accounts']['create']['fixed_values'] = $fixed;
        } else {
            $this->validateGameDetails(mappings: true);
        }
        $catalog = app(DriverProfileCatalog::class);
        $profile = $this->draft;
        $type = $this->originalType ?? $this->tab;
        $profile['type'] = $type;
        $profile['format_version'] = ProfileFormat::CURRENT_VERSION;
        $profile['id'] = $this->originalId ?? trim((string) ($profile['id'] ?? ''));
        $profile['name'] = trim((string) ($profile['name'] ?? ''));
        if ($this->originalId === null && $profile['id'] === '') {
            $baseId = substr(Str::slug($profile['name']), 0, 48) ?: 'driver';
            if (preg_match('/\A[a-z]/', $baseId) !== 1) {
                $baseId = 'driver-'.$baseId;
            }
            $profile['id'] = $baseId;
            for ($suffix = 2; $catalog->find($type, $profile['id']) !== null; $suffix++) {
                $profile['id'] = $baseId.'-'.$suffix;
            }
        }
        $profile['config']['capabilities'] = array_keys(array_filter($this->enabled, static fn ($v) => $v === true));
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
            if ($type === 'login') {
                $profile = app(ProfileEditorDraft::class)->clean($profile);
                $profile = app(LoginProfileEditor::class)->prepare($profile, $this->originalAccountsTable);
            } else {
                $profile = app(GameProfileEditor::class)->prepare($profile, $this->originalGameConfig);
            }
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
            $this->error = $type === 'login'
                ? match ($exception->getMessage()) {
                    'Driver profile ID already exists.' => __('drivers.short_name_taken'),
                    'This driver was changed by another administrator. Reopen it before saving.' => __('drivers.reload_profile'),
                    default => __('drivers.login_save_failed'),
                }
            : (str_starts_with($exception->getMessage(), 'drivers.') ? __($exception->getMessage()) : __('drivers.game_save_failed'));
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

    public function exportProfile(string $type, string $id): StreamedResponse
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
        if ($this->importFile === null) {
            return;
        }
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

    public function closeImport(): void
    {
        $this->importOpen = false;
        $this->importFile = null;
        $this->resetValidation('importFile');
    }

    public function cancelDelete(): void
    {
        $this->confirmDelete = false;
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

    public function addGameRelation(): void
    {
        $this->managePermission();
        abort_unless($this->editing && $this->draft['type'] === 'game', 409);
        if (count($this->draft['config']['relations']) >= 16) {
            return;
        }
        for ($index = 1; isset($this->draft['config']['relations']['relation_'.$index]); $index++) {
        }
        $this->draft['config']['relations']['relation_'.$index] = ['table' => '', 'local_column' => '', 'foreign_column' => '', 'join' => 'left', 'cardinality' => 'one'];
    }

    public function configureGameFeature(string $name): void
    {
        $this->managePermission();
        abort_unless($this->editing && $this->draft['type'] === 'game' && in_array($name, ['heroes', 'castles'], true), 409);
        $id = $this->draft['config']['characters']['fields']['id']['column'];
        $characterTable = $this->draft['config']['characters']['table'];
        if ($name === 'heroes') {
            $this->draft['config']['relations']['heroes'] ??= ['table' => 'heroes', 'local_column' => $id, 'foreign_column' => $id, 'join' => 'inner', 'cardinality' => 'one'];
            $this->draft['config']['features']['heroes'] ??= ['relation' => 'heroes', 'count_column' => 'count', 'played_column' => 'played', 'claimed_column' => 'claimed'];
        } else {
            $this->draft['config']['relations']['clan'] ??= ['table' => 'clan_data', 'local_column' => $this->draft['config']['characters']['fields']['clan_id']['column'] ?? 'clanid', 'foreign_column' => 'clan_id', 'join' => 'left', 'cardinality' => 'one'];
            $this->draft['config']['relations']['castle'] ??= ['table' => 'castle', 'local_column' => 'hasCastle', 'foreign_column' => 'id', 'join' => 'inner', 'cardinality' => 'one'];
            $this->draft['config']['relations']['leader'] ??= ['table' => $characterTable, 'local_column' => 'leader_id', 'foreign_column' => $id, 'join' => 'left', 'cardinality' => 'one'];
            $this->draft['config']['features']['castles'] ??= ['relation' => 'castle', 'name_source' => 'catalog', 'clan_relation' => 'clan', 'leader_relation' => 'leader', 'clan_columns' => ['id' => 'clan_id', 'name' => 'clan_name', 'level' => 'clan_level', 'reputation' => 'reputation_score'], 'leader_name_column' => $this->draft['config']['characters']['fields']['name']['column']];
        }
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
            (in_array($field, ['id', 'name'], true) && $kind !== 'column')) {
            return;
        }
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

    private function loadRegistrationRows(): void
    {
        $this->registrationRows = [];
        foreach ($this->draft['config']['accounts']['create']['fixed_values'] ?? [] as $column => $value) {
            $this->registrationRows[] = ['column' => $column, 'value' => $value];
        }
    }

    public function addRegistrationRow(): void
    {
        $this->managePermission();
        abort_unless($this->editing && $this->draft['type'] === 'login', 409);
        if (count($this->registrationRows) < 16) {
            $this->registrationRows[] = ['column' => '', 'value' => '0'];
        }
    }

    public function removeRegistrationRow(int $index): void
    {
        $this->managePermission();
        unset($this->registrationRows[$index]);
        $this->registrationRows = array_values($this->registrationRows);
        $this->resetValidation('registrationRows');
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
