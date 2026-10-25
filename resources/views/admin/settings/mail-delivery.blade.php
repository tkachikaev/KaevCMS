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

        @error('delivery_mode')<p class="admin-field-error">{{ $message }}</p>@enderror
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
                        {{ __('The database queue test was not processed.') }}
                    @elseif($probe['status'] === 'failed')
                        {{ __('The asynchronous process did not start. Synchronous delivery remains available.') }}
                    @elseif($mode === 'database')
                        {{ __('The test may take up to one minute and does not send an email.') }}
                    @else
                        {{ __('The test does not send an email.') }}
                    @endif
                </p>

                @if($probe['status'] === 'failed' && ! empty($explanations[$mode]))
                    <div class="notice notice-warning mail-delivery-explanation">
                        <p><strong>{{ __('Why') }}:</strong> {{ $explanations[$mode]['reason'] }}</p>
                        <p><strong>{{ __('What to do') }}:</strong> {{ $explanations[$mode]['solution'] }}</p>
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.settings.mail.delivery-probe') }}">
                    @csrf
                    <input type="hidden" name="delivery_mode" value="{{ $mode }}">
                    <button class="button button-secondary" type="submit" @disabled($probe['status'] === 'pending')>{{ __('Check mode') }}</button>
                </form>
            </div>
        @endforeach
    </div>
</section>

<section class="form-card mail-delivery-card mail-cron-card">
    <div class="mail-delivery-heading">
        <div>
            <h2>{{ __('Cron for the database queue') }}</h2>
            <p>{{ __('The database queue needs the hosting to run one command every minute. Everything below is filled in from this installation.') }}</p>
        </div>
    </div>

    @if($hosting['pending_mail_jobs'] > 0)
        <div class="notice notice-warning">
            <p>{{ trans_choice(':count email is waiting in the queue and is sent only while cron runs.|:count emails are waiting in the queue and are sent only while cron runs.', $hosting['pending_mail_jobs'], ['count' => $hosting['pending_mail_jobs']]) }}</p>
        </div>
    @endif

    <dl class="mail-cron-facts">
        <div>
            <dt>{{ __('PHP for cron') }}</dt>
            <dd>
                @if($hosting['php_cli'])
                    <code>{{ $hosting['php_cli'] }}</code> (PHP {{ $hosting['php_cli_version'] }})
                @else
                    {{ __('Not detected automatically. Use the full path to PHP :version or newer from the hosting panel instead of "php".', ['version' => '8.3']) }}
                @endif
            </dd>
        </div>
        <div>
            <dt>{{ __('Website PHP') }}</dt>
            <dd>PHP {{ $hosting['web_php_version'] }}</dd>
        </div>
        <div>
            <dt>{{ __('Artisan file') }}</dt>
            <dd>
                <code>{{ $hosting['artisan_path'] }}</code>
                @unless($hosting['artisan_readable'])<span class="status-badge status-badge-danger">{{ __('Not readable') }}</span>@endunless
                @if($hosting['split_install'])<small>{{ __('The core is installed outside the public directory: use this path, not a path inside the site folder.') }}</small>@endif
            </dd>
        </div>
    </dl>

    <label class="mail-cron-label" for="mail-cron-command">{{ __('Cron line (every minute)') }}</label>
    <input id="mail-cron-command" class="mail-cron-command" type="text" readonly value="{{ $hosting['cron_command'] }}">
    <label class="mail-cron-label" for="mail-cron-command-panel">{{ __('Command for panels that have a separate schedule field (Plesk and similar)') }}</label>
    <input id="mail-cron-command-panel" class="mail-cron-command" type="text" readonly value="{{ $hosting['cron_command_panel'] }}">
    @if($hosting['panel_hint'])
        <p class="mail-cron-hint">{{ $hosting['panel_hint'] }}</p>
    @endif
    @if($hosting['php_cli'] === null || $hosting['php_cli_candidates'] !== [])
        @if($hosting['php_cli_candidates'] !== [])
            <p class="mail-cron-hint">{{ __('PHP binaries found on this server') }}:
                @foreach($hosting['php_cli_candidates'] as $candidate)
                    <code>{{ $candidate['binary'] }}</code> ({{ $candidate['version'] ?? __('Unknown') }}){{ $loop->last ? '' : ',' }}
                @endforeach
            </p>
        @endif
    @endif

    <div
        class="mail-delivery-probe"
        data-mail-delivery-probe
        data-probe-mode="cron"
        data-probe-status="{{ $cronCheck['status'] }}"
        data-probe-status-url="{{ route('admin.settings.mail.cron-check.status') }}"
        data-probe-max-attempts="140"
    >
        <div class="mail-delivery-probe-heading">
            <strong>{{ __('Is cron really running?') }}</strong>
            @if($cronCheck['status'] === 'passed')
                <span class="status-badge status-badge-success">{{ __('Working') }}</span>
            @elseif($cronCheck['status'] === 'pending')
                <span class="status-badge status-badge-warning">{{ __('Checking') }}</span>
            @elseif($cronCheck['status'] === 'failed')
                <span class="status-badge status-badge-danger">{{ __('Not working') }}</span>
            @else
                <span class="status-badge status-badge-muted">{{ __('Not checked') }}</span>
            @endif
        </div>
        <p>
            @if($cronCheck['status'] === 'passed')
                {{ __('Cron ran the Scheduler after the check started, so the database queue will be processed.') }}
            @elseif($cronCheck['status'] === 'pending')
                {{ __('Waiting for the next cron run (up to about two minutes)...') }}
            @elseif($cronCheck['status'] === 'failed')
                {{ __('Cron did not run the Scheduler within two minutes. Check the cron line above and the PHP path.') }}
            @else
                {{ __('The check waits for the next real cron run. It does not send an email.') }}
            @endif
            @if($cronCheck['last_run_at'])
                <small>{{ __('Last Scheduler run: :time', ['time' => IlluminateSupportCarbon::parse($cronCheck['last_run_at'])->diffForHumans()]) }}</small>
            @endif
        </p>
        <form method="POST" action="{{ route('admin.settings.mail.cron-check') }}">
            @csrf
            <button class="button button-secondary" type="submit" @disabled($cronCheck['status'] === 'pending')>{{ __('Check cron') }}</button>
        </form>
    </div>
</section>

@endsection

@push('scripts')
<script src="{{ asset('assets/admin/js/mail-delivery.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
