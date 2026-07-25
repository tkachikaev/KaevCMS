<?php

namespace App\Services;

use App\Models\CmsSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class CmsSettings
{
    /** @var array<string, string|null> */
    private array $loaded = [];

    public function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, $this->loaded)) {
            return $this->loaded[$key] ?? $default;
        }

        if (! $this->tableExists()) {
            return $default;
        }

        try {
            $value = CmsSetting::query()->where('key', $key)->value('value');
        } catch (Throwable) {
            return $default;
        }

        $this->loaded[$key] = is_string($value) ? $value : null;

        return $this->loaded[$key] ?? $default;
    }

    /**
     * @param  array<string, string|null>  $defaults
     * @return array<string, string|null>
     */
    public function getMany(array $defaults): array
    {
        $missingKeys = array_values(array_filter(
            array_keys($defaults),
            fn (string $key): bool => ! array_key_exists($key, $this->loaded),
        ));

        if ($missingKeys !== [] && $this->tableExists()) {
            try {
                $storedValues = CmsSetting::query()
                    ->whereIn('key', $missingKeys)
                    ->pluck('value', 'key');

                foreach ($missingKeys as $key) {
                    $value = $storedValues->get($key);
                    $this->loaded[$key] = is_string($value) ? $value : null;
                }
            } catch (Throwable) {
                // The application can still use safe defaults while the database is unavailable.
            }
        }

        $values = [];
        foreach ($defaults as $key => $default) {
            $values[$key] = array_key_exists($key, $this->loaded)
                ? ($this->loaded[$key] ?? $default)
                : $default;
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
        if (! $this->tableExists()) {
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

    private function tableExists(): bool
    {
        try {
            return Schema::hasTable('cms_settings');
        } catch (Throwable) {
            return false;
        }
    }
}
