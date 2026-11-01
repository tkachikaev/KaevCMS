@php($key = $product?->id ?? 'new')
@php($dialogId = 'shop-product-'.$key)
@php($editingOld = old('_shop_dialog') === (string) $key)
@php($rows = $product === null ? [['item_id' => '', 'quantity' => 1]] : ($product->type === 'bundle' ? $product->bundleItems->map(fn ($item) => ['item_id' => $item->item_id, 'quantity' => $item->quantity])->all() : [['item_id' => $product->item_id, 'quantity' => $product->quantity]]))
@php($rows = $editingOld ? old('items', $rows) : $rows)
<dialog id="{{ $dialogId }}" class="social-provider-dialog" data-shop-dialog data-open-on-error="{{ $editingOld && $errors->any() ? '1' : '0' }}" aria-labelledby="{{ $dialogId }}-title">
    <header class="social-provider-dialog-heading">
        <h2 id="{{ $dialogId }}-title">{{ $product ? __('module-shop::messages.edit_product') : __('module-shop::messages.create_product') }}</h2>
        <button type="button" class="button button-secondary" data-shop-close aria-label="{{ __('module-shop::messages.close_dialog') }}">×</button>
    </header>
    <p class="form-help">{{ __('module-shop::messages.unified_product_hint') }}</p>
    @if($editingOld && $errors->any())
        <div class="notice notice-error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    <form method="POST" action="{{ $product ? route('admin.module-pages.shop.products.update', ['adminPath' => $adminPath, 'server' => $server->id, 'product' => $product->id]) : route('admin.module-pages.shop.products.store', ['adminPath' => $adminPath, 'server' => $server->id]) }}" data-shop-product-form data-max-items="100">
        @csrf
        @if($product) @method('PUT') @endif
        <input type="hidden" name="_shop_unified" value="1">
        <input type="hidden" name="_shop_dialog" value="{{ $key }}">
        <x-admin.field :for="$dialogId.'-section'" :label="__('module-shop::messages.section_label')">
            <select id="{{ $dialogId }}-section" name="section_id" required>
                @foreach($tabs as $choice)
                    <option value="{{ $choice->id }}" @selected((int) ($editingOld ? old('section_id') : ($product?->section_id ?? $selectedSection?->id)) === $choice->id)>{{ $choice->nameFor() }}</option>
                @endforeach
            </select>
        </x-admin.field>
        <div class="form-grid">
            <x-admin.field :for="$dialogId.'-name-ru'" :label="__('module-shop::messages.name_ru')">
                <input id="{{ $dialogId }}-name-ru" name="name_ru" required maxlength="120" value="{{ $editingOld ? old('name_ru') : ($product?->name_ru ?? '') }}">
            </x-admin.field>
            <x-admin.field :for="$dialogId.'-name-en'" :label="__('module-shop::messages.name_en')">
                <input id="{{ $dialogId }}-name-en" name="name_en" required maxlength="120" value="{{ $editingOld ? old('name_en') : ($product?->name_en ?? '') }}">
            </x-admin.field>
            <x-admin.field :for="$dialogId.'-description-ru'" :label="__('module-shop::messages.description_ru')">
                <textarea id="{{ $dialogId }}-description-ru" name="description_ru" rows="2" maxlength="2000">{{ $editingOld ? old('description_ru') : ($product?->description_ru ?? '') }}</textarea>
            </x-admin.field>
            <x-admin.field :for="$dialogId.'-description-en'" :label="__('module-shop::messages.description_en')">
                <textarea id="{{ $dialogId }}-description-en" name="description_en" rows="2" maxlength="2000">{{ $editingOld ? old('description_en') : ($product?->description_en ?? '') }}</textarea>
            </x-admin.field>
            <x-admin.field :for="$dialogId.'-price'" :label="__('module-shop::messages.price_coins')">
                <input id="{{ $dialogId }}-price" name="price_coins" type="number" required min="1" max="1000000" value="{{ $editingOld ? old('price_coins') : ($product?->price_coins ?? 1) }}">
            </x-admin.field>
            <x-admin.field :for="$dialogId.'-order'" :label="__('module-shop::messages.sort_order')">
                <input id="{{ $dialogId }}-order" name="sort_order" type="number" required min="0" max="10000" value="{{ $editingOld ? old('sort_order') : ($product?->sort_order ?? 0) }}">
            </x-admin.field>
        </div>
        <x-admin.toggle :id="$dialogId.'-active'" name="is_active" :label="__('module-shop::messages.visible_in_catalog')" :checked="(bool) ($editingOld ? old('is_active') : (($product?->is_active ?? false) && ($product?->category_id === null || ($product?->category?->is_active ?? false))))" />
        <h3>{{ __('module-shop::messages.unified_items_title') }}</h3>
        <p class="form-help">{{ __('module-shop::messages.unified_items_help') }}</p>
        <div data-shop-rows>
            @foreach($rows as $index => $row)
                <div class="form-grid" data-shop-item-row>
                    <x-admin.field :for="$dialogId.'-item-'.$index" :label="__('module-shop::messages.item_id')">
                        <input id="{{ $dialogId }}-item-{{ $index }}" name="items[{{ $index }}][item_id]" type="number" min="1" max="2147483647" required value="{{ $row['item_id'] ?? '' }}">
                    </x-admin.field>
                    <x-admin.field :for="$dialogId.'-qty-'.$index" :label="__('module-shop::messages.quantity')">
                        <input id="{{ $dialogId }}-qty-{{ $index }}" name="items[{{ $index }}][quantity]" type="number" min="1" max="2000000000" required value="{{ $row['quantity'] ?? 1 }}">
                    </x-admin.field>
                    <button type="button" class="button button-secondary" data-shop-remove-item>{{ __('module-shop::messages.remove_item') }}</button>
                </div>
            @endforeach
        </div>
        <template data-shop-row-template>
            <div class="form-grid" data-shop-item-row>
                <x-admin.field :for="$dialogId.'-item-new'" :label="__('module-shop::messages.item_id')">
                    <input name="items[__INDEX__][item_id]" type="number" min="1" max="2147483647" required>
                </x-admin.field>
                <x-admin.field :for="$dialogId.'-qty-new'" :label="__('module-shop::messages.quantity')">
                    <input name="items[__INDEX__][quantity]" type="number" min="1" max="2000000000" required value="1">
                </x-admin.field>
                <button type="button" class="button button-secondary" data-shop-remove-item>{{ __('module-shop::messages.remove_item') }}</button>
            </div>
        </template>
        <p><button type="button" class="button button-secondary" data-shop-add-item>{{ __('module-shop::messages.add_item_row') }}</button></p>
        <div class="admin-row-actions">
            <button type="button" class="button button-secondary" data-shop-close>{{ __('module-shop::messages.cancel') }}</button>
            <button type="submit" class="button button-primary">{{ __('module-shop::messages.save') }}</button>
        </div>
    </form>
    @if($product)
        <form method="POST" action="{{ route('admin.module-pages.shop.products.destroy', ['adminPath' => $adminPath, 'server' => $server->id, 'product' => $product->id]) }}">
            @csrf @method('DELETE')
            <label><input type="checkbox" name="confirm" value="1" required> {{ __('module-shop::messages.delete_confirm') }}</label>
            <button type="submit" class="button button-secondary">{{ __('module-shop::messages.delete') }}</button>
        </form>
    @endif
</dialog>
