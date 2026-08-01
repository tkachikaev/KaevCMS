<?php

namespace App\Services\Diagnostics;

use App\Models\FailedJob;
use App\Models\SystemUpdate;
use App\Services\SystemInformation;
use App\Support\Modules\ModuleManager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

final class DiagnosticPackageBuilder
{
    private const MAX_LOG_FILES = 3;

    private const MAX_LOG_BYTES = 262144;

    private const MAX_LOG_LINES = 400;

    public function __construct(
        private readonly SystemInformation $systemInformation,
        private readonly ModuleManager $modules,
        private readonly DatabaseManager $database,
        private readonly Filesystem $files,
        private readonly DiagnosticRedactor $redactor,
    ) {}

    public function build(): DiagnosticPackageFile
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException(__('The PHP zip extension is required to create a diagnostic package.'));
        }

        $directory = storage_path('app/kaevcms/diagnostics');
        $this->ensureDirectory($directory);
        $this->removeExpiredPackages($directory);

        $generatedAt = now();
        $rawSystem = $this->systemInformation->collect();
        $identifier = Str::uuid()->toString();
        $filename = sprintf(
            'KaevCMS-%s-diagnostics-%s.zip',
            (string) preg_replace('/[^0-9A-Za-z._-]/', '-', (string) ($rawSystem['cms']['version'] ?? 'unknown')),
            $generatedAt->format('Ymd-His'),
        );
        $path = $directory.DIRECTORY_SEPARATOR.$identifier.'.zip';

        $system = $this->safeSystemInformation($rawSystem);
        $modules = $this->moduleInformation();
        $migrations = $this->migrationInformation();
        $updates = $this->updateInformation();
        $errors = $this->errorInformation();

        $entries = [
            'README.txt' => $this->readme($generatedAt),
            'diagnostic-report.txt' => $this->report($generatedAt, $system, $modules, $migrations, $updates, $errors),
            'system.json' => $this->json($system),
            'modules.json' => $this->json($modules),
            'migrations.json' => $this->json($migrations),
            'updates.json' => $this->json($updates),
            'recent-errors.log' => $errors['log'],
        ];

        $zip = new ZipArchive;
        $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new RuntimeException(__('Could not create the diagnostic package.'));
        }

        try {
            foreach ($entries as $entry => $contents) {
                if (! $zip->addFromString($entry, $this->redactor->text($contents))) {
                    throw new RuntimeException(__('Could not write the diagnostic package.'));
                }
            }
        } catch (Throwable $exception) {
            $zip->close();
            @unlink($path);

            throw $exception;
        }

        if (! $zip->close() || ! is_file($path)) {
            @unlink($path);

            throw new RuntimeException(__('Could not finalize the diagnostic package.'));
        }

        @chmod($path, 0600);

        return new DiagnosticPackageFile($path, $filename);
    }

    /** @return array<string, mixed> */
    private function safeSystemInformation(array $information): array
    {
        $security = (array) ($information['security'] ?? []);
        $encryption = (array) ($security['encryption'] ?? []);

        return $this->redactor->arrayValue([
            'generated_at' => now(),
            'cms' => $information['cms'] ?? [],
            'software' => $information['software'] ?? [],
            'environment' => $information['environment'] ?? [],
            'security' => [
                'password_hash_driver' => $security['driver'] ?? null,
                'password_hash_label' => $security['label'] ?? null,
                'argon2id_supported' => $security['argon2id_supported'] ?? null,
                'encryption' => [
                    'state' => $encryption['state'] ?? null,
                    'status' => $encryption['status'] ?? null,
                    'details' => $encryption['details'] ?? null,
                    'encrypted_values_total' => $encryption['encrypted_values_total'] ?? null,
                    'invalid_values_total' => $encryption['invalid_values_total'] ?? null,
                    'categories' => $encryption['categories'] ?? [],
                ],
            ],
            'database' => $information['database'] ?? [],
            'proxy' => $information['proxy'] ?? [],
            'runtime' => $information['runtime'] ?? [],
            'permissions' => $information['components'] ?? [],
            'extensions' => $information['extensions'] ?? [],
            'external_databases' => $information['external_databases'] ?? [],
            'disk' => $this->diskInformation(),
            'installer' => [
                'public_directory_present' => is_dir(public_path('install')),
                'installed_lock_present' => is_file(storage_path('app/installed.lock')),
            ],
        ]);
    }

    /** @return array{available: bool, modules: list<array<string, mixed>>, error_class: string|null} */
    private function moduleInformation(): array
    {
        try {
            $modules = array_map(function (array $module): array {
                return $this->redactor->arrayValue([
                    'id' => $module['id'] ?? null,
                    'name' => $module['name'] ?? null,
                    'version' => $module['version'] ?? null,
                    'stored_version' => $module['stored_version'] ?? null,
                    'enabled' => $module['enabled'] ?? false,
                    'status' => $module['status'] ?? 'unknown',
                    'valid' => $module['valid'] ?? false,
                    'compatible' => $module['compatible'] ?? false,
                    'update_available' => $module['update_available'] ?? false,
                    'migration_tracking_available' => $module['migration_tracking_available'] ?? null,
                    'pending_count' => $module['pending_count'] ?? 0,
                    'pending_migrations' => array_values((array) ($module['pending_migrations'] ?? [])),
                    'modified_migrations' => array_values((array) ($module['modified_migrations'] ?? [])),
                    'missing_migrations' => array_values((array) ($module['missing_migrations'] ?? [])),
                    'runtime_error_present' => is_string($module['last_error'] ?? null) && trim((string) $module['last_error']) !== '',
                    'migration_error_present' => is_string($module['migration_error'] ?? null) && trim((string) $module['migration_error']) !== '',
                ]);
            }, $this->modules->installed());

            return [
                'available' => true,
                'modules' => array_values($modules),
                'error_class' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'available' => false,
                'modules' => [],
                'error_class' => $exception::class,
            ];
        }
    }

    /** @return array<string, mixed> */
    private function migrationInformation(): array
    {
        $available = [];
        foreach ($this->files->glob(database_path('migrations/*.php')) ?: [] as $path) {
            $available[] = pathinfo($path, PATHINFO_FILENAME);
        }
        sort($available, SORT_STRING);

        $applied = [];
        $latestBatch = null;
        $trackingAvailable = false;
        $errorClass = null;

        try {
            $connection = $this->database->connection();
            if ($connection->getSchemaBuilder()->hasTable('migrations')) {
                $trackingAvailable = true;
                $rows = $connection->table('migrations')->orderBy('migration')->get(['migration', 'batch']);

                foreach ($rows as $row) {
                    $migration = is_scalar($row->migration ?? null) ? (string) $row->migration : '';
                    if ($migration === '') {
                        continue;
                    }

                    $batch = is_numeric($row->batch ?? null) ? (int) $row->batch : 0;
                    $applied[] = ['migration' => $migration, 'batch' => $batch];
                    $latestBatch = max((int) ($latestBatch ?? 0), $batch);
                }
            }
        } catch (Throwable $exception) {
            $errorClass = $exception::class;
        }

        $appliedNames = array_column($applied, 'migration');
        $pending = array_values(array_diff($available, $appliedNames));

        return $this->redactor->arrayValue([
            'tracking_available' => $trackingAvailable,
            'available_count' => count($available),
            'applied_count' => count($applied),
            'pending_count' => count($pending),
            'latest_batch' => $latestBatch,
            'pending' => $pending,
            'applied' => $applied,
            'error_class' => $errorClass,
        ]);
    }

    /** @return array{available: bool, updates: list<array<string, mixed>>, error_class: string|null} */
    private function updateInformation(): array
    {
        try {
            if (! $this->database->connection()->getSchemaBuilder()->hasTable('system_updates')) {
                return ['available' => false, 'updates' => [], 'error_class' => null];
            }

            $updates = SystemUpdate::query()
                ->latest()
                ->limit(20)
                ->get()
                ->map(fn (SystemUpdate $update): array => $this->redactor->arrayValue([
                    'from_version' => $update->from_version,
                    'target_version' => $update->target_version,
                    'status' => $update->status,
                    'phase' => $update->phase,
                    'installation_type' => $update->installation_type,
                    'file_count' => $update->file_count,
                    'delete_count' => $update->delete_count,
                    'error_present' => is_string($update->error_summary) && trim($update->error_summary) !== '',
                    'error_class' => $this->exceptionClass((string) ($update->error_summary ?? '')),
                    'error_fingerprint' => is_string($update->error_summary) && trim($update->error_summary) !== ''
                        ? substr(hash('sha256', $this->redactor->text($update->error_summary)), 0, 16)
                        : null,
                    'started_at' => $update->started_at,
                    'completed_at' => $update->completed_at,
                    'created_at' => $update->created_at,
                ]))
                ->values()
                ->all();

            return ['available' => true, 'updates' => $updates, 'error_class' => null];
        } catch (Throwable $exception) {
            return ['available' => false, 'updates' => [], 'error_class' => $exception::class];
        }
    }

    /** @return array{failed_jobs: list<array<string, mixed>>, log: string} */
    private function errorInformation(): array
    {
        $failedJobs = [];

        try {
            if ($this->database->connection()->getSchemaBuilder()->hasTable('failed_jobs')) {
                $failedJobs = FailedJob::query()
                    ->latest('failed_at')
                    ->limit(20)
                    ->get()
                    ->map(fn (FailedJob $job): array => $this->redactor->arrayValue([
                        'queue' => (string) $job->getAttribute('queue'),
                        'job' => $job->displayName(),
                        'exception_class' => $job->exceptionClass(),
                        'failed_at' => $job->getAttribute('failed_at'),
                    ]))
                    ->values()
                    ->all();
            }
        } catch (Throwable $exception) {
            $failedJobs = [[
                'queue' => null,
                'job' => null,
                'exception_class' => $exception::class,
                'failed_at' => null,
            ]];
        }

        $sections = [
            'KaevCMS diagnostic error extract',
            'Generated: '.now()->toIso8601String(),
            '',
            'Failed queue jobs:',
        ];

        if ($failedJobs === []) {
            $sections[] = '  NONE';
        } else {
            foreach ($failedJobs as $job) {
                $sections[] = sprintf(
                    '  %s | %s | %s | %s',
                    (string) ($job['failed_at'] ?? 'N/A'),
                    (string) ($job['queue'] ?? 'N/A'),
                    (string) ($job['job'] ?? 'N/A'),
                    (string) ($job['exception_class'] ?? 'N/A'),
                );
            }
        }

        $sections[] = '';
        $sections[] = 'Sanitized application error signatures:';
        $sections[] = $this->recentLogExtract();

        return [
            'failed_jobs' => $failedJobs,
            'log' => $this->redactor->text(implode(PHP_EOL, $sections).PHP_EOL),
        ];
    }

    private function recentLogExtract(): string
    {
        $paths = $this->files->glob(storage_path('logs/laravel*.log')) ?: [];
        usort($paths, static fn (string $left, string $right): int => ((int) @filemtime($right)) <=> ((int) @filemtime($left)));
        $paths = array_slice($paths, 0, self::MAX_LOG_FILES);
        usort($paths, static fn (string $left, string $right): int => ((int) @filemtime($left)) <=> ((int) @filemtime($right)));

        if ($paths === []) {
            return '  No Laravel log files found.';
        }

        /** @var array<string, array{first_time: string, last_time: string, level: string, exception_class: string, fingerprint: string, occurrences: int, order: int}> $signatures */
        $signatures = [];
        $eventOrder = 0;

        foreach ($paths as $path) {
            $contents = $this->tail($path, self::MAX_LOG_BYTES);
            if ($contents === '') {
                continue;
            }

            $lines = preg_split('/\R/', $contents) ?: [];
            $lines = array_slice($lines, -self::MAX_LOG_LINES);
            $events = [];
            $current = null;

            foreach ($lines as $line) {
                if (preg_match('/^\[(?<time>[^\]]+)\](?:\s+|\.)[^.\s]+\.(?<level>[A-Z]+):\s*(?<message>.*)$/i', $line, $matches) === 1) {
                    if (is_array($current)) {
                        $events[] = $current;
                    }

                    $level = strtoupper((string) $matches['level']);
                    $current = in_array($level, ['WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true)
                        ? [
                            'time' => (string) $matches['time'],
                            'level' => $level,
                            'lines' => [(string) $matches['message']],
                        ]
                        : null;

                    continue;
                }

                if (is_array($current) && count($current['lines']) < 12) {
                    $current['lines'][] = $line;
                }
            }

            if (is_array($current)) {
                $events[] = $current;
            }

            foreach ($events as $event) {
                $raw = implode(PHP_EOL, (array) $event['lines']);
                $safe = $this->redactor->text($raw);
                $exceptionClass = $this->exceptionClass($raw) ?? 'UNKNOWN';
                $fingerprint = substr(hash('sha256', $safe), 0, 16);
                $signature = implode('|', [(string) $event['level'], $exceptionClass, $fingerprint]);
                $eventOrder++;

                if (! isset($signatures[$signature])) {
                    $signatures[$signature] = [
                        'first_time' => (string) $event['time'],
                        'last_time' => (string) $event['time'],
                        'level' => (string) $event['level'],
                        'exception_class' => $exceptionClass,
                        'fingerprint' => $fingerprint,
                        'occurrences' => 1,
                        'order' => $eventOrder,
                    ];

                    continue;
                }

                $signatures[$signature]['last_time'] = (string) $event['time'];
                $signatures[$signature]['occurrences']++;
                $signatures[$signature]['order'] = $eventOrder;
            }
        }

        if ($signatures === []) {
            return '  No recent warning or error entries found.';
        }

        $summaries = array_values($signatures);
        usort($summaries, static fn (array $left, array $right): int => ((int) $right['order']) <=> ((int) $left['order']));

        $output = [];
        foreach (array_slice($summaries, 0, 100) as $summary) {
            $time = $summary['first_time'] === $summary['last_time']
                ? $summary['last_time']
                : $summary['first_time'].' .. '.$summary['last_time'];

            $output[] = sprintf(
                '%s | %s | %s | fingerprint=%s | occurrences=%d',
                $time,
                $summary['level'],
                $summary['exception_class'],
                $summary['fingerprint'],
                $summary['occurrences'],
            );
        }

        return implode(PHP_EOL, $output);
    }

    private function exceptionClass(string $value): ?string
    {
        if (preg_match('/\b([A-Za-z_\\\\][A-Za-z0-9_\\\\]*(?:Exception|Error|Throwable))\b/', $value, $matches) === 1) {
            return Str::limit($matches[1], 190, '');
        }

        return null;
    }

    private function tail(string $path, int $maximumBytes): string
    {
        $handle = @fopen($path, 'rb');
        if (! is_resource($handle)) {
            return '';
        }

        try {
            $size = (int) (@filesize($path) ?: 0);
            $offset = max(0, $size - $maximumBytes);
            if ($offset > 0) {
                fseek($handle, $offset);
            }

            $contents = stream_get_contents($handle, $maximumBytes);

            return is_string($contents) ? $contents : '';
        } finally {
            fclose($handle);
        }
    }

    /** @return array{free_bytes: int|null, total_bytes: int|null, used_percent: float|null} */
    private function diskInformation(): array
    {
        $free = @disk_free_space(base_path());
        $total = @disk_total_space(base_path());

        return [
            'free_bytes' => is_numeric($free) ? (int) $free : null,
            'total_bytes' => is_numeric($total) ? (int) $total : null,
            'used_percent' => is_numeric($free) && is_numeric($total) && (float) $total > 0
                ? round((((float) $total - (float) $free) / (float) $total) * 100, 2)
                : null,
        ];
    }

    /** @param array<string, mixed> $system @param array<string, mixed> $modules @param array<string, mixed> $migrations @param array<string, mixed> $updates @param array<string, mixed> $errors */
    private function report(Carbon $generatedAt, array $system, array $modules, array $migrations, array $updates, array $errors): string
    {
        $lines = [
            __('KaevCMS diagnostic package'),
            str_repeat('=', 32),
            __('Generated at: :value', ['value' => $generatedAt->toIso8601String()]),
            __('The package contains sanitized technical information only. Secrets and personal data are removed.'),
            '',
            __('System summary'),
            str_repeat('-', 20),
            __('KaevCMS: :value', ['value' => $system['cms']['version'] ?? 'N/A']),
            __('PHP: :value', ['value' => $system['software']['php'] ?? 'N/A']),
            __('Laravel: :value', ['value' => $system['software']['laravel'] ?? 'N/A']),
            __('Operating system: :value', ['value' => $system['software']['os'] ?? 'N/A']),
            __('Environment: :value', ['value' => $system['environment']['name'] ?? 'N/A']),
            __('CMS database status: :value', ['value' => ($system['database']['connected'] ?? false) ? 'OK' : 'ERROR']),
            __('Scheduler status: :value', ['value' => $system['runtime']['scheduler']['status'] ?? 'N/A']),
            __('Queue processing status: :value', ['value' => $system['runtime']['queue']['status'] ?? 'N/A']),
            __('Pending jobs: :value', ['value' => $system['runtime']['jobs']['pending'] ?? 0]),
            __('Failed jobs: :value', ['value' => $system['runtime']['jobs']['failed'] ?? 0]),
            __('Free disk space: :value bytes', ['value' => $system['disk']['free_bytes'] ?? 'N/A']),
            __('Public installer present: :value', ['value' => ($system['installer']['public_directory_present'] ?? false) ? 'YES' : 'NO']),
            '',
            __('Permissions and components'),
            str_repeat('-', 26),
        ];

        foreach ((array) ($system['permissions'] ?? []) as $component) {
            $lines[] = sprintf(
                '- %s | %s | %s',
                (string) ($component['label'] ?? 'N/A'),
                (string) ($component['status'] ?? 'N/A'),
                (string) ($component['details'] ?? ''),
            );
        }

        $lines[] = '';
        $lines[] = __('Modules');
        $lines[] = str_repeat('-', 20);
        foreach ((array) ($modules['modules'] ?? []) as $module) {
            $lines[] = sprintf(
                '- %s %s | %s | pending migrations: %d',
                (string) ($module['id'] ?? 'unknown'),
                (string) ($module['version'] ?? 'unknown'),
                (string) ($module['status'] ?? 'unknown'),
                (int) ($module['pending_count'] ?? 0),
            );
        }
        if (($modules['modules'] ?? []) === []) {
            $lines[] = '- NONE';
        }

        $lines[] = '';
        $lines[] = __('Database migrations');
        $lines[] = str_repeat('-', 20);
        $lines[] = __('Applied migrations: :value', ['value' => $migrations['applied_count'] ?? 0]);
        $lines[] = __('Pending migrations: :value', ['value' => $migrations['pending_count'] ?? 0]);
        foreach ((array) ($migrations['pending'] ?? []) as $migration) {
            $lines[] = '- PENDING '.$migration;
        }

        $lines[] = '';
        $lines[] = __('Recent CMS updates');
        $lines[] = str_repeat('-', 20);
        foreach ((array) ($updates['updates'] ?? []) as $update) {
            $lines[] = sprintf(
                '- %s -> %s | %s | %s',
                (string) ($update['from_version'] ?? 'N/A'),
                (string) ($update['target_version'] ?? 'N/A'),
                (string) ($update['status'] ?? 'N/A'),
                ($update['error_present'] ?? false)
                    ? 'error '.(string) ($update['error_class'] ?? 'UNKNOWN').' #'.(string) ($update['error_fingerprint'] ?? 'N/A')
                    : '',
            );
        }
        if (($updates['updates'] ?? []) === []) {
            $lines[] = '- NONE';
        }

        $lines[] = '';
        $lines[] = __('Recent errors');
        $lines[] = str_repeat('-', 20);
        $lines[] = __('Failed queue jobs included: :value', ['value' => count((array) ($errors['failed_jobs'] ?? []))]);
        $lines[] = __('See recent-errors.log for sanitized error signatures.');

        return $this->redactor->text(implode(PHP_EOL, $lines).PHP_EOL);
    }

    private function readme(Carbon $generatedAt): string
    {
        return implode(PHP_EOL, [
            'KaevCMS diagnostic package',
            'Generated: '.$generatedAt->toIso8601String(),
            '',
            'This archive contains technical diagnostics prepared for support.',
            'It does not copy .env, APP_KEY, passwords, tokens, cookies, database credentials, user records or raw database files.',
            'Recent logs are grouped by signature and reduced to first/last timestamps, severity, exception class, fingerprint and occurrence count; raw messages are not included.',
            '',
            'Files:',
            '- diagnostic-report.txt — readable summary',
            '- system.json — versions, environment, permissions, databases, queue, scheduler and disk state',
            '- modules.json — installed module and module migration state',
            '- migrations.json — core migration state',
            '- updates.json — recent CMS update state',
            '- recent-errors.log — sanitized recent warning/error signatures and failed-job classes',
            '',
            'Before sending the archive, the administrator may inspect every file inside it.',
            '',
            'RU: Архив содержит обезличенную техническую диагностику. Секреты, персональные данные, .env и файлы базы данных в него не копируются.',
        ]).PHP_EOL;
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        return json_encode(
            $this->redactor->arrayValue($value),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ).PHP_EOL;
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_link($directory)) {
            throw new RuntimeException(__('The diagnostic package directory is unsafe.'));
        }

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException(__('Could not create the diagnostic package directory.'));
        }

        if (! is_writable($directory)) {
            throw new RuntimeException(__('The diagnostic package directory is not writable.'));
        }
    }

    private function removeExpiredPackages(string $directory): void
    {
        $threshold = time() - 86400;

        foreach ($this->files->glob($directory.DIRECTORY_SEPARATOR.'*.zip') ?: [] as $path) {
            $modifiedAt = @filemtime($path);
            if (is_int($modifiedAt) && $modifiedAt < $threshold) {
                @unlink($path);
            }
        }
    }
}
