<?php

namespace App\Services\Updates;

use App\Models\SystemUpdate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class VdsUpdateAgent
{
    public function __construct(
        private readonly ?string $markerPathOverride = null,
        private readonly ?string $requestDirectoryOverride = null,
        private readonly ?string $projectRootOverride = null,
    ) {}

    public const SCHEMA = 1;

    public const AGENT_VERSION = 1;

    public function status(): VdsUpdateAgentStatus
    {
        if (! $this->supportedPlatform()) {
            return new VdsUpdateAgentStatus(
                'unsupported',
                __('The VDS update agent is available only on Linux installations.'),
            );
        }

        $marker = $this->readMarker();
        if ($marker === null) {
            return new VdsUpdateAgentStatus(
                $this->markerExists() ? 'invalid' : 'missing',
                $this->markerExists()
                    ? __('The VDS update agent registration does not match this KaevCMS installation.')
                    : __('The VDS update agent is not installed.'),
            );
        }

        $projectRoot = realpath($this->projectRoot());
        $registeredRoot = isset($marker['project_root']) && is_string($marker['project_root'])
            ? realpath($marker['project_root'])
            : false;
        $schema = $marker['schema'] ?? null;
        $version = $marker['agent_version'] ?? null;

        if (! is_string($projectRoot)
            || ! is_string($registeredRoot)
            || $registeredRoot !== $projectRoot
            || $schema !== self::SCHEMA
            || ! is_int($version)
            || $version < 1) {
            return new VdsUpdateAgentStatus(
                'invalid',
                __('The VDS update agent registration does not match this KaevCMS installation.'),
                $marker,
            );
        }

        $directory = $this->requestDirectory();
        if (! is_dir($directory) || ! is_writable($directory)) {
            return new VdsUpdateAgentStatus(
                'invalid',
                __('The VDS update agent request directory is unavailable or not writable by PHP-FPM.'),
                $marker,
            );
        }

        return new VdsUpdateAgentStatus(
            'ready',
            __('The VDS update agent is ready.'),
            $marker,
        );
    }

    public function supportedPlatform(): bool
    {
        $override = config('cms.updates.vds_agent_supported');
        if (is_bool($override)) {
            return $override;
        }

        return PHP_OS_FAMILY === 'Linux';
    }

    public function recommended(): bool
    {
        $override = config('cms.updates.vds_agent_recommended');
        if (is_bool($override)) {
            return $override;
        }

        return $this->supportedPlatform()
            && ($this->markerExists() || ! is_writable($this->projectRoot()));
    }

    public function installCommand(): string
    {
        return 'cd '.escapeshellarg($this->projectRoot()).' && sudo bash deployment/vds/install-update-agent.sh';
    }

    public function diagnosticsCommand(): string
    {
        return 'cd '.escapeshellarg($this->projectRoot()).' && php artisan kaevcms:update-agent:status';
    }

    public function requestDirectory(): string
    {
        return $this->requestDirectoryOverride ?? storage_path('app/kaevcms/update-agent/requests');
    }

    public function markerPath(): string
    {
        return $this->markerPathOverride ?? storage_path('app/kaevcms/update-agent/agent.json');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function register(array $metadata): void
    {
        $projectRoot = realpath($this->projectRoot());
        if (! is_string($projectRoot)) {
            throw new RuntimeException('Unable to resolve the KaevCMS project root.');
        }

        $directory = dirname($this->markerPath());
        $this->ensureDirectory($directory, 0770);
        $this->ensureDirectory($this->requestDirectory(), 0770);

        $payload = [
            'schema' => self::SCHEMA,
            'agent_version' => self::AGENT_VERSION,
            'project_root' => $projectRoot,
            'service_name' => $this->safeMetadataString($metadata, 'service_name'),
            'path_unit' => $this->safeMetadataString($metadata, 'path_unit'),
            'php_binary' => $this->safeMetadataString($metadata, 'php_binary'),
            'installed_at' => $this->safeMetadataString($metadata, 'installed_at') ?? now()->utc()->toIso8601String(),
            'last_seen_at' => now()->utc()->toIso8601String(),
        ];

        $this->writeJsonAtomically($this->markerPath(), $payload, 0660);
    }

    public function touch(): void
    {
        $marker = $this->readMarker();
        if ($marker === null) {
            return;
        }

        $marker['last_seen_at'] = now()->utc()->toIso8601String();
        $this->writeJsonAtomically($this->markerPath(), $marker, 0660);
    }

    public function unregister(): void
    {
        if (is_file($this->markerPath()) && ! @unlink($this->markerPath())) {
            throw new RuntimeException('Unable to remove the VDS update agent registration marker.');
        }
    }

    public function queue(SystemUpdate $update, string $maintenanceSecret): SystemUpdate
    {
        if (! $this->status()->isReady()) {
            throw new RuntimeException(__('The VDS update agent is not ready. Install or repair it before starting the update.'));
        }

        if (preg_match('/\A[a-zA-Z0-9]{32,128}\z/', $maintenanceSecret) !== 1) {
            throw new RuntimeException(__('The update recovery secret is invalid.'));
        }

        $requestPath = $this->requestPath($update->uuid);

        /** @var array{update: SystemUpdate, sha256: string} $queuedState */
        $queuedState = DB::transaction(function () use ($update, $maintenanceSecret): array {
            $locked = SystemUpdate::query()->lockForUpdate()->find($update->getKey());
            if (! $locked instanceof SystemUpdate || ! $locked->isStaged() || $locked->isQueuedForAgent()) {
                throw new RuntimeException(__('Only a staged update package can be queued.'));
            }

            $anotherUpdateExists = SystemUpdate::query()
                ->where($locked->getKeyName(), '!=', $locked->getKey())
                ->where(function (Builder $query): void {
                    $query->where('status', SystemUpdate::STATUS_APPLYING)
                        ->orWhereNotNull('agent_requested_at');
                })
                ->exists();
            if ($anotherUpdateExists) {
                throw new RuntimeException(__('Another update is already running or waiting for the VDS agent.'));
            }

            $archivePath = $this->absolutePackagePath($locked);
            if (! is_file($archivePath) || ! is_readable($archivePath)) {
                throw new RuntimeException(__('The staged update archive is missing.'));
            }

            $actualHash = hash_file('sha256', $archivePath);
            if (! is_string($actualHash)
                || ! is_string($locked->package_sha256)
                || ! hash_equals(strtolower($locked->package_sha256), strtolower($actualHash))) {
                throw new RuntimeException(__('The staged update archive checksum no longer matches the verified package.'));
            }

            $locked->forceFill([
                'execution_mode' => SystemUpdate::EXECUTION_VDS_AGENT,
                'maintenance_secret' => $maintenanceSecret,
                'agent_requested_at' => now(),
                'agent_seen_at' => null,
                'error_summary' => null,
            ])->save();

            return ['update' => $locked, 'sha256' => strtolower($actualHash)];
        });

        $queued = $queuedState['update'];
        $actualHash = $queuedState['sha256'];

        try {
            $this->writeJsonAtomically($requestPath, [
                'schema' => self::SCHEMA,
                'uuid' => $queued->uuid,
                'package_sha256' => $actualHash,
                'requested_at' => now()->utc()->toIso8601String(),
            ], 0660);
        } catch (Throwable $exception) {
            DB::transaction(function () use ($queued): void {
                $locked = SystemUpdate::query()->lockForUpdate()->find($queued->getKey());
                if ($locked instanceof SystemUpdate && $locked->isQueuedForAgent()) {
                    $locked->forceFill([
                        'execution_mode' => SystemUpdate::EXECUTION_WEB,
                        'maintenance_secret' => null,
                        'agent_requested_at' => null,
                        'agent_seen_at' => null,
                    ])->save();
                }
            });

            throw $exception;
        }

        return $queued;
    }

    /**
     * @return list<string>
     */
    public function pendingRequests(): array
    {
        $files = glob($this->requestDirectory().'/*.request');
        if (! is_array($files)) {
            return [];
        }

        sort($files, SORT_STRING);

        return array_values(array_filter($files, static fn (string $path): bool => is_file($path)));
    }

    /**
     * @return array{uuid: string, package_sha256: string}|null
     */
    public function readRequest(string $path): ?array
    {
        $requestDirectory = realpath($this->requestDirectory());
        $requestPath = realpath($path);
        if (! is_string($requestDirectory)
            || ! is_string($requestPath)
            || ! str_starts_with($requestPath, rtrim($requestDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            return null;
        }

        $name = basename($requestPath);
        if (preg_match('/\A([0-9a-f-]{36})\.request\z/i', $name, $matches) !== 1 || ! Str::isUuid($matches[1])) {
            return null;
        }

        $contents = file_get_contents($requestPath);
        if (! is_string($contents)) {
            return null;
        }

        try {
            $payload = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($payload)
            || ($payload['schema'] ?? null) !== self::SCHEMA
            || ($payload['uuid'] ?? null) !== $matches[1]
            || ! is_string($payload['package_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/i', $payload['package_sha256']) !== 1) {
            return null;
        }

        return [
            'uuid' => $matches[1],
            'package_sha256' => strtolower($payload['package_sha256']),
        ];
    }

    public function removeRequest(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function absolutePackagePath(SystemUpdate $update): string
    {
        $relative = str_replace('\\', '/', $update->package_path);
        $expected = 'kaevcms/updates/packages/'.$update->uuid.'.zip';
        if ($relative !== $expected) {
            throw new RuntimeException(__('The update package path is invalid.'));
        }

        return storage_path('app'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative));
    }

    private function markerExists(): bool
    {
        return is_file($this->markerPath());
    }

    /** @return array<string, mixed>|null */
    private function readMarker(): ?array
    {
        if (! is_file($this->markerPath()) || ! is_readable($this->markerPath())) {
            return null;
        }

        $contents = file_get_contents($this->markerPath());
        if (! is_string($contents)) {
            return null;
        }

        try {
            $decoded = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function requestPath(string $uuid): string
    {
        if (! Str::isUuid($uuid)) {
            throw new RuntimeException(__('The update request identifier is invalid.'));
        }

        return $this->requestDirectory().DIRECTORY_SEPARATOR.$uuid.'.request';
    }

    private function ensureDirectory(string $path, int $mode): void
    {
        if (! is_dir($path) && ! mkdir($path, $mode, true) && ! is_dir($path)) {
            throw new RuntimeException('Unable to create the VDS update agent directory.');
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            @chmod($path, $mode);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJsonAtomically(string $path, array $payload, int $mode): void
    {
        $directory = dirname($path);
        $this->ensureDirectory($directory, 0770);

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $temporary = $directory.'/.'.basename($path).'.'.bin2hex(random_bytes(8)).'.tmp';

        try {
            if (file_put_contents($temporary, $encoded, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write the VDS update agent state file.');
            }
            if (PHP_OS_FAMILY !== 'Windows') {
                @chmod($temporary, $mode);
            }
            if (! rename($temporary, $path)) {
                throw new RuntimeException('Unable to publish the VDS update agent state file.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function projectRoot(): string
    {
        return $this->projectRootOverride ?? base_path();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function safeMetadataString(array $metadata, string $key): ?string
    {
        $value = $metadata[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : Str::limit($value, 500, '');
    }
}
