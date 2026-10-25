<div class="server-manager universal-drivers" data-testid="universal-driver-manager">
    @if($notice)<div class="notice notice-success" role="status">{{ $notice }}</div>@endif
    @if($error)<div class="notice notice-error" role="alert">{{ $error }}</div>@endif
    @error('importFile')<div class="notice notice-error" role="alert">{{ $message }}</div>@enderror

    <nav class="ud-types" aria-label="{{ __('drivers.types') }}">
        <button type="button" @class(['button','button-primary' => $tab === 'login','button-secondary' => $tab !== 'login']) wire:click="switchType('login')">LoginServer</button>
        <button type="button" @class(['button','button-primary' => $tab === 'game','button-secondary' => $tab !== 'game']) wire:click="switchType('game')">GameServer</button>
    </nav>
    <div class="ud-toolbar">
        <p>{{ __('drivers.description') }}</p>
        @if($canManage)
            <div class="ud-actions">
                <button type="button" class="button button-secondary" wire:click="$set('importOpen', true)">{{ __('drivers.import') }}</button>
                <button type="button" class="button button-primary" wire:click="createProfile('{{ $tab }}')">+ {{ __('drivers.create') }}</button>
            </div>
        @endif
    </div>

    @if($importOpen && $canManage)
        <form class="ud-panel" wire:submit="importProfile">
            <h2>{{ __('drivers.import') }}</h2>
            <p>{{ __('drivers.import_help') }}</p>
            <input aria-label="JSON" type="file" accept=".json,application/json" wire:model="importFile" required>
            <div class="ud-actions">
                <button class="button button-primary" type="submit" wire:loading.attr="disabled" wire:target="importFile,importProfile">{{ __('drivers.import') }}</button>
                <button class="button button-secondary" type="button" wire:click="$set('importOpen', false)">{{ __('Cancel') }}</button>
            </div>
        </form>
    @endif

    @if($confirmDelete && $canManage)
        <div class="ud-panel" role="alertdialog" aria-modal="true" aria-label="{{ __('drivers.delete') }}">
            <h2>{{ __('drivers.delete') }} — {{ $originalId }}</h2>
            <p>{{ __('drivers.delete_warning') }}</p>
            <div class="ud-actions">
                <button type="button" class="button button-danger" wire:click="deleteProfile">{{ __('drivers.delete') }}</button>
                <button type="button" class="button button-secondary" wire:click="$set('confirmDelete', false)">{{ __('Cancel') }}</button>
            </div>
        </div>
    @endif

    @unless($editing)
        <div class="ud-list">
            @forelse($profiles as $entry)
                @php($p = $entry['profile'])
                @if($p['type'] === $tab)
                    <article class="ud-profile" wire:key="profile-{{ $p['type'] }}-{{ $p['id'] }}">
                        <div class="ud-profile-copy">
                            <h2>{{ $p['name'] }}</h2>
                            <div class="ud-meta"><code>{{ $p['id'] }}</code> · {{ $entry['origin'] === 'builtin' ? __('drivers.builtin') : __('drivers.custom') }}@if($entry['modified']) · {{ __('drivers.modified') }}@endif · {{ count($p['config']['capabilities']) }} {{ __('drivers.capabilities') }}</div>
                        </div>
                        <div class="ud-actions">
                            <button class="button button-secondary" type="button" wire:click="exportProfile('{{ $p['type'] }}', '{{ $p['id'] }}')">{{ __('drivers.export') }}</button>
                            @if($canManage)
                                <details class="server-card-menu">
                                    <summary aria-label="{{ __('More actions') }}">⋯</summary>
                                    <button type="button" wire:click="editProfile('{{ $p['type'] }}', '{{ $p['id'] }}')">{{ __('drivers.edit') }}</button>
                                    <button type="button" wire:click="cloneProfile('{{ $p['type'] }}', '{{ $p['id'] }}')">{{ __('drivers.copy') }}</button>
                                    @if($entry['origin'] === 'builtin' && $entry['modified'])
                                        <button type="button" wire:click="restoreProfile('{{ $p['type'] }}', '{{ $p['id'] }}')">{{ __('drivers.restore') }}</button>
                                    @endif
                                    <button type="button" wire:click="askDelete('{{ $p['type'] }}', '{{ $p['id'] }}')">{{ __('drivers.delete') }}</button>
                                </details>
                            @endif
                        </div>
                    </article>
                @endif
            @empty
                <div class="empty-state"><h2>{{ __('drivers.empty') }}</h2></div>
            @endforelse
            @foreach($hiddenProfiles as $p)
                @if($p['type'] === $tab)
                    <article class="ud-profile ud-profile-hidden" wire:key="hidden-{{ $p['type'] }}-{{ $p['id'] }}">
                        <div class="ud-profile-copy"><h2>{{ $p['name'] }}</h2><p>{{ __('drivers.hidden') }}</p></div>
                        @if($canManage)<button class="button button-secondary" type="button" wire:click="restoreProfile('{{ $p['type'] }}', '{{ $p['id'] }}')">{{ __('drivers.restore') }}</button>@endif
                    </article>
                @endif
            @endforeach
        </div>
    @else
        @php($config = $draft['config'])
        <div class="ud-editor">
            <div class="ud-editor-heading">
                <div><span class="ud-kicker">{{ $draft['type'] === 'login' ? 'LoginServer' : 'GameServer' }}</span><h2>{{ $originalId === null ? __('drivers.new_profile') : __('drivers.edit') . ': ' . ($draft['name'] ?? '') }}</h2></div>
                <button type="button" class="button button-secondary" wire:click="closeEditor">{{ __('Cancel') }}</button>
            </div>
            <p class="ud-info">{{ __('drivers.offline_help') }}</p>
            <nav class="ud-sections" aria-label="{{ __('drivers.sections') }}">
                @foreach(['general', 'schema', 'mapping', 'features'] as $s)
                    @if($s !== 'features' || $draft['type'] === 'game')
                        <button type="button" @class(['ud-section-tab', 'active' => $section === $s]) wire:click="setSection('{{ $s }}')">{{ __('drivers.section_'.$s) }}</button>
                    @endif
                @endforeach
            </nav>

            @if($section === 'general')
                <section class="ud-panel">
                    <h3>{{ __('drivers.section_general') }}</h3>
                    <div class="ud-grid">
                        <label>{{ __('drivers.name') }}<input type="text" maxlength="100" wire:model="draft.name" required></label>
                        <label>{{ __('drivers.identifier') }}<input type="text" maxlength="64" wire:model="draft.id" placeholder="my-server" @disabled($originalId !== null)><small>{{ __('drivers.identifier_help') }}</small></label>
                        <label>Charset<input type="text" maxlength="64" wire:model="draft.config.database.charset"></label>
                        <label>Collation<input type="text" maxlength="64" wire:model="draft.config.database.collation"></label>
                    </div>
                    <h3>{{ __('drivers.capabilities') }}</h3>
                    <div class="ud-checks">
                        @foreach($capabilities as $capability)
                            <label><input type="checkbox" wire:model="enabled.{{ $capability }}"> {{ __('drivers.cap_'.$capability) }}</label>
                        @endforeach
                    </div>
                </section>
            @elseif($section === 'schema')
                <section class="ud-panel">
                    <div class="ud-section-head"><div><h3>{{ __('drivers.schema') }}</h3><p>{{ __('drivers.schema_help') }}</p></div><button type="button" class="button button-secondary" wire:click="addRequirement">+ {{ __('drivers.table') }}</button></div>
                    @foreach($config['requirements'] as $i => $r)
                        <div class="ud-group" wire:key="requirement-{{ $i }}">
                            <div class="ud-section-head"><strong>{{ __('drivers.table') }} {{ $i+1 }}</strong><button class="button button-secondary" type="button" wire:click="removeRequirement({{ $i }})" @disabled(count($config['requirements']) < 2)>{{ __('drivers.remove') }}</button></div>
                            <div class="ud-grid"><label>{{ __('drivers.table') }}<input type="text" wire:model="draft.config.requirements.{{ $i }}.table"></label><label class="ud-check"><input type="checkbox" wire:model="draft.config.requirements.{{ $i }}.required"> {{ __('drivers.required') }}</label></div>
                            @foreach(['columns' => 'required_columns', 'optional_columns' => 'optional_columns', 'any_columns' => 'any_columns'] as $kind => $label)
                                <div class="ud-column-set">
                                    <div class="ud-section-head"><strong>{{ __('drivers.'.$label) }}</strong><button class="button button-secondary" type="button" wire:click="addColumn({{ $i }}, '{{ $kind }}')">+</button></div>
                                    <div class="ud-inline-list">
                                        @foreach(($r[$kind] ?? []) as $j => $column)
                                            <div class="ud-inline-input" wire:key="column-{{ $i }}-{{ $kind }}-{{ $j }}"><input type="text" maxlength="64" wire:model="draft.config.requirements.{{ $i }}.{{ $kind }}.{{ $j }}" aria-label="{{ __('drivers.column') }}"><button type="button" title="{{ __('drivers.remove') }}" wire:click="removeColumn({{ $i }}, '{{ $kind }}', {{ $j }})">×</button></div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </section>
                @if($draft['type'] === 'game')
                    <section class="ud-panel">
                        <h3>{{ __('drivers.relations') }}</h3>
                        @foreach($config['relations'] as $name => $relation)
                            <div class="ud-group" wire:key="relation-{{ $name }}">
                                <div class="ud-section-head"><strong>{{ $name }}</strong><button type="button" class="button button-secondary" wire:click="removeRelation('{{ $name }}')">{{ __('drivers.remove') }}</button></div>
                                <div class="ud-grid">
                                    @foreach(['table' => 'table', 'local_column' => 'local_column', 'foreign_column' => 'foreign_column'] as $key => $label)
                                        <label>{{ __('drivers.'.$label) }}<input type="text" wire:model="draft.config.relations.{{ $name }}.{{ $key }}"></label>
                                    @endforeach
                                    <label>JOIN<select wire:model="draft.config.relations.{{ $name }}.join"><option value="left">LEFT</option><option value="inner">INNER</option></select></label>
                                    <label>{{ __('drivers.cardinality') }}<select wire:model="draft.config.relations.{{ $name }}.cardinality"><option value="one">1 → 1</option><option value="many">1 → N</option></select></label>
                                </div>
                            </div>
                        @endforeach
                        <div class="ud-actions"><input type="text" maxlength="64" wire:model="newRelation" placeholder="{{ __('drivers.relation_name') }}"><button type="button" class="button button-secondary" wire:click="addRelation">+ {{ __('drivers.relation') }}</button></div>
                    </section>
                @endif
            @elseif($section === 'mapping')
                @if($draft['type'] === 'login')
                    <section class="ud-panel">
                        <h3>{{ __('drivers.account_mapping') }}</h3>
                        <div class="ud-grid">
                            <label>{{ __('drivers.table') }}<input type="text" wire:model="draft.config.accounts.table"></label>
                            <label>{{ __('drivers.ownership') }}<select wire:model="draft.config.accounts.ownership.identity"><option value="login">Login</option><option value="id">ID</option></select></label>
                            <label>{{ __('drivers.owner_column') }}<input type="text" wire:model="draft.config.accounts.ownership.column"></label>
                            @foreach(['login','password','id','email','created_at','last_active','access_level','last_server'] as $field)
                                <label>{{ __('drivers.field_'.$field) }}<input type="text" maxlength="64" wire:model="draft.config.accounts.fields.{{ $field }}" placeholder="{{ __('drivers.optional') }}"></label>
                            @endforeach
                        </div>
                        <h3>{{ __('drivers.password') }}</h3>
                        <div class="ud-grid"><label>{{ __('drivers.password_scheme') }}<select wire:model.live="draft.config.password.scheme"><option value="sha1_base64">SHA-1 / Base64</option><option value="bcrypt_2a">BCrypt $2a$</option></select></label>
                        @if(($config['password']['scheme'] ?? '') === 'bcrypt_2a')<label>BCrypt cost<input type="number" min="4" max="16" wire:model.number="draft.config.password.cost"></label>@endif</div>
                        <p class="ud-info">{{ __('drivers.account_creation_help') }}</p>
                        <h3>{{ __('drivers.fixed_values') }}</h3>
                        @foreach(($config['accounts']['create']['fixed_values'] ?? []) as $column => $value)
                            <div class="ud-actions" wire:key="fixed-{{ $column }}"><label>{{ $column }}<input type="text" wire:model="draft.config.accounts.create.fixed_values.{{ $column }}"></label><button type="button" class="button button-secondary" wire:click="removeFixedValue('{{ $column }}')">×</button></div>
                        @endforeach
                        <div class="ud-actions"><input maxlength="64" type="text" wire:model="newFixedColumn" placeholder="{{ __('drivers.column') }}"><button class="button button-secondary" type="button" wire:click="addFixedValue">+ {{ __('drivers.fixed_value') }}</button></div>
                    </section>
                @else
                    <section class="ud-panel">
                        <h3>{{ __('drivers.characters') }}</h3>
                        <div class="ud-grid">
                            <label>{{ __('drivers.table') }}<input type="text" wire:model="draft.config.characters.table"></label>
                            <label>{{ __('drivers.ownership') }}<select wire:model="draft.config.characters.owner.identity"><option value="login">Login</option><option value="id">ID</option></select></label>
                            <label>{{ __('drivers.owner_column') }}<input type="text" wire:model="draft.config.characters.owner.column"></label>
                        </div>
                        <h3>{{ __('drivers.fields') }}</h3>
                        @foreach($config['characters']['fields'] as $field => $def)
                            <div class="ud-group" wire:key="field-{{ $field }}">
                                <div class="ud-section-head"><strong>{{ __('drivers.field_'.$field) }}</strong>@unless(in_array($field,['id','name'],true))<button type="button" class="button button-secondary" wire:click="removeField('{{ $field }}')">{{ __('drivers.remove') }}</button>@endunless</div>
                                <div class="ud-grid">
                                    <label>{{ __('drivers.source') }}<select wire:change="changeFieldKind('{{ $field }}', $event.target.value)">
                                        @foreach(['column', 'related', 'eav', 'choice', 'operator'] as $kind)
                                            <option value="{{ $kind }}" @selected($def['kind'] === $kind) @disabled(in_array($field, ['id','name'],true) && $kind !== 'column')>{{ __('drivers.kind_'.$kind) }}</option>
                                        @endforeach
                                    </select></label>
                                    @if(in_array($def['kind'],['column','related'],true))
                                        <label>{{ __('drivers.column') }}<input type="text" wire:model="draft.config.characters.fields.{{ $field }}.column"></label>
                                    @endif
                                    @if(in_array($def['kind'],['related','eav'],true))
                                        <label>{{ __('drivers.relation') }}<select wire:model="draft.config.characters.fields.{{ $field }}.relation"><option value="">—</option>@foreach(array_keys($config['relations']) as $rel)<option value="{{ $rel }}">{{ $rel }}</option>@endforeach</select></label>
                                    @endif
                                    @if($def['kind'] === 'eav')
                                        @foreach(['key_column','value_column','key_value'] as $key)<label>{{ __('drivers.'.$key) }}<input type="text" wire:model="draft.config.characters.fields.{{ $field }}.{{ $key }}"></label>@endforeach
                                    @endif
                                    @if($def['kind'] === 'choice')
                                        <label>{{ __('drivers.preference') }}<select wire:model="draft.config.characters.fields.{{ $field }}.preference"><option value="first_present">{{ __('drivers.first_present') }}</option><option value="mobius_chronicle">Mobius chronicle</option></select></label>
                                        @foreach($def['columns'] as $index => $col)<label>{{ __('drivers.column') }} {{ $index+1 }}<input type="text" wire:model="draft.config.characters.fields.{{ $field }}.columns.{{ $index }}"></label>@endforeach
                                        <button class="button button-secondary" type="button" wire:click="addChoiceColumn('{{ $field }}')" @disabled(count($def['columns']) >= 4)>+ {{ __('drivers.column') }}</button>
                                    @endif
                                    @if($def['kind'] === 'operator')<label>{{ __('drivers.operation') }}<select wire:model="draft.config.characters.fields.{{ $field }}.operator"><option value="rusacis_hero_active">RUSaCis hero active</option></select></label>@endif
                                </div>
                            </div>
                        @endforeach
                        <div class="ud-actions"><select wire:model="newField"><option value="">{{ __('drivers.choose_field') }}</option>@foreach($fieldNames as $field)@unless(isset($config['characters']['fields'][$field]))<option value="{{ $field }}">{{ __('drivers.field_'.$field) }}</option>@endunless @endforeach</select><button type="button" class="button button-secondary" wire:click="addField">+ {{ __('drivers.field') }}</button></div>
                    </section>
                @endif
            @elseif($section === 'features' && $draft['type'] === 'game')
                <section class="ud-panel">
                    <h3>{{ __('drivers.filters') }}</h3>
                    @foreach($config['filters'] as $i => $filter)
                        <div class="ud-actions" wire:key="filter-{{ $i }}"><input type="text" aria-label="{{ __('drivers.column') }}" wire:model="draft.config.filters.{{ $i }}.column"><select wire:model.live="draft.config.filters.{{ $i }}.op"><option value="eq">=</option><option value="gt">&gt;</option><option value="zero_or_null">0 / NULL</option></select>@if($filter['op'] !== 'zero_or_null')<input type="text" aria-label="{{ __('drivers.value') }}" wire:model="draft.config.filters.{{ $i }}.value">@endif <button type="button" class="button button-secondary" wire:click="removeFilter({{ $i }})">×</button></div>
                    @endforeach
                    <button type="button" class="button button-secondary" wire:click="addFilter">+ {{ __('drivers.filter') }}</button>
                </section>
                <section class="ud-panel">
                    <h3>{{ __('drivers.rankings') }}</h3>
                    @foreach($config['rankings'] as $name => $rank)
                        <div class="ud-group" wire:key="ranking-{{ $name }}"><div class="ud-section-head"><strong>{{ __('drivers.cap_'.$name) }}</strong><button type="button" class="button button-secondary" wire:click="removeRanking('{{ $name }}')">×</button></div><div class="ud-grid"><label>{{ __('drivers.column') }}<input type="text" wire:model="draft.config.rankings.{{ $name }}.column"></label><label>{{ __('drivers.secondary') }}<input type="text" wire:model="draft.config.rankings.{{ $name }}.secondary"></label><label class="ud-check"><input type="checkbox" wire:model="draft.config.rankings.{{ $name }}.positive_only"> {{ __('drivers.positive_only') }}</label></div></div>
                    @endforeach
                    <div class="ud-actions"><select wire:model="newRanking"><option value="">—</option>@foreach(['level','pvp','pk','play_time'] as $name)@unless(isset($config['rankings'][$name]))<option value="{{ $name }}">{{ __('drivers.cap_'.$name) }}</option>@endunless @endforeach</select><button type="button" class="button button-secondary" wire:click="addRanking">+ {{ __('drivers.ranking') }}</button></div>
                </section>
                <section class="ud-panel">
                    <h3>{{ __('drivers.features') }}</h3>
                    @foreach($config['features'] as $name => $feature)
                        <div class="ud-group" wire:key="feature-{{ $name }}"><div class="ud-section-head"><strong>{{ __('drivers.cap_'.$name) }}</strong><button type="button" class="button button-secondary" wire:click="removeFeature('{{ $name }}')">×</button></div><div class="ud-grid">
                            <label>{{ __('drivers.relation') }}<select wire:model="draft.config.features.{{ $name }}.relation"><option value="">—</option>@foreach(array_keys($config['relations']) as $rel)<option value="{{ $rel }}">{{ $rel }}</option>@endforeach</select></label>
                            @if($name === 'heroes')
                                @foreach(['played_column','active_column','count_column','claimed_column','active_value','played_value'] as $field)
                                    <label>{{ __('drivers.'.$field) }}<input type="text" wire:model="draft.config.features.heroes.{{ $field }}"></label>
                                @endforeach
                            @else
                                <label>{{ __('drivers.name_source') }}<select wire:model.live="draft.config.features.castles.name_source"><option value="catalog">{{ __('drivers.name_catalog') }}</option><option value="column">{{ __('drivers.name_column') }}</option></select></label>
                                @if(($feature['name_source'] ?? '') === 'column')<label>{{ __('drivers.name_column') }}<input type="text" wire:model="draft.config.features.castles.name_column"></label>@endif
                                @foreach(['clan_relation','leader_relation','leader_name_column'] as $field)
                                    <label>{{ __('drivers.'.$field) }}@if(str_ends_with($field, 'relation'))<select wire:model="draft.config.features.castles.{{ $field }}"><option value="">—</option>@foreach(array_keys($config['relations']) as $rel)<option value="{{ $rel }}">{{ $rel }}</option>@endforeach</select>@else<input type="text" wire:model="draft.config.features.castles.{{ $field }}">@endif</label>
                                @endforeach
                                @foreach(['id','name','level','reputation'] as $field)<label>{{ __('drivers.clan_'.$field) }}<input type="text" wire:model="draft.config.features.castles.clan_columns.{{ $field }}"></label>@endforeach
                            @endif
                        </div></div>
                    @endforeach
                    <div class="ud-actions"><select wire:model="newFeature"><option value="">—</option>@foreach(['heroes','castles'] as $name)@unless(isset($config['features'][$name]))<option value="{{ $name }}">{{ __('drivers.cap_'.$name) }}</option>@endunless @endforeach</select><button type="button" class="button button-secondary" wire:click="addFeature">+ {{ __('drivers.feature') }}</button></div>
                </section>
            @endif
            <div class="ud-savebar"><button type="button" class="button button-primary" wire:click="saveProfile" wire:loading.attr="disabled" wire:target="saveProfile">{{ __('Save') }}</button><button type="button" class="button button-secondary" wire:click="closeEditor">{{ __('Cancel') }}</button></div>
        </div>
    @endunless
</div>
