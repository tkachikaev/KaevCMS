@extends('admin.layouts.panel')

@section('title', __('System updates'))
@section('description', __('Upload a trusted KaevCMS update package and review it before installation.'))

@section('content')
@include('admin.settings._system_tabs')

<section class="system-overview update-overview">
    <div>
        <span class="system-eyebrow">KaevCMS</span>
        <strong>{{ __('Version :version', ['version' => $currentVersion]) }}</strong>
        <p>{{ $installationType === 'split' ? __('Split shared-hosting installation') : __('Standard public-root installation') }}</p>
    </div>
    <div class="system-overview-actions">
        <a wire:navigate class="button button-secondary" href="{{ route('admin.settings.system') }}">← {{ __('System information') }}</a>
    </div>
</section>

@if(! $zipAvailable)
    <div class="notice notice-error" role="alert">
        <strong>{{ __('Updates are unavailable.') }}</strong>
        <span>{{ __('Install and enable the PHP zip extension before uploading an update package.') }}</span>
    </div>
@endif

<section class="form-card update-upload-card">
    <div class="system-section-heading">
        <div>
            <h2>{{ __('Upload update package') }}</h2>
            <p>{{ __('Only upload a package obtained from an official KaevCMS release. The package is checked before any files are changed.') }}</p>
        </div>
        <span class="status-badge status-badge-muted">ZIP</span>
    </div>

    <form method="POST" action="{{ route('admin.settings.system.updates.store') }}" enctype="multipart/form-data" class="update-upload-form">
        @csrf
        <label class="settings-field" for="update_package">
            <span>{{ __('Update package') }}</span>
            <input id="update_package" name="package" type="file" accept=".zip,application/zip" required @disabled(! $zipAvailable)>
            <small>{{ __('The package must contain a release manifest and per-file SHA256 checksums.') }}</small>
        </label>
        <button class="button button-primary" type="submit" @disabled(! $zipAvailable)>{{ __('Upload and verify') }}</button>
    </form>
</section>

@if($stagedUpdates->isNotEmpty())
<section class="form-card">
    <div class="system-section-heading">
        <div>
            <h2>{{ __('Ready to install') }}</h2>
            <p>{{ __('Staged packages do not change the website until you explicitly start installation.') }}</p>
        </div>
    </div>

    <div class="update-card-list">
        @foreach($stagedUpdates as $update)
            <article class="admin-card-row update-row">
                <div>
                    <strong>{{ __('KaevCMS :version', ['version' => $update->target_version]) }}</strong>
                    <small>{{ $update->package_id }} · {{ $update->file_count }} {{ __('update files') }}</small>
                </div>
                <span class="status-badge status-badge-warning">{{ $update->statusLabel() }}</span>
                <a wire:navigate class="button button-primary" href="{{ route('admin.settings.system.updates.show', $update) }}">{{ __('Review update') }}</a>
            </article>
        @endforeach
    </div>
</section>
@endif

<section class="form-card">
    <div class="system-section-heading">
        <div>
            <h2>{{ __('Update history') }}</h2>
            <p>{{ __('The latest manual update attempts are recorded here.') }}</p>
        </div>
    </div>

    @if($history->isEmpty())
        <div class="empty-state"><p>{{ __('No updates have been installed through the web updater yet.') }}</p></div>
    @else
        <div class="admin-table-wrap">
            <table class="admin-table update-history-table">
                <thead>
                    <tr>
                        <th>{{ __('Version') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th>{{ __('Started') }}</th>
                        <th>{{ __('Completed') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($history as $update)
                        <tr>
                            <td><strong>{{ $update->from_version }} → {{ $update->target_version }}</strong><small>{{ $update->package_id }}</small></td>
                            <td><span @class([
                                'status-badge',
                                'status-badge-success' => $update->status === \App\Models\SystemUpdate::STATUS_SUCCEEDED,
                                'status-badge-danger' => $update->status === \App\Models\SystemUpdate::STATUS_FAILED,
                                'status-badge-muted' => ! in_array($update->status, [\App\Models\SystemUpdate::STATUS_SUCCEEDED, \App\Models\SystemUpdate::STATUS_FAILED], true),
                            ])>{{ $update->statusLabel() }}</span></td>
                            <td>{{ $update->started_at?->format('d.m.Y H:i') ?? '—' }}</td>
                            <td>{{ $update->completed_at?->format('d.m.Y H:i') ?? '—' }}</td>
                            <td><a wire:navigate class="button button-secondary" href="{{ route('admin.settings.system.updates.show', $update) }}">{{ __('Details') }}</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endsection
