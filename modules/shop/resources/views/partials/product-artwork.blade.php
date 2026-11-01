{{-- Shared artwork for the administration preview and the player catalog. --}}
<div class="shop-product-artwork" data-shop-art-count="{{ count($visual['items'] ?? []) }}" aria-hidden="true">
    @forelse(($visual['items'] ?? []) as $image)
        <span class="shop-product-artwork-item">
            @if($image['icon'])
                <img src="{{ $image['icon'] }}" width="64" height="64" alt="" loading="lazy" decoding="async">
            @else
                <span class="shop-product-artwork-fallback">◇</span>
            @endif
        </span>
    @empty
        <span class="shop-product-artwork-item"><span class="shop-product-artwork-fallback">◇</span></span>
    @endforelse
    @if(($visual['remaining'] ?? 0) > 0)
        <span class="shop-product-artwork-more">+{{ format_number($visual['remaining']) }}</span>
    @endif
</div>
