@extends('admin.layouts.panel')

@section('title', __('Wallet appearance'))
@section('description', __('Customize virtual currency names and icon. Prices and payments will be configured separately in Donations.'))

@section('content')
@include('admin.settings._system_tabs')

<form class="settings-form" method="POST" action="{{ route('admin.settings.wallet-appearance.update') }}" enctype="multipart/form-data">
    @csrf
    @method('PUT')
    <div class="settings-grid">
        <section class="form-card">
            <h2>{{ __('Virtual currency names') }}</h2>
            <p>{{ __('Names are labels only. Changing them never changes balances or journal records.') }}</p>
            <div class="translation-editor" data-locale-tabs>
                <div class="translation-tabs" role="tablist" aria-label="{{ __('Content language') }}">
                    @foreach ($translations as $locale => $forms)
                        <button type="button" role="tab" @class(['translation-tab', 'active' => $loop->first]) data-locale-tab="{{ $locale }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}">{{ strtoupper($locale) }}</button>
                    @endforeach
                </div>
                @foreach ($translations as $locale => $forms)
                    <div role="tabpanel" @class(['translation-panel', 'active' => $loop->first]) data-locale-panel="{{ $locale }}" @if(! $loop->first) hidden @endif>
                        @foreach (['one' => '1', 'few' => '2', 'many' => '5'] as $form => $number)
                            <div class="form-group">
                                <label for="wallet_{{ $locale }}_{{ $form }}">{{ __('For :count coins', ['count' => $number]) }}</label>
                                <input type="text" id="wallet_{{ $locale }}_{{ $form }}" name="translations[{{ $locale }}][{{ $form }}]" maxlength="48" required value="{{ old('translations.'.$locale.'.'.$form, $forms[$form]) }}">
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
            <hr class="form-divider">
            <div class="form-group">
                <label for="wallet_symbol">{{ __('Short currency symbol') }}</label>
                <input type="text" id="wallet_symbol" name="symbol" maxlength="16" value="{{ old('symbol', $settings['symbol']) }}" placeholder="COIN">
                <small>{{ __('Optional short label; not a payment currency or exchange rate.') }}</small>
            </div>
        </section>
        <aside class="settings-media-column">
            <section class="form-card">
                <h2>{{ __('Wallet icon') }}</h2>
                <div class="settings-image-upload" data-settings-image>
                    <div @class(['settings-image-preview', 'settings-favicon-preview', 'has-image' => $settings['icon_url']]) data-settings-preview data-preview-alt="{{ __('Selected image preview') }}">
                        @if ($settings['icon_url'])
                            <img src="{{ $settings['icon_url'] }}" alt="{{ __('Current wallet icon') }}" data-settings-preview-image>
                        @else
                            <span data-settings-preview-empty>🪙</span>
                        @endif
                    </div>
                    <input id="wallet_icon" name="icon" type="file" accept=".png,.webp,image/png,image/webp" data-settings-file>
                    <button class="button button-secondary" type="button" data-settings-file-select>{{ __('Choose wallet icon') }}</button>
                    @if ($settings['icon_url'])
                        <label class="settings-remove-row">
                            <input name="remove_icon" type="checkbox" value="1" @checked(old('remove_icon')) data-settings-remove>
                            <span>{{ __('Remove wallet icon') }}</span>
                        </label>
                    @endif
                    <small>{{ __('PNG or WebP only, up to 1 MB and 1024×1024 pixels. SVG is disabled.') }}</small>
                </div>
            </section>
        </aside>
    </div>
    @unless (auth('admin')->user()->isReadOnly())
        <div class="admin-actions-panel settings-actions">
            <button type="submit" class="button button-primary">{{ __('Save settings') }}</button>
        </div>
    @endunless
</form>
@endsection

@push('scripts')
<script src="{{ asset('assets/admin/js/settings.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
<script src="{{ asset('assets/admin/js/localization.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
