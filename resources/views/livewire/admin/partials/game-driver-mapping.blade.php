<p class="ud-info">{{ __('drivers.game_mapping_help') }}</p>
<div class="ud-mapping-row">
    <div class="ud-mapping-purpose"><strong>{{ __('drivers.character_owner') }}</strong><small>{{ __('drivers.required_field') }}</small></div>
    <span class="ud-arrow" aria-hidden="true">→</span>
    <label>{{ __('drivers.table') }}<input type="text" wire:model.blur="draft.config.characters.table" maxlength="64"></label>
    <span class="ud-arrow" aria-hidden="true">→</span>
    <label>{{ __('drivers.column') }}<input type="text" wire:model="draft.config.characters.owner.column" maxlength="64"></label>
</div>
@foreach(['characters.table', 'characters.owner.column'] as $path)
    @error('draft.config.'.$path)<p class="ud-field-error" role="alert">{{ $message }}</p>@enderror
@endforeach
@php
    $groups = [
        'game_basic' => ['id','name','level','class_id','race','gender','online'],
        'game_statistics' => ['experience','pvp_kills','pk_kills','play_time_seconds','last_seen_at_ms','reputation'],
        'game_additional' => ['title','noble','hero','clan_id','clan_name','created_at','x','y','z'],
    ];
@endphp
@foreach($groups as $group => $fields)
    <details class="ud-game-group" data-game-group="{{ $group }}" wire:ignore.self @if($group === 'game_basic') open @endif>
        <summary><h3>{{ __('drivers.'.$group) }}</h3></summary>
        @foreach($fields as $field)
            @php($def = $config['characters']['fields'][$field] ?? ['kind' => 'column', 'column' => ''])
            @php($relation = $config['relations'][$def['relation'] ?? ''] ?? null)
            <div class="ud-game-field" wire:key="game-field-{{ $field }}">
                <div class="ud-mapping-row">
                    <div class="ud-mapping-purpose"><strong>{{ $field === 'id' ? __('drivers.field_id_game') : __('drivers.field_'.$field) }}</strong><small>{{ in_array($field,['id','name'],true) ? __('drivers.required_field') : __('drivers.empty_if_missing') }}</small></div>
                    <span class="ud-arrow" aria-hidden="true">→</span>
                    <label>{{ __('drivers.table') }}
                        @if(in_array($def['kind'], ['related','eav'], true))
                            @if($relation)
                                <input type="text" wire:model.blur="draft.config.relations.{{ $def['relation'] }}.table" maxlength="64">
                            @else
                                <span class="ud-info">{{ __('drivers.game_relation_missing') }}</span>
                            @endif
                        @else
                            <input type="text" wire:model.blur="draft.config.characters.table" maxlength="64">
                        @endif
                    </label>
                    <span class="ud-arrow" aria-hidden="true">→</span>
                    @if(in_array($def['kind'], ['column','related'], true))
                        <label>{{ __('drivers.column') }}<input type="text" wire:model="draft.config.characters.fields.{{ $field }}.column" maxlength="64" placeholder="{{ __('drivers.optional') }}"></label>
                    @elseif($def['kind'] === 'eav')
                        <label>{{ __('drivers.value_column') }}<input type="text" wire:model="draft.config.characters.fields.{{ $field }}.value_column" maxlength="64"></label>
                    @else
                        <span class="ud-info">{{ __('drivers.kind_'.$def['kind']) }}</span>
                    @endif
                </div>
                @error('draft.config.characters.fields.'.$field.'.column')<p class="ud-field-error" role="alert">{{ $message }}</p>@enderror
                <details class="ud-field-source" wire:ignore.self @if($def['kind'] !== 'column') open @endif>
                    <summary>{{ __('drivers.field_source_settings') }}</summary>
                    <div class="ud-grid">
                        <label>{{ __('drivers.source') }}
                            <select wire:change="changeFieldKind('{{ $field }}', $event.target.value)">
                                @foreach(['column','related','eav','choice','operator'] as $kind)
                                    <option value="{{ $kind }}" @selected($def['kind'] === $kind) @disabled(in_array($field,['id','name'],true) && $kind !== 'column')>{{ __('drivers.kind_'.$kind) }}</option>
                                @endforeach
                            </select>
                        </label>
                        @if(in_array($def['kind'], ['related','eav'], true))
                            <label>{{ __('drivers.relation') }}<select wire:model.live="draft.config.characters.fields.{{ $field }}.relation">
                                <option value="">{{ __('drivers.choose_relation') }}</option>
                                @foreach($config['relations'] as $name => $r)<option value="{{ $name }}">{{ $r['table'] ?: $name }}</option>@endforeach
                            </select></label>
                            <p class="ud-info">{{ __('drivers.relations_help') }}</p>
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
                        @unless(in_array($field,['id','name'],true))<button type="button" class="button button-secondary" wire:click="removeField('{{ $field }}')">{{ __('drivers.clear_mapping') }}</button>@endunless
                    </div>
                </details>
            </div>
        @endforeach
    </details>
@endforeach
<p class="ud-info">{{ __('drivers.game_rescue_unavailable') }}</p>
