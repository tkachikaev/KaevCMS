<?php

namespace App\Services\Mail;

use App\Services\CmsSettings;
use App\Services\Infrastructure\RuntimeDiagnostics;
use App\Services\MailSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Explains why an asynchronous mail mode cannot run on this hosting and prepares the cron
 * line that the database queue needs. Everything here is read-only apart from the cron check
 * timestamp: the capability probes themselves live in {@see MailSettings}.
 */
final class MailHostingDiagnostics
{
    public const KEY_CRON_CHECK_REQUESTED_AT = 'mail.cron_check_requested_at';

    /** Cron fires once a minute, so one missed minute plus the run itself is the longest honest wait. */
    public const CRON_CHECK_TIMEOUT_SECONDS = 130;

    private const MINIMUM_PHP = '8.3.0';

    private const PROCESS_FUNCTIONS = ['proc_open', 'popen', 'exec', 'shell_exec', 'passthru', 'system'];

    /** @var array{binary: string|null, version: string|null, candidates: list<array{binary: string, version: string|null}>}|null */
    private ?array $phpCli = null;

    public function __construct(
        private readonly CmsSettings $settings,
        private readonly RuntimeDiagnostics $runtime,
    ) {}

    /**
     * @return array{
     *     process_functions_available: bool,
     *     disabled_functions: list<string>,
     *     php_cli: string|null,
     *     php_cli_version: string|null,
     *     php_cli_candidates: list<array{binary: string, version: string|null}>,
     *     web_php_version: string,
     *     artisan_path: string,
     *     artisan_readable: bool,
     *     split_install: bool,
     *     panel: string,
     *     panel_hint: string|null,
     *     cron_command: string,
     *     cron_command_panel: string,
     *     pending_mail_jobs: int
     * }
     */
    public function report(): array
    {
        $disabled = $this->disabledProcessFunctions();
        $cli = $this->phpCli();
        $artisan = base_path('artisan');
        $panel = $this->panel();
        $binary = $cli['binary'] ?? 'php';

        return [
            'process_functions_available' => ! in_array('proc_open', $disabled, true),
            'disabled_functions' => $disabled,
            'php_cli' => $cli['binary'],
            'php_cli_version' => $cli['version'],
            'php_cli_candidates' => $cli['candidates'],
            'web_php_version' => PHP_VERSION,
            'artisan_path' => $artisan,
            'artisan_readable' => @is_readable($artisan),
            'split_install' => $this->splitInstall(),
            'panel' => $panel,
            'panel_hint' => $this->panelHint($panel),
            'cron_command' => '* * * * * cd '.escapeshellarg(base_path()).' && '.$this->shellWord($binary).' artisan schedule:run >> /dev/null 2>&1',
            'cron_command_panel' => $this->shellWord($binary).' '.$this->shellWord($artisan).' schedule:run',
            'pending_mail_jobs' => $this->pendingMailJobs(),
        ];
    }

    /**
     * What went wrong and what to do about it, for one mode check that did not pass.
     *
     * @return array{reason: string, solution: string}
     */
    public function explainFailure(string $mode, ?string $errorClass): array
    {
        if ($mode === MailSettings::MODE_DATABASE) {
            return $this->explainDatabaseFailure();
        }

        $report = $this->report();

        if (! $report['process_functions_available']) {
            return [
                'reason' => __('The hosting forbids starting background processes (:functions are disabled in PHP).', [
                    'functions' => implode(', ', $report['disabled_functions']),
                ]),
                'solution' => __('Ask the hosting support to allow :function, or use the database queue with cron. Synchronous delivery keeps working.', ['function' => 'proc_open']),
            ];
        }

        if ($report['php_cli'] === null) {
            return [
                'reason' => __('No PHP :version command-line binary was found, so the background process has nothing to run with.', ['version' => '8.3+']),
                'solution' => __('Use the database queue with cron and give cron the full path to PHP 8.3 or newer from the hosting panel. Synchronous delivery keeps working.'),
            ];
        }

        if (! $report['artisan_readable']) {
            return [
                'reason' => __('The artisan file was not found or cannot be read at :path.', ['path' => $report['artisan_path']]),
                'solution' => __('Check that the private core directory is complete and readable by the web server user.'),
            ];
        }

        if ($errorClass === 'MailDeliveryProbeTimeout') {
            return [
                'reason' => __('The background process was started but did not report back within :seconds seconds. Many hostings stop processes that outlive the web request.', [
                    'seconds' => app(MailSettings::class)->probeTimeoutSeconds(MailSettings::MODE_BACKGROUND),
                ]),
                'solution' => __('Use the database queue with cron instead. Synchronous delivery keeps working.'),
            ];
        }

        return [
            'reason' => __('The background process could not be started (:error).', ['error' => $errorClass ?? __('Unknown error')]),
            'solution' => __('Use the database queue with cron instead. Synchronous delivery keeps working.'),
        ];
    }

