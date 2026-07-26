<?php

namespace App\Services\GameAssets;

use App\Models\GameServer;
use JsonException;
use Throwable;

final class GameItemProfileCatalog
{
    /** @var array<string, array<int, array<string, string>>> */
    private array $catalogs = [];

    /** @var array<int, string> */
    private array $serverProfiles = [];

    /** @return array<string, string>|null */
    public function find(string $profile, int $itemId): ?array
    {
        $profile = $this->normalizeProfile($profile);
        if ($profile === null || $itemId <= 0) {
            return null;
        }

        return $this->catalog($profile)[$itemId] ?? null;
    }

    public function profileForServer(GameServer|int|null $server): string
    {
        if (is_int($server) && $server > 0) {
            if (isset($this->serverProfiles[$server])) {
                return $this->serverProfiles[$server];
            }

            try {
                $resolved = GameServer::query()->select(['id', 'chronicle'])->find($server);
            } catch (Throwable) {
                $resolved = null;
            }

            return $this->serverProfiles[$server] = $this->profileForServer($resolved);
        }

        if ($server instanceof GameServer) {
            $profile = $this->normalizeProfile((string) $server->chronicle);
            if ($profile !== null) {
                if ((int) $server->getKey() > 0) {
                    $this->serverProfiles[(int) $server->getKey()] = $profile;
                }

                return $profile;
            }
        }

        return $this->normalizeProfile(
            (string) config('cms.game_assets.default_item_profile', 'interlude')
        ) ?? 'interlude';
    }

    public function normalizeProfile(string $profile): ?string
    {
        $profile = strtolower(trim($profile));
        if ($profile === '') {
            return null;
        }

        $collapsed = preg_replace('/[^a-z0-9]+/', '', $profile);
        if (! is_string($collapsed)) {
            return null;
        }

        if ($profile === 'ct0' || str_contains($profile, 'interlude')) {
            return 'interlude';
        }
        if ($collapsed === 'h5' || str_contains($collapsed, 'highfive')) {
            return 'high-five';
        }
        if (str_contains($collapsed, 'shinemaker')) {
            return 'shine-maker';
        }
        if (str_contains($profile, 'classic')) {
            return 'classic';
        }

        $profile = preg_replace('/[^a-z0-9]+/', '-', $profile);
        if (! is_string($profile)) {
            return null;
        }

        $profile = trim($profile, '-');

        return $profile !== ''
            && strlen($profile) <= 64
            && preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $profile) === 1
                ? $profile
                : null;
    }

    /** @return array<int, array<string, string>> */
    private function catalog(string $profile): array
    {
        if (array_key_exists($profile, $this->catalogs)) {
            return $this->catalogs[$profile];
        }

        $directory = rtrim(
            (string) config('cms.game_assets.item_catalog_path', resource_path('game-items')),
            '\\/',
        );
        $path = $directory.DIRECTORY_SEPARATOR.$profile.'.json';
        if (! is_file($path)) {
            return $this->catalogs[$profile] = [];
        }

        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            return $this->catalogs[$profile] = [];
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->catalogs[$profile] = [];
        }

        if (! is_array($decoded)) {
            return $this->catalogs[$profile] = [];
        }

        $catalog = [];
        foreach ($decoded as $itemId => $entry) {
            if (! is_array($entry) || ! is_numeric($itemId) || (int) $itemId <= 0) {
                continue;
            }

            $normalized = [];
            $icon = $this->normalizeIconKey($entry['icon'] ?? null);

            if ($icon !== null) {
                $normalized['icon'] = $icon;
            }

            foreach ($entry as $field => $value) {
                if (! is_string($field)
                    || preg_match('/\Aname_[a-zA-Z0-9_-]{2,32}\z/D', $field) !== 1) {
                    continue;
                }

                $name = $this->normalizeName($value);
                if ($name !== null) {
                    $normalized[$field] = $name;
                }
            }

            $catalog[(int) $itemId] = $normalized;
        }

        return $this->catalogs[$profile] = $catalog;
    }

    private function normalizeIconKey(mixed $icon): ?string
    {
        if (! is_string($icon)) {
            return null;
        }

        $icon = trim($icon);

        return $icon !== ''
            && strlen($icon) <= 190
            && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]*\z/D', $icon) === 1
                ? $icon
                : null;
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
