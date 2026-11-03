@php($description = $product->descriptionFor())
@if($description !== '')
    @if(mb_strlen($description) > 160)
        <p class="shop-product-description">{{ mb_substr($description, 0, 157) }}…</p>
        <details class="shop-product-details shop-product-description-details">
            <summary>{{ __('module-shop::messages.card_full_description') }}</summary>
            <p>{{ $description }}</p>
        </details>
    @else
        <p class="shop-product-description">{{ $description }}</p>
    @endif
@endif
