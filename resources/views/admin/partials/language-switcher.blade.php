@if (count($enabledLanguages ?? []) > 1)
    <div class="admin-language-switcher" aria-label="{{ __('Switch interface language') }}">
        @foreach ($enabledLanguages as $code => $language)
            <form method="POST" action="{{ route('admin.language.switch', ['locale' => $code]) }}">
                @csrf
                <button type="submit" @class(['active' => app()->getLocale() === $code]) lang="{{ $code }}" title="{{ $language['native_name'] }}">
                    {{ strtoupper($code) }}
                </button>
            </form>
        @endforeach
    </div>
@endif
