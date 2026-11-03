{{-- One item of a product, laid out like the promo-code reward rows. --}}
<div class="promo-reward-row" data-shop-item-row>
    <span class="promo-reward-preview" data-shop-item-preview aria-hidden="true">
        @if(! empty($row['icon']))
            <img src="{{ $row['icon'] }}" alt="" width="48" height="48">
        @else
            <i>◇</i>
        @endif
    </span>
    <div class="form-group promo-reward-item-field">
        <label for="{{ $dialogId }}-item-{{ $index }}">{{ __('module-shop::messages.item_id') }}</label>
        <input id="{{ $dialogId }}-item-{{ $index }}" name="items[{{ $index }}][item_id]" type="number" min="1" max="2147483647" step="1" inputmode="numeric" required value="{{ $row['item_id'] ?? '' }}" data-shop-item-id>
        <small class="promo-reward-name-preview" data-shop-item-name>{{ $row['name'] ?? __('module-shop::messages.enter_item_id') }}</small>
    </div>
    <div class="form-group promo-reward-amount-field">
        <label for="{{ $dialogId }}-qty-{{ $index }}">{{ __('module-shop::messages.quantity') }}</label>
        <input id="{{ $dialogId }}-qty-{{ $index }}" name="items[{{ $index }}][quantity]" type="number" min="1" max="2000000000" step="1" inputmode="numeric" required value="{{ $row['quantity'] ?? 1 }}" data-shop-item-quantity>
    </div>
    <button type="button" class="button button-secondary promo-reward-remove" data-shop-remove-item aria-label="{{ __('module-shop::messages.remove_item') }}" title="{{ __('module-shop::messages.remove_item') }}">×</button>
</div>
