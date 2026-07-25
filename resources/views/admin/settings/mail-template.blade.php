@extends('admin.layouts.panel')

@section('title', __('Mail settings'))
@section('description', __('Ready-to-use system email templates with safe text editing.'))

@section('content')
@include('admin.settings._mail_tabs')

<nav class="translation-tabs standalone" aria-label="{{ __('Template language') }}">
    @foreach ($languages as $code => $language)
        <a wire:navigate @class(['translation-tab', 'active' => $templateLocale === $code]) href="{{ route('admin.settings.mail.template', ['template' => $template['key'], 'locale' => $code]) }}">
            {{ $language['native_name'] }}
        </a>
    @endforeach
</nav>

<div class="mail-template-heading">
    <div>
        <div class="mail-template-title-row">
            <h2>{{ $template['title'] }}</h2>
            @if ($template['customized'])
                <span class="status-badge status-badge-success">{{ __('Customized') }}</span>
            @else
                <span class="status-badge status-badge-muted">{{ __('Default') }}</span>
            @endif
        </div>
        <p>{{ $template['description'] }}</p>
    </div>

    <form method="POST" action="{{ route('admin.settings.mail.template.reset', ['template' => $template['key']]) }}" data-mail-template-reset data-reset-confirm="{{ __('Restore the default template? Current changes will be removed.') }}">
        @csrf
        <input type="hidden" name="locale" value="{{ $templateLocale }}">
        <button class="button button-secondary" type="submit">{{ __('Restore default template') }}</button>
    </form>
</div>

<div class="mail-template-layout" data-mail-template-editor data-requires-action="{{ $template['requires_action'] ? '1' : '0' }}" data-demo-expires="{{ __('60 minutes') }}">
    <form class="settings-form mail-template-form" method="POST" action="{{ route('admin.settings.mail.template.update', ['template' => $template['key']]) }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="locale" value="{{ $templateLocale }}">

        <section class="form-card">
            <h2>{{ __('Email content') }}</h2>
            <p class="mail-template-help">{{ __('No HTML is required: the CMS creates the responsive layout. Leave an empty line to start a new paragraph.') }}</p>

            <div class="form-group">
                <label for="subject">{{ __('Email subject') }}</label>
                <input id="subject" name="subject" type="text" maxlength="200" required value="{{ old('subject', $template['subject']) }}" data-mail-template-field="subject">
            </div>

            <div class="form-group">
                <label for="header">{{ __('Name in the email header') }}</label>
                <input id="header" name="header" type="text" maxlength="150" required value="{{ old('header', $template['header']) }}" data-mail-template-field="header">
                <small>{{ __('This text is shown in the dark header of the standard email layout.') }}</small>
            </div>

            <div class="form-group">
                <label for="heading">{{ __('Heading') }}</label>
                <input id="heading" name="heading" type="text" maxlength="150" required value="{{ old('heading', $template['heading']) }}" data-mail-template-field="heading">
            </div>

            <div class="form-group">
                <label for="body">{{ __('Main text') }}</label>
                <textarea id="body" name="body" rows="8" maxlength="5000" required data-mail-template-field="body">{{ old('body', $template['body']) }}</textarea>
                <small>{!! __('You may use <code>**bold text**</code> and <code>*italic*</code>. HTML tags are blocked.') !!}</small>
            </div>

            @if ($template['requires_action'])
                <div class="form-group">
                    <label for="action_text">{{ __('Button text') }}</label>
                    <input id="action_text" name="action_text" type="text" maxlength="100" required value="{{ old('action_text', $template['action_text']) }}" data-mail-template-field="action_text">
                    <small>{{ __('The button URL is generated automatically and cannot be edited.') }}</small>
                </div>
            @else
                <input type="hidden" name="action_text" value="">
            @endif

            <div class="form-group">
                <label for="footer">{{ __('Additional text') }}</label>
                <textarea id="footer" name="footer" rows="5" maxlength="3000" data-mail-template-field="footer">{{ old('footer', $template['footer']) }}</textarea>
                <small>{{ __('Usually contains link expiry or security information.') }}</small>
            </div>
        </section>

        <section class="form-card mail-template-variables">
            <h2>{{ __('Available variables') }}</h2>
            <p>{{ __('Select a variable to insert it into the last active field.') }}</p>
            <div class="mail-template-variable-list">
                @foreach ($template['variables'] as $variable)
                    @php($placeholder = chr(123).chr(123).$variable.chr(125).chr(125))
                    <button class="mail-template-variable" type="button" data-template-variable="{{ $variable }}">{{ $placeholder }}</button>
                @endforeach
            </div>
        </section>

        <div class="admin-actions-panel settings-actions settings-actions-inside">
            <button class="button button-primary" type="submit">{{ __('Save template') }}</button>
        </div>
    </form>

    <aside class="mail-template-side">
        <section class="form-card mail-preview-card">
            <div class="mail-preview-heading">
                <div>
                    <h2>{{ __('Preview') }}</h2>
                    <p>{{ __('Demo values are used only for the preview.') }}</p>
                </div>
                <span class="status-badge status-badge-muted">Email</span>
            </div>

            <div class="mail-preview-subject">
                <span>{{ __('Subject') }}</span>
                <strong data-mail-preview="subject">{{ $preview['subject'] }}</strong>
            </div>

            <div class="mail-preview-window">
                <div class="mail-preview-brand" data-mail-preview="header">{{ $preview['header'] }}</div>
                <div class="mail-preview-content">
                    <h3 data-mail-preview="heading">{{ $preview['heading'] }}</h3>
                    <div class="mail-preview-copy" data-mail-preview="body">{{ $preview['body'] }}</div>
                    @if ($template['requires_action'])
                        <span class="mail-preview-button" data-mail-preview="action_text">{{ $preview['action_text'] }}</span>
                    @endif
                    <div class="mail-preview-footer" data-mail-preview="footer">{{ $preview['footer'] }}</div>
                    <div class="mail-preview-signature">{{ __('Regards, :site team', ['site' => site_name($templateLocale)]) }}</div>
                </div>
            </div>
        </section>

        <form class="settings-form" method="POST" action="{{ route('admin.settings.mail.template.test', ['template' => $template['key']]) }}">
            @csrf
            <input type="hidden" name="locale" value="{{ $templateLocale }}">
            <section class="form-card mail-template-test-card">
                <h2>{{ __('Test delivery') }}</h2>
                <p>{{ __('The saved template is sent with demo values and a safe example URL.') }}</p>

                @if (! $mailSettings['ready'])
                    <div class="notice notice-warning"><p>{{ __('Verify SMTP on the Connection tab first.') }}</p></div>
                @endif

                <div class="form-group">
                    <label for="test_email">{{ __('Recipient address') }}</label>
                    <input id="test_email" name="test_email" type="email" maxlength="255" required value="{{ old('test_email', $mailSettings['admin_email']) }}" placeholder="admin@example.com">
                </div>

                <button class="button button-secondary" type="submit" @disabled(! $mailSettings['ready'])>{{ __('Send test template') }}</button>
            </section>
        </form>
    </aside>
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets/admin/js/mail-templates.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