    /** @return array{status: string, requested_at: string|null, last_run_at: string|null} */
    public function cronCheck(): array
    {
        $requestedAt = $this->requestedAt();
        $lastRun = $this->runtime->schedulerLastSeenAt();

        if ($requestedAt === null) {
            return ['status' => 'not_tested', 'requested_at' => null, 'last_run_at' => $lastRun?->toIso8601String()];
        }

        $status = match (true) {
            $lastRun !== null && $lastRun->gte($requestedAt) => 'passed',
            $requestedAt->gte(now()->subSeconds(self::CRON_CHECK_TIMEOUT_SECONDS)) => 'pending',
            default => 'failed',
        };

        return [
            'status' => $status,
            'requested_at' => $requestedAt->toIso8601String(),
            'last_run_at' => $lastRun?->toIso8601String(),
        ];
    }

    public function beginCronCheck(): void
    {
        $this->settings->set(self::KEY_CRON_CHECK_REQUESTED_AT, now()->toIso8601String());
    }

    /** @return array{reason: string, solution: string} */
    private function explainDatabaseFailure(): array
    {
        $lastRun = $this->runtime->schedulerLastSeenAt();

        if ($lastRun === null) {
            return [
                'reason' => __('Cron has never run the Laravel Scheduler on this site, so nothing processes the queue.'),
                'solution' => __('Add the cron command shown below in the hosting panel, wait a minute, then press "Check cron".'),
            ];
        }

        if ($lastRun->lt(now()->subMinutes(3))) {
            return [
                'reason' => __('Cron ran the Scheduler last :time and then stopped.', ['time' => $lastRun->diffForHumans()]),
                'solution' => __('Check that the cron job is still enabled and that its PHP path was not changed by a PHP version update.'),
            ];
        }

        return [
            'reason' => __('Cron works, but the test job was not processed in time.'),
            'solution' => __('Run the check again; if it keeps failing, see Settings → Diagnostics for queue errors or ask the hosting support whether the database allows long enough queries.'),
        ];
    }

    private function requestedAt(): ?Carbon
    {
        $value = (string) ($this->settings->get(self::KEY_CRON_CHECK_REQUESTED_AT, '') ?? '');

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private function disabledProcessFunctions(): array
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return array_values(array_filter(
            self::PROCESS_FUNCTIONS,
            fn (string $function): bool => in_array($function, $disabled, true) || ! function_exists($function),
        ));
    }

