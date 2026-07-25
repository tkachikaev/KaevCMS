@extends('admin.layouts.panel')

@section('title', __('Queue management'))
@section('description', __('Database queue status, failed jobs and service data retention.'))

@section('content')
@include('admin.settings._system_tabs')

<section class="system-overview queue-overview">
    <div>
        <span class="system-eyebrow">Laravel Queue</span>
        <strong>{{ __('Queue management') }}</strong>
        <p>{{ __('The CMS processes every database queue currently containing jobs. Payloads and exception texts are never displayed here.') }}</p>
    </div>
    <div class="system-overview-actions">
        <a wire:navigate class="button button-secondary" href="{{ route('admin.settings.system') }}">← {{ __('System information') }}</a>
        <form method="POST" action="{{ route('admin.settings.system.queue.restart') }}">
            @csrf
            <button class="button button-primary" type="submit">{{ __('Restart queue worker') }}</button>
        </form>
    </div>
</section>

@if($errors->has('queue'))
    <div class="notice notice-warning" role="alert"><p>{{ $errors->first('queue') }}</p></div>
@endif

<section class="form-card queue-runtime-card">
    <div class="system-section-heading">
        <div>
            <h2>{{ __('Current queue status') }}</h2>
            <p>{{ __('Heartbeat data is updated when a database job starts, succeeds or fails.') }}</p>
        </div>
        <span @class([
            'status-badge',
            'status-badge-success' => $runtime['queue']['state'] === 'success',
            'status-badge-warning' => in_array($runtime['queue']['state'], ['warning', 'neutral'], true),
            'status-badge-danger' => $runtime['queue']['state'] === 'danger',
        ])>{{ $runtime['queue']['status'] }}</span>
    </div>

    <div class="dashboard-runtime-metrics queue-runtime-metrics">
        <div><span>{{ __('Pending jobs') }}</span><strong>{{ $runtime['jobs']['pending'] }}</strong></div>
        <div><span>{{ __('Failed jobs') }}</span><strong>{{ $runtime['jobs']['failed'] }}</strong></div>
        <div><span>{{ __('Last successful job') }}</span><strong class="dashboard-runtime-time">{{ $runtime['queue']['last_succeeded_at']?->diffForHumans() ?? '—' }}</strong></div>
        <div><span>{{ __('Last failed job') }}</span><strong class="dashboard-runtime-time">{{ $runtime['queue']['last_failed_at']?->diffForHumans() ?? '—' }}</strong></div>
    </div>

    @if($runtime['jobs']['queues'] === [])
        <p class="dashboard-runtime-ok">{{ __('No database queue activity has been recorded yet.') }}</p>
    @else
        <div class="queue-list">
            @foreach($runtime['jobs']['queues'] as $queue)
                <article class="queue-list-row">
                    <span class="system-status-dot {{ $queue['state'] }}" aria-hidden="true"></span>
                    <div class="queue-list-main">
                        <strong><code>{{ $queue['name'] }}</code></strong>
                        <small>
                            {{ __('Pending: :pending · Failed: :failed', ['pending' => $queue['pending'], 'failed' => $queue['failed']]) }}
                            @if($queue['last_succeeded_at'])
                                · {{ __('Last success: :time', ['time' => $queue['last_succeeded_at']->diffForHumans()]) }}
                            @endif
                        </small>
                    </div>
                    <span @class([
                        'status-badge',
                        'status-badge-success' => $queue['state'] === 'success',
                        'status-badge-warning' => in_array($queue['state'], ['warning', 'neutral'], true),
                        'status-badge-danger' => $queue['state'] === 'danger',
                    ])>
                        @if($queue['state'] === 'danger')
                            {{ __('Stalled') }}
                        @elseif($queue['failed'] > 0)
                            {{ __('Has failures') }}
                        @elseif($queue['pending'] > 0)
                            {{ __('Waiting') }}
                        @else
                            {{ __('Ready') }}
                        @endif
                    </span>
                </article>
            @endforeach
        </div>
    @endif

    @if($runtime['warnings'] !== [])
        <div class="dashboard-runtime-warnings">
            @foreach($runtime['warnings'] as $warning)<p>{{ $warning }}</p>@endforeach
        </div>
    @endif
</section>

