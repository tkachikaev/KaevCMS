{{-- Website and player account language menu: the current language code opens a list of the
     enabled languages by their own names, so many languages take no more room than two. --}}
@if (count($enabledLanguages ?? []) > 1)
    @php
        $currentCode = app()->getLocale();
        $currentName = $enabledLanguages[$currentCode]['native_name'] ?? strtoupper($currentCode);
    @endphp
    <details {{ $attributes->class(['language-menu']) }} data-language-menu>
        <summary aria-label="{{ __('Switch language') }}: {{ $currentName }}">
            <span class="language-menu-code">{{ strtoupper($currentCode) }}</span>
            <svg class="language-menu-chevron" width="12" height="12" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </summary>
        <div class="language-menu-list">
            @foreach ($enabledLanguages as $code => $language)
                <a @class(['active' => $code === $currentCode]) href="{{ route('language.switch', ['locale' => $code, 'return' => request()->getRequestUri()]) }}" lang="{{ $code }}" hreflang="{{ $code }}" @if ($code === $currentCode) aria-current="true" @endif data-no-navigate>
                    <span>{{ $language['native_name'] }}</span>
                    <small>{{ strtoupper($code) }}</small>
                </a>
            @endforeach
        </div>
    </details>
    @once
        <script src="{{ asset('assets/js/language-menu.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
    @endonce
@endif
