<?php

namespace App\Support\Modules;

use App\Support\KaevCMS;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Throwable;

final class ModuleValidator
{
    private const SUPPORTED_SCHEMA = 1;

    /** @var list<string> */
    private const ALLOWED_FIELDS = [
        'schema',
        'id',
        'name',
        'version',
        'author',
        'description',
        'cms_min',
        'cms_max',
        'namespace',
        'autoload',
        'bootstrap',
        'routes',
        'views',
        'lang',
        'migrations',
    ];

    private readonly Filesystem $files;

    public function __construct(Filesystem $files)
    {
        $this->files = $files;
    }

    /**
     * @param  list<string>  $reservedIds
     * @return array<string, mixed>
     */
    public function inspect(string $id, string $modulesPath, array $reservedIds = []): array
    {
        $result = $this->emptyResult($id);

        if (preg_match('/\A[a-z0-9][a-z0-9-]{0,99}\z/', $id) !== 1) {
            $result['errors'][] = __('Invalid module directory name.');

            return $result;
        }

        if (in_array($id, $reservedIds, true)) {
            $result['errors'][] = __('This module identifier is reserved by KaevCMS.');

            return $result;
        }

        $configuredRoot = rtrim($modulesPath, '/\\');
        $candidatePath = $configuredRoot.DIRECTORY_SEPARATOR.$id;
        $root = realpath($configuredRoot);
        $path = realpath($candidatePath);

        if (
            $root === false
            || $path === false
            || is_link($candidatePath)
            || $this->isInside($path, $root) === false
            || $this->files->isDirectory($path) === false
        ) {
            $result['errors'][] = __('Module directory not found or unsafe.');

            return $result;
        }

        $result['path'] = $path;
        $manifestPath = $this->safeFile($path, 'module.json');
        if ($manifestPath === null) {
            $result['errors'][] = __('The module.json file was not found.');

            return $result;
        }

        try {
            $manifest = json_decode($this->files->get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $result['errors'][] = __('The module.json file contains invalid JSON.');

            return $result;
        }

        if (is_array($manifest) === false || array_is_list($manifest)) {
            $result['errors'][] = __('The module.json file must contain a JSON object.');

            return $result;
        }

        $result['manifest'] = $manifest;
        $result['schema'] = Arr::get($manifest, 'schema');
        $result['name'] = $this->displayString(Arr::get($manifest, 'name'), $id, 120);
        $result['version'] = $this->displayString(Arr::get($manifest, 'version'), '—', 50);
        $result['author'] = $this->displayString(Arr::get($manifest, 'author'), '—', 120);
        $result['description'] = $this->displayString(Arr::get($manifest, 'description'), '', 1000);
        $result['cms_min'] = $this->nullableString(Arr::get($manifest, 'cms_min'));
        $result['cms_max'] = $this->nullableString(Arr::get($manifest, 'cms_max'));

        $unknownFields = array_values(array_diff(array_keys($manifest), self::ALLOWED_FIELDS));
        foreach ($unknownFields as $unknownField) {
            $result['errors'][] = __('Unknown module.json field: :field.', ['field' => $unknownField]);
        }

        if (Arr::get($manifest, 'schema') !== self::SUPPORTED_SCHEMA) {
            $result['errors'][] = __('Unsupported module manifest schema.');
        }

        foreach (['id' => 100, 'name' => 120, 'version' => 50, 'author' => 120] as $requiredField => $maximumLength) {
            $value = Arr::get($manifest, $requiredField);
            if (is_string($value) === false || trim($value) === '') {
                $result['errors'][] = __('The :field field is missing from module.json.', ['field' => $requiredField]);

                continue;
            }

            if (mb_strlen(trim($value)) > $maximumLength) {
                $result['errors'][] = __('The :field field exceeds the maximum length of :max characters.', [
                    'field' => $requiredField,
                    'max' => $maximumLength,
                ]);
            }
        }

        if (array_key_exists('description', $manifest)) {
            if (is_string($manifest['description']) === false) {
                $result['errors'][] = __('The :field field must contain text.', ['field' => 'description']);
            } elseif (mb_strlen(trim($manifest['description'])) > 1000) {
                $result['errors'][] = __('The :field field exceeds the maximum length of :max characters.', [
                    'field' => 'description',
                    'max' => 1000,
                ]);
            }
        }

        if (Arr::get($manifest, 'id') !== $id) {
            $result['errors'][] = __('The id field does not match the module directory name.');
        }

        $rawModuleVersion = Arr::get($manifest, 'version');
        if (is_string($rawModuleVersion) === false || $this->isVersion(trim($rawModuleVersion)) === false) {
            $result['errors'][] = __('The module version must use semantic versioning.');
        }

        foreach (['cms_min', 'cms_max'] as $versionField) {
            $rawVersion = Arr::get($manifest, $versionField);
            if ($rawVersion === null) {
                continue;
            }

            if (is_string($rawVersion) === false || trim($rawVersion) === '' || $this->isVersion(trim($rawVersion)) === false) {
                $result['errors'][] = __('The :field field must contain a valid CMS version.', ['field' => $versionField]);
            }
        }

        if (
            $result['cms_min'] !== null
            && $result['cms_max'] !== null
            && $this->isVersion($result['cms_min'])
            && $this->isVersion($result['cms_max'])
            && version_compare($result['cms_min'], $result['cms_max'], '>')
        ) {
            $result['errors'][] = __('The minimum CMS version cannot be greater than the maximum CMS version.');
        }

        $this->inspectAutoload($manifest, $path, $result);
        $this->inspectOptionalFile($manifest, 'bootstrap', $path, $result);
        $this->inspectOptionalDirectory($manifest, 'views', $path, $result);
        $this->inspectOptionalDirectory($manifest, 'lang', $path, $result);
        $this->inspectMigrations($manifest, $path, $result);
        $this->inspectRoutes($manifest, $path, $result);

        $result['valid'] = $result['errors'] === [];
        $result['compatible'] = $result['valid'] && $this->isCompatible($result['cms_min'], $result['cms_max']);

        if ($result['valid'] && $result['compatible'] === false) {
            $result['errors'][] = __('The module is incompatible with the current CMS version.');
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function emptyResult(string $id): array
    {
        return [
            'id' => $id,
            'name' => $id,
            'version' => '—',
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
            'errors' => [],
            'manifest' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $result
     */
    private function inspectAutoload(array $manifest, string $root, array &$result): void
    {
        $namespace = Arr::get($manifest, 'namespace');
        $autoload = Arr::get($manifest, 'autoload');

        if ($namespace === null && $autoload === null) {
            return;
        }

        if (is_string($namespace) === false || preg_match('/\A(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+\z/', $namespace) !== 1) {
            $result['errors'][] = __('The module namespace must be a valid PSR-4 namespace ending with a backslash.');
        } else {
            $result['namespace'] = $namespace;
        }

        if (is_string($autoload) === false || trim($autoload) === '') {
            $result['errors'][] = __('The autoload field is required when a module namespace is declared.');

            return;
        }

        $autoloadPath = $this->safeDirectory($root, $autoload);
        if ($autoloadPath === null) {
            $result['errors'][] = __('The module autoload directory was not found or is unsafe.');

            return;
        }

        $result['autoload_path'] = $autoloadPath;
        $result['capabilities'][] = 'autoload';
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $result
     */
    private function inspectOptionalFile(array $manifest, string $field, string $root, array &$result): void
    {
        $value = Arr::get($manifest, $field);
        if ($value === null) {
            return;
        }

        if (is_string($value) === false || trim($value) === '') {
            $result['errors'][] = __('The :field field must contain a relative file path.', ['field' => $field]);

            return;
        }

        $path = $this->safeFile($root, $value);
        if ($path === null) {
            $result['errors'][] = __('The module :field file was not found or is unsafe.', ['field' => $field]);

            return;
        }

        $result[$field.'_path'] = $path;
        $result['capabilities'][] = $field;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $result
     */
    private function inspectOptionalDirectory(array $manifest, string $field, string $root, array &$result): void
    {
        $value = Arr::get($manifest, $field);
        if ($value === null) {
            return;
        }

        if (is_string($value) === false || trim($value) === '') {
            $result['errors'][] = __('The :field field must contain a relative directory path.', ['field' => $field]);

            return;
        }

        $path = $this->safeDirectory($root, $value);
        if ($path === null) {
            $result['errors'][] = __('The module :field directory was not found or is unsafe.', ['field' => $field]);

            return;
        }

        $result[$field.'_path'] = $path;
        $result['capabilities'][] = $field;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $result
     */
    private function inspectMigrations(array $manifest, string $root, array &$result): void
    {
        $value = Arr::get($manifest, 'migrations');
        if ($value === null) {
            return;
        }

        if (is_string($value) === false || trim($value) === '') {
            $result['errors'][] = __('The migrations field must contain a relative directory path.');

            return;
        }

        $path = $this->safeDirectory($root, $value);
        if ($path === null) {
            $result['errors'][] = __('The module migrations directory was not found or is unsafe.');

            return;
        }

        $migrationFiles = [];
        foreach ($this->files->files($path) as $file) {
            $name = $file->getFilename();
            if (str_starts_with($name, '.')) {
                continue;
            }

            $filePath = $file->getRealPath();
            if (
                preg_match('/\A\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+\.php\z/', $name) !== 1
                || strlen($name) > 190
                || $file->isLink()
                || $filePath === false
                || $this->isInside($filePath, $path) === false
            ) {
                $result['errors'][] = __('Invalid or unsafe module migration file: :file.', ['file' => $name]);

                continue;
            }

            $migrationFiles[$name] = $filePath;
        }

        ksort($migrationFiles, SORT_STRING);
        $result['migrations_path'] = $path;
        $result['migration_files'] = $migrationFiles;
        $result['capabilities'][] = 'migrations';
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $result
     */
    private function inspectRoutes(array $manifest, string $root, array &$result): void
    {
        $routes = Arr::get($manifest, 'routes');
        if ($routes === null) {
            return;
        }

        if (is_array($routes) === false || array_is_list($routes)) {
            $result['errors'][] = __('The routes field must contain a JSON object.');

            return;
        }

        foreach (array_diff(array_keys($routes), ['web', 'admin']) as $unknownRoute) {
            $result['errors'][] = __('Unknown module route group: :group.', ['group' => $unknownRoute]);
        }

        foreach (['web', 'admin'] as $group) {
            $relativePath = Arr::get($routes, $group);
            if ($relativePath === null) {
                continue;
            }

            if (is_string($relativePath) === false || trim($relativePath) === '') {
                $result['errors'][] = __('The :group route must contain a relative file path.', ['group' => $group]);

                continue;
            }

            $path = $this->safeFile($root, $relativePath);
            if ($path === null) {
                $result['errors'][] = __('The :group module route file was not found or is unsafe.', ['group' => $group]);

                continue;
            }

            $result['route_paths'][$group] = $path;
            $result['capabilities'][] = $group.'_routes';
        }
    }

    private function isCompatible(?string $minimum, ?string $maximum): bool
    {
        $cmsVersion = KaevCMS::version();

        if ($minimum !== null && version_compare($cmsVersion, $minimum, '<')) {
            return false;
        }

        if ($maximum !== null && version_compare($cmsVersion, $maximum, '>')) {
            return false;
        }

        return true;
    }

    private function safeFile(string $root, string $relativePath): ?string
    {
        $path = $this->safePath($root, $relativePath);

        return $path !== null && $this->files->isFile($path) ? $path : null;
    }

    private function safeDirectory(string $root, string $relativePath): ?string
    {
        $path = $this->safePath($root, $relativePath);

        return $path !== null && $this->files->isDirectory($path) ? $path : null;
    }

    private function safePath(string $root, string $relativePath): ?string
    {
        $relativePath = trim($relativePath);
        if (
            $relativePath === ''
            || str_contains($relativePath, "\0")
            || str_contains($relativePath, '\\')
            || str_starts_with($relativePath, '/')
            || preg_match('/(?:^|\/)\.\.(?:\/|$)/', $relativePath) === 1
        ) {
            return null;
        }

        $path = realpath($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath));

        if ($path === false || $this->isInside($path, $root) === false) {
            return null;
        }

        return $path;
    }

    private function isInside(string $path, string $root): bool
    {
        $root = rtrim($root, '/\\');

        return $path === $root || str_starts_with($path, $root.DIRECTORY_SEPARATOR);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function displayString(mixed $value, string $default, int $limit): string
    {
        if (is_string($value) === false || trim($value) === '') {
            return $default;
        }

        return mb_substr(trim($value), 0, $limit);
    }

    private function isVersion(string $version): bool
    {
        return preg_match('/\A\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?\z/', $version) === 1;
    }
}
