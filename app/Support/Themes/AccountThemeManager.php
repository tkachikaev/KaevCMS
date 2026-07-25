<?php

namespace App\Support\Themes;

use App\Services\CmsSettings;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final class AccountThemeManager
{
    private const ACTIVE_THEME_SETTING = 'account_theme.active';

    /** @var array<string, mixed> */
    private array $manifest = [];

    private string $activeTheme;

    public function __construct(
        private readonly string $themesPath,
        private readonly string $publicThemesPath,
        private readonly string $fallbackTheme,
        private readonly CmsSettings $settings,
        private readonly Filesystem $files,
        private readonly ThemeValidator $validator,
    ) {
        $this->activeTheme = $fallbackTheme;
    }

    public function boot(): void
    {
        $requestedTheme = $this->settings->get(self::ACTIVE_THEME_SETTING, $this->fallbackTheme) ?? $this->fallbackTheme;
        $theme = $this->inspect($requestedTheme);

        if (! $theme['valid'] || ! $theme['compatible']) {
            $theme = $this->inspect($this->fallbackTheme);
        }

        if (! $theme['valid'] || ! $theme['compatible']) {
            throw new RuntimeException("Fallback account theme [{$this->fallbackTheme}] is missing, invalid, or incompatible.");
        }

        $this->applyResolvedTheme($theme);
    }

    /** @return array<int, array<string, mixed>> */
    public function installed(): array
    {
        if (! $this->files->isDirectory($this->themesPath)) {
            return [];
        }

        $themes = [];

        foreach ($this->files->directories($this->themesPath) as $directory) {
            $themes[] = $this->inspect(basename($directory));
        }

        usort($themes, static function (array $left, array $right): int {
            if ($left['active'] !== $right['active']) {
                return $left['active'] ? -1 : 1;
            }

            return strcasecmp((string) $left['name'], (string) $right['name']);
        });

        return $themes;
    }

    /** @return array<string, mixed> */
    public function inspect(string $slug): array
    {
        return $this->validator->inspect(
            slug: $slug,
            themesPath: $this->themesPath,
            publicThemesPath: $this->publicThemesPath,
            assetUrlPrefix: 'account-themes',
            activeTheme: $this->activeTheme,
            requiredFiles: ['views/layouts/app.blade.php', 'views/dashboard.blade.php'],
            requiredPublicFiles: ['assets/css/app.css', 'assets/js/navigation.js'],
            missingPublicAssetsMessage: __('Account theme public assets were not found.'),
        );
    }

    public function activate(string $slug): void
    {
        $theme = $this->inspect($slug);

        if (! $theme['valid']) {
            throw new RuntimeException(__('A damaged theme cannot be activated.'));
        }

        if (! $theme['compatible']) {
            throw new RuntimeException(__('A theme incompatible with this CMS version cannot be activated.'));
        }

        $this->settings->set(self::ACTIVE_THEME_SETTING, $slug);
        $this->applyResolvedTheme($theme);
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return $this->manifest;
    }

    public function name(): string
    {
        return $this->activeTheme;
    }

    public function themePath(): string
    {
        return rtrim($this->themesPath, '/\\').DIRECTORY_SEPARATOR.$this->activeTheme;
    }

    public function asset(string $path): string
    {
        $url = asset('account-themes/'.$this->activeTheme.'/'.ltrim($path, '/'));
        $version = trim((string) ($this->manifest['version'] ?? ''));

        return $version !== '' ? $url.'?v='.rawurlencode($version) : $url;
    }

    /** @param array<string, mixed> $theme */
    private function applyResolvedTheme(array $theme): void
    {
        $this->activeTheme = (string) $theme['slug'];
        $this->manifest = (array) $theme['manifest'];

        $viewPaths = [$this->themePath().DIRECTORY_SEPARATOR.'views'];
        $fallbackViews = rtrim($this->themesPath, '/\\')
            .DIRECTORY_SEPARATOR.$this->fallbackTheme
            .DIRECTORY_SEPARATOR.'views';

        if ($this->activeTheme !== $this->fallbackTheme && $this->files->isDirectory($fallbackViews)) {
            $viewPaths[] = $fallbackViews;
        }

        view()->replaceNamespace('account-theme', $viewPaths);
        view()->share('activeAccountTheme', $this->manifest);
    }
}
