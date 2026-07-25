<?php

namespace App\Services\Localization;

use App\Services\CmsSettings;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

final class LanguageManager
{
    public const KEY_ENABLED = 'localization.enabled';

    public const KEY_DEFAULT = 'localization.default';

    public const KEY_FALLBACK = 'localization.fallback';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $installedCache = null;

    public function __construct(private readonly CmsSettings $settings) {}

    /** @return array<string, array{code:string,name:string,native_name:string,direction:string,fallback:string,author:string,built_in:bool,coverage:int}> */
    public function installed(): array
    {
        if ($this->installedCache !== null) {
            return $this->installedCache;
        }

        $languages = [];
        $basePath = lang_path();

        if (! File::isDirectory($basePath)) {
            return $this->installedCache = [];
        }

        foreach (File::directories($basePath) as $directory) {
            $metadataPath = $directory.DIRECTORY_SEPARATOR.'language.php';

            if (! File::isFile($metadataPath)) {
                continue;
            }

            $metadata = require $metadataPath;
            if (! is_array($metadata)) {
                continue;
            }

            $directoryCode = basename($directory);
            $code = $this->normalizeCode((string) ($metadata['code'] ?? $directoryCode));

            if ($code === null || $code !== $this->normalizeCode($directoryCode)) {
                continue;
            }

            $languages[$code] = [
                'code' => $code,
                'name' => trim((string) ($metadata['name'] ?? $code)) ?: $code,
                'native_name' => trim((string) ($metadata['native_name'] ?? $metadata['name'] ?? $code)) ?: $code,
                'direction' => strtolower((string) ($metadata['direction'] ?? 'ltr')) === 'rtl' ? 'rtl' : 'ltr',
                'fallback' => $this->normalizeCode((string) ($metadata['fallback'] ?? 'en')) ?? 'en',
                'author' => trim((string) ($metadata['author'] ?? '')),
                'built_in' => in_array($code, (array) config('localization.built_in', ['ru', 'en']), true),
                'coverage' => $this->coverage($code),
            ];
        }

        uasort($languages, static function (array $left, array $right): int {
            if ($left['built_in'] !== $right['built_in']) {
                return $left['built_in'] ? -1 : 1;
            }

            return strnatcasecmp($left['native_name'], $right['native_name']);
        });

        return $this->installedCache = $languages;
    }

    /** @return array<string, array<string, mixed>> */
    public function enabled(): array
    {
        $installed = $this->installed();
        if ($installed === []) {
            return [];
        }

        $stored = $this->decodeLocales($this->settings->get(self::KEY_ENABLED));
        if ($stored === []) {
            $stored = array_values(array_filter(
                (array) config('localization.built_in', ['ru', 'en']),
                static fn (mixed $code): bool => is_string($code),
            ));
        }

        $enabled = [];
        foreach ($stored as $code) {
            if (isset($installed[$code])) {
                $enabled[$code] = $installed[$code];
            }
        }

        if ($enabled === []) {
            $firstCode = (string) array_key_first($installed);
            $enabled[$firstCode] = $installed[$firstCode];
        }

        return $enabled;
    }

    /** @return array<int, string> */
    public function enabledCodes(): array
    {
        return array_keys($this->enabled());
    }

    public function default(): string
    {
        $enabled = $this->enabledCodes();
        $candidate = $this->normalizeCode((string) $this->settings->get(
            self::KEY_DEFAULT,
            (string) config('localization.default', config('app.locale', 'ru')),
        ));

        if ($candidate !== null && in_array($candidate, $enabled, true)) {
            return $candidate;
        }

        return $enabled[0] ?? 'ru';
    }

    public function fallback(): string
    {
        $enabled = $this->enabledCodes();
        $candidate = $this->normalizeCode((string) $this->settings->get(
            self::KEY_FALLBACK,
            (string) config('localization.fallback', config('app.fallback_locale', 'en')),
        ));

        if ($candidate !== null && in_array($candidate, $enabled, true)) {
            return $candidate;
        }

        $default = $this->default();
        foreach ($enabled as $code) {
            if ($code !== $default) {
                return $code;
            }
        }

        return $default;
    }

