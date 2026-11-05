@extends('admin.layouts.panel')
@section('title', __('module-shop::messages.admin_title'))
@section('content')
@php($adminPath = request()->route('adminPath'))
@php($serverEnabled = (bool) ($settings?->enabled ?? false))
<div class="shop-editor" data-shop-server-page data-shop-swap-root
    data-shop-item-preview-url="{{ route('admin.module-pages.shop.items.preview', ['adminPath' => $adminPath, 'server' => $server->id, 'item' => 0]) }}"
    data-shop-item-search-url="{{ route('admin.module-pages.shop.items.search', ['adminPath' => $adminPath, 'server' => $server->id]) }}"
    data-shop-text-enter-item="{{ __('module-shop::messages.enter_item_id') }}"
    data-shop-text-unknown-item="{{ __('module-shop::messages.unknown_item') }}"
    data-shop-text-no-results="{{ __('module-shop::messages.item_search_empty') }}"
    data-shop-text-limit="{{ __('module-shop::messages.bundle_item_limit_reached') }}"
    data-shop-text-duplicate="{{ __('module-shop::messages.duplicate_bundle_item') }}"
    data-shop-text-order-failed="{{ __('module-shop::messages.order_failed') }}">

    <div class="shop-editor-toolbar">
        <details class="shop-server-picker">
            <summary class="button button-secondary">
                <span>{{ $server->nameFor() }}</span>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
            </summary>
            <div class="shop-server-picker-menu" role="list">
                @foreach($servers as $choice)
                    <a role="listitem" wire:navigate @class(['active' => $choice->id === $server->id]) @if($choice->id === $server->id) aria-current="page" @endif href="{{ route('admin.module-pages.shop.servers.show', ['adminPath' => $adminPath, 'server' => $choice->id]) }}">{{ $choice->nameFor() }}</a>
                @endforeach
            </div>
        </details>
        <span class="shop-editor-toolbar-spacer"></span>
        <a wire:navigate class="button button-secondary" href="{{ route('admin.module-pages.shop.purchases.index', ['adminPath' => $adminPath, 'server_id' => $server->id]) }}">{{ __('module-shop::messages.purchase_admin_title') }}</a>
        {{-- The shop-wide switch: purchases need it and the server switch together. --}}
        <form method="POST" action="{{ route('admin.module-pages.shop.settings.update', ['adminPath' => $adminPath]) }}" data-shop-async @class(['shop-sales-switch', 'is-on' => $salesEnabled])>
            @csrf @method('PUT')
            <input type="hidden" name="sales_enabled" value="{{ $salesEnabled ? '0' : '1' }}">
            <input type="hidden" name="_return_server" value="{{ $server->id }}">
            <span id="shop-global-sales-label">{{ $salesEnabled ? __('module-shop::messages.global_sales_on') : __('module-shop::messages.global_sales_off_short') }}</span>
            <button type="submit" class="shop-switch" role="switch" aria-checked="{{ $salesEnabled ? 'true' : 'false' }}" aria-labelledby="shop-global-sales-label" @disabled(! $canManage)><span></span></button>
        </form>
        <form method="POST" action="{{ route('admin.module-pages.shop.servers.settings.update', ['adminPath' => $adminPath, 'server' => $server->id]) }}" data-shop-async @class(['shop-sales-switch', 'is-on' => $serverEnabled])>
            @csrf @method('PUT')
            <input type="hidden" name="enabled" value="{{ $serverEnabled ? '0' : '1' }}">
            <span id="shop-sales-label">{{ $serverEnabled ? __('module-shop::messages.server_sales_on') : __('module-shop::messages.server_sales_off') }}</span>
            <button type="submit" class="shop-switch" role="switch" aria-checked="{{ $serverEnabled ? 'true' : 'false' }}" aria-labelledby="shop-sales-label" @disabled(! $canManage)><span></span></button>
        </form>
    </div>
    @if(! $sourceEnabled)
        <p class="notice notice-warning shop-editor-notice">{{ __('module-shop::messages.wallet_source_disabled') }}</p>
    @elseif(! $salesEnabled || ! $serverEnabled)
        <p class="notice notice-warning shop-editor-notice">{{ __('module-shop::messages.sales_need_both') }}</p>
    @endif
    @if(session('status'))<div class="notice notice-success shop-editor-notice" role="status" data-shop-flash>{{ session('status') }}</div>@endif
    @if($errors->any() && ! old('_shop_dialog'))<div class="notice notice-error shop-editor-notice" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <div class="shop-editor-live" role="status" aria-live="polite" data-shop-live></div>

    <div class="shop-editor-layout">
        <section class="shop-sections-panel" aria-labelledby="shop-sections-title">
            <header class="shop-panel-head">
                <h2 id="shop-sections-title">{{ __('module-shop::messages.sections_title') }}</h2>
                @if($canManage && $tabs->count() > 1)<small>{{ __('module-shop::messages.sections_drag_hint') }}</small>@endif
            </header>
            @if($tabs->isEmpty())
                <p class="shop-panel-empty">{{ __('module-shop::messages.no_sections_description') }}</p>
            @else
                <ol class="shop-section-list" @if($canManage) data-shop-sortable data-shop-order-url="{{ route('admin.module-pages.shop.sections.order', ['adminPath' => $adminPath, 'server' => $server->id]) }}" @endif>
                    @foreach($tabs as $section)
                        @php($current = $selectedSection?->id === $section->id)
                        <li @class(['shop-section-item', 'is-current' => $current, 'is-hidden' => ! $section->is_active]) data-shop-sort-id="{{ $section->id }}">
                            @if($canManage)
                                <button type="button" class="shop-drag-handle" data-shop-drag-handle aria-label="{{ __('module-shop::messages.move_item', ['name' => $section->nameFor()]) }}">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>
                                </button>
                            @endif
                            <a class="shop-section-link" wire:navigate.preserve-scroll @if($current) aria-current="page" @endif href="{{ route('admin.module-pages.shop.servers.show', ['adminPath' => $adminPath, 'server' => $server->id, 'section' => $section->id]) }}">
                                <span class="shop-section-name">{{ $section->nameFor() }}</span>
                                @unless($section->is_active)<span class="status-badge status-badge-muted">{{ __('module-shop::messages.hidden') }}</span>@endunless
                                <span class="shop-section-count admin-num" aria-label="{{ __('module-shop::messages.products_count', ['count' => $section->products_count]) }}">{{ format_number($section->products_count) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ol>
            @endif
            @if($canManage)
                <form class="shop-section-create" method="POST" action="{{ route('admin.module-pages.shop.sections.store', ['adminPath' => $adminPath, 'server' => $server->id]) }}" data-shop-async>
                    @csrf
                    <label for="shop-new-section">{{ __('module-shop::messages.new_section') }}</label>
                    <div class="shop-section-create-row">
                        <input id="shop-new-section" name="name" maxlength="120" required autocomplete="off" placeholder="{{ __('module-shop::messages.new_section_placeholder') }}">
                        <button type="submit" class="button button-primary shop-icon-button" aria-label="{{ __('module-shop::messages.add_section') }}">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                        </button>
                    </div>
                </form>
            @endif
        </section>

        <section class="shop-products-panel" aria-labelledby="shop-products-title">
            @if($selectedSection)
                <header class="shop-products-head">
                    <h2 id="shop-products-title">{{ $selectedSection->nameFor() }}</h2>
                    @if($canManage)
                        <button type="button" class="button button-secondary shop-icon-button" data-shop-open="shop-section-dialog" aria-label="{{ __('module-shop::messages.edit_section') }}">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16v4z"/></svg>
                        </button>
                        <form method="POST" action="{{ route('admin.module-pages.shop.sections.visibility', ['adminPath' => $adminPath, 'server' => $server->id, 'tab' => $selectedSection->id]) }}" data-shop-async @class(['shop-visibility', 'is-on' => $selectedSection->is_active])>
                            @csrf @method('PATCH')
                            <input type="hidden" name="is_active" value="{{ $selectedSection->is_active ? '0' : '1' }}">
                            <button type="submit" class="shop-switch" role="switch" aria-checked="{{ $selectedSection->is_active ? 'true' : 'false' }}" aria-labelledby="shop-section-visibility"><span></span></button>
                            <span id="shop-section-visibility">{{ $selectedSection->is_active ? __('module-shop::messages.section_visible') : __('module-shop::messages.section_hidden') }}</span>
                        </form>
                    @endif
                    <span class="shop-editor-toolbar-spacer"></span>
                    @if($products->isNotEmpty())
                        <label class="shop-search">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                            <span class="shop-visually-hidden">{{ __('module-shop::messages.product_search') }}</span>
                            <input type="search" data-shop-filter placeholder="{{ __('module-shop::messages.product_search') }}" autocomplete="off">
                        </label>
                    @endif
                    @if($canManage)
                        <button type="button" class="button button-primary" data-shop-open="shop-product-new">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                            {{ __('module-shop::messages.add_product') }}
                        </button>
                    @endif
                </header>
                @if($products->isEmpty())
                    <div class="shop-products-empty">
                        <h3>{{ __('module-shop::messages.no_products_title') }}</h3>
                        <p>{{ __('module-shop::messages.no_products_description') }}</p>
                    </div>
                @else
                    <div class="shop-admin-grid" @if($canManage) data-shop-sortable data-shop-order-url="{{ route('admin.module-pages.shop.sections.products.order', ['adminPath' => $adminPath, 'server' => $server->id, 'tab' => $selectedSection->id]) }}" @endif>
                        @foreach($products as $product)
                            @php($visual = $productVisuals[$product->id] ?? [])
                            @php($visible = $selectedSection->is_active && $product->is_active && ($product->category_id === null || ($product->category?->is_active ?? false)))
                            @php($itemCount = count($visual['contents'] ?? []))
                            <article class="shop-admin-card" data-shop-sort-id="{{ $product->id }}" data-shop-product="{{ $product->id }}" data-shop-product-visible="{{ $visible ? '1' : '0' }}" data-shop-filter-text="{{ mb_strtolower($product->nameFor().' '.collect($visual['contents'] ?? [])->pluck('name')->implode(' ').' '.collect($visual['contents'] ?? [])->pluck('item_id')->implode(' ')) }}">
                                @if($canManage)
                                    <button type="button" class="shop-drag-handle shop-card-handle" data-shop-drag-handle aria-label="{{ __('module-shop::messages.move_item', ['name' => $product->nameFor()]) }}">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>
                                    </button>
                                @endif
                                <button type="button" class="shop-admin-card-open" @if($canManage) data-shop-open="shop-product-{{ $product->id }}" @else disabled @endif>
                                    @include('module-shop::partials.product-artwork', ['visual' => $visual])
                                    <span class="shop-admin-card-body">
                                        <strong>{{ $product->nameFor() }}</strong>
                                        <span class="shop-admin-card-meta">{{ $itemCount > 1 ? trans_choice('module-shop::messages.bundle_meta', $itemCount, ['count' => format_number($itemCount)]) : __('module-shop::messages.item_meta', ['count' => format_number((int) ($visual['contents'][0]['quantity'] ?? 0))]) }}</span>
                                        <span class="shop-admin-card-price admin-num"><img src="{{ $coinIcon }}" alt="" width="18" height="18">{{ format_number($product->price_coins) }}</span>
                                    </span>
                                    @unless($visible)<span class="status-badge status-badge-muted shop-admin-card-badge">{{ __('module-shop::messages.hidden') }}</span>@endunless
                                </button>
                            </article>
                        @endforeach
                    </div>
                    <p class="shop-products-empty shop-filter-empty" data-shop-filter-empty hidden>{{ __('module-shop::messages.product_search_empty') }}</p>
                @endif
            @else
                <div class="shop-products-empty">
                    <h3>{{ __('module-shop::messages.no_sections_title') }}</h3>
                    <p>{{ __('module-shop::messages.no_sections_description') }}</p>
                </div>
            @endif
        </section>
    </div>

    @if($canManage && $selectedSection)
        @include('module-shop::admin._section-dialog', ['section' => $selectedSection])
        @include('module-shop::admin._product-drawer', ['product' => null])
        @foreach($products as $product)
            @include('module-shop::admin._product-drawer', ['product' => $product])
        @endforeach
    @endif
</div>
@endsection
@push('scripts')
<script src="{{ asset('assets/admin/js/shop-editor.js') }}?v={{ cms_version() }}" defer data-navigate-track data-navigate-once></script>
@endpush
