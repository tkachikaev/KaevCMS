@extends('admin.layouts.panel')

@section('title', __('Update details'))
@section('description', __('Review compatibility, backups and integrity checks before installing the package.'))

@section('content')
@include('admin.settings._system_tabs')

<section class="system-overview update-overview">
    <div>
        <span class="system-eyebrow">{{ $update->package_id }}</span>
        <strong>{{ $update->from_version }} → {{ $update->target_version }}</strong>
        <p>{{ $update->installation_type === 'split' ? __('Split shared-hosting installation') : __('Standard public-root installation') }}</p>
    </div>
    <div class="system-overview-actions">
        <span @class([
            'status-badge',
            'status-badge-warning' => $update->status === \App\Models\SystemUpdate::STATUS_STAGED,
            'status-badge-success' => $update->status === \App\Models\SystemUpdate::STATUS_SUCCEEDED,
            'status-badge-danger' => $update->status === \App\Models\SystemUpdate::STATUS_FAILED,
            'status-badge-muted' => in_array($update->status, [\App\Models\SystemUpdate::STATUS_APPLYING, \App\Models\SystemUpdate::STATUS_DISCARDED], true),
        ])>{{ $update->statusLabel() }}</span>
        @if($update->status === \App\Models\SystemUpdate::STATUS_APPLYING)
            <span class="status-badge status-badge-muted">{{ __('Phase: :phase', ['phase' => $update->phaseLabel()]) }}</span>
        @endif
        <a wire:navigate class="button button-secondary" href="{{ route('admin.settings.system.updates.index') }}">← {{ __('All updates') }}</a>
    </div>
</section>

@if($inspectionError)
    <div class="notice notice-error" role="alert">
        <strong>{{ __('The staged package can no longer be verified.') }}</strong>
        <span>{{ $inspectionError }}</span>
    </div>
@endif

@if($package)
    <div class="update-summary-grid">
        <section class="form-card update-summary-card">
            <h2>{{ __('Package summary') }}</h2>
            <dl class="system-definition-list">
                <div><dt>{{ __('Target version') }}</dt><dd><strong>{{ $package->targetVersion }}</strong></dd></div>
                <div><dt>{{ __('Supported versions') }}</dt><dd>{{ $package->minimumVersion }} — {{ $package->maximumVersion }}</dd></div>
                <div><dt>{{ __('Files to replace') }}</dt><dd>{{ count($package->files) }}</dd></div>
                <div><dt>{{ __('Paths to remove') }}</dt><dd>{{ count($package->delete) }}</dd></div>
                <div><dt>{{ __('Database migrations') }}</dt><dd>{{ $package->migrate ? __('Will be applied') : __('Not required') }}</dd></div>
            </dl>
        </section>

        <section class="form-card update-summary-card">
            <h2>{{ __('Safety model') }}</h2>
            <ul class="update-safety-list">
                <li>{{ __('The website enters maintenance mode during installation.') }}</li>
                <li>{{ __('Affected application files and the CMS database are backed up first.') }}</li>
                <li>{{ __('Every payload file is checked using SHA256 before replacement.') }}</li>
                <li>{{ __('The updater attempts an automatic rollback if installation fails.') }}</li>
            </ul>
        </section>
    </div>

    @if($package->warnings !== [])
        <div class="notice notice-warning" role="alert">
            @foreach($package->warnings as $warning)<p>{{ $warning }}</p>@endforeach
        </div>
    @endif

    <section class="form-card">
        <div class="system-section-heading">
            <div>
                <h2>{{ __('Preflight checks') }}</h2>
                <p>{{ __('Installation is blocked while any required check fails.') }}</p>
            </div>
            <span class="status-badge {{ $checksPassed ? 'status-badge-success' : 'status-badge-danger' }}">{{ $checksPassed ? __('Ready') : __('Attention required') }}</span>
        </div>
        <div class="update-check-list">
            @foreach($checks as $check)
                <div @class(['update-check', 'failed' => ! $check['passed']])>
                    <span aria-hidden="true">{{ $check['passed'] ? '✓' : '!' }}</span>
                    <div><strong>{{ $check['label'] }}</strong><small>{{ $check['detail'] }}</small></div>
                </div>
            @endforeach
        </div>
    </section>

    @php($changelog = is_array($package->manifest['changelog'] ?? null) ? $package->manifest['changelog'] : [])
    @if($changelog !== [])
        <section class="form-card">
            <h2>{{ __('Package changes') }}</h2>
            <ul class="update-changelog">
                @foreach($changelog as $change)
                    @if(is_string($change))<li>{{ $change }}</li>@endif
                @endforeach
            </ul>
        </section>
    @endif

    @if($update->isStaged())
        <section class="form-card update-confirm-card">
            <div class="system-section-heading">
                <div>
                    <h2>{{ __('Install update') }}</h2>
                    <p>{{ __('Do not close the browser or start another update until this operation finishes.') }}</p>
                </div>
            </div>

            @if($recoveryUrl)
                <div class="notice notice-warning update-emergency-access" role="alert">
                    <strong>{{ __('Emergency access link') }}</strong>
                    <span>{{ __('Keep this link open or copy it before starting. It grants this browser temporary access if the update is interrupted while maintenance mode is active.') }}</span>
                    <a href="{{ $recoveryUrl }}" target="_blank" rel="noopener noreferrer"><code>{{ $recoveryUrl }}</code></a>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.settings.system.updates.apply', $update) }}" class="update-confirm-form">
                @csrf
                <label class="settings-field" for="current_password">
                    <span>{{ __('Current administrator password') }}</span>
                    <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
                </label>
                <label class="settings-checkbox update-confirm-checkbox">
                    <input name="confirmation" type="checkbox" value="1" required>
                    <span>{{ __('I understand that the website will briefly enter maintenance mode and confirm installation of this trusted package.') }}</span>
                </label>
                <button class="button button-primary" type="submit" @disabled(! $checksPassed)>{{ __('Start update') }}</button>
            </form>

            <form method="POST" action="{{ route('admin.settings.system.updates.destroy', $update) }}" class="update-discard-form">
                @csrf
                @method('DELETE')
                <button class="button button-danger" type="submit">{{ __('Discard package') }}</button>
            </form>
        </section>
    @endif
