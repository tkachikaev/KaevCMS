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

@if($agentRecommended || $agentStatus->isInstalled())
<section class="form-card update-agent-card" data-vds-agent-status="{{ $agentStatus->state }}">
    <div class="system-section-heading">
        <div>
            <h2>{{ __('VDS update agent') }}</h2>
            <p>{{ $agentStatus->message }}</p>
        </div>
        <span @class([
            'status-badge',
            'status-badge-success' => $agentStatus->isReady(),
            'status-badge-danger' => $agentStatus->state === 'invalid',
            'status-badge-warning' => ! $agentStatus->isReady() && $agentStatus->state !== 'invalid',
        ])>{{ $agentStatus->isReady() ? __('Ready') : ($agentStatus->state === 'invalid' ? __('Needs repair') : __('Not installed')) }}</span>
    </div>

    @if(! $agentStatus->isReady())
        <div class="notice notice-warning" role="alert">
            <strong>{{ __('Browser updates on a VDS require the local update agent.') }}</strong>
            <span>{{ __('Run this command once through SSH, then reload this page. Manual CLI updates remain available without the agent.') }}</span>
        </div>
        <div class="update-agent-command">
            <code id="vds-agent-install-command">{{ $agentInstallCommand }}</code>
            <button class="button button-secondary" type="button" data-copy-command data-copy-target="vds-agent-install-command" data-copy-success="{{ __('Command copied.') }}" data-copy-error="{{ __('Could not copy the command.') }}">{{ __('Copy command') }}</button>
        </div>
        <small class="update-command-state" data-copy-state aria-live="polite"></small>
    @else
        <div class="update-agent-meta">
            <span>{{ __('Agent version: :version', ['version' => $agentStatus->metadata['agent_version'] ?? 1]) }}</span>
            <span>{{ __('Update requests are executed locally as the deployment owner.') }}</span>
        </div>
    @endif
</section>
@endif

<section class="form-card update-upload-card">
    <div class="system-section-heading">
        <div>
            <h2>{{ __('Upload update package') }}</h2>
            <p class="update-upload-warning" role="alert">{{ __('Only upload a package obtained from an official KaevCMS release. An update archive can replace KaevCMS program files. The package is checked before any files are changed. The website owner is responsible for the package selected for installation.') }}</p>
        </div>
        <span class="status-badge status-badge-muted">ZIP</span>
    </div>

    @if($uploadTooLarge)
        <div class="notice notice-error" role="alert" data-update-upload-too-large>
            <strong>{{ __('The package was not uploaded: it is larger than this server accepts through the browser (:limit).', ['limit' => $uploadLimitLabel]) }}</strong>
            <span>{{ __('Raise upload_max_filesize and post_max_size to 64M in the hosting PHP settings, or place the ZIP in the upload folder below through FTP or the file manager.') }}</span>
        </div>
    @elseif($uploadLimitLow)
        <div class="notice notice-warning" role="status" data-update-upload-limit-low>
            <strong>{{ __('This server accepts uploads up to :limit through the browser.', ['limit' => $uploadLimitLabel]) }}</strong>
            <span>{{ __('Release packages that include dependencies can be larger. Raise upload_max_filesize and post_max_size to 64M in the hosting PHP settings, or place the ZIP in the upload folder below through FTP or the file manager.') }}</span>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.settings.system.updates.store') }}" enctype="multipart/form-data" class="update-upload-form">
        @csrf
        <x-admin.field
            for="update_package"
            name="package"
            :label="__('Update package')"
            :hint="__('The package must contain a release manifest and per-file SHA256 checksums.').' '.__('Maximum upload size: :limit.', ['limit' => $uploadLimitLabel])"
        >
            <input id="update_package" name="package" type="file" accept=".zip,application/zip" required
                data-update-max-bytes="{{ $uploadLimitBytes }}"
                data-update-too-large="{{ __('This file is larger than this server accepts through the browser (:limit). Raise the PHP upload limit or place the ZIP in the upload folder below.', ['limit' => $uploadLimitLabel]) }}"
                @disabled(! $zipAvailable) @if($errors->has('package')) aria-invalid="true" @endif>
        </x-admin.field>
        <button class="button button-primary" type="submit" @disabled(! $zipAvailable)>{{ __('Upload and verify') }}</button>
    </form>
</section>

@if($admin->isOwner())
<section class="form-card update-incoming-card" data-update-incoming>
    <div class="system-section-heading">
        <div>
            <h2>{{ __('Package from the server folder') }}</h2>
            <p>{{ __('For packages larger than the browser upload limit: copy the ZIP into this folder through FTP, SFTP or the hosting file manager, then reload this page. The package is checked exactly like an uploaded one.') }}</p>
        </div>
        <span class="status-badge status-badge-muted">FTP</span>
    </div>

    <div class="update-agent-command">
        <code id="update-incoming-directory">{{ $incomingDirectory }}</code>
        <button class="button button-secondary" type="button" data-copy-command data-copy-target="update-incoming-directory" data-copy-success="{{ __('Path copied.') }}" data-copy-error="{{ __('Could not copy the path.') }}">{{ __('Copy path') }}</button>
    </div>
    <small class="update-command-state" data-copy-state aria-live="polite"></small>

    @if(! $incomingReady)
        <div class="notice notice-warning" role="alert">
            <span>{{ __('The folder does not exist yet and PHP could not create it. Create it through FTP or the file manager.') }}</span>
        </div>
    @endif

    @error('incoming')
        <div class="notice notice-error" role="alert"><span>{{ $message }}</span></div>
    @enderror

    @if($incomingPackages === [])
        <div class="empty-state"><p>{{ __('No ZIP files in the folder yet.') }}</p></div>
    @else
        <div class="update-card-list">
            @foreach($incomingPackages as $incomingPackage)
                <article class="admin-card-row update-row">
                    <div>
                        <strong>{{ $incomingPackage['name'] }}</strong>
                        <small>{{ \App\Services\Updates\UpdateUploadLimit::format($incomingPackage['size']) }} · {{ date('d.m.Y H:i', $incomingPackage['modified_at']) }}</small>
                    </div>
                    <form method="POST" action="{{ route('admin.settings.system.updates.import') }}">
                        @csrf
                        <input type="hidden" name="incoming" value="{{ $incomingPackage['name'] }}">
                        <button class="button button-primary" type="submit" @disabled(! $zipAvailable)>{{ __('Verify package') }}</button>
                    </form>
                </article>
            @endforeach
        </div>
    @endif
</section>
@endif

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

@push('scripts')
<script src="{{ asset('assets/admin/js/system-updates.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
