<?php

namespace App\Services;

use App\Services\Infrastructure\RuntimeDiagnostics;
use App\Services\Security\EncryptionHealth;
use App\Services\Servers\ExternalDatabaseInformation;
use App\Support\KaevCMS;
use App\Support\PasswordHashing;
use App\Support\TrustedProxyConfiguration;
use Composer\Composer;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use PDO;
use Throwable;

final class SystemInformation
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly MailSettings $mailSettings,
        private readonly RuntimeDiagnostics $runtimeDiagnostics,
        private readonly EncryptionHealth $encryptionHealth,
        private readonly ExternalDatabaseInformation $externalDatabaseInformation,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $database = $this->databaseInformation();
        $proxy = $this->proxyInformation();
        $extensions = $this->extensionInformation();
        $runtime = $this->runtimeDiagnostics->overview();
        $encryption = $this->encryptionHealth->inspect();
        $components = $this->componentInformation($database, $proxy, $runtime, $encryption);
        $externalDatabases = $this->externalDatabaseInformation->collect();

        $information = [
            'cms' => [
                'version' => KaevCMS::version(),
            ],
            'software' => [
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'composer' => $this->composerVersion() ?? __('Could not determine'),
                'os' => $this->operatingSystem(),
                'architecture' => (PHP_INT_SIZE * 8).'-bit',
                'sapi' => PHP_SAPI,
            ],
            'environment' => [
                'name' => app()->environment(),
                'debug' => (bool) config('app.debug'),
                'php_timezone' => date_default_timezone_get(),
                'cms_timezone' => (string) config('app.timezone'),
                'cache' => (string) config('cache.default'),
                'session' => (string) config('session.driver'),
                'queue' => (string) config('queue.default'),
                'mail' => (string) config('mail.default'),
                'logging' => (string) config('logging.default'),
            ],
            'security' => [
                ...$this->passwordHashInformation(),
                'encryption' => $encryption,
            ],
            'database' => $database,
            'proxy' => $proxy,
            'runtime' => $runtime,
            'components' => $components,
            'extensions' => $extensions,
            'external_databases' => $externalDatabases,
        ];

        $information['report'] = $this->buildReport($information);

        return $information;
    }

    /**
     * @return array{
     *     connection: string,
     *     driver: string,
     *     driver_label: string,
     *     version: string|null,
     *     connected: bool,
     *     path: string|null,
     *     size: string|null,
     *     error: string|null,
     *     sqlite_busy_timeout: int|null,
     *     sqlite_journal_mode: string|null,
     *     sqlite_synchronous: string|null,
     *     sqlite_production_warning: bool
     * }
     */
    private function databaseInformation(): array
    {
        $connectionName = (string) config('database.default');
        $driver = (string) config("database.connections.{$connectionName}.driver", 'unknown');
        $version = null;
        $connected = false;
        $error = null;
        $sqliteBusyTimeout = null;
        $sqliteJournalMode = null;
        $sqliteSynchronous = null;

        try {
            $connection = $this->database->connection($connectionName);
            $connection->select('select 1');
            $connected = true;

            try {
                $serverVersion = $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
                $version = is_scalar($serverVersion) ? (string) $serverVersion : null;
            } catch (Throwable) {
                $version = null;
            }

            if ($driver === 'sqlite') {
                $sqliteBusyTimeout = $this->sqlitePragmaInteger($connection, 'busy_timeout');
                $sqliteJournalMode = $this->sqlitePragmaString($connection, 'journal_mode');
                $sqliteSynchronous = $this->sqliteSynchronousLabel(
                    $this->sqlitePragmaInteger($connection, 'synchronous'),
                );
            }
        } catch (Throwable $exception) {
            $error = $exception::class;
        }

        $databasePath = null;
        $databaseSize = null;

        if ($driver === 'sqlite') {
            $configuredPath = (string) config("database.connections.{$connectionName}.database", '');

            if ($configuredPath === ':memory:') {
                $databasePath = ':memory:';
            } elseif ($configuredPath !== '') {
                $databasePath = $this->relativeProjectPath($configuredPath);
                $databaseSize = is_file($configuredPath)
                    ? $this->formatBytes((int) filesize($configuredPath))
                    : __('File not found');
            }
        }

        return [
            'connection' => $connectionName,
            'driver' => $driver,
            'driver_label' => $this->databaseDriverLabel($driver),
            'version' => $version,
            'connected' => $connected,
            'path' => $databasePath,
            'size' => $databaseSize,
            'error' => $error,
            'sqlite_busy_timeout' => $sqliteBusyTimeout,
            'sqlite_journal_mode' => $sqliteJournalMode,
            'sqlite_synchronous' => $sqliteSynchronous,
            'sqlite_production_warning' => $driver === 'sqlite' && app()->environment('production'),
        ];
    }

    /**
     * @return array{enabled: bool, trusts_all: bool, valid_count: int, invalid_count: int}
     */
    private function proxyInformation(): array
    {
        $configuration = TrustedProxyConfiguration::parse(
            config('infrastructure.trusted_proxies'),
        );

        return [
            'enabled' => $configuration['trusts_all'] || $configuration['valid'] !== [],
            'trusts_all' => $configuration['trusts_all'],
            'valid_count' => count($configuration['valid']),
            'invalid_count' => count($configuration['invalid']),
        ];
    }

    private function sqlitePragmaInteger(Connection $connection, string $pragma): ?int
    {
        $value = $this->sqlitePragmaValue($connection, $pragma);

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : null;
    }

    private function sqlitePragmaString(Connection $connection, string $pragma): ?string
    {
        $value = $this->sqlitePragmaValue($connection, $pragma);

        return is_scalar($value) ? strtolower((string) $value) : null;
    }

    private function sqlitePragmaValue(Connection $connection, string $pragma): mixed
    {
        try {
            $row = $connection->selectOne("PRAGMA {$pragma}");

            if (! is_object($row)) {
                return null;
            }

            $values = get_object_vars($row);

            return $values === [] ? null : reset($values);
        } catch (Throwable) {
            return null;
        }
    }

    private function sqliteSynchronousLabel(?int $value): ?string
    {
        return match ($value) {
            0 => 'OFF',
            1 => 'NORMAL',
            2 => 'FULL',
            3 => 'EXTRA',
            default => null,
        };
    }

    /**
     * @param  array{
     *     connection: string,
     *     driver: string,
     *     driver_label: string,
     *     version: string|null,
     *     connected: bool,
     *     path: string|null,
     *     size: string|null,
     *     error: string|null,
     *     sqlite_busy_timeout: int|null,
     *     sqlite_journal_mode: string|null,
     *     sqlite_synchronous: string|null,
     *     sqlite_production_warning: bool
     * }  $database
     * @param  array{enabled: bool, trusts_all: bool, valid_count: int, invalid_count: int}  $proxy
     * @param  array{
     *     queue: array{state: string, status: string, details: string},
     *     scheduler: array{state: string, status: string, details: string}
     * }  $runtime
     * @param  array{state: string, status: string, details: string}  $encryption
     * @return array<int, array{label: string, state: string, status: string, details: string}>
     */
    private function componentInformation(array $database, array $proxy, array $runtime, array $encryption): array
    {
        $components = [];

        $databaseState = ! $database['connected']
            ? 'danger'
            : ($database['sqlite_production_warning'] ? 'warning' : 'success');

        $components[] = [
            'label' => __('Database'),
            'state' => $databaseState,
            'status' => ! $database['connected']
                ? __('Error')
                : ($database['sqlite_production_warning'] ? __('Testing database in production') : __('Working')),
            'details' => ! $database['connected']
                ? __('Could not establish a connection')
                : ($database['sqlite_production_warning']
                    ? __('SQLite is intended for local development and testing. Use MySQL or MariaDB for a public site.')
                    : trim((string) $database['driver_label'].' '.(string) ($database['version'] ?? ''))),
        ];

        $proxyState = $proxy['invalid_count'] > 0 || $proxy['trusts_all'] ? 'warning' : ($proxy['enabled'] ? 'success' : 'neutral');
        $components[] = [
            'label' => __('Trusted proxies'),
            'state' => $proxyState,
            'status' => match (true) {
                $proxy['invalid_count'] > 0 => __('Configuration warning'),
                $proxy['trusts_all'] => __('All proxies are trusted'),
                $proxy['enabled'] => __('Configured'),
                default => __('Not configured'),
            },
            'details' => match (true) {
                $proxy['invalid_count'] > 0 => __('Some TRUSTED_PROXIES entries are invalid and were ignored.'),
                $proxy['trusts_all'] => __('Use TRUSTED_PROXIES=* only when the web server cannot be reached directly.'),
                $proxy['enabled'] => __('Trusted proxy addresses configured: :count', ['count' => $proxy['valid_count']]),
                default => __('This is normal unless the site is behind Cloudflare or another reverse proxy.'),
            },
        ];

        foreach ([
            __('Storage directory') => storage_path(),
            __('Bootstrap cache directory') => base_path('bootstrap/cache'),
            __('News image uploads') => rtrim((string) config('cms.news.uploads_path', public_path('uploads')), '/\\').DIRECTORY_SEPARATOR.'news',
            __('Page image uploads') => rtrim((string) config('cms.pages.uploads_path', public_path('uploads')), '/\\').DIRECTORY_SEPARATOR.'pages',
            __('Logo and favicon uploads') => rtrim((string) config('cms.settings.uploads_path', public_path('uploads')), '/\\').DIRECTORY_SEPARATOR.'settings',
            __('Account avatar uploads') => (string) config('cms.account_avatars.uploads_path', public_path('uploads/account-avatars')),
            __('Game asset uploads') => (string) config('cms.game_assets.uploads_path', public_path('uploads/game-assets')),
        ] as $label => $path) {
            $writable = $this->ensureWritableDirectory($path);
            $components[] = [
                'label' => $label,
                'state' => $writable ? 'success' : 'danger',
                'status' => $writable ? __('Writable') : __('Not writable'),
                'details' => $this->relativeProjectPath($path),
            ];
        }

        try {
            $mailConfigured = $this->mailSettings->isConfigured();
            $mailReady = $this->mailSettings->isReady();

            $components[] = [
                'label' => __('Mail system'),
                'state' => $mailReady ? 'success' : ($mailConfigured ? 'warning' : 'neutral'),
                'status' => $mailReady ? __('Configured and verified') : ($mailConfigured ? __('Verification required') : __('Not configured')),
                'details' => $mailReady
                    ? __('A test email was sent successfully')
                    : __('Check the Mail tab'),
            ];
        } catch (Throwable) {
            $components[] = [
                'label' => __('Mail system'),
                'state' => 'warning',
                'status' => __('Status could not be determined'),
                'details' => __('Could not read mail settings'),
            ];
        }

        $components[] = [
            'label' => __('Encrypted secrets'),
            'state' => $encryption['state'],
            'status' => $encryption['status'],
            'details' => $encryption['details'],
        ];

        $components[] = [
            'label' => __('Queues'),
            'state' => $runtime['queue']['state'],
            'status' => $runtime['queue']['status'],
            'details' => $runtime['queue']['details'],
        ];

        $components[] = [
            'label' => __('Laravel scheduler'),
            'state' => $runtime['scheduler']['state'],
            'status' => $runtime['scheduler']['status'],
            'details' => $runtime['scheduler']['details'],
        ];

        return $components;
    }

    /**
     * @return array<int, array{name: string, loaded: bool, required: bool}>
     */
    private function extensionInformation(): array
    {
        $required = [
            'ctype',
            'dom',
            'fileinfo',
            'mbstring',
            'openssl',
            'pdo',
            'pdo_mysql',
            'tokenizer',
            'xml',
        ];

        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver", 'unknown');
        $optional = ['curl', 'zip', 'intl', 'gd'];

        if ($driver === 'sqlite') {
            $required[] = 'pdo_sqlite';
        } else {
            $optional[] = 'pdo_sqlite';
        }
        $result = [];

        foreach ($required as $extension) {
            $result[] = [
                'name' => $extension,
                'loaded' => extension_loaded($extension),
                'required' => true,
            ];
        }

        foreach ($optional as $extension) {
            $result[] = [
                'name' => $extension,
                'loaded' => extension_loaded($extension),
                'required' => false,
            ];
        }

        return $result;
    }

    private function composerVersion(): ?string
    {
        if (class_exists(Composer::class)) {
            return Composer::VERSION;
        }

        if (! function_exists('shell_exec')) {
            return null;
        }

        $disabledFunctions = array_filter(array_map(
            static fn (string $function): string => trim($function),
            explode(',', (string) ini_get('disable_functions')),
        ));

        if (in_array('shell_exec', $disabledFunctions, true)) {
            return null;
        }

        $output = @shell_exec('composer --version --no-ansi 2>&1');

        if (! is_string($output)) {
            return null;
        }

        if (preg_match('/Composer version\s+([^\s]+)/i', $output, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @return array{driver: string, label: string, argon2id_supported: bool}
     */
    private function passwordHashInformation(): array
    {
        return [
            'driver' => PasswordHashing::effectiveDriver(),
            'label' => PasswordHashing::label(),
            'argon2id_supported' => PasswordHashing::argon2idSupported(),
        ];
    }

    private function operatingSystem(): string
    {
        $release = trim((string) php_uname('r'));

        return trim(PHP_OS_FAMILY.' '.$release);
    }

    private function databaseDriverLabel(string $driver): string
    {
        return match ($driver) {
            'sqlite' => 'SQLite',
            'mysql' => 'MySQL',
            'mariadb' => 'MariaDB',
            'pgsql' => 'PostgreSQL',
            'sqlsrv' => 'Microsoft SQL Server',
            default => Str::headline($driver),
        };
    }

    private function ensureWritableDirectory(string $path): bool
    {
        if (! is_dir($path) && ! @mkdir($path, 0775, true) && ! is_dir($path)) {
            return false;
        }

        $testFile = null;
        $written = false;

        try {
            $testFile = rtrim($path, '/\\').DIRECTORY_SEPARATOR.'.kaevcms-system-check-'.bin2hex(random_bytes(8));
            $written = file_put_contents($testFile, 'ok', LOCK_EX) !== false && is_file($testFile);
        } catch (Throwable) {
            $written = false;
        } finally {
            if (is_string($testFile) && is_file($testFile)) {
                for ($attempt = 0; $attempt < 3; $attempt++) {
                    if (@unlink($testFile) || ! is_file($testFile)) {
                        break;
                    }

                    usleep(100000);
                    clearstatcache(true, $testFile);
                }
            }
        }

        return $written;
    }

    private function relativeProjectPath(string $path): string
    {
        $base = str_replace('\\', '/', rtrim(base_path(), '/\\'));
        $normalized = str_replace('\\', '/', $path);

        if (str_starts_with(Str::lower($normalized), Str::lower($base.'/'))) {
            return ltrim(substr($normalized, strlen($base)), '/');
        }

        return basename($normalized);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return __(':bytes B', ['bytes' => $bytes]);
        }

        $units = [__('KB'), __('MB'), __('GB'), __('TB')];
        $value = $bytes / 1024;
        $unit = $units[0];

        foreach (array_slice($units, 1) as $nextUnit) {
            if ($value < 1024) {
                break;
            }

            $value /= 1024;
            $unit = $nextUnit;
        }

        return number_format($value, $value >= 10 ? 1 : 2, app()->getLocale() === 'ru' ? ',' : '.', app()->getLocale() === 'ru' ? ' ' : ',').' '.$unit;
    }

    /**
     * @param  array<string, mixed>  $information
     */
    private function buildReport(array $information): string
    {
        $database = $information['database'];
        $environment = $information['environment'];
        $software = $information['software'];
        $security = $information['security'];
        $proxy = $information['proxy'];

        return implode(PHP_EOL, [
            __('KaevCMS: :value', ['value' => $information['cms']['version']]),
            __('Laravel: :value', ['value' => $software['laravel']]),
            __('PHP: :value', ['value' => $software['php']]),
            __('Composer: :value', ['value' => $software['composer']]),
            __('OS: :value', ['value' => $software['os']]),
            __('Architecture: :value', ['value' => $software['architecture']]),
            __('PHP SAPI: :value', ['value' => $software['sapi']]),
            __('Password hash: :value', ['value' => $security['label']]),
            __('Encrypted secrets: :value', ['value' => $security['encryption']['status']]),
            __('Unavailable encrypted values: :value', ['value' => $security['encryption']['invalid_values_total']]),
            __('Database: :value', ['value' => $database['driver_label'].($database['version'] ? ' '.$database['version'] : '')]),
            __('Database connection: :value', ['value' => $database['connected'] ? 'OK' : 'ERROR']),
            ...($database['driver'] === 'sqlite' ? [
                __('SQLite busy timeout: :value ms', ['value' => $database['sqlite_busy_timeout'] ?? __('Could not determine')]),
                __('SQLite journal mode: :value', ['value' => $database['sqlite_journal_mode'] ?? __('Could not determine')]),
                __('SQLite synchronous mode: :value', ['value' => $database['sqlite_synchronous'] ?? __('Could not determine')]),
            ] : []),
            __('Trusted proxies: :value', ['value' => match (true) {
                $proxy['trusts_all'] => 'ALL',
                $proxy['enabled'] => 'CONFIGURED',
                default => 'DISABLED',
            }]),
            'APP_ENV: '.$environment['name'],
            'APP_DEBUG: '.($environment['debug'] ? 'true' : 'false'),
            __('CMS timezone: :value', ['value' => $environment['cms_timezone']]),
            __('Cache: :value', ['value' => $environment['cache']]),
            __('Session: :value', ['value' => $environment['session']]),
            __('Queue: :value', ['value' => $environment['queue']]),
            __('Scheduler status: :value', ['value' => $information['runtime']['scheduler']['status']]),
            __('Queue processing status: :value', ['value' => $information['runtime']['queue']['status']]),
            __('Pending jobs: :value', ['value' => $information['runtime']['jobs']['pending']]),
            __('Failed jobs: :value', ['value' => $information['runtime']['jobs']['failed']]),
            __('Last successful job: :value', ['value' => $information['runtime']['queue']['last_succeeded_at']?->toIso8601String() ?? 'N/A']),
            __('Active queues: :value', ['value' => implode(', ', array_map(
                static fn (array $queue): string => $queue['name'].'('.$queue['pending'].'/'.$queue['failed'].')',
                $information['runtime']['jobs']['queues'],
            )) ?: 'NONE']),
            __('Mail: :value', ['value' => $environment['mail']]),
            __('Logging: :value', ['value' => $environment['logging']]),
            '',
            ...$this->externalDatabaseInformation->reportLines($information['external_databases']),
        ]);
    }
}
