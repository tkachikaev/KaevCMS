@extends('admin.layouts.panel')
@section('title', __('module-shop::messages.server_title', ['server' => $server->nameFor()]))
@section('description', __('module-shop::messages.server_description'))
@section('content')
@php($adminPath = request()->route('adminPath'))
<div class="admin-section-stack" data-shop-server-page>
    <p><x-admin.button wire:navigate :href="route('admin.module-pages.shop.index', ['adminPath' => $adminPath])">{{ __('module-shop::messages.back') }}</x-admin.button></p>
    @if(session('status'))<div class="notice notice-success" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="notice notice-error" role="alert"><strong>{{ __('module-shop::messages.validation_error') }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <x-admin.card>
        <x-admin.card-heading :title="__('module-shop::messages.sales_settings')" />
        <form method="POST" action="{{ route('admin.module-pages.shop.servers.settings.update', ['adminPath' => $adminPath, 'server' => $server->id]) }}">
            @csrf @method('PUT')
            <x-admin.toggle id="shop_server_enabled" name="enabled" :label="__('module-shop::messages.server_sales_enabled')" :checked="(bool) old('enabled', $settings?->enabled ?? false)" :disabled="! $canManage" />
            @if($canManage)<div class="admin-row-actions"><x-admin.button type="submit" variant="primary">{{ __('module-shop::messages.save') }}</x-admin.button></div>@endif
        </form>
    </x-admin.card>
    <x-admin.card>
        <x-admin.card-heading :title="__('module-shop::messages.sections_title')" :description="__('module-shop::messages.sections_description')" />
        @if($tabs->isEmpty())
            <div class="admin-empty-state empty-state"><h3>{{ __('module-shop::messages.no_sections_title') }}</h3><p>{{ __('module-shop::messages.no_sections_description') }}</p></div>
        @else
            <nav class="admin-tabs" aria-label="{{ __('module-shop::messages.sections_title') }}">
                @foreach($tabs as $section)
                    <a wire:navigate @class(['admin-tab', 'active' => $selectedSection?->id === $section->id]) @if($selectedSection?->id === $section->id) aria-current="page" @endif href="{{ route('admin.module-pages.shop.servers.show', ['adminPath' => $adminPath, 'server' => $server->id, 'section' => $section->id]) }}">{{ $section->nameFor() }}</a>
                @endforeach
            </nav>
        @endif
        @if($canManage)
            <div class="admin-row-actions"><button type="button" class="button button-secondary" data-shop-open="shop-section-new">{{ __('module-shop::messages.add_section') }}</button>
            @if($selectedSection)<button type="button" class="button button-secondary" data-shop-open="shop-section-{{ $selectedSection->id }}">{{ __('module-shop::messages.edit_section') }}</button>@endif</div>
        @endif
    </x-admin.card>
    @if($selectedSection)
        <x-admin.card>
            <x-admin.card-heading :title="$selectedSection->nameFor()" :description="__('module-shop::messages.products_description')" />
            @php($sectionProducts = $products->where('section_id', $selectedSection->id))
            @if($sectionProducts->isEmpty())
                <div class="admin-empty-state empty-state"><h3>{{ __('module-shop::messages.no_products_title') }}</h3><p>{{ __('module-shop::messages.no_products_description') }}</p></div>
            @else
                <div class="shop-admin-toolbar" data-shop-display>
                    <div class="shop-admin-view-controls" role="group" aria-label="{{ __('module-shop::messages.card_display_mode') }}">
                        <button type="button" class="button button-secondary" data-shop-view-toggle="cards" aria-pressed="true">{{ __('module-shop::messages.card_view') }}</button>
                        <button type="button" class="button button-secondary" data-shop-view-toggle="table" aria-pressed="false">{{ __('module-shop::messages.table_view') }}</button>
                    </div>
                </div>
                <div class="shop-product-grid shop-admin-cards" data-shop-view-panel="cards">
                    @foreach($sectionProducts as $product)
                        @php($effectiveActive = $selectedSection->is_active && $product->is_active && ($product->category_id === null || ($product->category?->is_active ?? false)))
                        <article class="shop-product-card" data-shop-product="{{ $product->id }}" data-shop-product-visible="{{ $effectiveActive ? '1' : '0' }}">
                            @include('module-shop::partials.product-artwork', ['visual' => $productVisuals[$product->id] ?? []])
                            <div class="shop-product-card-body">
                                <h3>{{ $product->nameFor() }}</h3>
                                @include('module-shop::partials.product-description')
                                @include('module-shop::partials.product-contents')
                            </div>
                            <div class="shop-product-card-footer">
                                <strong class="shop-product-price">{{ __('module-shop::messages.price_value', ['count' => format_number($product->price_coins)]) }}</strong>
                                <span @class(['status-badge', 'status-badge-success' => $effectiveActive])>{{ $effectiveActive ? __('module-shop::messages.active') : __('module-shop::messages.hidden') }}</span>
                                @if($canManage)
                                    <button type="button" class="button button-secondary" data-shop-open="shop-product-{{ $product->id }}">{{ __('module-shop::messages.edit') }}</button>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
                <div class="shop-admin-table" data-shop-view-panel="table" hidden>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead><tr>
                                <th>{{ __('module-shop::messages.product_name') }}</th>
                                <th>{{ __('module-shop::messages.card_contents_title') }}</th>
                                <th>{{ __('module-shop::messages.price_coins') }}</th>
                                <th>{{ __('module-shop::messages.card_visibility') }}</th>
                                @if($canManage)<th>{{ __('module-shop::messages.card_actions') }}</th>@endif
                            </tr></thead>
                            <tbody>
                                @foreach($sectionProducts as $product)
                                    @php($effectiveActive = $selectedSection->is_active && $product->is_active && ($product->category_id === null || ($product->category?->is_active ?? false)))
                                    <tr data-shop-product-visible="{{ $effectiveActive ? '1' : '0' }}">
                                        <td>{{ $product->nameFor() }}</td>
                                        <td>{{ format_number($product->type === 'item' ? 1 : $product->bundleItems->count()) }}</td>
                                        <td class="admin-num">{{ format_number($product->price_coins) }}</td>
                                        <td><span @class(['status-badge', 'status-badge-success' => $effectiveActive])>{{ $effectiveActive ? __('module-shop::messages.active') : __('module-shop::messages.hidden') }}</span></td>
                                        @if($canManage)<td><button type="button" class="button button-secondary" data-shop-open="shop-product-{{ $product->id }}">{{ __('module-shop::messages.edit') }}</button></td>@endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
            @if($canManage)<div class="admin-row-actions"><button type="button" class="button button-primary" data-shop-open="shop-product-new">{{ __('module-shop::messages.create_product') }}</button></div>@endif
        </x-admin.card>
    @endif
    @if($canManage)
        <dialog class="social-provider-dialog" id="shop-section-new" data-shop-dialog data-open-on-error="{{ old('_shop_section') === 'new' && $errors->any() ? '1' : '0' }}" aria-labelledby="shop-section-new-title">
            <header class="social-provider-dialog-heading"><h2 id="shop-section-new-title">{{ __('module-shop::messages.add_section') }}</h2><button type="button" class="button button-secondary" data-shop-close>×</button></header>
            <form method="POST" action="{{ route('admin.module-pages.shop.tabs.store', ['adminPath' => $adminPath, 'server' => $server->id]) }}">
                @csrf<input type="hidden" name="_shop_section" value="new">
                @include('module-shop::admin.fields', ['prefix' => 'new-section', 'record' => null, 'kind' => 'tabs', 'tabOptions' => collect(), 'disabled' => false])
                <div class="admin-row-actions"><button type="submit" class="button button-primary">{{ __('module-shop::messages.save') }}</button></div>
            </form>
        </dialog>
        @if($selectedSection)
            <dialog class="social-provider-dialog" id="shop-section-{{ $selectedSection->id }}" data-shop-dialog data-open-on-error="{{ old('_shop_section') === 'edit' && $errors->any() ? '1' : '0' }}" aria-labelledby="shop-section-edit-title">
                <header class="social-provider-dialog-heading"><h2 id="shop-section-edit-title">{{ __('module-shop::messages.edit_section') }}</h2><button type="button" class="button button-secondary" data-shop-close>×</button></header>
                <form method="POST" action="{{ route('admin.module-pages.shop.tabs.update', ['adminPath' => $adminPath, 'server' => $server->id, 'tab' => $selectedSection->id]) }}">
                    @csrf @method('PUT')<input type="hidden" name="_shop_section" value="edit">
                    @include('module-shop::admin.fields', ['prefix' => 'edit-section', 'record' => $selectedSection, 'kind' => 'tabs', 'tabOptions' => collect(), 'disabled' => false])
                    <div class="admin-row-actions"><button type="submit" class="button button-primary">{{ __('module-shop::messages.save') }}</button></div>
                </form>
                <form method="POST" action="{{ route('admin.module-pages.shop.tabs.destroy', ['adminPath' => $adminPath, 'server' => $server->id, 'tab' => $selectedSection->id]) }}">
                    @csrf @method('DELETE')
                    <label><input type="checkbox" name="confirm" value="1" required> {{ __('module-shop::messages.delete_confirm') }}</label>
                    <button type="submit" class="button button-secondary">{{ __('module-shop::messages.delete') }}</button>
                </form>
            </dialog>
            @include('module-shop::admin._unified-product-dialog', ['product' => null])
            @foreach($sectionProducts as $product)
                @include('module-shop::admin._unified-product-dialog', ['product' => $product])
            @endforeach
        @endif
    @endif
</div>
@endsection
@push('scripts')
<script src="{{ asset('assets/admin/js/shop-editor.js') }}?v={{ cms_version() }}" defer data-navigate-track data-navigate-once></script>
@endpush
