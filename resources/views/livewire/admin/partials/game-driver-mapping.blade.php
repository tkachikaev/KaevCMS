<p class="ud-info">{{ __('drivers.game_mapping_help') }}</p>
<div class="ud-main-table">
    <label for="ud-game-main-table">{{ __('drivers.main_table') }}
        <input id="ud-game-main-table" type="text" wire:model.blur="draft.config.characters.table" maxlength="64">
        <small>{{ __('drivers.main_table_help') }}</small>
    </label>
    @error('draft.config.characters.table')<p class="ud-field-error" role="alert">{{ $message }}</p>@enderror
</div>
@php
    $groups = [
        'game_basic' => ['id','name','level','class_id','race','gender','online'],
        'game_statistics' => ['experience','pvp_kills','pk_kills','play_time_seconds','last_seen_at_ms','reputation'],
        'game_additional' => ['title','noble','hero','clan_id','clan_name','created_at','x','y','z'],
    ];
    $mainTable = $config['characters']['table'];
@endphp
@foreach($groups as $group => $fields)
    <details class="ud-game-group" data-game-group="{{ $group }}" wire:ignore.self>
        <summary><h3>{{ __('drivers.'.$group) }}</h3></summary>
        <div class="ud-map-wrap">
        <table class="ud-map-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('drivers.col_field') }}</th>
                    <th scope="col">{{ __('drivers.table') }}</th>
                    <th scope="col">{{ __('drivers.column') }}</th>
                    <th scope="col" aria-label="{{ __('drivers.clear_mapping') }}"></th>
                </tr>
            </thead>
            <tbody>
                @if($group === 'game_basic')
                    <tr class="ud-map-row ud-map-required" wire:key="game-field-owner">
                        <th scope="row"><strong>{{ __('drivers.character_owner') }}</strong><small>{{ __('drivers.required_field') }}</small></th>
                        <td><code class="ud-main-table-name">{{ $mainTable }}</code></td>
                        <td>
                            <input type="text" aria-label="{{ __('drivers.character_owner') }}: {{ __('drivers.column') }}" wire:model="draft.config.characters.owner.column" maxlength="64">
                            @error('draft.config.characters.owner.column')<p class="ud-field-error" role="alert">{{ $message }}</p>@enderror
                        </td>
                        <td></td>
                    </tr>
                @endif
                @foreach($fields as $field)
                    @php($def = $config['characters']['fields'][$field] ?? ['kind' => 'column', 'column' => ''])
                    @php($relation = $config['relations'][$def['relation'] ?? ''] ?? null)
                    @php($required = in_array($field, ['id','name'], true))
                    @php($label = $field === 'id' ? __('drivers.field_id_game') : __('drivers.field_'.$field))
                    <tr @class(['ud-map-row', 'ud-map-required' => $required]) wire:key="game-field-{{ $field }}">
                        <th scope="row"><strong>{{ $label }}</strong><small>{{ $required ? __('drivers.required_field') : __('drivers.empty_if_missing') }}</small></th>
                        <td>
                            @if(in_array($def['kind'], ['related','eav'], true))
                                @if($relation)
                                    <input type="text" aria-label="{{ $label }}: {{ __('drivers.table') }}" wire:model.blur="draft.config.relations.{{ $def['relation'] }}.table" maxlength="64" placeholder="{{ __('drivers.other_table_placeholder') }}">
                                @else
                                    <span class="ud-info">{{ __('drivers.game_relation_missing') }}</span>
                                @endif
                            @elseif($def['kind'] === 'operator')
                                <span class="ud-info">{{ __('drivers.kind_operator') }}</span>
                            @else
                                <code class="ud-main-table-name">{{ $mainTable }}</code>
                            @endif
                        </td>
                        <td>
                            @if(in_array($def['kind'], ['column','related'], true))
                                <input type="text" aria-label="{{ $label }}: {{ __('drivers.column') }}" wire:model="draft.config.characters.fields.{{ $field }}.column" maxlength="64" placeholder="{{ __('drivers.optional') }}">
                            @elseif($def['kind'] === 'eav')
                                <input type="text" aria-label="{{ $label }}: {{ __('drivers.value_column') }}" wire:model="draft.config.characters.fields.{{ $field }}.value_column" maxlength="64" placeholder="{{ __('drivers.value_column') }}">
                            @elseif($def['kind'] === 'choice')
                                <span class="ud-info">{{ __('drivers.kind_choice') }}</span>
                            @endif
                            @error('draft.config.characters.fields.'.$field.'.column')<p class="ud-field-error" role="alert">{{ $message }}</p>@enderror
                        </td>
                        <td class="ud-map-end">
                            @unless($required)
                                <button type="button" class="ud-remove" wire:click="removeField('{{ $field }}')" aria-label="{{ __('drivers.clear_mapping') }}" title="{{ __('drivers.clear_mapping') }}">✕</button>
                            @endunless
                        </td>
                    </tr>
                    @unless($required)
                        <tr class="ud-map-extra" wire:key="game-field-source-{{ $field }}">
                            <td colspan="4">
                                <details class="ud-field-source" wire:ignore.self @if($def['kind'] !== 'column') open @endif>
                                    <summary>{{ __('drivers.field_source_settings') }}</summary>
                                    <div class="ud-grid">
                                        <label>{{ __('drivers.source') }}
                                            <select wire:change="changeFieldKind('{{ $field }}', $event.target.value)">
                                                @foreach(['column','related','eav','choice','operator'] as $kind)
                                                    @continue($kind === 'operator' && $field !== 'hero' && $def['kind'] !== 'operator')
                                                    <option value="{{ $kind }}" @selected($def['kind'] === $kind)>{{ __('drivers.kind_'.$kind) }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        @if(in_array($def['kind'], ['related','eav'], true))
                                            <label>{{ __('drivers.relation') }}<select wire:model.live="draft.config.characters.fields.{{ $field }}.relation">
                                                <option value="">{{ __('drivers.choose_relation') }}</option>
                                                @foreach($config['relations'] as $name => $r)<option value="{{ $name }}">{{ $r['table'] ?: __('drivers.relation_unset') }}</option>@endforeach
                                            </select></label>
                                            @if($relation)
                                                <label>{{ __('drivers.link_from', ['table' => $mainTable]) }}<input type="text" wire:model="draft.config.relations.{{ $def['relation'] }}.local_column" maxlength="64"></label>
                                                <label>{{ __('drivers.link_to') }}<input type="text" wire:model="draft.config.relations.{{ $def['relation'] }}.foreign_column" maxlength="64"></label>
                                            @endif
                                        @endif
                                        @if($def['kind'] === 'eav')
                                            <p class="ud-info">{{ __('drivers.game_eav_world_limit') }}</p>
                                            <label>{{ __('drivers.key_column') }}<input type="text" wire:model="draft.config.characters.fields.{{ $field }}.key_column"></label>
                                            <label>{{ __('drivers.key_value') }}<input type="text" wire:model="draft.config.characters.fields.{{ $field }}.key_value"></label>
                                        @elseif($def['kind'] === 'choice')
                                            <label>{{ __('drivers.preference') }}<select wire:model="draft.config.characters.fields.{{ $field }}.preference"><option value="first_present">{{ __('drivers.first_present') }}</option><option value="mobius_chronicle">{{ __('drivers.mobius_chronicle') }}</option></select></label>
                                            @foreach($def['columns'] as $i => $column)
                                                <label>{{ __('drivers.column') }} {{ $i + 1 }}<input type="text" wire:model="draft.config.characters.fields.{{ $field }}.columns.{{ $i }}"></label>
                                            @endforeach
                                            <button type="button" class="button button-secondary" wire:click="addChoiceColumn('{{ $field }}')" @disabled(count($def['columns']) >= 4)>+ {{ __('drivers.column') }}</button>
                                        @elseif($def['kind'] === 'operator')
                                            <p class="ud-info">{{ __('drivers.rusacis_hero_help') }}</p>
                                        @endif
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @endunless
                @endforeach
            </tbody>
        </table>
        </div>
    </details>
@endforeach
<p class="ud-info">{{ __('drivers.game_rescue_unavailable') }}</p>
