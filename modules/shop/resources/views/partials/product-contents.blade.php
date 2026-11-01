{{-- The entire immutable catalog composition, including items outside the three-image preview. --}}
<details class="shop-product-details">
    <summary>{{ __('module-shop::messages.card_contents', ['count' => format_number($product->type === 'item' ? 1 : $product->bundleItems->count())]) }}</summary>
    <ul>
        @if($product->type === 'item')
            <li><span>{{ __('module-shop::messages.item_label', ['id' => format_number($product->item_id)]) }}</span><strong>× {{ format_number($product->quantity) }}</strong></li>
        @else
            @foreach($product->bundleItems as $item)
                <li><span>{{ __('module-shop::messages.item_label', ['id' => format_number($item->item_id)]) }}</span><strong>× {{ format_number($item->quantity) }}</strong></li>
            @endforeach
        @endif
    </ul>
</details>
