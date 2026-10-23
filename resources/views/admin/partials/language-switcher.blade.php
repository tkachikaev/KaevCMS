{{-- Interface language menu: the current code opens a list of languages by their own names. --}}
@if (count($enabledLanguages ?? []) > 1)
    @php
        $currentCode = app()->getLocale();
        $currentName = $enabledLanguages[$currentCode]['native_name'] ?? strtoupper($currentCode);
    @endphp
    <details class="admin-language-menu" data-language-menu>
        <summary aria-label="{{ __('Switch interface language') }}: {{ $currentName }}">
            <span>{{ strtoupper($currentCode) }}</span>
            <svg class="admin-language-menu-chevron" width="12" height="12" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </summary>
        <div class="admin-language-menu-list">
            @foreach ($enabledLanguages as $code => $language)
                <form method="POST" action="{{ route('admin.language.switch', ['locale' => $code]) }}">
                    @csrf
                    <button type="submit" @class(['active' => $code === $currentCode]) lang="{{ $code }}" @if ($code === $currentCode) aria-current="true" @endif>
                        <span>{{ $language['native_name'] }}</span>
                        <small>{{ strtoupper($code) }}</small>
                    </button>
                </form>
            @endforeach
        </div>
    </details>
    @once
        <script src="{{ asset('assets/js/language-menu.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
    @endonce
@endif
