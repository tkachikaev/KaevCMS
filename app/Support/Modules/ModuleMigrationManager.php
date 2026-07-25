<?php

namespace App\Support\Modules;

use App\Models\ModuleMigration;
use App\Models\ModuleState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use RuntimeException;
use Throwable;

final class ModuleMigrationManager
{
    public function __construct(
        private readonly int $lockSeconds,
        private readonly Filesystem $files,
    ) {}

    /**
     * @param  array<string, mixed>  $module
     * @return array<string, mixed>
     */
    public function inspect(array $module): array
    {
        $files = $this->migrationFiles($module);
        $declared = is_string($module['migrations_path'] ?? null);
        $result = [
            'has_migrations' => $declared,
            'tracking_available' => true,
            'available_migrations' => array_keys($files),
            'applied_migrations' => [],
            'pending_migrations' => [],
            'modified_migrations' => [],
            'missing_migrations' => [],
            'available_count' => count($files),
            'applied_count' => 0,
            'pending_count' => 0,
        ];

        if ($this->trackingTableExists() === false) {
            if ($declared) {
                $result['tracking_available'] = false;
                $result['pending_migrations'] = array_keys($files);
                $result['pending_count'] = count($files);
            }

            return $result;
        }

        try {
            $applied = ModuleMigration::query()
                ->where('module_id', (string) $module['id'])
                ->orderBy('id')
                ->get()
                ->keyBy('migration');
        } catch (Throwable) {
            if ($declared) {
                $result['tracking_available'] = false;
                $result['pending_migrations'] = array_keys($files);
                $result['pending_count'] = count($files);
            }

            return $result;
        }

        if ($declared === false && $applied->isEmpty()) {
            return $result;
        }

        $result['has_migrations'] = true;

        foreach ($files as $name => $path) {
            $record = $applied->get($name);
            if ($record instanceof ModuleMigration) {
                $result['applied_migrations'][] = $name;

                if ($this->checksum($path) !== $record->checksum) {
                    $result['modified_migrations'][] = $name;
                }

                continue;
            }

            $result['pending_migrations'][] = $name;
        }

        foreach ($applied as $name => $record) {
            if (array_key_exists((string) $name, $files) === false) {
                $result['missing_migrations'][] = (string) $name;
            }
        }

        $result['applied_count'] = count($result['applied_migrations']);
        $result['pending_count'] = count($result['pending_migrations']);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $module
     * @return array<string, mixed>
     */
    public function migrate(array $module): array
    {
        $inspection = $this->inspect($module);
        if ($inspection['has_migrations'] === false) {
            return $this->emptyResult();
        }

        if ($inspection['tracking_available'] === false) {
            throw new RuntimeException(__('Module migration tracking is unavailable. Run the KaevCMS database migrations first.'));
        }

        if ($inspection['modified_migrations'] !== [] || $inspection['missing_migrations'] !== []) {
            throw new RuntimeException(__('An already applied module migration was modified or removed. Restore the original file or add a newer migration.'));
        }

        $moduleId = (string) $module['id'];
        $lock = Cache::lock($this->lockName($moduleId), $this->lockSeconds);
        if ($lock->get() === false) {
            throw new RuntimeException(__('Another database operation is already running for this module. Try again shortly.'));
        }

        try {
            return $this->runPending($module);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $module
     * @return array<string, mixed>
     */
    private function runPending(array $module): array
    {
        $inspection = $this->inspect($module);
        if ($inspection['modified_migrations'] !== [] || $inspection['missing_migrations'] !== []) {
            throw new RuntimeException(__('An already applied module migration was modified or removed. Restore the original file or add a newer migration.'));
        }

        if ($inspection['pending_migrations'] === []) {
            $this->clearFailure((string) $module['id']);

            return $this->emptyResult();
        }

        $moduleId = (string) $module['id'];
        $files = $this->migrationFiles($module);
        $batch = ((int) ModuleMigration::query()->where('module_id', $moduleId)->max('batch')) + 1;
        $completed = [];
        $current = null;
        $currentName = null;

        try {
            foreach ((array) $inspection['pending_migrations'] as $migrationName) {
                if (is_string($migrationName) === false) {
                    throw new RuntimeException('A pending module migration name is invalid.');
                }

                $migrationPath = $files[$migrationName] ?? null;
                if (is_string($migrationPath) === false) {
                    throw new RuntimeException('A pending module migration file is unavailable.');
                }

                $currentName = $migrationName;
                $migrationChecksum = $this->checksum($migrationPath);
                $current = $this->loadMigration($migrationPath);
                $this->invokeMigration($current, 'up');

                ModuleMigration::query()->create([
                    'module_id' => $moduleId,
                    'migration' => $migrationName,
                    'checksum' => $migrationChecksum,
                    'batch' => $batch,
                    'ran_at' => now(),
                ]);

                $completed[] = [
                    'name' => $migrationName,
                    'migration' => $current,
                ];
                $current = null;
                $currentName = null;
            }
        } catch (Throwable $exception) {
            $rollbackSucceeded = $this->rollbackCurrentRun($moduleId, $currentName, $current, $completed);
            $this->recordFailure($module, $exception, $rollbackSucceeded);

            Log::error('KaevCMS module database migration failed.', [
                'module_id' => $moduleId,
                'module_version' => (string) $module['version'],
                'migration' => $currentName,
                'exception' => $exception::class,
                'rollback_succeeded' => $rollbackSucceeded,
            ]);

            $message = $rollbackSucceeded
                ? __('Module database migration failed. Applied changes were rolled back and module code remains inactive.')
                : __('Module database migration failed and automatic rollback was incomplete. Review the module database before retrying.');

            throw new RuntimeException($message, previous: $exception);
        }

        $this->clearFailure($moduleId);

        return [
            'applied' => array_column($completed, 'name'),
            'applied_count' => count($completed),
            'rolled_back' => false,
        ];
    }

    /**
     * @param  list<array{name: string, migration: Migration}>  $completed
     */
    private function rollbackCurrentRun(
        string $moduleId,
        ?string $currentName,
        ?Migration $current,
        array $completed,
    ): bool {
        $succeeded = true;

        if ($current instanceof Migration) {
            try {
                $this->invokeMigration($current, 'down');
            } catch (Throwable $exception) {
                $succeeded = false;
                $this->logRollbackFailure($moduleId, $currentName, $exception);
            }
        }

        foreach (array_reverse($completed) as $entry) {
            try {
                $this->invokeMigration($entry['migration'], 'down');
                ModuleMigration::query()
                    ->where('module_id', $moduleId)
                    ->where('migration', $entry['name'])
                    ->delete();
            } catch (Throwable $exception) {
                $succeeded = false;
                $this->logRollbackFailure($moduleId, $entry['name'], $exception);
            }
        }

        return $succeeded;
    }

    private function logRollbackFailure(string $moduleId, ?string $migration, Throwable $exception): void
    {
        Log::error('KaevCMS module migration rollback failed.', [
            'module_id' => $moduleId,
            'migration' => $migration,
            'exception' => $exception::class,
        ]);
    }

    private function loadMigration(string $path): Migration
    {
        $migration = require $path;
        if (($migration instanceof Migration) === false) {
            throw new RuntimeException('Module migration files must return an Illuminate database migration instance.');
        }

        if (is_callable([$migration, 'up']) === false || is_callable([$migration, 'down']) === false) {
            throw new RuntimeException('Module migrations must provide public up and down methods.');
        }

        return $migration;
    }

    private function invokeMigration(Migration $migration, string $method): void
    {
        (new ReflectionMethod($migration, $method))->invoke($migration);
    }

    /** @param array<string, mixed> $module */
    private function recordFailure(array $module, Throwable $exception, bool $rollbackSucceeded): void
    {
        $error = ($rollbackSucceeded ? 'migration: ' : 'migration-recovery: ').$exception::class;
        $state = ModuleState::query()->find((string) $module['id']);

        if ($state instanceof ModuleState) {
            $state->forceFill([
                'migration_error' => mb_substr($error, 0, 190),
                'migration_error_at' => now(),
            ])->save();

            return;
        }

        ModuleState::query()->create([
            'id' => (string) $module['id'],
            'version' => (string) $module['version'],
            'enabled' => false,
            'migration_error' => mb_substr($error, 0, 190),
            'migration_error_at' => now(),
        ]);
    }

    private function clearFailure(string $moduleId): void
    {
        ModuleState::query()
            ->whereKey($moduleId)
            ->whereNotNull('migration_error')
            ->update([
                'migration_error' => null,
                'migration_error_at' => null,
            ]);
    }

    /**
     * @param  array<string, mixed>  $module
     * @return array<string, string>
     */
    private function migrationFiles(array $module): array
    {
        $result = [];

        foreach ((array) ($module['migration_files'] ?? []) as $name => $path) {
            if (is_string($name) && is_string($path) && $this->files->isFile($path)) {
                $result[$name] = $path;
            }
        }

        ksort($result, SORT_STRING);

        return $result;
    }

    private function checksum(string $path): string
    {
        $checksum = hash_file('sha256', $path);
        if (is_string($checksum) === false) {
            throw new RuntimeException('Unable to calculate the module migration checksum.');
        }

        return $checksum;
    }

    private function trackingTableExists(): bool
    {
        try {
            return Schema::hasTable('cms_module_migrations');
        } catch (Throwable) {
            return false;
        }
    }

    private function lockName(string $moduleId): string
    {
        return 'kaevcms:module-migrations:'.$moduleId;
    }

    /** @return array{applied: list<string>, applied_count: int, rolled_back: bool} */
    private function emptyResult(): array
    {
        return [
            'applied' => [],
            'applied_count' => 0,
            'rolled_back' => false,
        ];
    }
}
