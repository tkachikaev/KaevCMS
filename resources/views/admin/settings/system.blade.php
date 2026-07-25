@extends('admin.layouts.panel')

@section('title', __('System information'))
@section('description', __('Versions, environment and KaevCMS component status.'))

@section('content')
@include('admin.settings._system_tabs')

<section class="system-overview">
    <div>
        <span class="system-eyebrow">KaevCMS</span>
        <strong>{{ __('Version :version', ['version' => $system['cms']['version']]) }}</strong>
        <p>{!! __('The version is read from the <code>VERSION</code> file in the project root.') !!}</p>
        @if(auth('admin')->user()?->isOwner() === true)
            <a wire:navigate class="button button-primary system-version-update-button" href="{{ route('admin.settings.system.updates.index') }}">{{ __('Manage updates') }}</a>
        @endif
    </div>
    <div class="system-overview-actions">
        <a wire:navigate class="button button-secondary" href="{{ route('admin.settings.system') }}">{{ __('Refresh information') }}</a>
        <button
            class="button button-primary"
            type="button"
            data-copy-system-report
            data-copy-success="{{ __('Report copied.') }}"
            data-copy-error="{{ __('Could not copy the report.') }}"
        >{{ __('Copy report') }}</button>
    </div>
</section>

<div class="system-information-grid">
    <section class="form-card system-card">
        <h2>{{ __('Software') }}</h2>
        <dl class="system-definition-list">
            <div><dt>PHP</dt><dd>{{ $system['software']['php'] }}</dd></div>
            <div><dt>Laravel</dt><dd>{{ $system['software']['laravel'] }}</dd></div>
            <div><dt>Composer</dt><dd>{{ $system['software']['composer'] }}</dd></div>
            <div><dt>{{ __('Operating system') }}</dt><dd>{{ $system['software']['os'] }}</dd></div>
            <div><dt>{{ __('PHP architecture') }}</dt><dd>{{ $system['software']['architecture'] }}</dd></div>
            <div><dt>PHP SAPI</dt><dd><code>{{ $system['software']['sapi'] }}</code></dd></div>
            <div>
                <dt>{{ __('Password hash') }}</dt>
                <dd>
                    <code>{{ $system['security']['label'] }}</code>
                    @if($system['security']['driver'] === 'bcrypt' && ! $system['security']['argon2id_supported'])
                        <small class="system-definition-note">{{ __('Argon2id is not supported by the system.') }}</small>
                    @endif
                </dd>
            </div>
        </dl>
    </section>

    <section class="form-card system-card">
        <h2>{{ __('Laravel environment') }}</h2>
        <dl class="system-definition-list">
            <div><dt>{{ __('Environment') }}</dt><dd><code>{{ $system['environment']['name'] }}</code></dd></div>
            <div><dt>{{ __('Debug mode') }}</dt><dd><span class="status-badge {{ $system['environment']['debug'] ? 'status-badge-warning' : 'status-badge-success' }}">{{ $system['environment']['debug'] ? __('Enabled') : __('Disabled') }}</span></dd></div>
            <div><dt>{{ __('PHP time zone') }}</dt><dd>{{ $system['environment']['php_timezone'] }}</dd></div>
            <div><dt>{{ __('CMS time zone') }}</dt><dd>{{ $system['environment']['cms_timezone'] }}</dd></div>
            <div><dt>{{ __('Cache') }}</dt><dd><code>{{ $system['environment']['cache'] }}</code></dd></div>
            <div><dt>{{ __('Sessions') }}</dt><dd><code>{{ $system['environment']['session'] }}</code></dd></div>
            <div><dt>{{ __('Queues') }}</dt><dd><code>{{ $system['environment']['queue'] }}</code></dd></div>
            <div><dt>{{ __('Mail') }}</dt><dd><code>{{ $system['environment']['mail'] }}</code></dd></div>
            <div><dt>{{ __('Laravel logs') }}</dt><dd><code>{{ $system['environment']['logging'] }}</code></dd></div>
            <div>
                <dt>{{ __('Trusted proxies') }}</dt>
                <dd>
                    <span @class([
                        'status-badge',
                        'status-badge-warning' => $system['proxy']['trusts_all'] || $system['proxy']['invalid_count'] > 0,
                        'status-badge-success' => $system['proxy']['enabled'] && ! $system['proxy']['trusts_all'] && $system['proxy']['invalid_count'] === 0,
                        'status-badge-muted' => ! $system['proxy']['enabled'] && $system['proxy']['invalid_count'] === 0,
                    ])>
                        @if($system['proxy']['invalid_count'] > 0)
                            {{ __('Configuration warning') }}
                        @elseif($system['proxy']['trusts_all'])
                            {{ __('All proxies are trusted') }}
                        @elseif($system['proxy']['enabled'])
                            {{ __('Configured') }}
                        @else
                            {{ __('Not configured') }}
                        @endif
                    </span>
                </dd>
            </div>
        </dl>
    </section>

    <section class="form-card system-card">
        <h2>{{ __('CMS database') }}</h2>
        <dl class="system-definition-list">
            <div><dt>{{ __('Connection') }}</dt><dd><code>{{ $system['database']['connection'] }}</code></dd></div>
            <div><dt>{{ __('Driver') }}</dt><dd>{{ $system['database']['driver_label'] }}</dd></div>
            <div><dt>{{ __('Server version') }}</dt><dd>{{ $system['database']['version'] ?: __('Could not determine') }}</dd></div>
            <div><dt>{{ __('Status') }}</dt><dd><span class="status-badge {{ $system['database']['connected'] ? 'status-badge-success' : 'status-badge-danger' }}">{{ $system['database']['connected'] ? __('Connected') : __('Connection error') }}</span></dd></div>
            @if($system['database']['path'])
                <div><dt>{{ __('File') }}</dt><dd><code>{{ $system['database']['path'] }}</code></dd></div>
            @endif
            @if($system['database']['size'])
                <div><dt>{{ __('Size') }}</dt><dd>{{ $system['database']['size'] }}</dd></div>
            @endif
            @if($system['database']['driver'] === 'sqlite')
                <div><dt>{{ __('Lock wait') }}</dt><dd><code>{{ $system['database']['sqlite_busy_timeout'] ?? __('Could not determine') }} ms</code></dd></div>
                <div><dt>{{ __('Journal mode') }}</dt><dd><code>{{ $system['database']['sqlite_journal_mode'] ? strtoupper($system['database']['sqlite_journal_mode']) : __('Could not determine') }}</code></dd></div>
                <div><dt>{{ __('Write synchronization') }}</dt><dd><code>{{ $system['database']['sqlite_synchronous'] ?? __('Could not determine') }}</code></dd></div>
            @endif
        </dl>
        @if($system['database']['sqlite_production_warning'])
            <div class="notice notice-warning system-database-warning">
                <p><strong>{{ __('SQLite is being used in production.') }}</strong> {{ __('SQLite is intended for local development and testing. Use MySQL or MariaDB for a public site.') }}</p>
            </div>
        @endif
    </section>

    <section class="form-card system-card">
        <h2>{{ __('APP_KEY encryption') }}</h2>
        <dl class="system-definition-list">
            <div>
                <dt>{{ __('Status') }}</dt>
                <dd><span class="status-badge status-badge-{{ $system['security']['encryption']['state'] === 'success' ? 'success' : 'danger' }}">{{ $system['security']['encryption']['status'] }}</span></dd>
            </div>
            <div><dt>{{ __('Encrypted values') }}</dt><dd>{{ $system['security']['encryption']['encrypted_values_total'] }}</dd></div>
            <div><dt>{{ __('Unavailable values') }}</dt><dd>{{ $system['security']['encryption']['invalid_values_total'] }}</dd></div>
            @foreach($system['security']['encryption']['categories'] as $category)
                <div>
                    <dt>{{ $category['label'] }}</dt>
                    <dd>{{ __(':saved saved, :invalid unavailable', ['saved' => $category['saved'], 'invalid' => $category['invalid']]) }}</dd>
                </div>
            @endforeach
        </dl>
        <p class="system-definition-note">{{ $system['security']['encryption']['details'] }}</p>
    </section>