@endif

@if($update->status === \App\Models\SystemUpdate::STATUS_APPLYING)
    <section class="form-card update-recovery-card">
        <div class="system-section-heading">
            <div>
                <h2>{{ __('Interrupted update recovery') }}</h2>
                <p>{{ __('Use recovery only when the original update request has stopped or failed to finish. A running update keeps an exclusive lock and cannot be recovered at the same time.') }}</p>
            </div>
            <span class="status-badge status-badge-danger">{{ __('Recovery mode') }}</span>
        </div>

        @if($recoveryUrl)
            <div class="notice notice-warning" role="alert">
                <strong>{{ __('Maintenance bypass') }}</strong>
                <span>{{ __('Open this link in the same browser if the normal site shows the maintenance page, then return here.') }}</span>
                <a href="{{ $recoveryUrl }}" target="_blank" rel="noopener noreferrer"><code>{{ $recoveryUrl }}</code></a>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.settings.system.updates.recover', $update) }}" class="update-confirm-form">
            @csrf
            <label class="settings-field" for="recovery_current_password">
                <span>{{ __('Current administrator password') }}</span>
                <input id="recovery_current_password" name="current_password" type="password" autocomplete="current-password" required>
            </label>
            <label class="settings-checkbox update-confirm-checkbox">
                <input name="confirmation" type="checkbox" value="1" required>
                <span>{{ __('I confirm that the original update request is no longer running and want to restore the saved files and CMS database.') }}</span>
            </label>
            <button class="button button-danger" type="submit">{{ __('Attempt recovery') }}</button>
        </form>
    </section>
@endif

@if($update->error_summary)
    <div class="notice notice-error" role="alert"><strong>{{ __('Update error') }}</strong><span>{{ $update->error_summary }}</span></div>
@endif

@if($update->backup_path || $update->log_path)
<section class="form-card">
    <h2>{{ __('Recovery information') }}</h2>
    <dl class="system-definition-list">
        <div><dt>{{ __('Backup') }}</dt><dd><code>{{ $update->backup_path ?? '—' }}</code></dd></div>
        <div><dt>{{ __('Log') }}</dt><dd>
            @if($update->log_path)
                <a class="button button-secondary" href="{{ route('admin.settings.system.updates.log', $update) }}" target="_blank" rel="noopener">{{ __('Open update log') }}</a>
            @else
                —
            @endif
        </dd></div>
    </dl>
    @if($logTail)
        <pre class="update-log-preview">{{ $logTail }}</pre>
    @endif
</section>
@endif
@endsection