    /** @return array<int, string> */
    public function fallbackCandidates(?string $locale = null): array
    {
        $candidates = [];
        $locale = $this->normalizeCode((string) ($locale ?? app()->getLocale()));

        if ($locale !== null && $this->isEnabled($locale)) {
            $candidates[] = $locale;
        }

        $candidates[] = $this->fallback();
        $candidates[] = $this->default();

        return array_values(array_unique($candidates));
    }

    public function isInstalled(string $locale): bool
    {
        $locale = $this->normalizeCode($locale);

        return $locale !== null && isset($this->installed()[$locale]);
    }

    public function isEnabled(string $locale): bool
    {
        $locale = $this->normalizeCode($locale);

        return $locale !== null && isset($this->enabled()[$locale]);
    }

    /** @return array<string, mixed>|null */
    public function language(string $locale): ?array
    {
        $locale = $this->normalizeCode($locale);

        return $locale !== null ? ($this->installed()[$locale] ?? null) : null;
    }

    public function direction(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return (string) (($this->language($locale)['direction'] ?? 'ltr'));
    }

    /** @param array<int, string> $enabled */
    public function update(array $enabled, string $default, string $fallback): void
    {
        $installed = $this->installed();
        $normalized = [];

        foreach ($enabled as $code) {
            $code = $this->normalizeCode((string) $code);
            if ($code !== null && isset($installed[$code])) {
                $normalized[] = $code;
            }
        }

        $normalized = array_values(array_unique($normalized));
        if ($normalized === []) {
            throw new InvalidArgumentException('At least one installed language must remain enabled.');
        }

        $default = $this->normalizeCode($default) ?? '';
        $fallback = $this->normalizeCode($fallback) ?? '';

        if (! in_array($default, $normalized, true)) {
            throw new InvalidArgumentException('The default language must be enabled.');
        }

        if (! in_array($fallback, $normalized, true)) {
            throw new InvalidArgumentException('The fallback language must be enabled.');
        }

        $this->settings->setMany([
            self::KEY_ENABLED => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            self::KEY_DEFAULT => $default,
            self::KEY_FALLBACK => $fallback,
        ]);
    }

    public function normalizeCode(string $code): ?string
    {
        $code = trim(str_replace('_', '-', $code));
        if ($code === '') {
            return null;
        }

        $parts = explode('-', $code, 2);
        $language = strtolower($parts[0]);
        $normalized = $language;

        if (isset($parts[1]) && $parts[1] !== '') {
            $normalized .= '-'.strtoupper($parts[1]);
        }

        $pattern = '/^'.(string) config('localization.locale_pattern', '[a-z]{2,3}(?:-[A-Z]{2})?').'$/D';

        return preg_match($pattern, $normalized) === 1 ? $normalized : null;
    }

    public function coverage(string $locale): int
    {
        $reference = $this->translationKeys('en');
        if ($reference === []) {
            $reference = $this->translationKeys('ru');
        }

        if ($reference === []) {
            return 100;
        }

        $translated = array_fill_keys($this->translationKeys($locale), true);
        $present = 0;

        foreach ($reference as $key) {
            if (isset($translated[$key])) {
                $present++;
            }
        }

        return (int) floor(($present / count($reference)) * 100);
    }

    /** @return array<int, string> */
    private function translationKeys(string $locale): array
    {
        $path = lang_path($locale.'.json');
        if (! File::isFile($path)) {
            return [];
        }

        $decoded = json_decode((string) File::get($path), true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_keys($decoded), 'is_string'));
    }

    /** @return array<int, string> */
    private function decodeLocales(?string $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return [];
        }

        $locales = [];
        foreach ($decoded as $code) {
            if (! is_string($code)) {
                continue;
            }

            $normalized = $this->normalizeCode($code);
            if ($normalized !== null) {
                $locales[] = $normalized;
            }
        }

        return array_values(array_unique($locales));
    }
}