</div>

<section class="form-card system-components-card">
    <div class="system-section-heading">
        <div>
            <h2>{{ __('System operations') }}</h2>
            <p>{{ __('Scheduler, queues and jobs that require administrator attention.') }}</p>
        </div>
        <a wire:navigate class="button button-secondary" href="{{ route('admin.settings.system.queue') }}">{{ __('Queue details') }}</a>
    </div>

    <div class="dashboard-runtime-statuses system-runtime-statuses">
        @foreach([
            __('Laravel scheduler') => $system['runtime']['scheduler'],
            __('Queue processing') => $system['runtime']['queue'],
        ] as $label => $status)
            <article class="dashboard-runtime-status">
                <span class="system-status-dot {{ $status['state'] }}" aria-hidden="true"></span>
                <div>
                    <strong>{{ $label }}</strong>
                    <small>{{ $status['details'] }}</small>
                </div>
                <span class="status-badge status-badge-{{ $status['state'] === 'success' ? 'success' : ($status['state'] === 'danger' ? 'danger' : 'warning') }}">{{ $status['status'] }}</span>
            </article>
        @endforeach
    </div>

    <div class="dashboard-runtime-metrics system-runtime-metrics">
        <div><span>{{ __('Pending jobs') }}</span><strong>{{ $system['runtime']['jobs']['pending'] }}</strong></div>
        <div><span>{{ __('Failed jobs') }}</span><strong>{{ $system['runtime']['jobs']['failed'] }}</strong></div>
        <div>
            <span>{{ __('Oldest pending job') }}</span>
            <strong class="dashboard-runtime-time">{{ $system['runtime']['jobs']['oldest_pending_at'] ? $system['runtime']['jobs']['oldest_pending_at']->diffForHumans() : '—' }}</strong>
        </div>
        <div>
            <span>{{ __('Last successful job') }}</span>
            <strong class="dashboard-runtime-time">{{ $system['runtime']['queue']['last_succeeded_at']?->diffForHumans() ?? '—' }}</strong>
        </div>
    </div>

    @if($system['runtime']['warnings'] !== [])
        <div class="dashboard-runtime-warnings">
            @foreach($system['runtime']['warnings'] as $warning)
                <p>{{ $warning }}</p>
            @endforeach
        </div>
    @else
        <p class="dashboard-runtime-ok">{{ __('No Scheduler or queue problems detected.') }}</p>
    @endif
