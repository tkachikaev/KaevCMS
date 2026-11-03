<?php

namespace KaevCMS\Modules\Shop\Models\Concerns;

use App\Services\Localization\LanguageManager;

/**
 * Localized catalog text with the site's language fallback chain. The legacy
 * name_ru/name_en columns answer only when no translation row exists.
 */
trait HasShopTranslations
{
    protected function translated(string $field, ?string $locale = null): ?string
    {
        $translations = $this->relationLoaded('translations') ? $this->translations : $this->translations()->get();
        $byLocale = $translations->keyBy('locale');
        foreach (app(LanguageManager::class)->fallbackCandidates($locale) as $candidate) {
            $value = $byLocale->has($candidate) ? trim((string) $byLocale->get($candidate)->{$field}) : '';
            if ($value !== '') {
                return $value;
            }
        }
        foreach ($translations as $translation) {
            $value = trim((string) $translation->{$field});
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** @return array<string, array<string, mixed>> Translation fields keyed by locale for editors. */
    public function translationMap(): array
    {
        $translations = $this->relationLoaded('translations') ? $this->translations : $this->translations()->get();

        return $translations->keyBy('locale')->map(static fn ($row): array => $row->only(['name', 'description', 'name_manual']))->all();
    }
}
