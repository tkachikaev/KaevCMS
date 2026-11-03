@php($details = $itemDetails ?? collect((array) $purchase->items_snapshot)->map(fn ($item) => ['item_id' => (int) ($item['item_id'] ?? 0), 'quantity' => (int) ($item['quantity'] ?? 0), 'name' => __('module-shop::messages.item_label', ['id' => format_number((int) ($item['item_id'] ?? 0))]), 'icon' => null])->all())
@if(count($details) > 0)
    <div class="reward-history-list">
        @foreach($details as $item)
            <article class="reward-history-card shop-receipt-item">
                <div class="reward-history-main">
                    @if(! empty($item['icon']))<img class="shop-receipt-icon" src="{{ $item['icon'] }}" alt="" width="40" height="40">@endif
                    <div>
                        <h3>{{ $item['name'] }}</h3>
                        <small>ID {{ $item['item_id'] }}</small>
                    </div>
                </div>
                <div class="reward-history-status"><strong>× {{ format_number($item['quantity']) }}</strong></div>
            </article>
        @endforeach
    </div>
@else
    <p>{{ __('module-shop::messages.purchase_no_contents') }}</p>
@endif
