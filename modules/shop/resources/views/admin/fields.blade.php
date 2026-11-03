@php($useOld = old('_form') === $prefix)
@php($values = $record)
@if($kind === 'categories')
    <x-admin.field :for="$prefix.'-tab'" :label="__('module-shop::messages.tab_label')">
        <select id="{{ $prefix }}-tab" name="tab_id" required @disabled($disabled)>
            @foreach($tabOptions as $option)
                <option value="{{ $option->id }}" @selected((int) ($useOld ? old('tab_id') : ($values?->tab_id ?? $option->id)) === $option->id)>{{ $option->nameFor() }}</option>
            @endforeach
        </select>
    </x-admin.field>
@endif
<x-admin.field :for="$prefix.'-ru'" :label="__('module-shop::messages.name_ru')">
    <input id="{{ $prefix }}-ru" name="name_ru" type="text" maxlength="120" required value="{{ $useOld ? old('name_ru') : ($values?->name_ru ?? '') }}" @disabled($disabled)>
</x-admin.field>
<x-admin.field :for="$prefix.'-en'" :label="__('module-shop::messages.name_en')">
    <input id="{{ $prefix }}-en" name="name_en" type="text" maxlength="120" required value="{{ $useOld ? old('name_en') : ($values?->name_en ?? '') }}" @disabled($disabled)>
</x-admin.field>
<x-admin.field :for="$prefix.'-order'" :label="__('module-shop::messages.sort_order')" :hint="__('module-shop::messages.sort_hint')">
    <input id="{{ $prefix }}-order" name="sort_order" type="number" min="0" max="10000" required value="{{ $useOld ? old('sort_order') : ($values?->sort_order ?? 0) }}" @disabled($disabled)>
</x-admin.field>
<x-admin.toggle :id="$prefix.'-active'" name="is_active" :label="__('module-shop::messages.visible_in_catalog')" :hint="__('module-shop::messages.active_hint')" :checked="(bool) ($useOld ? old('is_active') : ($values?->is_active ?? true))" :disabled="$disabled" />
<input type="hidden" name="_form" value="{{ $prefix }}" @disabled($disabled)>
