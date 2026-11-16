<?php

namespace App\Services;

use App\Services\Settings\SettingsImageStorage;

/** How the public website lists news: counts per page and the cover used when an article has none. */
final class NewsDisplaySettings
{
    public const KEY_HOME_LIMIT = 'news.home_limit';

    public const KEY_PER_PAGE = 'news.per_page';

    public const KEY_DEFAULT_COVER = 'news.default_cover';

    /** Allowed values as [default, minimum, maximum]. */
    public const HOME_LIMIT = [3, 1, 12];

    public const PER_PAGE = [10, 5, 50];

    public function __construct(
        private readonly CmsSettings $settings,
        private readonly SettingsImageStorage $images,
    ) {}

    /** @return array{home_limit: int, per_page: int, default_cover: string|null, default_cover_url: string|null} */
    public function values(): array
    {
        $values = $this->settings->getMany([
            self::KEY_HOME_LIMIT => null,
            self::KEY_PER_PAGE => null,
            self::KEY_DEFAULT_COVER => null,
        ]);
        $cover = $this->images->normalizePath($values[self::KEY_DEFAULT_COVER], 'news-cover');

        return [
            'home_limit' => $this->integerValue($values[self::KEY_HOME_LIMIT], self::HOME_LIMIT),
            'per_page' => $this->integerValue($values[self::KEY_PER_PAGE], self::PER_PAGE),
            'default_cover' => $cover,
            'default_cover_url' => $this->images->publicUrl($cover),
        ];
    }

    /** @param array{home_limit: int, per_page: int, default_cover: string|null} $values */
    public function update(array $values): void
    {
        $this->settings->setMany([
            self::KEY_HOME_LIMIT => (string) $this->integerValue((string) $values['home_limit'], self::HOME_LIMIT),
            self::KEY_PER_PAGE => (string) $this->integerValue((string) $values['per_page'], self::PER_PAGE),
            self::KEY_DEFAULT_COVER => $values['default_cover'],
        ]);
    }

    public function homeLimit(): int
    {
        return $this->values()['home_limit'];
    }

    public function perPage(): int
    {
        return $this->values()['per_page'];
    }

    public function defaultCoverUrl(): ?string
    {
        return $this->values()['default_cover_url'];
    }

    /**
     * A stored number within its range; anything else falls back to the default.
     *
     * @param  array{int, int, int}  $range
     */
    private function integerValue(?string $value, array $range): int
    {
        [$default, $minimum, $maximum] = $range;
        if ($value === null || preg_match('/^\d{1,4}$/', trim($value)) !== 1) {
            return $default;
        }

        return max($minimum, min($maximum, (int) trim($value)));
    }
}
