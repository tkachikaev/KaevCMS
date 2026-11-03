{{-- Full bundle composition with every icon; opened from the card without moving the grid. --}}
<dialog id="shop-contents-{{ $product->id }}" class="account-operation-modal shop-contents-dialog" data-account-social-dialog aria-labelledby="shop-contents-{{ $product->id }}-title">
    <div class="account-operation-modal-card">
        <header class="account-operation-modal-head">
            <span class="account-operation-modal-mark" aria-hidden="true">
                @if(! empty($contents[0]['icon']))<img src="{{ $contents[0]['icon'] }}" alt="" width="40" height="40">@else◇@endif
            </span>
            <div>
                <span class="account-eyebrow">{{ trans_choice('module-shop::messages.bundle_meta', count($contents), ['count' => format_number(count($contents))]) }}</span>
                <h2 id="shop-contents-{{ $product->id }}-title">{{ $product->nameFor() }}</h2>
                @if($product->descriptionFor() !== '')<p>{{ $product->descriptionFor() }}</p>@endif
            </div>
            <button type="button" class="account-operation-modal-close" data-account-social-close aria-label="{{ __('Close') }}">×</button>
        </header>
        <div class="account-operation-rewards shop-contents-list" aria-label="{{ __('module-shop::messages.purchase_contents') }}">
            @foreach($contents as $item)
                <article class="account-operation-reward">
                    <span class="account-operation-reward-icon" aria-hidden="true">
                        @if(! empty($item['icon']))<img src="{{ $item['icon'] }}" alt="" width="64" height="64">@else<span>◇</span>@endif
                    </span>
                    <strong>{{ $item['name'] }}</strong>
                    <small>× {{ format_number($item['quantity']) }}</small>
                </article>
            @endforeach
        </div>
        <footer class="account-operation-modal-actions">
            @if($buyable)
                <button type="button" class="account-button primary" data-account-social-close data-account-social-open="shop-buy-{{ $product->id }}">{{ __('module-shop::messages.buy_for', ['count' => format_number($product->price_coins)]) }}</button>
            @endif
            <button type="button" class="account-button secondary" data-account-social-close>{{ __('Close') }}</button>
        </footer>
    </div>
</dialog>
