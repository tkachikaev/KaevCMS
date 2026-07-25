@extends('admin.layouts.panel')

@section('title', __('Languages'))
@section('description', __('Enable installed language packs and select the default and fallback languages.'))

@section('content')
@include('admin.settings._system_tabs')

<form class="form-card language-settings" method="POST" action="{{ route('admin.settings.languages.update') }}">
    @csrf
    @method('PUT')

    <div class="form-section-heading">
        <div>
            <h2>{{ __('Installed languages') }}</h2>
            <p>{{ __('Russian and English are included with KaevCMS. Additional packs are discovered in the lang directory.') }}</p>
        </div>
    </div>

    <div class="language-list">
        @foreach($installedLanguages as $code => $language)
            <label class="language-card">
                <span class="language-card-check">
                    <input
                        type="checkbox"
                        name="enabled_locales[]"
                        value="{{ $code }}"
                        @checked(in_array($code, old('enabled_locales', $enabledLocales), true))
                    >
                </span>
                <span class="language-card-main">
                    <strong>{{ $language['native_name'] }}</strong>
                    <small>{{ $language['name'] }} · {{ $code }} · {{ strtoupper($language['direction']) }}</small>
                </span>
                <span class="language-card-meta">
                    @if($language['built_in'])
                        <span class="status-badge status-badge-success">{{ __('Built in') }}</span>
                    @else
                        <span class="status-badge">{{ __('Language pack') }}</span>
                    @endif
                    <small>{{ __('Translation coverage: :percent%', ['percent' => $language['coverage']]) }}</small>
                </span>
            </label>
        @endforeach
    </div>

    @error('enabled_locales')<p class="field-error">{{ $message }}</p>@enderror
    @error('enabled_locales.*')<p class="field-error">{{ $message }}</p>@enderror

    <div class="form-grid two-columns language-selectors">
        <div>
            <label for="default_locale">{{ __('Default site language') }}</label>
            <select id="default_locale" name="default_locale" required>
                @foreach($installedLanguages as $code => $language)
                    <option value="{{ $code }}" @selected(old('default_locale', $defaultLocale) === $code)>
                        {{ $language['native_name'] }} ({{ $code }})
                    </option>
                @endforeach
            </select>
            <small>{{ __('Used for old links, new users and content fallback.') }}</small>
            @error('default_locale')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="fallback_locale">{{ __('Fallback language') }}</label>
            <select id="fallback_locale" name="fallback_locale" required>
                @foreach($installedLanguages as $code => $language)
                    <option value="{{ $code }}" @selected(old('fallback_locale', $fallbackLocale) === $code)>
                        {{ $language['native_name'] }} ({{ $code }})
                    </option>
                @endforeach
            </select>
            <small>{{ __('Used when a page, news item or mail template has no translation.') }}</small>
            @error('fallback_locale')<p class="field-error">{{ $message }}</p>@enderror
        </div>
    </div>

    <div class="notice notice-info">
        <strong>{{ __('Adding another language') }}</strong>
        <p>{{ __('Copy a reviewed language pack into lang/<locale>/language.php and lang/<locale>.json. It will appear here automatically.') }}</p>
    </div>

    <button class="button button-primary" type="submit">{{ __('Save language settings') }}</button>
</form>
@endsection
