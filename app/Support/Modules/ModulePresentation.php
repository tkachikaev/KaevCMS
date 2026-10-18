<?php

namespace App\Support\Modules;

/** Localize catalogue text at render time, including disabled modules. */
final class ModulePresentation
{
    /** @param array<string, mixed> $module */
    public static function text(array $module, string $field): string
    {
        $locales = $module['manifest']['locales'] ?? [];
        foreach (array_unique([app()->getLocale(), config('app.fallback_locale')]) as $locale) {
            $value = $locales[$locale][$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return (string) ($module[$field] ?? '');
    }
}
