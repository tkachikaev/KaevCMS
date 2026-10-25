<?php

namespace App\Support\UniversalDrivers;

use App\Support\Modules\ModuleManager;

/**
 * Extension point between modules and driver profiles.
 *
 * A module that needs something from the game or login database (a premium-account table, a
 * column with an expiry date, ...) declares it in module.json under "driver_extensions". The
 * driver editor then shows those fields in a "Modules" section, the answers are stored in the
 * profile under config.extensions.<module>.<key>.<field>, and the module refuses to switch on
 * until the driver in use has them. See docs/*\/UNIVERSAL_DRIVERS.md ("Extension point for modules").
 *
 * The stored values are plain data. A module must quote identifiers through the query builder and
 * must never put them into raw SQL.
 */
class DriverExtensionCatalog
{
    public const FIELD_KINDS = ['table', 'column', 'text', 'number', 'choice'];

    public function __construct(private readonly ModuleManager $modules) {}

    /**
     * Declarations of every installed, valid module (switched on or not, so an administrator can
     * fill the driver in before enabling the module).
     *
     * @return list<array<string, mixed>>
     */
    public function declarations(?string $type = null): array
    {
        $result = [];
        foreach ($this->modules->installed() as $module) {
            if (($module['valid'] ?? false) !== true || ! is_array($module['manifest'] ?? null)) {
                continue;
            }
            array_push($result, ...self::fromManifest((string) $module['id'], (string) ($module['name'] ?? $module['id']), $module['manifest'], $type));
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array<string, mixed>>
     */
    public static function fromManifest(string $moduleId, string $moduleName, array $manifest, ?string $type = null): array
    {
        $result = [];
        foreach (is_array($manifest['driver_extensions'] ?? null) ? $manifest['driver_extensions'] : [] as $declaration) {
            if (! is_array($declaration) || ! in_array($declaration['type'] ?? null, ['login', 'game'], true)
                || ($type !== null && $declaration['type'] !== $type)) {
                continue;
            }
            $fields = [];
            foreach (is_array($declaration['fields'] ?? null) ? $declaration['fields'] : [] as $field) {
                if (! is_array($field) || ! is_string($field['name'] ?? null) || ! in_array($field['kind'] ?? null, self::FIELD_KINDS, true)) {
                    continue;
                }
                $fields[] = [
                    'name' => $field['name'],
                    'kind' => $field['kind'],
                    'required' => (bool) ($field['required'] ?? false),
                    'label' => self::label($field['label'] ?? null, $field['name']),
                    'options' => array_values(array_filter((array) ($field['options'] ?? []), 'is_string')),
                ];
            }
            $result[] = [
                'module' => $moduleId,
                'module_name' => $moduleName,
                'key' => (string) ($declaration['key'] ?? ''),
                'type' => $declaration['type'],
                'required' => (bool) ($declaration['required'] ?? false),
                'title' => self::label($declaration['title'] ?? null, (string) ($declaration['key'] ?? '')),
                'description' => self::label($declaration['description'] ?? null, ''),
                'fields' => $fields,
            ];
        }

        return $result;
    }

    /**
     * Required fields that the profile has not filled in yet.
     *
     * @param  array<string, mixed>  $profile
     * @param  list<array<string, mixed>>  $declarations
     * @return list<array{module: string, module_name: string, key: string, title: string, field: string, label: string}>
     */
    public static function missing(array $profile, array $declarations): array
    {
        $missing = [];
        foreach ($declarations as $declaration) {
            if ($declaration['required'] !== true) {
                continue;
            }
            $values = $profile['config']['extensions'][$declaration['module']][$declaration['key']] ?? [];
            foreach ($declaration['fields'] as $field) {
                if ($field['required'] === true && trim((string) ($values[$field['name']] ?? '')) === '') {
                    $missing[] = [
                        'module' => $declaration['module'], 'module_name' => $declaration['module_name'],
                        'key' => $declaration['key'], 'title' => $declaration['title'],
                        'field' => $field['name'], 'label' => $field['label'],
                    ];
                }
            }
        }

        return $missing;
    }

    /**
     * Messages for values of declared extensions that do not fit their field kind.
     *
     * @param  array<string, mixed>  $extensions
     * @return array<string, string> keyed by the Livewire path of the input
     */
    public function validate(string $type, array $extensions): array
    {
        $errors = [];
        foreach ($this->declarations($type) as $declaration) {
            foreach ($declaration['fields'] as $field) {
                $value = $extensions[$declaration['module']][$declaration['key']][$field['name']] ?? '';
                $path = 'draft.config.extensions.'.$declaration['module'].'.'.$declaration['key'].'.'.$field['name'];
                if (! is_scalar($value) || trim((string) $value) === '') {
                    continue;
                }
                $value = trim((string) $value);
                $problem = match ($field['kind']) {
                    'table', 'column' => preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D', $value) === 1 ? null : 'drivers.ext_invalid_identifier',
                    'number' => preg_match('/\A\d{1,9}\z/D', $value) === 1 ? null : 'drivers.ext_invalid_number',
                    'choice' => in_array($value, $field['options'], true) ? null : 'drivers.ext_invalid_choice',
                    default => strlen($value) <= 100 && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1 ? null : 'drivers.ext_invalid_text',
                };
                if ($problem !== null) {
                    $errors[$path] = __($problem);
                }
            }
        }

        return $errors;
    }

    /**
     * Drops empty answers (and groups left empty). Answers for modules that are not installed here
     * are kept, so a driver can travel between sites.
     *
     * @param  array<string, mixed>  $extensions
     * @return array<string, mixed>
     */
    public function clean(array $extensions): array
    {
        $clean = [];
        foreach ($extensions as $module => $groups) {
            foreach (is_array($groups) ? $groups : [] as $key => $values) {
                foreach (is_array($values) ? $values : [] as $name => $value) {
                    if (is_scalar($value) && trim((string) $value) !== '') {
                        $clean[$module][$key][$name] = is_string($value) ? trim($value) : $value;
                    }
                }
            }
        }

        return $clean;
    }

    private static function label(mixed $value, string $fallback): string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        if (is_array($value)) {
            foreach ([app()->getLocale(), 'en', 'ru'] as $locale) {
                if (is_string($value[$locale] ?? null) && trim($value[$locale]) !== '') {
                    return trim($value[$locale]);
                }
            }
            foreach ($value as $text) {
                if (is_string($text) && trim($text) !== '') {
                    return trim($text);
                }
            }
        }

        return $fallback;
    }
}
