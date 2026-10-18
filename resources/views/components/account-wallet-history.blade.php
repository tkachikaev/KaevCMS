@props(['entries', 'includeInactive' => false, 'canShowInactive' => false, 'coinIconUrl' => null])
<x-account-panel class="account-section account-wallet-history" aria-labelledby="wallet-history-title" data-wallet-history>
    <x-account-panel-header title-id="wallet-history-title" :title="__('History')" :description="__('Top-ups, coins received and spending in one place.')" />
    @if($entries->isEmpty())
        <x-account-empty class="account-empty account-wallet-empty"><span class="account-empty-symbol" aria-hidden="true">◇</span><h3>{{ __('No wallet transactions yet') }}</h3><p>{{ __('Credits and purchases will be shown here once they occur.') }}</p></x-account-empty>
    @else
        <div class="account-wallet-list">
            @foreach($entries as $item)
                <article class="account-wallet-entry wallet-history-row wallet-history-{{ $item['tone'] }}" data-history-key="{{ $item['key'] }}"
                    @if($item['expires_at']) data-wallet-expires="{{ $item['expires_at']->getTimestamp() }}" data-wallet-now="{{ now()->getTimestamp() }}" data-wallet-time-template="{{ __('Time left: :time') }}" data-wallet-expired-label="{{ __('Payment time expired') }}" @endif>
                    @if($item['url'])<a wire:navigate class="wallet-history-main" href="{{ $item['url'] }}" aria-label="{{ __('View details') }}: {{ $item['amount'] }}">@else<div class="wallet-history-main">@endif
                        <span class="account-wallet-entry-symbol" aria-hidden="true"><x-account-wallet-icon :url="$coinIconUrl" /></span>
                        <div class="account-wallet-entry-details">
                            <strong @class(['account-wallet-credit' => $item['tone'] === 'success'])>{{ $item['amount'] }}</strong>
                            <span>{{ $item['title'] }}</span>
                            <small>{{ $item['date']?->format('d.m.Y H:i') }}</small>
                        </div>
                        <div class="wallet-history-state">
                            @if($item['detail'])<strong>{{ $item['detail'] }}</strong>@endif
                            @if($item['status'])<span class="wallet-history-status" data-wallet-order-status>{{ $item['status'] }}</span>@endif
                        </div>
                    @if($item['url'])</a>@else</div>@endif
                    @if($item['expires_at'])
                        <div class="wallet-history-footer">
                            <small class="wallet-history-time" data-wallet-time>{{ __('Pay before :date', ['date' => $item['expires_at']->format('d.m.Y H:i')]) }}</small>
                            <div class="wallet-history-actions">
                                @if($item['pay_url'])
                                    <a class="account-button primary" data-wallet-pay href="{{ $item['pay_url'] }}">{{ __('Pay') }}</a>
                                @elseif($item['prepare_url'])
                                    <form method="POST" action="{{ $item['prepare_url'] }}" data-wallet-pay-form>@csrf
                                        <input type="hidden" name="provider" value="{{ $item['provider'] }}">
                                        <button class="account-button primary" type="submit">{{ __('Pay') }}</button>
                                    </form>
                                @endif
                                @if($item['cancel_url'])
                                    <form method="POST" action="{{ $item['cancel_url'] }}" data-wallet-cancel-form>@csrf
                                        <input type="hidden" name="return_to" value="history">
                                        <button class="account-button secondary" type="submit">{{ __('Cancel top-up') }}</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
        @if($entries->hasPages())
            <nav class="simple-pagination account-wallet-pagination" aria-label="{{ __('Wallet history pages') }}">
                @if($entries->onFirstPage())<span class="account-button ghost disabled">← {{ __('Back') }}</span>@else<a wire:navigate class="account-button ghost" href="{{ $entries->previousPageUrl() }}">← {{ __('Back') }}</a>@endif
                <span class="account-wallet-page-number">{{ __('Page :current of :last', ['current' => $entries->currentPage(), 'last' => $entries->lastPage()]) }}</span>
                @if($entries->hasMorePages())<a wire:navigate class="account-button ghost" href="{{ $entries->nextPageUrl() }}">{{ __('Next') }} →</a>@else<span class="account-button ghost disabled">{{ __('Next') }} →</span>@endif
            </nav>
        @endif
    @endif
    @if($canShowInactive)
        <a wire:navigate class="wallet-history-inactive" href="{{ public_route('wallet.index', $includeInactive ? [] : ['inactive' => 1]) }}">{{ $includeInactive ? __('Hide inactive top-ups') : __('Show inactive top-ups') }}</a>
    @endif
</x-account-panel>
