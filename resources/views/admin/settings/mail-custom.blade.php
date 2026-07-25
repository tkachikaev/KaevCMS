@extends('admin.layouts.panel')

@section('title', __('Send custom email'))
@section('description', __('Send one custom HTML email without changing the system templates.'))

@section('content')
@include('admin.settings._mail_tabs')

<div class="custom-mail-layout" data-custom-mail-editor>
    <form class="settings-form custom-mail-form" method="POST" action="{{ route('admin.settings.mail.custom.send') }}">
        @csrf

        <section class="form-card">
            <div class="custom-mail-heading">
                <div>
                    <h2>{{ __('Custom HTML email') }}</h2>
                    <p>{{ __('The message is sent to one recipient. It is not stored as a template and is not used for mass mailing.') }}</p>
                </div>
                <span class="status-badge status-badge-muted">HTML</span>
            </div>

            @if (! $mailSettings['ready'])
                <div class="notice notice-warning">
                    <p>{{ __('Verify SMTP on the Connection tab first.') }}</p>
                </div>
            @endif

            <div class="form-group">
                <label for="recipient">{{ __('Recipient address') }}</label>
                <input id="recipient" name="recipient" type="email" maxlength="255" required value="{{ old('recipient') }}" placeholder="player@example.com">
                @error('recipient')<p class="error-text">{{ $message }}</p>@enderror
            </div>

            <div class="form-group">
                <label for="custom_subject">{{ __('Email subject') }}</label>
                <input id="custom_subject" name="subject" type="text" maxlength="200" required value="{{ old('subject') }}" placeholder="{{ __('Server announcement') }}">
                @error('subject')<p class="error-text">{{ $message }}</p>@enderror
            </div>

            <div class="form-group">
                <label for="custom_html">{{ __('HTML code') }}</label>
                <textarea id="custom_html" name="html" rows="28" maxlength="200000" required spellcheck="false" data-custom-mail-html>{{ old('html', $exampleHtml) }}</textarea>
                <small>{{ __('Complete HTML documents, tables, images, links and inline CSS are supported. Scripts, forms, PHP, Blade and JavaScript event attributes are blocked.') }}</small>
                @error('html')<p class="error-text">{{ $message }}</p>@enderror
            </div>

            <div class="custom-mail-actions">
                <button class="button button-secondary" type="button" data-custom-mail-preview-button>{{ __('Update preview') }}</button>
                <button class="button button-primary" type="submit" @disabled(! $mailSettings['ready'])>{{ __('Send email') }}</button>
            </div>
        </section>
    </form>

    <aside class="custom-mail-preview-card form-card">
        <div class="custom-mail-heading">
            <div>
                <h2>{{ __('Preview') }}</h2>
                <p>{{ __('The preview runs in an isolated frame. The server performs another security check before sending.') }}</p>
            </div>
        </div>

        <iframe
            class="custom-mail-preview-frame"
            title="{{ __('Custom email preview') }}"
            sandbox
            referrerpolicy="no-referrer"
            data-custom-mail-preview
        ></iframe>
    </aside>
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets/admin/js/custom-mail.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