<div class="queue-management-grid">
    <section class="form-card queue-retention-card">
        <div class="system-section-heading">
            <div>
                <h2>{{ __('Service data retention') }}</h2>
                <p>{{ __('Only completed mail records, expired failed jobs and inactive per-queue heartbeats are removed automatically.') }}</p>
            </div>
        </div>

        <dl class="system-definition-list queue-retention-list">
            <div><dt>{{ __('Mail delivery history') }}</dt><dd>{{ __(':days days', ['days' => $maintenance['mail_delivery_retention_days']]) }}</dd><small>{{ __('Expired: :count', ['count' => $maintenance['mail_deliveries_expired']]) }}</small></div>
            <div><dt>{{ __('Failed jobs') }}</dt><dd>{{ __(':days days', ['days' => $maintenance['failed_job_retention_days']]) }}</dd><small>{{ __('Expired: :count', ['count' => $maintenance['failed_jobs_expired']]) }}</small></div>
            <div><dt>{{ __('Inactive queue heartbeats') }}</dt><dd>{{ __(':days days', ['days' => $maintenance['heartbeat_retention_days']]) }}</dd><small>{{ __('Expired: :count', ['count' => $maintenance['queue_heartbeats_expired']]) }}</small></div>
            <div><dt>{{ __('Last cleanup') }}</dt><dd>{{ $maintenance['last_cleaned_at']?->format('d.m.Y H:i:s') ?? __('Never') }}</dd><small>{{ __('Automatic schedule: daily at 03:45.') }}</small></div>
        </dl>

        <form method="POST" action="{{ route('admin.settings.system.queue.cleanup') }}">
            @csrf
            <button class="button button-secondary" type="submit">{{ __('Delete expired service data') }}</button>
        </form>
    </section>

    <section class="form-card queue-worker-card">
        <div class="system-section-heading">
            <div>
                <h2>{{ __('Permanent queue worker') }}</h2>
                <p>{{ __('Scheduler starts short-lived workers automatically. A permanent worker can still be used for lower latency.') }}</p>
            </div>
        </div>
        <div class="notice {{ $runtime['queue']['restart_required'] ? 'notice-warning' : 'notice-info' }}">
            <p>
                @if($runtime['queue']['restart_required'])
                    {{ __('A restart is required because a previously active worker may still use old application settings.') }}
                @else
                    {{ __('Use the restart action after changing SMTP or other settings loaded when a permanent worker starts.') }}
                @endif
            </p>
        </div>
        <code class="queue-command">php artisan queue:work database --queue=mail-probe,mail,default --sleep=3 --tries=3 --backoff=10 --max-time=3600</code>
    </section>
</div>

<section class="form-card queue-failed-card">
    <div class="system-section-heading">
        <div>
            <h2>{{ __('Failed jobs') }}</h2>
            <p>{{ __('Only safe metadata is shown. Queue payloads and full exception messages remain hidden.') }}</p>
        </div>
    </div>

    @if($failedJobs->isEmpty())
        <div class="admin-empty-state empty-state queue-empty-state">
            <div class="empty-state-mark">Q</div>
            <h3>{{ __('No failed jobs') }}</h3>
            <p>{{ __('Jobs that exhaust all retry attempts will appear here.') }}</p>
        </div>
    @else
        <div class="admin-table-wrap queue-failed-table-wrap">
            <table class="admin-table queue-failed-table">
                <thead><tr><th>{{ __('Date and time') }}</th><th>{{ __('Job') }}</th><th>{{ __('Connection') }}</th><th>{{ __('Queue') }}</th><th>{{ __('Error type') }}</th><th>{{ __('Actions') }}</th></tr></thead>
                <tbody>
                @foreach($failedJobs as $job)
                    <tr>
                        <td><strong>{{ $job->failed_at?->format('d.m.Y') }}</strong><span class="audit-muted">{{ $job->failed_at?->format('H:i:s') }}</span></td>
                        <td><strong>{{ $job->displayName() }}</strong><span class="audit-muted">{{ $job->uuid }}</span></td>
                        <td><code>{{ $job->connection }}</code></td>
                        <td><code>{{ $job->queue }}</code></td>
                        <td><code>{{ $job->exceptionClass() }}</code></td>
                        <td>
                            <div class="admin-row-actions queue-failed-actions">
                                <form method="POST" action="{{ route('admin.settings.system.queue.retry', ['uuid' => $job->uuid]) }}">
                                    @csrf
                                    <button class="button button-primary" type="submit">{{ __('Retry') }}</button>
                                </form>
                                <button class="button button-danger" type="button" data-queue-delete-open data-queue-delete-title="{{ $job->displayName() }}" data-queue-delete-url="{{ route('admin.settings.system.queue.destroy', ['uuid' => $job->uuid]) }}">{{ __('Delete') }}</button>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @if($failedJobs->hasPages())
            <nav class="simple-pagination" aria-label="{{ __('Pagination') }}">
                <a wire:navigate @class(['button button-secondary', 'disabled' => $failedJobs->onFirstPage()]) href="{{ $failedJobs->previousPageUrl() ?? '#' }}">← {{ __('Previous') }}</a>
                <span>{{ __('Page :current of :last', ['current' => $failedJobs->currentPage(), 'last' => $failedJobs->lastPage()]) }}</span>
                <a wire:navigate @class(['button button-secondary', 'disabled' => ! $failedJobs->hasMorePages()]) href="{{ $failedJobs->nextPageUrl() ?? '#' }}">{{ __('Next') }} →</a>
            </nav>
        @endif
    @endif
</section>

<dialog class="confirm-dialog" data-queue-delete-dialog aria-labelledby="delete-queue-job-title">
    <div class="confirm-dialog-card">
        <div class="confirm-dialog-copy">
            <span class="confirm-dialog-mark" aria-hidden="true">!</span>
            <div>
                <h2 id="delete-queue-job-title">{{ __('Delete failed job record?') }}</h2>
                <p>{{ __('The job will not be retried and the failed record will be permanently deleted.') }}</p>
                <strong class="confirm-dialog-target" data-queue-delete-title></strong>
            </div>
        </div>
        <div class="confirm-dialog-actions">
            <button class="button button-secondary" type="button" data-queue-delete-cancel>{{ __('Cancel') }}</button>
            <form method="POST" action="" data-queue-delete-form>
                @csrf
                @method('DELETE')
                <button class="button button-danger" type="submit">{{ __('Yes, delete') }}</button>
            </form>
        </div>
    </div>
</dialog>
@endsection

@push('scripts')
<script src="{{ asset('assets/admin/js/queue.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
