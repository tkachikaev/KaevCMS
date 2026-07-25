@csrf
@if ($newsItem->exists)
    @method('PUT')
    <input type="hidden" name="news_id" value="{{ $newsItem->id }}">
@endif
<input type="hidden" name="preview_locale" value="{{ old('preview_locale', $defaultLocale) }}" data-preview-locale>

<div class="editor-grid">
    <div class="editor-main">
        <section class="form-card translation-editor" data-locale-tabs>
            <div class="settings-card-heading">
                <div>
                    <h2>{{ __('News content') }}</h2>
                    <p>{{ __('The default language requires a title and text. Empty translations use the fallback language on the website.') }}</p>
                </div>
            </div>

            <div class="translation-tabs" role="tablist" aria-label="{{ __('News language') }}">
                @foreach ($languages as $code => $language)
                    @php($hasContent = trim((string) ($translations[$code]['title'] ?? '')) !== '')
                    <button type="button" role="tab" @class(['translation-tab', 'active' => $code === $defaultLocale, 'complete' => $hasContent]) data-locale-tab="{{ $code }}" aria-selected="{{ $code === $defaultLocale ? 'true' : 'false' }}">
                        <span class="translation-tab-label">{{ $language['native_name'] }}</span>
                        @if ($code === $defaultLocale)<span class="translation-tab-default">{{ __('Default locale marker') }}</span>@endif
                    </button>
                @endforeach
            </div>

            @foreach ($languages as $locale => $language)
                @include('admin.news._translation-editor', ['locale' => $locale])
            @endforeach
        </section>
    </div>

    <aside class="editor-sidebar">
        <section class="form-card">
            <h2>{{ __('Preview image') }}</h2>
            <div class="cover-upload" data-cover-upload data-preview-alt="{{ __('Selected image preview') }}">
                <div class="cover-preview {{ $newsItem->coverUrl() ? 'has-image' : '' }}" data-cover-preview>
                    @if ($newsItem->coverUrl())
                        <img src="{{ $newsItem->coverUrl() }}" alt="{{ __('Current preview image') }}">
                    @else
                        <span>{{ __('No image selected') }}</span>
                    @endif
                </div>
                <label class="button button-secondary cover-select" for="cover_image">{{ __('Choose image') }}</label>
                <input id="cover_image" name="cover_image" type="file" accept="image/jpeg,image/png,image/webp" data-cover-input>
                <input type="hidden" name="remove_cover_image" value="0">
                @if ($newsItem->coverUrl())
                    <label class="remove-cover-row">
                        <input type="checkbox" name="remove_cover_image" value="1" data-cover-remove>
                        <span>{{ __('Remove current image') }}</span>
                    </label>
                @endif
                <small>{{ __('JPG, PNG or WebP, up to 5 MB. Recommended aspect ratio: 16:9.') }}</small>
            </div>
        </section>

        <section class="form-card">
            <h2>{{ __('Publication') }}</h2>
            <input type="hidden" name="is_published" value="0">
            <label class="switch-row" for="is_published">
                <input id="is_published" name="is_published" type="checkbox" value="1" @checked((bool) old('is_published', $newsItem->is_published))>
                <span>
                    <strong>{{ __('Publish news') }}</strong>
                    <small>{{ __('Without this option the news will be saved as a draft.') }}</small>
                </span>
            </label>
            <div class="form-group compact">
                <label for="published_at">{{ __('Date and time') }}</label>
                <input id="published_at" name="published_at" type="datetime-local" value="{{ old('published_at', $newsItem->published_at?->format('Y-m-d\TH:i')) }}">
                <small>{{ __('A future date creates a scheduled publication.') }}</small>
            </div>
        </section>

        @if ($newsItem->exists)
            <section class="form-card form-card-muted">
                <h2>{{ __('News addresses') }}</h2>
                @foreach ($languages as $code => $language)
                    @if ($newsItem->hasTranslation($code))
                        <code>/{{ $code }}/news/{{ $newsItem->slugFor($code) }}</code>
                    @endif
                @endforeach
                <p>{{ __('Addresses are generated automatically and remain stable when titles are edited.') }}</p>
            </section>
        @endif
    </aside>
</div>

<div class="admin-actions-panel editor-actions">
    <a wire:navigate class="button button-secondary" href="{{ route('admin.news.index') }}">{{ __('Cancel') }}</a>
    <button class="button button-secondary" type="submit" formaction="{{ route('admin.news.preview') }}" formmethod="POST" formtarget="_blank" data-news-preview>{{ __('Preview') }}</button>
    <button class="button button-primary" type="submit">{{ $newsItem->exists ? __('Save changes') : __('Create news') }}</button>
</div>
