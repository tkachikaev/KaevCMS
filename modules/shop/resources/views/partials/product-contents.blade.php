{{-- Contents by item name; the full list stays available even when the card is short. --}}
@php($contents = $contents ?? [])
@if(count($contents) === 1)
    <p class="shop-product-contents">{{ $contents[0]['name'] }} × {{ format_number($contents[0]['quantity']) }}</p>
@elseif(count($contents) > 1)
    <details class="shop-product-details">
        <summary>{{ trans_choice('module-shop::messages.bundle_meta', count($contents), ['count' => format_number(count($contents))]) }}</summary>
        <ul>
            @foreach($contents as $item)
                <li><span>{{ $item['name'] }}</span><strong>× {{ format_number($item['quantity']) }}</strong></li>
            @endforeach
        </ul>
    </details>
@endif