</section>

<section class="form-card system-components-card">
    <div class="system-section-heading">
        <div>
            <h2>{{ __('Component status') }}</h2>
            <p>{{ __('Checks run again whenever this page is opened.') }}</p>
        </div>
    </div>
    <div class="system-component-list">
        @foreach($system['components'] as $component)
            <article class="system-component-row">
                <span @class([
                    'system-status-dot',
                    'success' => $component['state'] === 'success',
                    'warning' => $component['state'] === 'warning',
                    'danger' => $component['state'] === 'danger',
                    'neutral' => $component['state'] === 'neutral',
                ]) aria-hidden="true"></span>
                <div>
                    <strong>{{ $component['label'] }}</strong>
                    <small>{{ $component['details'] }}</small>
                </div>
                <span @class([
                    'status-badge',
                    'status-badge-success' => $component['state'] === 'success',
                    'status-badge-warning' => $component['state'] === 'warning',
                    'status-badge-danger' => $component['state'] === 'danger',
                    'status-badge-muted' => $component['state'] === 'neutral',
                ])>{{ $component['status'] }}</span>
            </article>
        @endforeach
    </div>
</section>

<section class="form-card system-extensions-card">
    <div class="system-section-heading">
        <div>
            <h2>{{ __('PHP extensions') }}</h2>
            <p>{{ __('Required extensions are also checked by setup and diagnostic scripts.') }}</p>
        </div>
    </div>
    <div class="system-extension-grid">
        @foreach($system['extensions'] as $extension)
            <div @class(['system-extension', 'missing' => ! $extension['loaded']])>
                <div>
                    <code>{{ $extension['name'] }}</code>
                    <small>{{ $extension['required'] ? __('Required') : __('Optional') }}</small>
                </div>
                <span class="status-badge {{ $extension['loaded'] ? 'status-badge-success' : ($extension['required'] ? 'status-badge-danger' : 'status-badge-muted') }}">{{ $extension['loaded'] ? __('Installed') : __('Not installed') }}</span>
            </div>
        @endforeach
    </div>
</section>

<section class="form-card system-report-card">
    <div class="system-section-heading">
        <div>
            <h2>{{ __('Safe support report') }}</h2>
            <p>{{ __('The report excludes APP_KEY, passwords, tokens, cookies, database usernames and absolute paths.') }}</p>
        </div>
        <span class="system-copy-state" data-system-copy-state aria-live="polite"></span>
    </div>
    <pre data-system-report-preview>{{ $system['report'] }}</pre>
    <textarea class="system-report-source" data-system-report readonly aria-hidden="true" tabindex="-1">{{ $system['report'] }}</textarea>
</section>
@endsection

@push('scripts')
<script src="{{ asset('assets/admin/js/system.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
