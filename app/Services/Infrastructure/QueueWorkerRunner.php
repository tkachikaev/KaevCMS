<?php

namespace App\Services\Infrastructure;

use Illuminate\Support\Facades\Artisan;

class QueueWorkerRunner
{
    /** @return array{exit_code: int, output: string} */
    public function run(string $queue, int $maxTime, int $maxJobs, int $tries): array
    {
        $exitCode = Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => $queue,
            '--stop-when-empty' => true,
            '--max-time' => $maxTime,
            '--max-jobs' => $maxJobs,
            '--sleep' => 1,
            '--tries' => $tries,
            '--backoff' => 10,
            '--no-interaction' => true,
        ]);

        return [
            'exit_code' => $exitCode,
            'output' => trim(Artisan::output()),
        ];
    }
}
