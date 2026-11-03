@php($editingOld = old('_shop_dialog') === 'section')
@php($names = $editingOld ? (array) old('names', []) : $section->translations->pluck('name', 'locale')->all())
<dialog id="shop-section-dialog" class="shop-dialog" data-shop-dialog data-open-on-error="{{ $editingOld && $errors->any() ? '1' : '0' }}" aria-labelledby="shop-section-dialog-title">
    <form method="POST" action="{{ route('admin.module-pages.shop.sections.update', ['adminPath' => $adminPath, 'server' => $server->id, 'tab' => $section->id]) }}" data-shop-async class="shop-dialog-form">
        @csrf @method('PUT')
        <input type="hidden" name="_shop_dialog" value="section">
        <header class="shop-drawer-head">
            <h2 id="shop-section-dialog-title">{{ __('module-shop::messages.edit_section') }}</h2>
            <button type="button" class="shop-drawer-close" data-shop-close aria-label="{{ __('module-shop::messages.close_dialog') }}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </header>
        @if($editingOld && $errors->any())
            <div class="notice notice-error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <div class="shop-locale-editor" data-shop-locales>
            @if(count($languages) > 1)
                <div class="translation-tabs" role="tablist" aria-label="{{ __('module-shop::messages.languages') }}">
                    @foreach($languages as $code => $language)
                        <button type="button" role="tab" id="shop-section-tab-{{ $code }}" aria-controls="shop-section-panel-{{ $code }}" @class(['translation-tab', 'active' => $code === $defaultLocale]) data-shop-locale-tab="{{ $code }}" aria-selected="{{ $code === $defaultLocale ? 'true' : 'false' }}">
                            <span class="translation-tab-label">{{ $language['native_name'] ?? $code }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
            @foreach($languages as $code => $language)
                <div class="shop-locale-panel" role="tabpanel" id="shop-section-panel-{{ $code }}" aria-labelledby="shop-section-tab-{{ $code }}" data-shop-locale-panel="{{ $code }}" @if($code !== $defaultLocale) hidden @endif>
                    <x-admin.field :for="'shop-section-name-'.$code" :label="__('module-shop::messages.section_name')">
                        <input id="shop-section-name-{{ $code }}" name="names[{{ $code }}]" maxlength="120" value="{{ $names[$code] ?? '' }}" @if($code === $defaultLocale) required @else placeholder="{{ $names[$defaultLocale] ?? $section->nameFor($defaultLocale) }}" @endif>
                    </x-admin.field>
                </div>
            @endforeach
        </div>
        <x-admin.toggle id="shop-section-active" name="is_active" :label="__('module-shop::messages.section_visible')" :checked="(bool) ($editingOld ? old('is_active') : $section->is_active)" />
        <footer class="shop-drawer-foot">
            @if($section->products_count === 0)
                <button type="submit" form="shop-section-delete" class="button button-danger" data-shop-confirm="{{ __('module-shop::messages.delete_section_confirm', ['name' => $section->nameFor()]) }}">{{ __('module-shop::messages.delete') }}</button>
            @else
                <small class="shop-drawer-note">{{ __('module-shop::messages.section_has_products') }}</small>
            @endif
            <span class="shop-editor-toolbar-spacer"></span>
            <button type="button" class="button button-secondary" data-shop-close>{{ __('module-shop::messages.cancel') }}</button>
            <button type="submit" class="button button-primary">{{ __('module-shop::messages.save') }}</button>
        </footer>
    </form>
    @if($section->products_count === 0)
        <form id="shop-section-delete" method="POST" action="{{ route('admin.module-pages.shop.tabs.destroy', ['adminPath' => $adminPath, 'server' => $server->id, 'tab' => $section->id]) }}" data-shop-async hidden>
            @csrf @method('DELETE')
            <input type="hidden" name="confirm" value="1">
        </form>
    @endif
</dialog>
