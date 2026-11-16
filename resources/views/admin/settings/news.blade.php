@extends('admin.layouts.panel')

@section('title', __('News'))
@section('description', __('How news appears on the public website.'))

@section('content')
@include('admin.settings._system_tabs')

<form class="settings-form" method="POST" action="{{ route('admin.settings.news.update') }}" enctype="multipart/form-data">
    @csrf
    @method('PUT')

    <div class="settings-grid">
        <section class="form-card">
            <div class="settings-card-heading">
                <div>
                    <h2>{{ __('News on the website') }}</h2>
                    <p>{{ __('Only published news is counted. Scheduled news appears when its time comes.') }}</p>
                </div>
            </div>

            <div class="form-group">
                <label for="home_limit">{{ __('News on the home page') }}</label>
                <input id="home_limit" name="home_limit" type="number" inputmode="numeric" required
                    min="{{ \App\Services\NewsDisplaySettings::HOME_LIMIT[1] }}" max="{{ \App\Services\NewsDisplaySettings::HOME_LIMIT[2] }}"
                    value="{{ old('home_limit', $settings['home_limit']) }}">
                <small>{{ __('Latest published news shown on the home page, from :min to :max.', ['min' => \App\Services\NewsDisplaySettings::HOME_LIMIT[1], 'max' => \App\Services\NewsDisplaySettings::HOME_LIMIT[2]]) }}</small>
            </div>

            <div class="form-group">
                <label for="per_page">{{ __('News per page') }}</label>
                <input id="per_page" name="per_page" type="number" inputmode="numeric" required
                    min="{{ \App\Services\NewsDisplaySettings::PER_PAGE[1] }}" max="{{ \App\Services\NewsDisplaySettings::PER_PAGE[2] }}"
                    value="{{ old('per_page', $settings['per_page']) }}">
                <small>{{ __('News items on one page of the news section, from :min to :max.', ['min' => \App\Services\NewsDisplaySettings::PER_PAGE[1], 'max' => \App\Services\NewsDisplaySettings::PER_PAGE[2]]) }}</small>
            </div>
        </section>

        <aside class="settings-media-column">
            <section class="form-card">
                <h2>{{ __('Default cover') }}</h2>
                <div class="settings-image-upload" data-settings-image>
                    <div @class(['settings-image-preview', 'has-image' => $settings['default_cover_url']]) data-settings-preview data-preview-alt="{{ __('Selected image preview') }}">
                        @if ($settings['default_cover_url'])
                            <img src="{{ $settings['default_cover_url'] }}" alt="{{ __('Current default cover') }}" data-settings-preview-image>
                        @else
                            <span data-settings-preview-empty>{{ __('No cover uploaded') }}</span>
                        @endif
                    </div>

                    <input id="default_cover" name="default_cover" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" data-settings-file>
                    <button class="button button-secondary" type="button" data-settings-file-select>{{ __('Choose cover') }}</button>

                    <small>{{ __('Shown for news without its own image. JPG, PNG or WebP, up to 5 MB. Without it the theme shows its logo.') }}</small>

                    @if ($settings['default_cover_url'])
                        @include('admin.settings._image-remove', ['name' => 'remove_default_cover'])
                    @endif
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
@endpush
