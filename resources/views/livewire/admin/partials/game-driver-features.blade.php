<section class="ud-game-group">
    <div class="ud-section-head"><div><h3>{{ __('drivers.relations') }}</h3><p>{{ __('drivers.game_relations_help') }}</p></div><button type="button" class="button button-secondary" wire:click="addGameRelation">+ {{ __('drivers.add_row') }}</button></div>
    @forelse($config['relations'] as $name => $relation)
        @php
            $castle = $config['features']['castles'] ?? [];
            $sourceTable = in_array($name, [$castle['relation'] ?? '', $castle['leader_relation'] ?? ''], true)
                ? ($config['relations'][$castle['clan_relation'] ?? 'clan']['table'] ?? '') : $config['characters']['table'];
        @endphp
        <div class="ud-group" wire:key="game-relation-{{ $name }}">
            <div class="ud-section-head"><strong>{{ in_array($name,['clan','heroes','castle','leader'],true) ? __('drivers.relation_'.$name) : $name }}</strong><button type="button" class="button button-secondary" wire:click="removeRelation('{{ $name }}')">{{ __('drivers.remove') }}</button></div>
            <div class="ud-registration-row">
                <label>{{ __('drivers.table') }}<input type="text" wire:model.blur="draft.config.relations.{{ $name }}.table" maxlength="64"></label>
                <label>{{ __('drivers.link_from', ['table' => $sourceTable]) }}<input type="text" wire:model="draft.config.relations.{{ $name }}.local_column" maxlength="64"></label>
                <label>{{ __('drivers.link_to') }}<input type="text" wire:model="draft.config.relations.{{ $name }}.foreign_column" maxlength="64"></label>
            </div>
            <details class="ud-field-source" wire:ignore.self><summary>{{ __('drivers.relation_options') }}</summary>
                <div class="ud-grid">
                    <label>{{ __('drivers.cardinality') }}<select wire:model="draft.config.relations.{{ $name }}.cardinality"><option value="one">{{ __('drivers.one_row') }}</option><option value="many">{{ __('drivers.many_rows') }}</option></select></label>
                    <label>{{ __('drivers.missing_relation') }}<select wire:model="draft.config.relations.{{ $name }}.join"><option value="left">{{ __('drivers.keep_character') }}</option><option value="inner">{{ __('drivers.skip_character') }}</option></select></label>
                </div>
            </details>
        </div>
    @empty
        <p class="ud-info">{{ __('drivers.no_relations') }}</p>
    @endforelse
</section>

<details class="ud-game-group" wire:ignore.self>
    <summary><h3>{{ __('drivers.cap_heroes') }}</h3></summary>
    @if(isset($config['features']['heroes']))
        @php($hero = $config['features']['heroes'])
        <div class="ud-grid">
            <label>{{ __('drivers.relation') }}<select wire:model.live="draft.config.features.heroes.relation">@foreach($config['relations'] as $name => $r)<option value="{{ $name }}">{{ $r['table'] ?: $name }}</option>@endforeach</select></label>
        </div>
        @if(isset($config['relations'][$hero['relation']]))
            @include('livewire.admin.partials.game-data-row', ['purpose' => __('drivers.field_id_game'), 'tablePath' => 'draft.config.relations.'.$hero['relation'].'.table', 'columnPath' => 'draft.config.relations.'.$hero['relation'].'.foreign_column'])
            @foreach(['count_column' => 'hero_count_label', 'played_column' => 'hero_played_label', 'claimed_column' => 'hero_claimed_label'] as $key => $label)
                @include('livewire.admin.partials.game-data-row', ['purpose' => __('drivers.'.$label), 'tablePath' => 'draft.config.relations.'.$hero['relation'].'.table', 'columnPath' => 'draft.config.features.heroes.'.$key])
            @endforeach
            <details class="ud-field-source" wire:ignore.self><summary>{{ __('drivers.hero_filters') }}</summary><p class="ud-info">{{ __('drivers.hero_filters_help') }}</p><div class="ud-grid">
                @foreach(['played_column','played_value','active_column','active_value'] as $key)<label>{{ __('drivers.'.$key) }}<input type="text" wire:model="draft.config.features.heroes.{{ $key }}"></label>@endforeach
            </div></details>
        @else
            <p class="ud-field-error">{{ __('drivers.game_relation_missing') }}</p>
        @endif
        <div class="ud-actions"><button type="button" class="button button-secondary" wire:click="removeFeature('heroes')">{{ __('drivers.do_not_use') }}</button></div>
    @else
        <p class="ud-info">{{ __('drivers.heroes_setup_help') }}</p><div class="ud-actions"><button type="button" class="button button-secondary" wire:click="configureGameFeature('heroes')">{{ __('drivers.configure') }}</button></div>
    @endif
</details>

