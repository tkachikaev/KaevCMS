@extends('account-theme::layouts.app')
@section('title', __('My wallet'))
@section('content')
<section class="account-page-heading account-wallet-heading">
    <div><span class="account-eyebrow">{{ __('Virtual currency') }}</span><h1>{{ __('My wallet') }}</h1><p>{{ __('Your virtual currency balance is shared across all game accounts on this site.') }}</p></div>
    <a wire:navigate class="account-button secondary" href="{{ public_route('account') }}">{{ __('Back to overview') }}</a>
</section>

<section class="account-wallet-summary account-section" aria-label="{{ __('Wallet balance') }}">
    <span class="account-wallet-summary-icon" aria-hidden="true"><x-account-wallet-icon :url="$walletView['icon_url']" /></span>
    <div class="account-wallet-summary-text"><span class="account-eyebrow">{{ __('Available balance') }}</span><h2>{{ $walletView['label'] }}</h2></div>
    @if($walletView['blocked'])
        <span class="account-wallet-blocked" role="status">{{ __('Wallet spending suspended') }}</span>
    @endif
</section>
@if($walletView['blocked'])
    <p class="account-inline-warning">{{ __('Spending from this wallet is disabled. Contact support for details.') }}</p>
@endif

<section class="account-section account-wallet-history" aria-labelledby="wallet-history-title">
    <div class="account-section-heading"><div><span class="account-eyebrow">{{ __('Wallet activity') }}</span><h2 id="wallet-history-title">{{ __('Transaction history') }}</h2><p>{{ __('All virtual currency amounts are shown in coins, not payment currencies.') }}</p></div></div>
    @if($entries->isEmpty())
        <div class="account-empty account-wallet-empty"><span class="account-empty-symbol" aria-hidden="true">◇</span><h3>{{ __('No wallet transactions yet') }}</h3><p>{{ __('Credits and purchases will be shown here once they occur.') }}</p></div>
    @else
        <div class="account-wallet-list">
            @foreach($entries as $entry)
                <article class="account-wallet-entry">
                    <span class="account-wallet-entry-symbol" aria-hidden="true">{{ $entry->direction === 'credit' ? '+' : '−' }}</span>
                    <div class="account-wallet-entry-details">
                        <strong>{{ $entry->direction === 'credit' ? __('Coins received') : __('Coins spent') }}</strong>
                        <small>{{ $entry->occurred_at?->locale(app()->getLocale())->format('d.m.Y H:i') }}</small>
                    </div>
                    <div class="account-wallet-entry-amounts">
                        <strong @class(['account-wallet-credit' => $entry->direction === 'credit'])>{{ $entry->direction === 'credit' ? '+' : '−' }}{{ number_format($entry->amount, 0, '.', ' ') }}</strong>
                        <small>{{ __('Balance after: :amount', ['amount' => number_format($entry->balance_after, 0, '.', ' ')]) }}</small>
                    </div>
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
</section>
<p class="account-wallet-payments-unavailable">{{ __('Online wallet top-ups will become available when Donations is enabled.') }}</p>
@endsection
