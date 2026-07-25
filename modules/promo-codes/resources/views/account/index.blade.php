@extends('account-theme::layouts.app')
@section('title', __('module-promo-codes::messages.account_title'))
@section('content')
<section class="account-section account-surface">
    <div class="account-section-heading">
        <div>
            <span class="account-eyebrow">{{ __('module-promo-codes::messages.rewards') }}</span>
            <h1>{{ __('module-promo-codes::messages.account_title') }}</h1>
            <p>{{ __('module-promo-codes::messages.account_description') }}</p>
        </div>
    </div>

    <div class="account-form-layout">
        <form class="account-form-card" method="POST" action="{{ route('modules.promo-codes.activate') }}">
            @csrf
            <input type="hidden" name="request_token" value="{{ old('request_token', $requestToken) }}">
            <div class="account-form-title">
                <span aria-hidden="true">%</span>
                <div>
                    <h2>{{ __('module-promo-codes::messages.enter_code') }}</h2>
                    <p>{{ __('module-promo-codes::messages.enter_code_help') }}</p>
                </div>
            </div>
            <label>
                <span>{{ __('module-promo-codes::messages.code') }}</span>
                <div class="account-field-control">
                    <input name="code" type="text" minlength="4" maxlength="64" pattern="[A-Za-z0-9][A-Za-z0-9_-]{3,63}" value="{{ old('code') }}" autocomplete="off" required @class(['account-field-invalid' => $errors->has('code')])>
                    @error('code')<small class="account-field-error" role="alert">{{ $message }}</small>@enderror
                </div>
            </label>
            <div class="account-form-note">
                <span aria-hidden="true">i</span>
                <p>{{ __('module-promo-codes::messages.inventory_note') }}</p>
            </div>
            <div class="account-form-actions">
                <button class="account-button primary" type="submit">{{ __('module-promo-codes::messages.activate') }}</button>
                <a wire:navigate class="account-button secondary" href="{{ public_route('web-inventory.index') }}">{{ __('module-promo-codes::messages.open_inventory') }}</a>
            </div>
        </form>

        <aside class="account-form-aside">
            <span class="account-form-aside-symbol" aria-hidden="true">◇</span>
            <h2>{{ __('module-promo-codes::messages.how_it_works') }}</h2>
            <p>{{ __('module-promo-codes::messages.how_it_works_text') }}</p>
        </aside>
    </div>
</section>

<section class="account-section account-surface promo-activation-surface">
    <div class="account-section-heading">
        <div>
            <h2>{{ __('module-promo-codes::messages.recent_activations') }}</h2>
            <p>{{ __('module-promo-codes::messages.recent_activations_help') }}</p>
        </div>
    </div>

    @if($activations->isEmpty())
        <div class="account-empty"><span class="account-empty-symbol" aria-hidden="true">%</span><h2>{{ __('module-promo-codes::messages.no_activations_title') }}</h2><p>{{ __('module-promo-codes::messages.no_activations_description') }}</p></div>
    @else
        <div class="reward-history-list">
            @foreach($activations as $activation)
                <article class="reward-history-card">
                    <div class="reward-history-main">
                        <span class="reward-item-icon" aria-hidden="true">%</span>
                        <div>
                            <small>{{ $activation->gameServer->nameFor() }}</small>
                            <h3>{{ $activation->code_snapshot }}</h3>
                            <p>
                                @foreach($activation->rewardGrant?->items ?? [] as $reward)
                                    @if($iconUrls[$activation->id][$reward->item_id] ?? null)<img src="{{ $iconUrls[$activation->id][$reward->item_id] }}" alt="" width="24" height="24">@endif
                                    {{ $reward->displayName($activation->game_server_id) }} × {{ number_format($reward->amount, 0, '.', ' ') }}@if(! $loop->last), @endif
                                @endforeach
                            </p>
                        </div>
                    </div>
                    <div class="reward-history-status"><span class="reward-status reward-status-delivered">{{ __('module-promo-codes::messages.added_to_inventory') }}</span><small>{{ $activation->activated_at?->format('d.m.Y H:i') }}</small></div>
                </article>
            @endforeach
        </div>
    @endif
</section>
@endsection
