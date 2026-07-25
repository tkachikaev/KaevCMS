<?php

namespace App\Support\Modules;

use App\Models\ModuleState;
use Carbon\CarbonInterface;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class ModuleManager
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $installedCache = null;

    /** @param list<string> $reservedIds */
    public function __construct(
        private readonly string $modulesPath,
        private readonly array $reservedIds,
        private readonly int $runtimeRetrySeconds,
        private readonly Filesystem $files,
        private readonly ModuleValidator $validator,
        private readonly ModuleMigrationManager $migrations,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function installed(): array
    {
        if ($this->installedCache !== null) {
            return $this->installedCache;
        }

        $states = $this->states();
        $modules = [];
        $seenIds = [];

        if ($this->files->isDirectory($this->modulesPath)) {
            foreach ($this->files->directories($this->modulesPath) as $directory) {
                $id = basename($directory);
                $seenIds[] = $id;
                $modules[] = $this->mergeState(
                    $this->validator->inspect($id, $this->modulesPath, $this->reservedIds),
                    $states->get($id),
                );
            }
        }

        foreach ($states as $id => $state) {
            if (in_array($id, $seenIds, true) || ! $state->enabled) {
                continue;
            }

            $modules[] = $this->missingModule($state);
        }

        usort($modules, static function (array $left, array $right): int {
            if ($left['enabled'] !== $right['enabled']) {
                return $left['enabled'] ? -1 : 1;
            }

            if ($left['status'] !== $right['status']) {
                return strcmp((string) $left['status'], (string) $right['status']);
            }

            return strcasecmp((string) $left['name'], (string) $right['name']);
        });

        return $this->installedCache = $modules;
    }

    /** @return array<string, mixed> */
    public function inspect(string $id): array
    {
        foreach ($this->installed() as $module) {
            if ($module['id'] === $id) {
                return $module;
            }
        }

        $state = $this->states()->get($id);
        $module = $this->validator->inspect($id, $this->modulesPath, $this->reservedIds);

        if (
            $state instanceof ModuleState
            && ! $this->files->isDirectory($this->modulesPath.DIRECTORY_SEPARATOR.$id)
        ) {
            return $this->missingModule($state);
        }

        return $this->mergeState($module, $state);
    }

    /** @return array<int, array<string, mixed>> */
    public function enabled(): array
    {
        return array_values(array_filter(
            $this->installed(),
            fn (array $module): bool => $module['status'] === 'enabled'
                || ($module['status'] === 'runtime_error' && $this->runtimeRetryReady($module)),
        ));
    }

    /** @return array<string, mixed> */
    public function enable(string $id): array
    {
        $this->assertStateTable();
        $this->refresh();
        $module = $this->inspect($id);

        if (! $module['valid']) {
            throw new RuntimeException(__('A damaged module cannot be enabled.'));
        }

        if (! $module['compatible']) {
            throw new RuntimeException(__('A module incompatible with this CMS version cannot be enabled.'));
        }

        if (($module['migration_tracking_available'] ?? true) !== true) {
            throw new RuntimeException(__('Module migration tracking is unavailable. Run the KaevCMS database migrations first.'));
        }

        if (($module['modified_migrations'] ?? []) !== []) {
            throw new RuntimeException(__('An already applied module migration was modified or removed. Restore the original file or add a newer migration.'));
        }

        $migrationResult = $this->migrations->migrate($module);

        DB::transaction(static function () use ($module): void {
            ModuleState::query()->updateOrCreate(
                ['id' => $module['id']],
                [
                    'version' => $module['version'],
                    'enabled' => true,
                    'enabled_at' => now(),
                    'disabled_at' => null,
                    'last_error' => null,
                    'last_error_at' => null,
                    'migration_error' => null,
                    'migration_error_at' => null,
                ],
            );
        });

        $this->refresh();
        $resolved = $this->inspect($id);
        $resolved['migration_result'] = $migrationResult;

        return $resolved;
    }

    /** @return array<string, mixed> */
    public function disable(string $id): array
    {
        $this->assertStateTable();
        $state = ModuleState::query()->find($id);

        if (($state instanceof ModuleState) === false || ! $state->enabled) {
            throw new RuntimeException(__('The module is not enabled.'));
        }

        $state->forceFill([
            'enabled' => false,
            'disabled_at' => now(),
        ])->save();

        $this->refresh();

        return $this->inspect($id);
    }

    public function refresh(): void
    {
        $this->installedCache = null;
    }

    /** @return Collection<string, ModuleState> */
    private function states(): Collection
    {
        if (! $this->stateTableExists()) {
            return collect();
        }

        try {
            return ModuleState::query()->get()->keyBy('id');
        } catch (Throwable) {
            return collect();
        }
    }

    /**
     * @param  array<string, mixed>  $module
     * @return array<string, mixed>
     */
    private function mergeState(array $module, mixed $state): array
    {
        $enabled = $state instanceof ModuleState && $state->enabled;
        $updateAvailable = $state instanceof ModuleState
            && $state->version !== $module['version'];
        $runtimeError = $state instanceof ModuleState ? $state->last_error : null;
        $migrationError = $state instanceof ModuleState ? $state->migration_error : null;
        $migrationState = $this->migrations->inspect($module);
        $migrationPending = (int) $migrationState['pending_count'] > 0;
        $migrationModified = (array) $migrationState['modified_migrations'] !== []
            || (array) $migrationState['missing_migrations'] !== [];
        $migrationTrackingUnavailable = (bool) $migrationState['has_migrations']
            && $migrationState['tracking_available'] === false;

        $status = match (true) {
            ! $module['valid'] => 'invalid',
            ! $module['compatible'] => 'incompatible',
            $migrationTrackingUnavailable => 'migration_unavailable',
            $migrationModified => 'migration_modified',
            is_string($migrationError) && $migrationError !== '' => 'migration_error',
            $enabled && $updateAvailable => 'update_pending',
            $enabled && $migrationPending => 'migration_pending',
            ! $enabled && ($state instanceof ModuleState) === false && $migrationPending => 'install_pending',
            ! $enabled && $migrationPending => 'migration_pending',
            $enabled && is_string($runtimeError) && $runtimeError !== '' => 'runtime_error',
            $enabled => 'enabled',
            default => 'disabled',
        };

        $canEnable = $module['valid']
            && $module['compatible']
            && ! $migrationTrackingUnavailable
            && ! $migrationModified
            && (! $enabled || $updateAvailable || $migrationPending || $migrationError !== null);

        return array_merge($module, $migrationState, [
            'enabled' => $enabled,
            'status' => $status,
            'stored_version' => $state instanceof ModuleState ? $state->version : null,
            'enabled_at' => $state instanceof ModuleState ? $state->enabled_at : null,
            'disabled_at' => $state instanceof ModuleState ? $state->disabled_at : null,
            'last_error' => $runtimeError,
            'last_error_at' => $state instanceof ModuleState ? $state->last_error_at : null,
            'migration_error' => $migrationError,
            'migration_error_at' => $state instanceof ModuleState ? $state->migration_error_at : null,
            'migration_tracking_available' => $migrationState['tracking_available'],
            'can_enable' => $canEnable,
            'can_disable' => $enabled,
            'update_available' => $updateAvailable,
        ]);
    }

    /** @return array<string, mixed> */
    private function missingModule(ModuleState $state): array
    {
        return [
            'id' => $state->id,
            'name' => $state->id,
            'version' => $state->version,
            'author' => '—',
            'description' => '',
            'schema' => null,
            'cms_min' => null,
            'cms_max' => null,
            'namespace' => null,
            'path' => null,
            'autoload_path' => null,
            'bootstrap_path' => null,
            'views_path' => null,
            'lang_path' => null,
            'migrations_path' => null,
            'migration_files' => [],
            'route_paths' => [],
            'capabilities' => [],
            'valid' => false,
            'compatible' => false,
            'enabled' => $state->enabled,
            'status' => 'missing',
            'stored_version' => $state->version,
            'enabled_at' => $state->enabled_at,
            'disabled_at' => $state->disabled_at,
            'last_error' => $state->last_error,
            'last_error_at' => $state->last_error_at,
            'migration_error' => $state->migration_error,
            'migration_error_at' => $state->migration_error_at,
            'has_migrations' => false,
            'tracking_available' => true,
            'migration_tracking_available' => true,
            'available_migrations' => [],
            'applied_migrations' => [],
            'pending_migrations' => [],
            'modified_migrations' => [],
            'missing_migrations' => [],
            'available_count' => 0,
            'applied_count' => 0,
            'pending_count' => 0,
            'can_enable' => false,
            'can_disable' => $state->enabled,
            'update_available' => false,
            'errors' => [__('Module files are missing from the modules directory.')],
            'manifest' => [],
        ];
    }

    /** @param array<string, mixed> $module */
    private function runtimeRetryReady(array $module): bool
    {
        $lastErrorAt = $module['last_error_at'] ?? null;

        return ! $lastErrorAt instanceof CarbonInterface
            || $lastErrorAt->lte(now()->subSeconds($this->runtimeRetrySeconds));
    }

    private function assertStateTable(): void
    {
        if (! $this->stateTableExists()) {
            throw new RuntimeException(__('The module state table is unavailable. Run database migrations first.'));
        }
    }

    private function stateTableExists(): bool
    {
        try {
            return Schema::hasTable('cms_modules');
        } catch (Throwable) {
            return false;
        }
    }
}
