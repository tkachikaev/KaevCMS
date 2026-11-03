@php($key = $product?->id ?? 'new')
@php($dialogId = 'shop-product-'.$key)
@php($editingOld = old('_shop_dialog') === (string) $key)
@php($visual = $product ? ($productVisuals[$product->id] ?? []) : [])
@php($rows = $product ? ($visual['contents'] ?? []) : [['item_id' => '', 'quantity' => 1, 'name' => null, 'icon' => null]])
@php($rows = $editingOld ? array_values(array_map(fn ($row) => ['item_id' => $row['item_id'] ?? '', 'quantity' => $row['quantity'] ?? 1, 'name' => null, 'icon' => null], (array) old('items', []))) : $rows)
@php($rows = $rows === [] ? [['item_id' => '', 'quantity' => 1, 'name' => null, 'icon' => null]] : $rows)
@php($texts = $editingOld ? (array) old('translations', []) : ($product?->translationMap() ?? []))
@php($active = (bool) ($editingOld ? old('is_active') : ($product === null ? true : ($product->is_active && ($product->category_id === null || ($product->category?->is_active ?? false))))))
<dialog id="{{ $dialogId }}" class="shop-drawer" data-shop-dialog data-open-on-error="{{ $editingOld && $errors->any() ? '1' : '0' }}" aria-labelledby="{{ $dialogId }}-title">
    <form method="POST" class="shop-drawer-form" data-shop-async data-shop-product-form data-max-items="100"
        action="{{ $product ? route('admin.module-pages.shop.products.update', ['adminPath' => $adminPath, 'server' => $server->id, 'product' => $product->id]) : route('admin.module-pages.shop.products.store', ['adminPath' => $adminPath, 'server' => $server->id]) }}">
        @csrf
        @if($product) @method('PUT') @endif
        <input type="hidden" name="_shop_unified" value="1">
        <input type="hidden" name="_shop_dialog" value="{{ $key }}">

        <header class="shop-drawer-head">
            <div>
                <h2 id="{{ $dialogId }}-title">{{ $product ? __('module-shop::messages.edit_product') : __('module-shop::messages.create_product') }}</h2>
                <p>{{ __('module-shop::messages.drawer_context', ['section' => $selectedSection->nameFor(), 'server' => $server->nameFor()]) }}</p>
            </div>
            <button type="button" class="shop-drawer-close" data-shop-close aria-label="{{ __('module-shop::messages.close_dialog') }}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </header>

        <div class="shop-drawer-body">
            @if($editingOld && $errors->any())
                <div class="notice notice-error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif

            <section class="shop-drawer-block" aria-labelledby="{{ $dialogId }}-items-title">
                <div class="shop-drawer-block-head">
                    <h3 id="{{ $dialogId }}-items-title">{{ __('module-shop::messages.items_title') }}</h3>
                    <small data-shop-items-summary>{{ __('module-shop::messages.items_hint') }}</small>
                </div>
                <div class="promo-reward-list shop-item-rows" data-shop-rows>
                    @foreach($rows as $index => $row)
                        @include('module-shop::admin._item-row', ['index' => $index, 'row' => $row])
                    @endforeach
                </div>
                <template data-shop-row-template>
                    @include('module-shop::admin._item-row', ['index' => '__INDEX__', 'row' => ['item_id' => '', 'quantity' => 1, 'name' => null, 'icon' => null]])
                </template>
                <div class="shop-item-search" data-shop-item-search>
                    <label for="{{ $dialogId }}-search">{{ __('module-shop::messages.item_search_label') }}</label>
                    <div class="shop-item-search-field">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                        <input id="{{ $dialogId }}-search" type="search" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="{{ $dialogId }}-results" placeholder="{{ __('module-shop::messages.item_search_placeholder') }}" data-shop-item-search-input>
                    </div>
                    <ul id="{{ $dialogId }}-results" class="shop-item-search-results" role="listbox" hidden data-shop-item-search-results></ul>
                </div>
                <button type="button" class="button button-secondary shop-add-row" data-shop-add-item>+ {{ __('module-shop::messages.add_item_by_id') }}</button>
            </section>

            <section class="shop-drawer-block shop-locale-editor" data-shop-locales aria-label="{{ __('module-shop::messages.product_texts') }}">
                @if(count($languages) > 1)
                    <div class="translation-tabs" role="tablist" aria-label="{{ __('module-shop::messages.languages') }}">
                        @foreach($languages as $code => $language)
                            <button type="button" role="tab" id="{{ $dialogId }}-tab-{{ $code }}" aria-controls="{{ $dialogId }}-panel-{{ $code }}" @class(['translation-tab', 'active' => $code === $defaultLocale]) data-shop-locale-tab="{{ $code }}" aria-selected="{{ $code === $defaultLocale ? 'true' : 'false' }}">
                                <span class="translation-tab-label">{{ $language['native_name'] ?? $code }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
                @foreach($languages as $code => $language)
                    @php($manual = (bool) ($texts[$code]['name_manual'] ?? ($product !== null)))
                    <div class="shop-locale-panel" role="tabpanel" id="{{ $dialogId }}-panel-{{ $code }}" aria-labelledby="{{ $dialogId }}-tab-{{ $code }}" data-shop-locale-panel="{{ $code }}" @if($code !== $defaultLocale) hidden @endif>
                        <x-admin.field :for="$dialogId.'-name-'.$code" :label="__('module-shop::messages.product_name')">
                            <input id="{{ $dialogId }}-name-{{ $code }}" name="translations[{{ $code }}][name]" maxlength="120" value="{{ $texts[$code]['name'] ?? '' }}" data-shop-name="{{ $code }}" @if($code === $defaultLocale) data-shop-name-default @if(count($rows) > 1) required @endif @endif autocomplete="off">
                            <input type="hidden" name="translations[{{ $code }}][name_manual]" value="{{ $manual ? '1' : '0' }}" data-shop-name-manual="{{ $code }}">
                            <small class="shop-field-hint">{{ __('module-shop::messages.product_name_hint') }}</small>
                        </x-admin.field>
                        <x-admin.field :for="$dialogId.'-description-'.$code" :label="__('module-shop::messages.product_description')">
                            <textarea id="{{ $dialogId }}-description-{{ $code }}" name="translations[{{ $code }}][description]" rows="3" maxlength="2000">{{ $texts[$code]['description'] ?? '' }}</textarea>
                        </x-admin.field>
                    </div>
                @endforeach
            </section>

            <section class="shop-drawer-block shop-drawer-grid">
                <x-admin.field :for="$dialogId.'-price'" :label="__('module-shop::messages.price')">
                    <span class="shop-price-field">
                        <img src="{{ $coinIcon }}" alt="" width="20" height="20">
                        <input id="{{ $dialogId }}-price" name="price_coins" type="number" inputmode="numeric" required min="1" max="1000000" value="{{ $editingOld ? old('price_coins') : ($product?->price_coins ?? '') }}">
                    </span>
                </x-admin.field>
                <x-admin.field :for="$dialogId.'-section'" :label="__('module-shop::messages.section_label')">
                    <select id="{{ $dialogId }}-section" name="section_id" required>
                        @foreach($tabs as $choice)
                            <option value="{{ $choice->id }}" @selected((int) ($editingOld ? old('section_id') : ($product?->section_id ?? $selectedSection->id)) === $choice->id)>{{ $choice->nameFor() }}</option>
                        @endforeach
                    </select>
                </x-admin.field>
                <x-admin.toggle :id="$dialogId.'-active'" name="is_active" :label="__('module-shop::messages.product_visible')" :checked="$active" class="shop-drawer-toggle" />
            </section>
        </div>

        <footer class="shop-drawer-foot">
            <span class="shop-drawer-preview" aria-hidden="true" data-shop-preview>
                @foreach(array_slice($rows, 0, 3) as $row)
                    @if(! empty($row['icon']))<img src="{{ $row['icon'] }}" alt="" width="32" height="32">@endif
                @endforeach
            </span>
            @if($product)
                <button type="submit" form="{{ $dialogId }}-delete" class="button button-danger" data-shop-confirm="{{ __('module-shop::messages.delete_product_confirm', ['name' => $product->nameFor()]) }}">{{ __('module-shop::messages.delete') }}</button>
            @endif
            <span class="shop-editor-toolbar-spacer"></span>
            <button type="button" class="button button-secondary" data-shop-close>{{ __('module-shop::messages.cancel') }}</button>
            <button type="submit" class="button button-primary">{{ __('module-shop::messages.save_product') }}</button>
        </footer>
    </form>
    @if($product)
        <form id="{{ $dialogId }}-delete" method="POST" action="{{ route('admin.module-pages.shop.products.destroy', ['adminPath' => $adminPath, 'server' => $server->id, 'product' => $product->id]) }}" data-shop-async hidden>
            @csrf @method('DELETE')
            <input type="hidden" name="confirm" value="1">
        </form>
    @endif
</dialog>
