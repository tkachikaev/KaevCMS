@php($useOld = old('_form') === $prefix)
<x-admin.field :for="$prefix.'-category'" :label="__('module-shop::messages.category_label')">
    <select id="{{ $prefix }}-category" name="category_id" required @disabled($disabled)>
        @foreach($categories as $category)
            <option value="{{ $category['id'] }}" @selected((int) ($useOld ? old('category_id') : ($product?->category_id ?? $category['id'])) === $category['id'])>{{ $category['name'] }}</option>
        @endforeach
    </select>
</x-admin.field>
<x-admin.field :for="$prefix.'-ru'" :label="__('module-shop::messages.name_ru')">
    <input id="{{ $prefix }}-ru" name="name_ru" type="text" maxlength="120" required value="{{ $useOld ? old('name_ru') : ($product?->name_ru ?? '') }}" @disabled($disabled)>
</x-admin.field>
<x-admin.field :for="$prefix.'-en'" :label="__('module-shop::messages.name_en')">
    <input id="{{ $prefix }}-en" name="name_en" type="text" maxlength="120" required value="{{ $useOld ? old('name_en') : ($product?->name_en ?? '') }}" @disabled($disabled)>
</x-admin.field>
<x-admin.field :for="$prefix.'-price'" :label="__('module-shop::messages.price_coins')" :hint="__('module-shop::messages.price_hint')">
    <input id="{{ $prefix }}-price" name="price_coins" type="number" min="1" max="1000000" required value="{{ $useOld ? old('price_coins') : ($product?->price_coins ?? 1) }}" @disabled($disabled)>
</x-admin.field>
@if($type === 'item')
    <x-admin.field :for="$prefix.'-item'" :label="__('module-shop::messages.item_id')">
        <input id="{{ $prefix }}-item" name="item_id" type="number" min="1" max="2147483647" required value="{{ $useOld ? old('item_id') : ($product?->item_id ?? '') }}" @disabled($disabled)>
    </x-admin.field>
    <x-admin.field :for="$prefix.'-quantity'" :label="__('module-shop::messages.quantity')">
        <input id="{{ $prefix }}-quantity" name="quantity" type="number" min="1" max="2000000000" required value="{{ $useOld ? old('quantity') : ($product?->quantity ?? 1) }}" @disabled($disabled)>
    </x-admin.field>
@else
    <div class="notice notice-info" role="note">{{ __('module-shop::messages.bundle_components_hint') }}</div>
@endif
<x-admin.field :for="$prefix.'-order'" :label="__('module-shop::messages.sort_order')" :hint="__('module-shop::messages.sort_hint')">
    <input id="{{ $prefix }}-order" name="sort_order" type="number" min="0" max="10000" required value="{{ $useOld ? old('sort_order') : ($product?->sort_order ?? 0) }}" @disabled($disabled)>
</x-admin.field>
<x-admin.toggle :id="$prefix.'-active'" name="is_active" :label="__('module-shop::messages.visible_in_catalog')" :hint="__('module-shop::messages.sales_disabled_notice')" :checked="(bool) ($useOld ? old('is_active') : ($product?->is_active ?? false))" :disabled="$disabled" />
<input type="hidden" name="_form" value="{{ $prefix }}" @disabled($disabled)>