<details class="ud-game-group" wire:ignore.self>
    <summary><h3>{{ __('drivers.cap_castles') }}</h3></summary>
    @if(isset($config['features']['castles']))
        @php($castle = $config['features']['castles'])
        <div class="ud-grid">
            @foreach(['relation' => 'castle_table_link', 'clan_relation' => 'clan_relation', 'leader_relation' => 'leader_relation'] as $key => $label)
                <label>{{ __('drivers.'.$label) }}<select wire:model.live="draft.config.features.castles.{{ $key }}">@foreach($config['relations'] as $name => $r)<option value="{{ $name }}">{{ $r['table'] ?: $name }}</option>@endforeach</select></label>
            @endforeach
            <label>{{ __('drivers.name_source') }}<select wire:model.live="draft.config.features.castles.name_source"><option value="catalog">{{ __('drivers.name_catalog') }}</option><option value="column">{{ __('drivers.name_column') }}</option></select></label>
        </div>
        @if(isset($config['relations'][$castle['relation']], $config['relations'][$castle['clan_relation']], $config['relations'][$castle['leader_relation']]))
            @include('livewire.admin.partials.game-data-row', ['purpose' => __('drivers.castle_id'), 'tablePath' => 'draft.config.relations.'.$castle['relation'].'.table', 'columnPath' => 'draft.config.relations.'.$castle['relation'].'.foreign_column'])
            @if($castle['name_source'] === 'column')
                @include('livewire.admin.partials.game-data-row', ['purpose' => __('drivers.name_column'), 'tablePath' => 'draft.config.relations.'.$castle['relation'].'.table', 'columnPath' => 'draft.config.features.castles.name_column'])
            @endif
            @foreach(['id','name','level','reputation'] as $field)
                @include('livewire.admin.partials.game-data-row', ['purpose' => __('drivers.clan_'.$field), 'tablePath' => 'draft.config.relations.'.$castle['clan_relation'].'.table', 'columnPath' => 'draft.config.features.castles.clan_columns.'.$field])
            @endforeach
            @include('livewire.admin.partials.game-data-row', ['purpose' => __('drivers.leader_name_column'), 'tablePath' => 'draft.config.relations.'.$castle['leader_relation'].'.table', 'columnPath' => 'draft.config.features.castles.leader_name_column'])
        @else
            <p class="ud-field-error">{{ __('drivers.game_relation_missing') }}</p>
        @endif
        <div class="ud-actions"><button type="button" class="button button-secondary" wire:click="removeFeature('castles')">{{ __('drivers.do_not_use') }}</button></div>
    @else
        <p class="ud-info">{{ __('drivers.castles_setup_help') }}</p><div class="ud-actions"><button type="button" class="button button-secondary" wire:click="configureGameFeature('castles')">{{ __('drivers.configure') }}</button></div>
    @endif
</details>

<section class="ud-game-group">
    <h3>{{ __('drivers.rankings') }}</h3><p class="ud-info">{{ __('drivers.rankings_auto_help') }}</p>
    @if(in_array('eav', array_column($config['characters']['fields'], 'kind'), true))
        <p class="notice notice-warning">{{ __('drivers.game_eav_world_limit') }}</p>
    @else
    @foreach(['level' => 'level', 'pvp' => 'pvp_kills', 'pk' => 'pk_kills', 'play_time' => 'play_time_seconds'] as $name => $field)
        @if(($config['characters']['fields'][$field]['kind'] ?? '') === 'column' && !empty($config['characters']['fields'][$field]['column']))
            <div class="ud-grid ud-ranking" wire:key="game-ranking-{{ $name }}">
                <label>{{ __('drivers.cap_'.$name) }}<code>{{ $config['characters']['table'] }} → {{ $config['characters']['fields'][$field]['column'] }}</code></label>
                <label>{{ __('drivers.secondary') }}<select wire:model="draft.config.rankings.{{ $name }}.secondary"><option value="">{{ __('drivers.optional') }}</option>@foreach($config['characters']['fields'] as $key => $def)@if(($def['kind'] ?? 'column') === 'column' && !empty($def['column']))<option value="{{ $def['column'] }}">{{ __('drivers.field_'.$key) }} ({{ $def['column'] }})</option>@endif @endforeach</select></label>
                <label class="ud-check"><input type="checkbox" wire:model="draft.config.rankings.{{ $name }}.positive_only">{{ __('drivers.positive_only') }}</label>
            </div>
        @endif
    @endforeach
    @endif
</section>

<section class="ud-game-group">
    <div class="ud-section-head"><div><h3>{{ __('drivers.filters') }}</h3><p>{{ __('drivers.filters_help') }}</p></div><button type="button" class="button button-secondary" wire:click="addFilter">+ {{ __('drivers.add_row') }}</button></div>
    @foreach($config['filters'] as $index => $filter)
        <div class="ud-registration-row" wire:key="game-filter-{{ $index }}">
            <label>{{ __('drivers.column') }}<input type="text" wire:model="draft.config.filters.{{ $index }}.column"></label>
            <label>{{ __('drivers.condition') }}<select wire:model.live="draft.config.filters.{{ $index }}.op"><option value="eq">{{ __('drivers.equals') }}</option><option value="gt">{{ __('drivers.greater_than') }}</option><option value="zero_or_null">{{ __('drivers.zero_or_empty') }}</option></select></label>
            @if($filter['op'] !== 'zero_or_null')<label>{{ __('drivers.value') }}<input type="text" wire:model="draft.config.filters.{{ $index }}.value"></label>@else<span></span>@endif
            <button type="button" class="button button-secondary" wire:click="removeFilter({{ $index }})">{{ __('drivers.remove') }}</button>
        </div>
    @endforeach
</section>

@include('livewire.admin.partials.driver-extensions')
