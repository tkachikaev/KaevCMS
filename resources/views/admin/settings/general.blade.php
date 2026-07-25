@extends('admin.layouts.panel')

@section('title', __('Site'))
@section('description', __('Public website content, branding and time zone.'))

@section('content')
@include('admin.settings._system_tabs')

<form class="settings-form" method="POST" action="{{ route('admin.settings.general.update') }}" enctype="multipart/form-data">
    @csrf
    @method('PUT')

    <div class="settings-grid">
        <section class="form-card">
            <div class="settings-card-heading">
                <div>
                    <h2>{{ __('Public website text') }}</h2>
                    <p>{{ __('Fill in the default language. Other translations may be left empty and will use the fallback language.') }}</p>
                </div>
            </div>

            <div class="translation-editor" data-locale-tabs>
                <div class="translation-tabs" role="tablist" aria-label="{{ __('Content language') }}">
                    @foreach ($languages as $code => $language)
                        <button
                            type="button"
                            role="tab"
                            @class(['translation-tab', 'active' => $code === $defaultLocale])
                            data-locale-tab="{{ $code }}"
                            aria-selected="{{ $code === $defaultLocale ? 'true' : 'false' }}"
                        >
                            <span class="translation-tab-label">{{ $language['native_name'] }}</span>
                            @if ($code === $defaultLocale)<span class="translation-tab-default">{{ __('Default locale marker') }}</span>@endif
                        </button>
                    @endforeach
                </div>

                @foreach ($languages as $code => $language)
                    @php($values = $translations[$code] ?? ['name' => '', 'description' => '', 'footer_text' => ''])
                    <div
                        role="tabpanel"
                        @class(['translation-panel', 'active' => $code === $defaultLocale])
                        data-locale-panel="{{ $code }}"
                        @if($code !== $defaultLocale) hidden @endif
                    >
                        <div class="form-group">
                            <label for="site_name_{{ $code }}">{{ __('Site name') }}</label>
                            <input
                                id="site_name_{{ $code }}"
                                name="translations[{{ $code }}][name]"
                                type="text"
                                maxlength="100"
                                value="{{ old('translations.'.$code.'.name', $values['name']) }}"
                                @if($code === $defaultLocale) required @endif
                            >
                            <small>{{ __('Used in the browser title, header and public pages.') }}</small>
                        </div>

                        <div class="form-group">
                            <label for="site_description_{{ $code }}">{{ __('Short description') }}</label>
                            <textarea id="site_description_{{ $code }}" name="translations[{{ $code }}][description]" rows="4" maxlength="255">{{ old('translations.'.$code.'.description', $values['description']) }}</textarea>
                            <small>{{ __('Used on the home page and in the description meta tag.') }}</small>
                        </div>

                        <div class="form-group">
                            <label for="footer_text_{{ $code }}">{{ __('Footer text') }}</label>
                            <input id="footer_text_{{ $code }}" name="translations[{{ $code }}][footer_text]" type="text" maxlength="255" value="{{ old('translations.'.$code.'.footer_text', $values['footer_text']) }}">
                            <small>{{ __('Example: © 2026 KaevCMS.') }}</small>
                        </div>
                    </div>
                @endforeach
            </div>

            <hr class="form-divider">

            <h2>{{ __('Technical settings') }}</h2>
            <div class="form-group">
                <label for="timezone">{{ __('Time zone') }}</label>
                <select id="timezone" name="timezone" required>
                    @foreach ($timezones as $timezone)
                        <option value="{{ $timezone }}" @selected(old('timezone', $settings['timezone']) === $timezone)>{{ $timezone }}</option>
                    @endforeach
                </select>
                <small>{{ __('Used for scheduled news publication and date display.') }}</small>
            </div>

            <div class="form-group">
                <label for="admin_email">{{ __('Administrator email') }}</label>
                <input id="admin_email" name="admin_email" type="email" maxlength="255" value="{{ old('admin_email', $settings['admin_email']) }}" placeholder="admin@example.com">
                <small>{{ __('Public contact address. SMTP credentials are stored separately.') }}</small>
            </div>

        </section>

        <aside class="settings-media-column">
            <section class="form-card">
                <h2>{{ __('Logo') }}</h2>
                <div class="settings-image-upload" data-settings-image>
                    <div @class(['settings-image-preview', 'has-image' => $settings['logo_url']]) data-settings-preview data-preview-alt="{{ __('Selected image preview') }}">
                        @if ($settings['logo_url'])
                            <img src="{{ $settings['logo_url'] }}" alt="{{ __('Current logo') }}" data-settings-preview-image>
                        @else
                            <span data-settings-preview-empty>{{ __('No logo uploaded') }}</span>
                        @endif
                    </div>

                    <input id="logo" name="logo" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" data-settings-file>
                    <button class="button button-secondary" type="button" data-settings-file-select>{{ __('Choose logo') }}</button>

                    @if ($settings['logo_url'])
                        <label class="settings-remove-row">
                            <input name="remove_logo" type="checkbox" value="1" @checked(old('remove_logo')) data-settings-remove>
                            <span>{{ __('Remove current logo') }}</span>
                        </label>
                    @endif

                    <small>{{ __('JPG, PNG or WebP, up to 5 MB. SVG is intentionally disabled.') }}</small>
                </div>
            </section>

            <section class="form-card">
                <h2>Favicon</h2>
                <div class="settings-image-upload" data-settings-image>
                    <div @class(['settings-image-preview', 'settings-favicon-preview', 'has-image' => $settings['favicon_url']]) data-settings-preview data-preview-alt="{{ __('Selected image preview') }}">
                        @if ($settings['favicon_url'])
                            <img src="{{ $settings['favicon_url'] }}" alt="{{ __('Current favicon') }}" data-settings-preview-image>
                        @else
                            <span data-settings-preview-empty>{{ __('No icon uploaded') }}</span>
                        @endif
                    </div>

                    <input id="favicon" name="favicon" type="file" accept=".png,.webp,.ico,image/png,image/webp,image/x-icon,image/vnd.microsoft.icon" data-settings-file>
                    <button class="button button-secondary" type="button" data-settings-file-select>{{ __('Choose favicon') }}</button>

                    @if ($settings['favicon_url'])
                        <label class="settings-remove-row">
                            <input name="remove_favicon" type="checkbox" value="1" @checked(old('remove_favicon')) data-settings-remove>
                            <span>{{ __('Remove current favicon') }}</span>
                        </label>
                    @endif

                    <small>{{ __('PNG, WebP or ICO, up to 1 MB. Recommended size: 512×512.') }}</small>
                </div>
            </section>
        </aside>
    </div>

    <div class="admin-actions-panel settings-actions">
        <button class="button button-primary" type="submit">{{ __('Save settings') }}</button>
    </div>
</form>
@endsection

@push('scripts')
<script src="{{ asset('assets/admin/js/settings.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
<script src="{{ asset('assets/admin/js/localization.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
