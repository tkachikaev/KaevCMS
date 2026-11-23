<?php

namespace KaevCMS\Modules\Forum\Services;

use App\Services\CmsSettings;
use App\Support\Themes\ThemeValidator;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Custom themes live outside the document root in storage/app/forum-themes/<slug>:
 * theme.json (name, version, author), theme.css and an optional preview.
 * They carry no templates or PHP, so a theme from a stranger can change how the
 * forum looks but cannot run code on the server.
 */
final class ForumThemes
{
    private const BUNDLED = ['kaev-frost', 'kaev-classic'];

    public const SETTING = 'module.forum.theme';

    public const FALLBACK = 'kaev-frost';

    /** A stylesheet larger than this is refused: no real forum theme needs it. */
    public const MAX_CSS_BYTES = 1048576;

    /** @var array<string, mixed>|null */
    private ?array $active = null;

    public function __construct(
        private readonly CmsSettings $settings,
        private readonly ThemeValidator $validator,
        private readonly Filesystem $files,
        private readonly string $forumVersion,
        private readonly ?string $themesPath = null,
    ) {}

    private function path(string $slug): string
    {
        return $this->themesPath ?? (in_array($slug, self::BUNDLED, true)
            ? public_path('forum-themes') : storage_path('app/forum-themes'));
    }

