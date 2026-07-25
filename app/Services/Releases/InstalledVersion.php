<?php

namespace App\Services\Releases;

use App\Models\CmsSetting;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class InstalledVersion
{
    public const SETTING_KEY = 'system.installed_version';

    public function current(): ?string
    {
        $markerVersion = $this->markerVersion();
        $databaseVersion = $this->databaseVersion();

        if ($markerVersion !== null && $databaseVersion !== null && $markerVersion !== $databaseVersion) {
            throw new RuntimeException('Installed version marker does not match the database value.');
        }

        return $markerVersion ?? $databaseVersion;
    }

    public function markerVersion(): ?string
    {
        $path = $this->markerPath();
        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read the installed version marker.');
        }

        $decoded = json_decode($contents, true);
        $version = is_array($decoded) ? ($decoded['version'] ?? null) : null;

        if (! is_string($version) || ! $this->isValidVersion($version)) {
            throw new RuntimeException('Installed version marker is invalid.');
        }

        return $version;
    }

    public function databaseVersion(): ?string
    {
        try {
            if (! Schema::hasTable('cms_settings')) {
                return null;
            }

            $value = CmsSetting::query()->where('key', self::SETTING_KEY)->value('value');
        } catch (Throwable) {
            return null;
        }

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || ! $this->isValidVersion($value)) {
            throw new RuntimeException('Installed version value in the database is invalid.');
        }

        return $value;
    }

    public function mark(string $version): void
    {
        if (! $this->isValidVersion($version)) {
            throw new RuntimeException("Invalid installed version: {$version}");
        }

        if (! Schema::hasTable('cms_settings')) {
            throw new RuntimeException('CMS settings table is not available. Run database migrations first.');
        }

        $path = $this->markerPath();
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the installed version marker directory.');
        }

        $previousMarker = is_file($path) ? file_get_contents($path) : null;
        $payload = json_encode([
            'version' => $version,
            'recorded_at' => now()->utc()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (! is_string($payload)) {
            throw new RuntimeException('Unable to encode the installed version marker.');
        }

        $this->writeMarker($path, $payload."\n");

        try {
            CmsSetting::query()->updateOrCreate(
                ['key' => self::SETTING_KEY],
                ['value' => $version],
            );
        } catch (Throwable $exception) {
            $this->restoreMarker($path, $previousMarker);

            throw $exception;
        }
    }

    public function markerPath(): string
    {
        $path = config('cms.installed_version_marker', storage_path('app/kaevcms/installed-version.json'));

        if (! is_string($path) || trim($path) === '') {
            throw new RuntimeException('Installed version marker path is not configured.');
        }

        return $path;
    }

    private function writeMarker(string $path, string $contents): void
    {
        $temporary = $path.'.tmp.'.bin2hex(random_bytes(8));
        $backup = $path.'.bak.'.bin2hex(random_bytes(8));

        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the installed version marker.');
        }

        $hadPreviousMarker = is_file($path);
        if ($hadPreviousMarker && ! rename($path, $backup)) {
            @unlink($temporary);

            throw new RuntimeException('Unable to back up the installed version marker.');
        }

        if (! rename($temporary, $path)) {
            @unlink($temporary);
            if ($hadPreviousMarker) {
                @rename($backup, $path);
            }

            throw new RuntimeException('Unable to activate the installed version marker.');
        }

        if ($hadPreviousMarker) {
            @unlink($backup);
        }
    }

    private function restoreMarker(string $path, string|false|null $previousMarker): void
    {
        if (is_string($previousMarker)) {
            $this->writeMarker($path, $previousMarker);

            return;
        }

        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function isValidVersion(string $version): bool
    {
        return preg_match('/\A\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?\z/', $version) === 1;
    }
}
