@extends('admin.layouts.panel')
@section('title', __('Mail delivery'))
@section('description', __('Choose and verify how automatic account emails are sent.'))
@section('content')
@include('admin.settings._mail_tabs')

@if($appUrlMismatch)
    <div class="notice notice-warning">
        <p><strong>{{ __('APP_URL does not match the address currently open in the browser.') }}</strong></p>
        <p>{{ __('Queued emails use APP_URL for verification and password reset links. Check the value before using asynchronous delivery.') }}</p>
        <code>{{ $configuredUrl }}</code>
        <span>≠</span>
        <code>{{ $currentUrl }}</code>
    </div>
@endif

<section class="form-card mail-delivery-card">
    <div class="mail-delivery-heading">
        <div>
            <h2>{{ __('Delivery mode') }}</h2>
            <p>{{ __('Choose how automatic account emails are sent.') }}</p>
        </div>
        <span class="field-info" tabindex="0" data-tooltip="{{ __('Asynchronous modes are checked before activation. If the test fails, synchronous delivery remains active.') }}" aria-label="{{ __('About delivery modes') }}">i</span>
    </div>

    <form method="POST" action="{{ route('admin.settings.mail.delivery-mode.update') }}">
        @csrf
        @method('PUT')
        <div class="mail-delivery-options">
            <label class="mail-delivery-option">
                <input type="radio" name="delivery_mode" value="sync" @checked(old('delivery_mode', $settings['delivery_mode']) === 'sync')>
                <span><strong>{{ __('Synchronous') }}</strong><small>{{ __('The email is sent during the request. No additional server setup is required.') }}</small></span>
            </label>
            <label class="mail-delivery-option">
                <input type="radio" name="delivery_mode" value="background" @checked(old('delivery_mode', $settings['delivery_mode']) === 'background')>
                <span><strong>{{ __('Asynchronous') }}</strong><small>{{ __('Laravel sends the email after the response in a separate process. A permanent queue worker is not required.') }}</small></span>
            </label>
            <label class="mail-delivery-option">
                <input type="radio" name="delivery_mode" value="database" @checked(old('delivery_mode', $settings['delivery_mode']) === 'database')>
                <span><strong>{{ __('Asynchronous with database queue') }}</strong><small>{{ __('Emails are stored in the database and retried after temporary failures. Laravel Scheduler or a queue worker must be running.') }}</small></span>
            </label>
        </div>

        <div class="mail-delivery-description">
            <strong>{{ __('How the modes differ') }}</strong>
            <p>{{ __('Synchronous is the simplest option. Asynchronous responds faster. The database queue additionally preserves pending emails across restarts.') }}</p>
            <p>{{ __('When an asynchronous mode is selected for the first time, KaevCMS runs a real capability test before enabling it.') }}</p>
        </div>

        @error('delivery_mode')<p class="form-error">{{ $message }}</p>@enderror
        <button class="button button-primary" type="submit">{{ __('Save delivery mode') }}</button>
    </form>

    <div class="mail-delivery-probes">
        @foreach([
            'background' => [
                'title' => __('Asynchronous'),
                'status' => $settings['background_probe_status'],
                'supported' => $settings['background_supported'],
                'timeout' => 20,
            ],
            'database' => [
                'title' => __('Asynchronous with database queue'),
                'status' => $settings['database_probe_status'],
                'supported' => $settings['database_supported'],
                'timeout' => 100,
            ],
        ] as $mode => $probe)
            <div
                class="mail-delivery-probe"
                data-mail-delivery-probe
                data-probe-mode="{{ $mode }}"
                data-probe-status="{{ $probe['status'] }}"
                data-probe-status-url="{{ route('admin.settings.mail.delivery-probe.status', ['delivery_mode' => $mode]) }}"
                data-probe-max-attempts="{{ $probe['timeout'] }}"
            >
                <div class="mail-delivery-probe-heading">
                    <strong>{{ $probe['title'] }}</strong>
                    @if($probe['status'] === 'passed')
                        <span class="status-badge status-badge-success">{{ __('Supported') }}</span>
                    @elseif($probe['status'] === 'pending')
                        <span class="status-badge status-badge-warning">{{ __('Checking') }}</span>
                    @elseif($probe['status'] === 'failed')
                        <span class="status-badge status-badge-danger">{{ __('Not supported') }}</span>
                    @else
                        <span class="status-badge status-badge-muted">{{ __('Not checked') }}</span>
                    @endif
                </div>

                <p>
                    @if($probe['status'] === 'passed')
                        {{ __('The server completed the selected mode test successfully.') }}
                    @elseif($probe['status'] === 'pending' && $mode === 'database')
                        {{ __('Waiting for Laravel Scheduler or a queue worker to process the database test job...') }}
                    @elseif($probe['status'] === 'pending')
                        {{ __('Waiting for the asynchronous process to confirm execution...') }}
                    @elseif($probe['status'] === 'failed' && $mode === 'database')
                        {{ __('The database queue test was not processed. Check Laravel Scheduler or the queue worker.') }}
                    @elseif($probe['status'] === 'failed')
                        {{ __('The asynchronous process did not start. Synchronous delivery remains available.') }}
                    @elseif($mode === 'database')
                        {{ __('The test may take up to one minute and does not send an email.') }}
                    @else
                        {{ __('The test does not send an email.') }}
                    @endif
                </p>

                <form method="POST" action="{{ route('admin.settings.mail.delivery-probe') }}">
                    @csrf
                    <input type="hidden" name="delivery_mode" value="{{ $mode }}">
                    <button class="button button-secondary" type="submit" @disabled($probe['status'] === 'pending')>{{ __('Check mode') }}</button>
                </form>
            </div>
        @endforeach
    </div>
</section>

@endsection

@push('scripts')
<script src="{{ asset('assets/admin/js/mail-delivery.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