    /** @return array{binary: string|null, version: string|null, candidates: list<array{binary: string, version: string|null}>} */
    private function phpCli(): array
    {
        if ($this->phpCli !== null) {
            return $this->phpCli;
        }

        $candidates = [];
        $best = null;

        if (in_array('proc_open', $this->disabledProcessFunctions(), true)) {
            // Without proc_open the version cannot be verified; the finder still gives the command to show.
            $found = (new PhpExecutableFinder)->find(false);

            return $this->phpCli = ['binary' => null, 'version' => null, 'candidates' => $found !== false ? [['binary' => $found, 'version' => null]] : []];
        }

        foreach (array_slice($this->candidatePaths(), 0, 14) as $binary) {
            $version = $this->versionOf($binary);
            $candidates[] = ['binary' => $binary, 'version' => $version];

            if ($version === null || version_compare($version, self::MINIMUM_PHP, '<')) {
                continue;
            }

            // The same minor version as the website: identical extensions and limits.
            $sameMinor = strncmp($version, PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.', 4) === 0;
            if ($best === null || ($sameMinor && ! $best['same']) || ($sameMinor === $best['same'] && version_compare($version, $best['version'], '>'))) {
                $best = ['binary' => $binary, 'version' => $version, 'same' => $sameMinor];
            }
        }

        return $this->phpCli = [
            'binary' => $best['binary'] ?? null,
            'version' => $best['version'] ?? null,
            'candidates' => $candidates,
        ];
    }

    /** @return list<string> */
    private function candidatePaths(): array
    {
        $paths = [];
        $found = (new PhpExecutableFinder)->find(false);
        if ($found !== false) {
            $paths[] = $found;
        }

        $major = PHP_MAJOR_VERSION.PHP_MINOR_VERSION;
        $dotted = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        $globs = [
            '/opt/plesk/php/*/bin/php',
            '/opt/cpanel/ea-php*/root/usr/bin/php',
            '/opt/alt/php*/usr/bin/php',
            '/usr/local/php*/bin/php',
            '/usr/local/bin/ea-php*',
            '/usr/bin/php'.$dotted,
            '/usr/bin/php'.$major,
            '/usr/local/bin/php'.$dotted,
            '/usr/local/bin/php',
            '/usr/bin/php',
        ];

        foreach ($globs as $pattern) {
            $matches = @glob($pattern) ?: [];
            rsort($matches, SORT_NATURAL);
            array_push($paths, ...$matches);
        }

        return array_values(array_unique(array_filter(
            $paths,
            fn (string $path): bool => @is_file($path) && @is_executable($path),
        )));
    }

    private function versionOf(string $binary): ?string
    {
        try {
            $process = new Process([$binary, '-r', 'echo PHP_VERSION;'], timeout: 4);
            $process->run();

            $output = trim($process->getOutput());

            return $process->isSuccessful() && preg_match('/^\d+\.\d+\.\d+/', $output) === 1 ? $output : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function splitInstall(): bool
    {
        return realpath(public_path()) !== realpath(base_path('public'));
    }

    private function panel(): string
    {
        $path = str_replace('\\', '/', base_path());

        return match (true) {
            @is_dir('/opt/plesk') || str_starts_with($path, '/var/www/vhosts/') => 'plesk',
            @is_dir('/usr/local/cpanel') => 'cpanel',
            @is_dir('/usr/local/directadmin') => 'directadmin',
            @is_dir('/usr/local/mgr5') => 'ispmanager',
            default => 'generic',
        };
    }

    private function panelHint(string $panel): ?string
    {
        return match ($panel) {
            'plesk' => __('Plesk: open Websites & Domains → Scheduled Tasks → Add Task, type "Run a command", paste the short command, and set the period to every minute.'),
            'cpanel' => __('cPanel: open Cron Jobs, choose "Once Per Minute", and paste the full cron line.'),
            'directadmin' => __('DirectAdmin: open Cron Jobs, add a job with every field set to "*", and paste the command.'),
            'ispmanager' => __('ISPmanager: open Scheduler (Cron), add a job that runs every minute, and paste the command.'),
            default => null,
        };
    }

    private function pendingMailJobs(): int
    {
        try {
            return (int) DB::connection(config('queue.connections.database.connection') ?: null)
                ->table((string) config('queue.connections.database.table', 'jobs'))
                ->whereIn('queue', ['mail', 'mail-probe'])
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function shellWord(string $value): string
    {
        return preg_match('/^[A-Za-z0-9_\-.\/:]+$/', $value) === 1 ? $value : escapeshellarg($value);
    }
}
