<?php

namespace App\Services;

use App\Models\CmsSetting;
use App\Support\Database\TableExistence;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class CmsSettings
{
    /** @var array<string, string|null> */
    private array $loaded = [];

    /**
     * All rows are read with one query on first use: a page needs dozens of settings and
     * reading them one by one cost a query each. The table is small (key/value rows).
     */
    private bool $allLoaded = false;

    public function __construct(private readonly TableExistence $tables) {}

    public function get(string $key, ?string $default = null): ?string
    {
        $this->loadAll();

        return $this->loaded[$key] ?? $default;
    }

    /**
     * @param  array<string, string|null>  $defaults
     * @return array<string, string|null>
     */
    public function getMany(array $defaults): array
    {
        $this->loadAll();

        $values = [];
        foreach ($defaults as $key => $default) {
            $values[$key] = $this->loaded[$key] ?? $default;
        }

        return $values;
    }

    public function set(string $key, ?string $value): void
    {
        $this->setMany([$key => $value]);
    }

    /** @param array<string, string|null> $values */
    public function setMany(array $values): void
    {
        if (! $this->tables->has('cms_settings')) {
            throw new RuntimeException('CMS settings table is not available. Run database migrations first.');
        }

        DB::transaction(function () use ($values): void {
            foreach ($values as $key => $value) {
                CmsSetting::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => $value],
                );
            }
        });

        foreach ($values as $key => $value) {
            $this->loaded[$key] = $value;
        }
    }

    /**
     * Drops the remembered values; the next read loads them again. Called when a setting is
     * written outside this service and after migrations.
     */
    public function forget(): void
    {
        $this->loaded = [];
        $this->allLoaded = false;
    }

    private function loadAll(): void
    {
        if ($this->allLoaded) {
            return;
        }

        if (! $this->tables->has('cms_settings')) {
            // Before installation: safe defaults, and the table is checked again next time.
            return;
        }

        try {
            foreach (CmsSetting::query()->pluck('value', 'key') as $key => $value) {
                $this->loaded[(string) $key] = is_string($value) ? $value : null;
            }
            $this->allLoaded = true;
        } catch (Throwable) {
            // The application can still use safe defaults while the database is unavailable.
        }
    }
}
