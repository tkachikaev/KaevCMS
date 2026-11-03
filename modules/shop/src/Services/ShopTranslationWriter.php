<?php

namespace KaevCMS\Modules\Shop\Services;

use App\Services\Localization\LanguageManager;
use Illuminate\Validation\ValidationException;
use KaevCMS\Modules\Shop\Models\ShopProduct;
use KaevCMS\Modules\Shop\Models\ShopTab;

/**
 * Writes localized section and product text for the enabled site languages and
 * keeps the legacy name_ru/name_en columns filled for snapshots and fallbacks.
 * Callers run it inside their own transaction.
 */
final class ShopTranslationWriter
{
    public function __construct(private readonly LanguageManager $languages) {}

    /** @return list<string> */
    public function locales(): array
    {
        return $this->languages->enabledCodes();
    }

    public function defaultLocale(): string
    {
        return $this->languages->default();
    }

    /**
     * @param  array<string, string|null>  $names  Names keyed by locale; empty ones fall back.
     * @return array{name_ru:string, name_en:string}
     */
    public function legacyNames(array $names, string $errorField = 'name'): array
    {
        $names = array_filter(array_map(static fn ($name): string => trim((string) $name), $names), static fn (string $name): bool => $name !== '');
        $fallback = $names[$this->defaultLocale()] ?? reset($names);
        if ($fallback === false) {
            throw ValidationException::withMessages([$errorField => __('module-shop::messages.name_required')]);
        }

        return ['name_ru' => $names['ru'] ?? $fallback, 'name_en' => $names['en'] ?? $fallback];
    }

    /** @param array<string, string|null> $names */
    public function saveSection(ShopTab $section, array $names): void
    {
        foreach ($this->locales() as $locale) {
            $name = trim((string) ($names[$locale] ?? ''));
            if ($name === '') {
                $section->translations()->where('locale', $locale)->delete();

                continue;
            }
            $section->translations()->updateOrCreate(['locale' => $locale], ['name' => $name]);
        }
    }

    /** @param array<string, array{name?:?string, description?:?string, name_manual?:bool}> $rows */
    public function saveProduct(ShopProduct $product, array $rows): void
    {
        foreach ($this->locales() as $locale) {
            $row = $rows[$locale] ?? [];
            $name = trim((string) ($row['name'] ?? ''));
            $description = trim((string) ($row['description'] ?? ''));
            if ($name === '' && $description === '') {
                $product->translations()->where('locale', $locale)->delete();

                continue;
            }
            $product->translations()->updateOrCreate(['locale' => $locale], [
                'name' => $name !== '' ? $name : ($product->nameFor($this->defaultLocale())),
                'description' => $description !== '' ? $description : null,
                'name_manual' => (bool) ($row['name_manual'] ?? true),
            ]);
        }
    }
}
