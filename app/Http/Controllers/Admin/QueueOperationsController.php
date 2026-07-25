<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FailedJob;
use App\Services\AuditLogger;
use App\Services\Infrastructure\QueueMaintenance;
use App\Services\Infrastructure\RuntimeDiagnostics;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;
use Throwable;

final class QueueOperationsController extends Controller
{
    public function index(
        RuntimeDiagnostics $runtimeDiagnostics,
        QueueMaintenance $maintenance,
    ): View {
        return view('admin.settings.queue', [
            'runtime' => $runtimeDiagnostics->overview(),
            'maintenance' => $maintenance->statistics(),
            'failedJobs' => $this->failedJobsQuery()
                ->orderByDesc('failed_at')
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString(),
        ]);
    }

    public function retry(string $uuid, AuditLogger $auditLogger): RedirectResponse
    {
        $job = $this->failedJobsQuery()->where('uuid', $uuid)->firstOrFail();

        try {
            $exitCode = Artisan::call('queue:retry', ['id' => [$job->uuid]]);
        } catch (Throwable $exception) {
            $auditLogger->failed(
                category: 'system',
                action: 'queue.failed_job_retry_failed',
                target: $job->displayName(),
                details: [
                    'uuid' => $job->uuid,
                    'queue' => $job->queue,
                    'exception_class' => $exception::class,
                ],
            );

            return back()->withErrors([
                'queue' => __('The failed job could not be returned to the queue.'),
            ]);
        }

        if ($exitCode !== 0) {
            $auditLogger->failed(
                category: 'system',
                action: 'queue.failed_job_retry_failed',
                target: $job->displayName(),
                details: [
                    'uuid' => $job->uuid,
                    'queue' => $job->queue,
                    'exit_code' => $exitCode,
                ],
            );

            return back()->withErrors([
                'queue' => __('The failed job could not be returned to the queue.'),
            ]);
        }

        $auditLogger->success(
            category: 'system',
            action: 'queue.failed_job_retried',
            target: $job->displayName(),
            details: [
                'uuid' => $job->uuid,
                'connection' => $job->connection,
                'queue' => $job->queue,
            ],
        );

        return redirect()
            ->route('admin.settings.system.queue')
            ->with('status', __('The failed job was returned to the queue.'));
    }

    public function destroy(string $uuid, AuditLogger $auditLogger): RedirectResponse
    {
        $job = $this->failedJobsQuery()->where('uuid', $uuid)->firstOrFail();
        $details = [
            'uuid' => $job->uuid,
            'connection' => $job->connection,
            'queue' => $job->queue,
        ];
        $target = $job->displayName();
        $job->delete();

        $auditLogger->success(
            category: 'system',
            action: 'queue.failed_job_deleted',
            target: $target,
            details: $details,
        );

        return redirect()
            ->route('admin.settings.system.queue')
            ->with('status', __('The failed job record was deleted.'));
    }

    public function cleanup(
        QueueMaintenance $maintenance,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $result = $maintenance->cleanup();

        $auditLogger->success(
            category: 'system',
            action: 'queue.retention_cleaned',
            target: __('Queue service data'),
            details: $result,
        );

        return redirect()
            ->route('admin.settings.system.queue')
            ->with('status', __('Expired queue service data deleted: :count.', [
                'count' => array_sum($result),
            ]));
    }

    public function restart(
        RuntimeDiagnostics $runtimeDiagnostics,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        try {
            $exitCode = Artisan::call('queue:restart');
        } catch (Throwable $exception) {
            $auditLogger->failed(
                category: 'system',
                action: 'queue.restart_failed',
                target: __('Queue worker'),
                details: ['exception_class' => $exception::class],
            );

            return back()->withErrors([
                'queue' => __('The queue restart signal could not be sent.'),
            ]);
        }

        if ($exitCode !== 0) {
            $auditLogger->failed(
                category: 'system',
                action: 'queue.restart_failed',
                target: __('Queue worker'),
                details: ['exit_code' => $exitCode],
            );

            return back()->withErrors([
                'queue' => __('The queue restart signal could not be sent.'),
            ]);
        }

        $runtimeDiagnostics->clearQueueRestartRequired();
        $auditLogger->success(
            category: 'system',
            action: 'queue.restart_requested',
            target: __('Queue worker'),
        );

        return redirect()
            ->route('admin.settings.system.queue')
            ->with('status', __('The queue restart signal was sent. A new worker will load the current settings.'));
    }

    /** @return Builder<FailedJob> */
    private function failedJobsQuery(): Builder
    {
        $defaultConnection = (string) config('database.default');
        $connection = (string) config('queue.failed.database', '');
        $model = new FailedJob;
        $model->setConnection($connection !== '' ? $connection : $defaultConnection);

        return $model->newQuery();
    }
}
