@csrf
@if ($pageItem->exists)
    @method('PUT')
    <input type="hidden" name="page_id" value="{{ $pageItem->id }}">
@endif
<input type="hidden" name="preview_locale" value="{{ old('preview_locale', $defaultLocale) }}" data-preview-locale>

<div class="editor-grid">
    <div class="editor-main">
        <section class="form-card translation-editor" data-locale-tabs>
            <div class="settings-card-heading">
                <div>
                    <h2>{{ __('Page content') }}</h2>
                    <p>{{ __('The default language requires a title and text. Empty translations use the fallback language on the website.') }}</p>
                </div>
            </div>

            <div class="translation-tabs" role="tablist" aria-label="{{ __('Page language') }}">
                @foreach ($languages as $code => $language)
                    @php($hasContent = trim((string) ($translations[$code]['title'] ?? '')) !== '')
                    <button type="button" role="tab" @class(['translation-tab', 'active' => $code === $defaultLocale, 'complete' => $hasContent]) data-locale-tab="{{ $code }}" aria-selected="{{ $code === $defaultLocale ? 'true' : 'false' }}">
                        <span class="translation-tab-label">{{ $language['native_name'] }}</span>
                        @if ($code === $defaultLocale)<span class="translation-tab-default">{{ __('Default locale marker') }}</span>@endif
                    </button>
                @endforeach
            </div>

            @foreach ($languages as $locale => $language)
                @include('admin.pages._translation-editor', ['locale' => $locale])
            @endforeach
        </section>
    </div>

    <aside class="editor-sidebar">
        <section class="form-card">
            <h2>{{ __('Publication') }}</h2>
            <input type="hidden" name="is_published" value="0">
            <label class="switch-row" for="is_published">
                <input id="is_published" name="is_published" type="checkbox" value="1" @checked((bool) old('is_published', $pageItem->is_published))>
                <span>
                    <strong>{{ __('Publish page') }}</strong>
                    <small>{{ __('Without this option the page is saved as a draft and is unavailable to visitors.') }}</small>
                </span>
            </label>
        </section>

        <section class="form-card">
            <h2>{{ __('Navigation') }}</h2>
            <input type="hidden" name="show_in_header" value="0">
            <label class="switch-row" for="show_in_header">
                <input id="show_in_header" name="show_in_header" type="checkbox" value="1" @checked((bool) old('show_in_header', $pageItem->show_in_header))>
                <span>
                    <strong>{{ __('Show in header') }}</strong>
                    <small>{{ __('Add the page to the main website navigation.') }}</small>
                </span>
            </label>

            <input type="hidden" name="show_in_footer" value="0">
            <label class="switch-row switch-row-spaced" for="show_in_footer">
                <input id="show_in_footer" name="show_in_footer" type="checkbox" value="1" @checked((bool) old('show_in_footer', $pageItem->show_in_footer))>
                <span>
                    <strong>{{ __('Show in footer') }}</strong>
                    <small>{{ __('Add the page to the documents section in the footer.') }}</small>
                </span>
            </label>

            <div class="form-group compact">
                <label for="sort_order">{{ __('Page sort order') }}</label>
                <input id="sort_order" name="sort_order" type="number" min="0" max="100000" value="{{ old('sort_order', $pageItem->sort_order ?? 100) }}" required>
                <small>{{ __('Pages with a lower number are displayed first.') }}</small>
            </div>
        </section>

        @if ($pageItem->exists)
            <section class="form-card form-card-muted">
                <h2>{{ __('Page addresses') }}</h2>
                <code>/pages/{{ $pageItem->slug }}</code>
                @foreach ($languages as $code => $language)
                    @if ($pageItem->hasTranslation($code))
                        <code>/{{ $code }}/pages/{{ $pageItem->slugFor($code) }}</code>
                    @endif
                @endforeach
                <p>{{ __('The address can be changed in each language tab. Old links will stop working after a change.') }}</p>
            </section>
        @endif
    </aside>
</div>

<div class="admin-actions-panel editor-actions">
    <a wire:navigate class="button button-secondary" href="{{ route('admin.pages.index') }}">{{ __('Cancel') }}</a>
    <button class="button button-secondary" type="submit" formaction="{{ route('admin.pages.preview') }}" formmethod="POST" formtarget="_blank" data-content-preview>{{ __('Preview') }}</button>
    <button class="button button-primary" type="submit">{{ $pageItem->exists ? __('Save changes') : __('Create page') }}</button>
</div>
