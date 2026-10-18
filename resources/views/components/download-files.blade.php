@props(['page'])
@once
    @push('head')
        <link rel="stylesheet" href="{{ asset('assets/css/downloads.css') }}?v={{ cms_version() }}&ui=managed-downloads" data-navigate-track>
    @endpush
@endonce
<div class="download-files" aria-label="{{ __('Game downloads') }}">
    @foreach ([['download_client_url', 'Game client', 'Install the game client before applying the server patch.', 'Download client'], ['download_patch_url', 'Server patch', 'Extract the patch into the game folder and replace existing files.', 'Download patch']] as [$field, $title, $description, $button])
        @php($fileUrl = \App\Services\Pages\DownloadPages::safeUrl($page->getAttribute($field)))
        <section class="download-file panel" data-download-file="{{ $field }}">
            <span class="download-file-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m-5-5 5 5 5-5M5 16v5h14v-5" /></svg></span>
            <h2>{{ __($title) }}</h2>
            <p>{{ __($description) }}</p>
            @if ($fileUrl)
                <a class="button button-gold" href="{{ $fileUrl }}" rel="noopener noreferrer" target="_blank">{{ __($button) }} <span aria-hidden="true">↗</span></a>
            @else
                <button class="button button-ghost" type="button" disabled>{{ __('Link coming soon') }}</button>
            @endif
        </section>
    @endforeach
</div>
