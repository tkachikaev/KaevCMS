@extends('admin.layouts.panel')
@section('title', $link->exists ? __('Edit navigation link') : __('Add link'))
@section('description', __('Custom links appear in the selected areas of both public themes.'))
@section('content')
<form method="POST" action="{{ $link->exists ? route('admin.navigation.update', $link) : route('admin.navigation.store') }}" class="content-editor">
    @csrf
    @if ($link->exists)
        @method('PUT')
    @endif
    <div class="editor-grid">
        <div class="editor-main">
            <section class="form-card">
                <div class="settings-card-heading"><div>
                    <h2>{{ __('Link details') }}</h2>
                    <p>{{ __('Give the link a title in each language. Empty translations use the default language.') }}</p>
                </div></div>
                @foreach ($languages as $locale => $language)
                    <x-admin.field :for="'title_'.$locale" :name="'translations.'.$locale.'.title'" :label="__('Link title (:language)', ['language' => $language['native_name']])">
                        <input id="title_{{ $locale }}" name="translations[{{ $locale }}][title]" type="text" maxlength="160" value="{{ old('translations.'.$locale.'.title', $translations[$locale] ?? '') }}" @if ($locale === $defaultLocale) required @endif @if ($errors->has('translations.'.$locale.'.title')) aria-invalid="true" @endif>
                    </x-admin.field>
                @endforeach
                <x-admin.field for="url" name="url" :label="__('Link URL')" :hint="__('Examples: /forum/ or https://discord.gg/example')">
                    <input id="url" name="url" type="text" maxlength="2048" value="{{ old('url', $link->url) }}" required spellcheck="false" autocomplete="off" @if ($errors->has('url')) aria-invalid="true" @endif>
                </x-admin.field>
            </section>
        </div>
        <aside class="editor-sidebar">
            <section class="form-card">
                <h2>{{ __('Visibility') }}</h2>
                <x-admin.toggle id="is_active" name="is_active" :label="__('Enabled')" :hint="__('Disabled links are hidden from visitors.')" :checked="(bool) old('is_active', $link->is_active)" compact />
                <x-admin.toggle id="show_in_header" name="show_in_header" :label="__('Show in header')" :checked="(bool) old('show_in_header', $link->show_in_header)" compact />
                <x-admin.toggle id="show_in_footer" name="show_in_footer" :label="__('Show in footer')" :checked="(bool) old('show_in_footer', $link->show_in_footer)" compact />
                <x-admin.toggle id="open_new_tab" name="open_new_tab" :label="__('Open in new tab')" :checked="(bool) old('open_new_tab', $link->open_new_tab)" compact />
                <x-admin.field for="sort_order" name="sort_order" :label="__('Order')" :hint="__('Lower numbers are displayed first.')" compact>
                    <input id="sort_order" name="sort_order" type="number" min="0" max="100000" value="{{ old('sort_order', $link->sort_order ?? 100) }}" required @if ($errors->has('sort_order')) aria-invalid="true" @endif>
                </x-admin.field>
            </section>
        </aside>
    </div>
    <div class="admin-actions-panel editor-actions">
        <a wire:navigate class="button button-secondary" href="{{ route('admin.navigation.index') }}">{{ __('Cancel') }}</a>
        <button class="button button-primary" type="submit">{{ $link->exists ? __('Save changes') : __('Add link') }}</button>
    </div>
</form>
@endsection
