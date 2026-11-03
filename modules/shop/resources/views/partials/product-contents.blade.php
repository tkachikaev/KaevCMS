{{-- Contents by item name. A bundle opens its full list in a dialog so cards never change height. --}}
@php($contents = $contents ?? [])
@if(count($contents) === 1)
    <p class="shop-product-contents">{{ $contents[0]['name'] }} × {{ format_number($contents[0]['quantity']) }}</p>
@elseif(count($contents) > 1)
    <p class="shop-product-contents shop-product-bundle">
        <span>{{ trans_choice('module-shop::messages.bundle_meta', count($contents), ['count' => format_number(count($contents))]) }}</span>
        <button type="button" class="shop-contents-button" data-account-social-open="shop-contents-{{ $product->id }}" aria-haspopup="dialog">{{ __('module-shop::messages.contents_open') }}</button>
    </p>
@endif