    /** @return list<array<string, mixed>> */
    public function installed(): array
    {
        $customRoot = $this->path('');
        $custom = $this->files->isDirectory($customRoot) ? $this->files->directories($customRoot) : [];
        $slugs = array_unique(array_merge(self::BUNDLED, array_map('basename', $custom)));
        $activeSlug = $this->activeSlug();
        $themes = [];
        foreach ($slugs as $slug) {
            $theme = $this->inspect($slug);
            $theme['active'] = $theme['slug'] === $activeSlug;
            $themes[] = $theme;
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
        $theme = $this->validator->inspect(
            slug: $slug,
            themesPath: $this->path($slug),
            publicThemesPath: $this->path($slug),
            assetUrlPrefix: 'forum-themes',
            activeTheme: '',
            requiredFiles: ['theme.css'],
        );

        if ($theme['preview_url'] !== null && ! in_array($slug, self::BUNDLED, true)) {
            $theme['preview_url'] = route('modules.forum.theme-asset', ['slug' => $slug, 'asset' => $theme['manifest']['preview']]);
        }

        if ($theme['valid']) {
            $file = $this->path($slug).DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'theme.css';
            $size = @filesize($file);
            $error = match (true) {
                $size === false || $size > self::MAX_CSS_BYTES => 'theme_css_too_large',
                ! self::cssStaysLocal($this->files->get($file)) => 'theme_css_external',
                default => null,
            };
            if ($error !== null) {
                $theme['errors'][] = __('module-forum::messages.'.$error);
                $theme['valid'] = false;
                $theme['compatible'] = false;
            }
        }

        $forumMin = $theme['manifest']['forum_min'] ?? null;
        $theme['forum_min'] = is_string($forumMin) && trim($forumMin) !== '' ? trim($forumMin) : null;
        if ($theme['valid'] && $theme['compatible'] && $theme['forum_min'] !== null
            && version_compare($this->forumVersion, $theme['forum_min'], '<')) {
            $theme['compatible'] = false;
            $theme['errors'][] = __('module-forum::messages.theme_forum_too_old', ['version' => $theme['forum_min']]);
        }

        return $theme;
    }

    /**
     * A theme may only use files from its own folder (or data: images). Stylesheets
     * that reach other sites can leak page data, such as the form token, through
     * attribute selectors with background images, so they are refused:
     * no @import, no absolute or protocol-relative addresses, and no CSS escapes
     * (which could spell any of these in disguise).
     */
    public static function cssStaysLocal(string $css): bool
    {
        // Comments are checked too: a "/*" inside a CSS string is not a comment, so
        // stripping them first could hide live code from this check.
        $code = $css;

        // Browsers fetch subresources only over http(s): banning the word (it cannot be
        // escaped once backslashes are gone) and "//" closes every way to another host.
        return ! str_contains($code, '\\')
            && ! str_contains($code, '//')
            && preg_match('~@import|https?:~i', $code) !== 1;
    }

    /**
     * The theme the forum is drawn with: the chosen one while it is intact,
     * otherwise the bundled fallback, so a broken folder never leaves the forum bare.
     *
     * @return array<string, mixed>
     */
    public function active(): array
    {
        if ($this->active !== null) {
            return $this->active;
        }

        $requested = (string) ($this->settings->get(self::SETTING, self::FALLBACK) ?? self::FALLBACK);
        $theme = $this->inspect($requested);
        if (! $theme['valid'] || ! $theme['compatible']) {
            $theme = $this->inspect(self::FALLBACK);
        }

        return $this->active = $theme;
    }

    public function activeSlug(): string
    {
        return (string) $this->active()['slug'];
    }

    public function stylesheetUrl(): ?string
    {
        $theme = $this->active();
        if (! $theme['valid'] || ! $theme['compatible']) {
            return null;
        }
        $slug = (string) $theme['slug'];
        // The file time, not the manifest version: an owner editing theme.css by hand
        // sees the change at once without bumping the version.
        $modified = @filemtime($this->path($slug).DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'theme.css');

        $url = in_array($slug, self::BUNDLED, true)
            ? asset('forum-themes/'.$slug.'/theme.css')
            : route('modules.forum.theme-asset', ['slug' => $slug, 'asset' => 'theme.css']);

        return $url.($modified ? '?v='.$modified : '');
    }

    /** Serve only static assets from a private custom theme, including its relative CSS resources. */
    public function customAsset(string $slug, string $asset): Response
    {
        abort_if(in_array($slug, self::BUNDLED, true) || ! preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $slug), 404);
        abort_unless(preg_match('~\A(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_-][a-zA-Z0-9_.-]*\.[a-zA-Z0-9]+\z~', $asset), 404);
        $root = realpath($this->path($slug));
        $directory = realpath($this->path($slug).DIRECTORY_SEPARATOR.$slug);
        abort_unless($root !== false && $directory === $root.DIRECTORY_SEPARATOR.$slug, 404);
        $file = realpath($directory.DIRECTORY_SEPARATOR.$asset);
        abort_unless($file !== false && str_starts_with($file, $directory.DIRECTORY_SEPARATOR) && is_file($file), 404);
        $headers = ['Content-Security-Policy' => "default-src 'none'; sandbox", 'X-Content-Type-Options' => 'nosniff'];
        if ($asset === 'theme.css') {
            abort_if(filesize($file) > self::MAX_CSS_BYTES, 404);
            $css = $this->files->get($file);
            abort_unless(self::cssStaysLocal($css), 404);

            return response($css, 200, ['Content-Type' => 'text/css'] + $headers);
        }
        $mime = match (strtolower(pathinfo($asset, PATHINFO_EXTENSION))) {
            'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp',
            'svg' => 'image/svg+xml', 'gif' => 'image/gif', 'avif' => 'image/avif',
            'woff' => 'font/woff', 'woff2' => 'font/woff2', default => null,
        };
        abort_if($mime === null, 404);

        return response()->file($file, ['Content-Type' => $mime] + $headers);
    }

    public function activate(string $slug): void
    {
        $theme = $this->inspect($slug);
        if (! $theme['valid']) {
            throw new RuntimeException(__('A damaged theme cannot be activated.'));
        }
        if (! $theme['compatible']) {
            throw new RuntimeException((string) (end($theme['errors']) ?: __('A theme incompatible with this CMS version cannot be activated.')));
        }

        $this->settings->set(self::SETTING, $slug);
        $this->active = null;
    }
}
