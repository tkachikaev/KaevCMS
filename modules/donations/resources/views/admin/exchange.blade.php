@extends('admin.layouts.panel')
@section('title', __('module-donations::messages.exchange_title'))
@section('description', __('module-donations::messages.exchange_admin_description'))
@section('content')
@include('module-donations::admin.navigation')
<x-admin.card>
    <x-admin.card-heading :title="__('module-donations::messages.exchange_rates_title')" :description="__('module-donations::messages.exchange_rates_help').' '.__('module-donations::messages.exchange_admin_help')" />
    @error('exchange')<p class="alert alert-danger" role="alert">{{ $message }}</p>@enderror
    @if($servers === [])
        <p>{{ __('module-donations::messages.exchange_no_servers') }}</p>
    @endif
    @error('servers')<p class="alert alert-danger" role="alert">{{ $message }}</p>@enderror
    @if($servers !== [])
    <form method="POST" action="{{ route('admin.module-pages.donations.exchange.save', ['adminPath' => $adminPath]) }}" data-testid="donations-exchange-settings" class="donation-exchange-settings">
        @csrf @method('PUT')
        @foreach($servers as $index => $server)
            @php
                $prefix = 'servers.'.$index;
                $field = 'servers['.$index.']';
                $itemId = old($prefix.'.item_id', $server['item_id']);
                $rate = old($prefix.'.items_per_coin', $server['items_per_coin']);
                $enabled = (bool) old($prefix.'.enabled', $server['enabled']);
            @endphp
            <section class="donation-exchange-server" data-admin-exchange-row
                data-preview-url="{{ route('admin.module-pages.donations.exchange.items.preview', ['adminPath' => $adminPath, 'serverId' => $server['server_id'], 'itemId' => '__ITEM__']) }}"
                data-empty-label="{{ __('module-donations::messages.exchange_enter_item') }}"
                data-loading-label="{{ __('module-donations::messages.exchange_item_loading') }}"
                data-unavailable-label="{{ __('module-donations::messages.exchange_item_unavailable') }}"
                aria-labelledby="exchange-server-{{ $server['server_id'] }}">
                <input type="hidden" name="{{ $field }}[server_id]" value="{{ $server['server_id'] }}">
                <header class="donation-exchange-server-head">
                    <h3 id="exchange-server-{{ $server['server_id'] }}">{{ $server['server_name'] }}</h3>
                    <x-admin.toggle id="exchange-enabled-{{ $server['server_id'] }}" :name="$field.'[enabled]'" :label="__('module-donations::messages.exchange_enabled_short')" :checked="$enabled" :disabled="$readOnly" />
                </header>
                @error($prefix.'.server_id')<small class="admin-field-error" role="alert">{{ $message }}</small>@enderror
                @error($prefix.'.enabled')<small class="admin-field-error" role="alert">{{ $message }}</small>@enderror
                <div class="donation-exchange-flow">
                    <div class="donation-exchange-source">
                        <img src="{{ $walletAppearance['icon_url'] }}" alt="" width="28" height="28" class="donation-exchange-coin">
                        <div><span class="donation-exchange-caption">{{ __('module-donations::messages.exchange_from_wallet') }}</span><strong>{{ $coinLabel }}</strong></div>
                    </div>
                    <span class="donation-exchange-arrow" aria-hidden="true">→</span>
                    <div class="donation-exchange-target">
                        <div class="donation-exchange-item">
                            <span class="donation-exchange-icon" aria-hidden="true" data-admin-exchange-icon>
                                @if($server['preview']['icon'] ?? null)<img src="{{ $server['preview']['icon'] }}" alt="" width="28" height="28">@else<span>◇</span>@endif
                            </span>
                            <div class="donation-exchange-item-copy">
                                <span class="donation-exchange-caption">{{ __('module-donations::messages.exchange_to_inventory') }}</span>
                                <strong data-admin-exchange-name aria-live="polite">{{ $server['preview']['name'] ?? __('module-donations::messages.exchange_enter_item') }}</strong>
                            </div>
                        </div>
                        <div class="donation-exchange-fields">
                            <x-admin.field for="exchange-item-{{ $server['server_id'] }}" :name="$prefix.'.item_id'" :label="__('module-donations::messages.exchange_item_id')">
                                <input id="exchange-item-{{ $server['server_id'] }}" name="{{ $field }}[item_id]" type="number" min="1" max="2147483647" step="1" value="{{ $itemId }}" inputmode="numeric" data-admin-exchange-item @disabled($readOnly)>
                            </x-admin.field>
                            <x-admin.field for="exchange-rate-{{ $server['server_id'] }}" :name="$prefix.'.items_per_coin'" :label="__('module-donations::messages.exchange_quantity')" :hint="__('module-donations::messages.exchange_quantity_help')">
                                <input id="exchange-rate-{{ $server['server_id'] }}" name="{{ $field }}[items_per_coin]" type="number" min="1" max="1000000" step="1" value="{{ $rate }}" inputmode="numeric" @disabled($readOnly)>
                            </x-admin.field>
                        </div>
                    </div>
                </div>
            </section>
        @endforeach
        @unless($readOnly)
            <div class="donation-exchange-actions">
                <span class="admin-field-help">{{ __('module-donations::messages.exchange_save_help') }}</span>
                <x-admin.button type="submit" variant="primary">{{ __('module-donations::messages.exchange_save_all') }}</x-admin.button>
            </div>
        @endunless
    </form>
    @endif
</x-admin.card>
@endsection
@push('scripts')
<script src="{{ asset('assets/modules/donations-exchange.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
