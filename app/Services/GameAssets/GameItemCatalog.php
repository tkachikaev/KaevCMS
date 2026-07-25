<?php

namespace App\Services\GameAssets;

use App\Models\GameServer;
use App\Services\Localization\LanguageManager;
use Illuminate\Support\Facades\Lang;

final class GameItemCatalog
{
    /** @var array<string, array<string|int, mixed>> */
    private array $catalogs = [];

    public function __construct(private readonly LanguageManager $languages) {}

    public function displayName(
        GameServer|int|null $server,
        int $itemId,
        ?string $fallbackName = null,
        ?string $locale = null,
    ): string {
        return $this->knownName($server, $itemId, $locale)
            ?? $this->normalizeName($fallbackName)
            ?? (string) Lang::get('Game item', [], $locale);
    }

    public function knownName(
        GameServer|int|null $server,
        int $itemId,
        ?string $locale = null,
    ): ?string {
        if ($itemId <= 0) {
            return null;
        }

        $serverId = $server instanceof GameServer ? (int) $server->getKey() : $server;

        foreach ($this->localeCandidates($locale) as $catalogLocale) {
            $catalog = $this->catalog($catalogLocale);
            $servers = $catalog['servers'] ?? [];

            if ($serverId !== null && $serverId > 0 && is_array($servers)) {
                $serverItems = $servers[$serverId] ?? [];
                if (is_array($serverItems)) {
                    $name = $this->normalizeName($serverItems[$itemId] ?? null);
                    if ($name !== null) {
                        return $name;
                    }
                }
            }

            $commonItems = $catalog['common'] ?? $catalog;
            if (is_array($commonItems)) {
                $name = $this->normalizeName($commonItems[$itemId] ?? null);
                if ($name !== null) {
                    return $name;
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    private function localeCandidates(?string $locale): array
    {
        $currentLocale = $this->safeLocale($locale ?? app()->getLocale());
        $candidates = $currentLocale !== null ? [$currentLocale] : [];

        foreach ($this->languages->fallbackCandidates($currentLocale) as $fallbackLocale) {
            $safeLocale = $this->safeLocale($fallbackLocale);
            if ($safeLocale !== null && ! in_array($safeLocale, $candidates, true)) {
                $candidates[] = $safeLocale;
            }
        }

        return $candidates;
    }

    /** @return array<string|int, mixed> */
    private function catalog(string $locale): array
    {
        if (array_key_exists($locale, $this->catalogs)) {
            return $this->catalogs[$locale];
        }

        $path = lang_path($locale.'/items.php');
        if (! is_file($path)) {
            return $this->catalogs[$locale] = [];
        }

        $catalog = require $path;

        return $this->catalogs[$locale] = is_array($catalog) ? $catalog : [];
    }

    private function safeLocale(string $locale): ?string
    {
        $locale = trim($locale);

        return preg_match('/\A[a-zA-Z0-9_-]{2,32}\z/D', $locale) === 1 ? $locale : null;
    }

    private function normalizeName(mixed $name): ?string
    {
        if (! is_string($name)) {
            return null;
        }

        $name = trim($name);

        return $name !== '' ? $name : null;
    }
}
