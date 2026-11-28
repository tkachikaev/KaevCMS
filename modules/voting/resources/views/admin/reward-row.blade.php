<div class="promo-reward-row" data-promo-reward-row>
    <span class="promo-reward-preview" data-promo-reward-preview aria-hidden="true">
        @if($preview && $preview['icon'])
            <img src="{{ $preview['icon'] }}" width="48" height="48" alt="">
        @else
            <i></i>
        @endif
    </span>
    <div class="form-group promo-reward-item-field">
        <label @if($index !== null) for="reward_item_{{ $index }}" @endif>{{ __('module-voting::messages.item_id') }}</label>
        <input type="number" min="1" max="2147483647" step="1" inputmode="numeric" data-promo-reward-item @if($index !== null) id="reward_item_{{ $index }}" name="rewards[{{ $index }}][item_id]" @endif value="{{ $itemId }}" @disabled(! $canManage)>
        <small class="promo-reward-name-preview" data-promo-reward-name>{{ $preview['name'] ?? __('module-voting::messages.enter_item') }}</small>
    </div>
    <div class="form-group promo-reward-amount-field">
        <label @if($index !== null) for="reward_amount_{{ $index }}" @endif>{{ __('module-voting::messages.amount') }}</label>
        <input type="number" min="1" max="2000000000" step="1" inputmode="numeric" data-promo-reward-amount @if($index !== null) id="reward_amount_{{ $index }}" name="rewards[{{ $index }}][amount]" @endif value="{{ $amount }}" @disabled(! $canManage)>
    </div>
    @if($canManage)
        <button type="button" class="button button-secondary promo-reward-remove" data-promo-reward-remove aria-label="{{ __('module-voting::messages.remove_item') }}">×</button>
    @endif
</div>
