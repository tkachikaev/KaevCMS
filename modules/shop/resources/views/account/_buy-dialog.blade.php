{{-- Purchase confirmation. The checkout form and its one-time request UUID live inside. --}}
<dialog id="shop-buy-{{ $product->id }}" class="account-operation-modal account-operation-modal-warning shop-buy-dialog" data-account-social-dialog aria-labelledby="shop-buy-{{ $product->id }}-title">
    <form class="account-operation-modal-card" method="POST" action="{{ route('modules.shop.checkout') }}">
        @csrf
        <input type="hidden" name="product_id" value="{{ $product->id }}">
        <input type="hidden" name="request_uuid" value="{{ $requestUuids[$product->id] }}">
        <input type="hidden" name="return_tab" value="{{ $tab->id }}">
        <header class="account-operation-modal-head">
            <span class="account-operation-modal-mark" aria-hidden="true"><x-account-wallet-icon :url="$walletView['icon_url']" /></span>
            <div>
                <span class="account-eyebrow">{{ __('module-shop::messages.account_title') }}</span>
                <h2 id="shop-buy-{{ $product->id }}-title">{{ __('module-shop::messages.buy_confirm_title', ['product' => $product->nameFor()]) }}</h2>
                <p>{{ __('module-shop::messages.buy_confirm_hint') }}</p>
            </div>
            <button type="button" class="account-operation-modal-close" data-account-social-close aria-label="{{ __('Close') }}">×</button>
        </header>
        <div class="account-operation-rewards" aria-label="{{ __('module-shop::messages.purchase_contents') }}">
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
        <dl class="shop-buy-summary">
            <div><dt>{{ __('module-shop::messages.purchase_price') }}</dt><dd>{{ __('module-shop::messages.account_price', ['count' => format_number($product->price_coins)]) }}</dd></div>
            <div><dt>{{ __('module-shop::messages.balance_after') }}</dt><dd>{{ __('module-shop::messages.account_price', ['count' => format_number($walletView['balance'] - $product->price_coins)]) }}</dd></div>
        </dl>
        <footer class="account-operation-modal-actions">
            <button type="submit" class="account-button primary">{{ __('module-shop::messages.buy_for', ['count' => format_number($product->price_coins)]) }}</button>
            <button type="button" class="account-button secondary" data-account-social-close>{{ __('module-shop::messages.cancel') }}</button>
        </footer>
    </form>
</dialog>
