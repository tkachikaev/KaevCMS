<div class="donations-rate-row {{ $row['remove'] ? 'is-removed' : '' }}" data-donations-row data-code="{{ $code }}" data-exponent="{{ $currency['exponent'] }}" data-existing="{{ $row['existing'] ? '1' : '0' }}" data-testid="donations-rate-{{ $code }}">
    <input type="hidden" data-donations-remove name="rates[{{ $code }}][remove]" value="{{ $row['remove'] ? '1' : '0' }}">
    <fieldset class="donations-rate-fields" data-donations-fields @disabled($readOnly || $row['remove']) @if($row['remove']) hidden @endif aria-labelledby="donations-name-{{ $code }}">
        <div class="donations-currency-identity">
            <span class="donations-currency-code" data-currency-code>{{ $code }}</span>
            <div><strong id="donations-name-{{ $code }}" data-currency-name>{{ $currency['name'] }}</strong><small>{{ __('module-donations::messages.price_per_coin') }}</small></div>
        </div>
        <x-admin.field :for="'donations-price-'.$code" :name="'rates.'.$code.'.unit_price'" :label="__('module-donations::messages.price_per_coin')">
            <input id="donations-price-{{ $code }}" data-donations-price name="rates[{{ $code }}][unit_price]" type="text" inputmode="decimal" maxlength="22" required value="{{ $row['unit_price'] }}" placeholder="1.00">
        </x-admin.field>
        @unless($readOnly)<x-admin.button type="button" data-donations-delete>{{ __('module-donations::messages.remove_currency') }}</x-admin.button>@endunless
        <div class="donations-rate-footer">
            <x-admin.toggle :id="'donations-enabled-'.$code" :name="'rates['.$code.'][enabled]'" :label="__('module-donations::messages.enable_rate')" :checked="(bool) $row['enabled']" :disabled="$readOnly" compact />
            <output data-donations-total data-empty="{{ __('module-donations::messages.preview_enter_price') }}" aria-live="polite">{{ __('module-donations::messages.preview_enter_price') }}</output>
        </div>
    </fieldset>
    <div class="donations-removal" data-donations-removal @unless($row['remove']) hidden @endunless>
        <div><strong data-removed-code>{{ $code }}</strong><p>{{ __('module-donations::messages.removal_pending') }}</p></div>
        @unless($readOnly)<x-admin.button type="button" data-donations-undo>{{ __('module-donations::messages.undo_remove') }}</x-admin.button>@endunless
    </div>
</div>
