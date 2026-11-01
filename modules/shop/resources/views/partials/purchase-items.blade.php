@if(is_array($purchase->items_snapshot) && count($purchase->items_snapshot) > 0)
    <div class="reward-history-list">
        @foreach($purchase->items_snapshot as $item)
            <article class="reward-history-card">
                <div class="reward-history-main">
                    <div>
                        <h3>{{ __('module-shop::messages.account_bundle_entry', ['id' => format_number((int) ($item['item_id'] ?? 0)), 'count' => format_number((int) ($item['quantity'] ?? 0))]) }}</h3>
                    </div>
                </div>
            </article>
        @endforeach
    </div>
@else
    <p>{{ __('module-shop::messages.purchase_no_contents') }}</p>
@endif
