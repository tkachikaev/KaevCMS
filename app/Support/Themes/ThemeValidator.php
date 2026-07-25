<?php

namespace App\Support\Themes;

use App\Support\KaevCMS;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Throwable;

final class ThemeValidator
{
    public function __construct(private readonly Filesystem $files) {}

    /**
     * @param  array<int, string>  $requiredFiles
     * @param  array<int, string>  $requiredPublicFiles
     * @return array<string, mixed>
     */
    public function inspect(
        string $slug,
        string $themesPath,
        string $publicThemesPath,
        string $assetUrlPrefix,
        string $activeTheme,
        array $requiredFiles,
        array $requiredPublicFiles = [],
        ?string $missingPublicAssetsMessage = null,
    ): array {
        $result = [
            'slug' => $slug,
            'name' => $slug,
            'version' => '—',
            'author' => '—',
            'description' => '',
            'cms_min' => null,
            'cms_max' => null,
            'preview_url' => null,
            'valid' => false,
            'compatible' => false,
            'active' => $slug === $activeTheme,
            'errors' => [],
            'manifest' => [],
        ];

        if (! preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $slug)) {
            $result['errors'][] = __('Invalid theme directory name.');

            return $result;
        }

        $root = realpath($themesPath);
        $path = realpath($themesPath.DIRECTORY_SEPARATOR.$slug);

        if ($root === false || $path === false || ! $this->isInside($path, $root)) {
            $result['errors'][] = __('Theme directory not found.');

            return $result;
        }

        $manifestPath = $this->safeFile($path, 'theme.json');
        if ($manifestPath === null) {
            $result['errors'][] = __('The theme.json file was not found.');

            return $result;
        }

        try {
            $manifest = json_decode($this->files->get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $result['errors'][] = __('The theme.json file contains invalid JSON.');

            return $result;
        }

        if (! is_array($manifest)) {
            $result['errors'][] = __('The theme.json file must contain a JSON object.');

            return $result;
        }

        $result['manifest'] = $manifest;
        $result['name'] = (string) Arr::get($manifest, 'name', $slug);
        $result['version'] = (string) Arr::get($manifest, 'version', '—');
        $result['author'] = (string) Arr::get($manifest, 'author', '—');
        $result['description'] = (string) Arr::get($manifest, 'description', '');
        $result['cms_min'] = $this->nullableString(Arr::get($manifest, 'cms_min'));
        $result['cms_max'] = $this->nullableString(Arr::get($manifest, 'cms_max'));

        foreach (['name', 'slug', 'version', 'author'] as $requiredField) {
            if (! is_string(Arr::get($manifest, $requiredField)) || trim((string) Arr::get($manifest, $requiredField)) === '') {
                $result['errors'][] = __('The :field field is missing from theme.json.', ['field' => $requiredField]);
            }
        }

        if (Arr::get($manifest, 'slug') !== $slug) {
            $result['errors'][] = __('The slug field does not match the theme directory name.');
        }

        foreach ($requiredFiles as $requiredFile) {
            if ($this->safeFile($path, $requiredFile) === null) {
                $result['errors'][] = __('Required file :file was not found.', ['file' => $requiredFile]);
            }
        }

        if ($requiredPublicFiles !== []) {
            $publicRoot = realpath($publicThemesPath);
            $publicPath = realpath($publicThemesPath.DIRECTORY_SEPARATOR.$slug);

            if ($publicRoot === false || $publicPath === false || ! $this->isInside($publicPath, $publicRoot)) {
                $result['errors'][] = $missingPublicAssetsMessage ?? __('Theme public assets were not found.');
            } else {
                foreach ($requiredPublicFiles as $requiredFile) {
                    if ($this->safeFile($publicPath, $requiredFile) === null) {
                        $result['errors'][] = __('Required file :file was not found.', ['file' => $requiredFile]);
                    }
                }
            }
        }

        $result['valid'] = $result['errors'] === [];
        $result['compatible'] = $result['valid'] && $this->isCompatible($result['cms_min'], $result['cms_max']);
        $result['preview_url'] = $this->previewUrl(
            slug: $slug,
            manifest: $manifest,
            publicThemesPath: $publicThemesPath,
            assetUrlPrefix: $assetUrlPrefix,
        );

        if ($result['valid'] && ! $result['compatible']) {
            $result['errors'][] = __('The theme is incompatible with the current CMS version.');
        }

        return $result;
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

    /** @param array<string, mixed> $manifest */
    private function previewUrl(
        string $slug,
        array $manifest,
        string $publicThemesPath,
        string $assetUrlPrefix,
    ): ?string {
        $preview = Arr::get($manifest, 'preview');

        if (! is_string($preview) || ! preg_match('/\A[a-zA-Z0-9_\/.\-]+\z/', $preview)) {
            return null;
        }

        $publicPath = realpath($publicThemesPath.DIRECTORY_SEPARATOR.$slug);
        if ($publicPath === false) {
            return null;
        }

        $previewPath = $this->safeFile($publicPath, $preview);
        if ($previewPath === null) {
            return null;
        }

        return asset(trim($assetUrlPrefix, '/').'/'.$slug.'/'.ltrim($preview, '/'));
    }

    private function safeFile(string $root, string $relativePath): ?string
    {
        if ($relativePath === '' || str_contains($relativePath, "\0") || str_contains($relativePath, '\\')) {
            return null;
        }

        $normalized = str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $path = realpath($root.DIRECTORY_SEPARATOR.$normalized);

        if ($path === false || ! $this->files->isFile($path) || ! $this->isInside($path, $root)) {
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
}
