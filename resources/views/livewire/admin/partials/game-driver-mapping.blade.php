<p class="ud-info">{{ __('drivers.game_mapping_help') }}</p>
<div class="ud-main-table">
    <label for="ud-game-main-table">{{ __('drivers.main_table') }}
        <input id="ud-game-main-table" type="text" wire:model.live.blur="draft.config.characters.table" maxlength="64">
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
                    @php($kind = $def['kind'])
                    <tr @class(['ud-map-row', 'ud-map-required' => $required]) wire:key="game-field-{{ $field }}">
                        <th scope="row"><strong>{{ $label }}</strong><small>{{ $required ? __('drivers.required_field') : __('drivers.empty_if_missing') }}</small></th>
                        <td>
                            @if($required || in_array($kind, ['choice','operator'], true))
                                <code class="ud-main-table-name">{{ $mainTable }}</code>
                            @else
                                <input type="text" aria-label="{{ $label }}: {{ __('drivers.table') }}" wire:model.live.blur="fieldTables.{{ $field }}" maxlength="64" placeholder="{{ $mainTable }}">
                            @endif
                        </td>
                        <td>
                            @if(in_array($kind, ['column','related'], true))
                                <input type="text" aria-label="{{ $label }}: {{ __('drivers.column') }}" wire:model="draft.config.characters.fields.{{ $field }}.column" maxlength="64" placeholder="{{ __('drivers.optional') }}">
                            @elseif($kind === 'eav')
                                <input type="text" aria-label="{{ $label }}: {{ __('drivers.value_column') }}" wire:model="draft.config.characters.fields.{{ $field }}.value_column" maxlength="64" placeholder="{{ __('drivers.value_column') }}">
                            @elseif($kind === 'choice')
                                <span class="ud-info">{{ __('drivers.kind_choice') }}</span>
                            @else
                                <span class="ud-info">{{ __('drivers.kind_operator') }}</span>
                            @endif
                            @error('draft.config.characters.fields.'.$field.'.column')<p class="ud-field-error" role="alert">{{ $message }}</p>@enderror
                        </td>
                        <td class="ud-map-end">
                            @unless($required)
                                <div class="ud-row-tools">
                                    <details class="ud-menu" data-ud-menu>
                                        <summary aria-label="{{ __('drivers.row_options') }}" aria-haspopup="menu" title="{{ __('drivers.row_options') }}">⋯</summary>
                                        <div class="ud-menu-items" role="menu">
                                            @foreach(['column','choice','eav','operator'] as $option)
                                                @continue($option === 'operator' && $field !== 'hero' && $kind !== 'operator')
                                                <button type="button" role="menuitem" wire:click="changeFieldKind('{{ $field }}', '{{ $option }}')">{{ __('drivers.store_'.$option) }}@if(($option === 'column' && in_array($kind, ['column','related'], true)) || $option === $kind) ✓@endif</button>
                                            @endforeach
                                        </div>
                                    </details>
                                    <button type="button" class="ud-remove" wire:click="removeField('{{ $field }}')" aria-label="{{ __('drivers.clear_mapping') }}" title="{{ __('drivers.clear_mapping') }}">✕</button>
                                </div>
                            @endunless
                        </td>
                    </tr>
                    @if(in_array($kind, ['related','eav'], true) || $kind === 'choice' || $kind === 'operator')
                        <tr class="ud-map-extra" wire:key="game-field-source-{{ $field }}">
                            <td colspan="4">
                                @if(in_array($kind, ['related','eav'], true) && $relation)
                                    <div class="ud-link-row">
                                        <span class="ud-link-title">{{ __('drivers.link_heading') }}</span>
                                        <label>{{ __('drivers.link_from', ['table' => $mainTable]) }}<input type="text" wire:model="draft.config.relations.{{ $def['relation'] }}.local_column" maxlength="64"></label>
                                        <span aria-hidden="true">=</span>
                                        <label>{{ __('drivers.link_to_table', ['table' => $relation['table'] ?: __('drivers.relation_unset')]) }}<input type="text" wire:model="draft.config.relations.{{ $def['relation'] }}.foreign_column" maxlength="64"></label>
                                    </div>
                                @endif
                                @if($kind === 'eav')
                                    <div class="ud-link-row">
                                        <span class="ud-link-title">{{ __('drivers.eav_heading') }}</span>
                                        <label>{{ __('drivers.key_column') }}<input type="text" wire:model="draft.config.characters.fields.{{ $field }}.key_column"></label>
                                        <label>{{ __('drivers.key_value') }}<input type="text" wire:model="draft.config.characters.fields.{{ $field }}.key_value"></label>
                                    </div>
                                    <p class="ud-info">{{ __('drivers.game_eav_world_limit') }}</p>
                                @elseif($kind === 'choice')
                                    <div class="ud-link-row">
                                        <span class="ud-link-title">{{ __('drivers.choice_heading') }}</span>
                                        @foreach($def['columns'] as $i => $column)
                                            <label>{{ __('drivers.column') }} {{ $i + 1 }}<input type="text" wire:model="draft.config.characters.fields.{{ $field }}.columns.{{ $i }}"></label>
                                        @endforeach
                                        <button type="button" class="button button-secondary" wire:click="addChoiceColumn('{{ $field }}')" @disabled(count($def['columns']) >= 4)>+ {{ __('drivers.column') }}</button>
                                        <label>{{ __('drivers.preference') }}<select wire:model="draft.config.characters.fields.{{ $field }}.preference"><option value="first_present">{{ __('drivers.first_present') }}</option><option value="mobius_chronicle">{{ __('drivers.mobius_chronicle') }}</option></select></label>
                                    </div>
                                @elseif($kind === 'operator')
                                    <p class="ud-info">{{ __('drivers.rusacis_hero_help') }}</p>
                                @endif
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
        </div>
    </details>
@endforeach
<p class="ud-info">{{ __('drivers.game_rescue_unavailable') }}</p>
