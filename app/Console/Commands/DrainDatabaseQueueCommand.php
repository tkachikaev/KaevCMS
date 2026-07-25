<?php

namespace App\Console\Commands;

use App\Services\Infrastructure\QueueWorkerRunner;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Throwable;

final class DrainDatabaseQueueCommand extends Command
{
    protected $signature = 'kaevcms:queue-drain
        {--max-time=50 : Maximum worker lifetime in seconds}
        {--max-jobs=100 : Maximum jobs processed in one Scheduler run}
        {--tries=3 : Maximum attempts before a job is marked as failed}';

    protected $description = 'Process every database queue that currently contains pending jobs';

    public function handle(DatabaseManager $database, QueueWorkerRunner $workerRunner): int
    {
        $maxTime = max(1, min(300, (int) $this->option('max-time')));
        $maxJobs = max(1, min(1000, (int) $this->option('max-jobs')));
        $tries = max(1, min(10, (int) $this->option('tries')));

        try {
            $defaultConnection = (string) config('database.default');
            $connectionName = (string) config('queue.connections.database.connection', '');
            $table = (string) config('queue.connections.database.table', 'jobs');
            $connection = $database->connection($connectionName !== '' ? $connectionName : $defaultConnection);

            if ($connection->getSchemaBuilder()->hasTable($table) === false) {
                $this->warn(__('Queue table is not available.'));

                return self::SUCCESS;
            }

            $queues = $connection->table($table)
                ->select('queue')
                ->distinct()
                ->pluck('queue')
                ->map(fn (mixed $queue): string => is_scalar($queue) ? trim((string) $queue) : '')
                ->filter(fn (string $queue): bool => $this->validQueueName($queue))
                ->unique()
                ->values()
                ->all();
        } catch (Throwable $exception) {
            $this->error(__('Unable to inspect database queues: :error', [
                'error' => $exception::class,
            ]));

            return self::FAILURE;
        }

        if ($queues === []) {
            $this->info(__('No database queue jobs are waiting.'));

            return self::SUCCESS;
        }

        usort($queues, static function (string $left, string $right): int {
            $priority = ['mail-probe' => 0, 'mail' => 1, 'default' => 2];
            $leftPriority = $priority[$left] ?? 100;
            $rightPriority = $priority[$right] ?? 100;

            return $leftPriority === $rightPriority
                ? strnatcasecmp($left, $right)
                : $leftPriority <=> $rightPriority;
        });

        $previousDrainState = config('cms.queue.scheduler_drain_active', false);
        $queueCount = count($queues);
        $jobBudget = max(1, intdiv($maxJobs, $queueCount));
        $timeBudget = max(1, intdiv($maxTime, $queueCount));
        config()->set('cms.queue.scheduler_drain_active', true);

        try {
            foreach ($queues as $queue) {
                $result = $workerRunner->run($queue, $timeBudget, $jobBudget, $tries);
                $exitCode = $result['exit_code'];
                $output = $result['output'];
                if ($output !== '') {
                    $this->line($output);
                }

                if ($exitCode !== self::SUCCESS) {
                    $this->error(__('Database queue worker exited with code :code.', ['code' => $exitCode]));

                    return $exitCode;
                }
            }
        } catch (Throwable $exception) {
            $this->error(__('Database queue worker could not be started: :error', [
                'error' => $exception::class,
            ]));

            return self::FAILURE;
        } finally {
            config()->set('cms.queue.scheduler_drain_active', $previousDrainState);
        }

        return self::SUCCESS;
    }

    private function validQueueName(string $queue): bool
    {
        if ($queue === '' || str_contains($queue, ',')) {
            return false;
        }

        return Str::length($queue) <= 190
            && preg_match('/^[A-Za-z0-9._:-]+$/', $queue) === 1;
    }
}
