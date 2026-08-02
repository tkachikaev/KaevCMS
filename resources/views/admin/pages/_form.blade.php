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
            <x-admin.toggle
                id="is_published"
                name="is_published"
                :label="__('Publish page')"
                :hint="__('Without this option the page is saved as a draft and is unavailable to visitors.')"
                :checked="(bool) old('is_published', $pageItem->is_published)"
                compact
            />
        </section>

        <section class="form-card">
            <h2>{{ __('Navigation') }}</h2>
            <x-admin.toggle
                id="show_in_header"
                name="show_in_header"
                :label="__('Show in header')"
                :hint="__('Add the page to the main website navigation.')"
                :checked="(bool) old('show_in_header', $pageItem->show_in_header)"
                compact
            />

            <x-admin.toggle
                id="show_in_footer"
                name="show_in_footer"
                :label="__('Show in footer')"
                :hint="__('Add the page to the documents section in the footer.')"
                :checked="(bool) old('show_in_footer', $pageItem->show_in_footer)"
                compact
            />

            <x-admin.field for="sort_order" name="sort_order" :label="__('Page sort order')" :hint="__('Pages with a lower number are displayed first.')" compact>
                <input id="sort_order" name="sort_order" type="number" min="0" max="100000" value="{{ old('sort_order', $pageItem->sort_order ?? 100) }}" required @if($errors->has('sort_order')) aria-invalid="true" @endif>
            </x-admin.field>
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
