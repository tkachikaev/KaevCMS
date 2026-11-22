<?php

namespace KaevCMS\Modules\Forum\Services;

use App\Services\CmsSettings;
use App\Support\Themes\ThemeValidator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Forum themes are style-only packages in public/forum-themes/<slug>:
 * theme.json (name, version, author), theme.css and an optional preview.
 * They carry no templates or PHP, so a theme from a stranger can change how the
 * forum looks but cannot run code on the server.
 */
final class ForumThemes
{
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

    public function path(): string
    {
        return $this->themesPath ?? public_path('forum-themes');
    }

    /** @return list<array<string, mixed>> */
    public function installed(): array
    {
        if (! $this->files->isDirectory($this->path())) {
            return [];
        }

        $activeSlug = $this->activeSlug();
        $themes = [];
        foreach ($this->files->directories($this->path()) as $directory) {
            $theme = $this->inspect(basename($directory));
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
            themesPath: $this->path(),
            publicThemesPath: $this->path(),
            assetUrlPrefix: 'forum-themes',
            activeTheme: '',
            requiredFiles: ['theme.css'],
        );

        if ($theme['valid']) {
            $file = $this->path().DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'theme.css';
            $size = @filesize($file);
            $error = match (true) {
                $size === false || $size > self::MAX_CSS_BYTES => 'theme_css_too_large',
                ! $this->stylesheetStaysLocal($file, (int) $size) => 'theme_css_external',
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

    /** The check runs once per file version, not on every forum page. */
    private function stylesheetStaysLocal(string $file, int $size): bool
    {
        $key = 'forum.theme.css-check.'.hash('sha256', $file.'|'.$size.'|'.(int) @filemtime($file));

        return (bool) Cache::rememberForever($key, fn (): bool => self::cssStaysLocal((string) $this->files->get($file)));
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

    public function stylesheetUrl(): string
    {
        $slug = $this->activeSlug();
        // The file time, not the manifest version: an owner editing theme.css by hand
        // sees the change at once without bumping the version.
        $modified = @filemtime($this->path().DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'theme.css');

        return asset('forum-themes/'.$slug.'/theme.css').($modified ? '?v='.$modified : '');
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
