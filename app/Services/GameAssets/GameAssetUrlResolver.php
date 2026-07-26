<?php

namespace App\Services\GameAssets;

use App\Models\GameServer;
use Illuminate\Support\Facades\File;

final class GameAssetUrlResolver
{
    private const EXTENSIONS = ['webp', 'png', 'jpg', 'jpeg'];

    public function __construct(private readonly GameItemProfileCatalog $catalogs) {}

    public function itemIcon(GameServer|int $server, int $itemId): ?string
    {
        if ($itemId <= 0) {
            return null;
        }

        return $this->resolveUploaded(
            'items',
            $this->serverId($server),
            $this->itemKeys($this->catalogs->profileForServer($server), $itemId),
        );
    }

    public function itemIconForProfile(string $profile, int $itemId): ?string
    {
        if ($itemId <= 0) {
            return null;
        }

        return $this->resolveUploaded('items', null, $this->itemKeys($profile, $itemId));
    }

    public function characterAvatar(GameServer|int|null $server, string $key): ?string
    {
        return $this->firstCharacterAvatar($server, [$key]);
    }

    /** @param list<string> $keys */
    public function firstCharacterAvatar(GameServer|int|null $server, array $keys): ?string
    {
        $safeKeys = [];
        foreach ($keys as $key) {
            $safeKey = $this->safeKey($key);
            if ($safeKey !== null && ! in_array($safeKey, $safeKeys, true)) {
                $safeKeys[] = $safeKey;
            }
        }

        if ($safeKeys === []) {
            return null;
        }

        return $this->resolveUploaded(
            'characters',
            $server === null ? null : $this->serverId($server),
            $safeKeys,
        );
    }

    public function rootPath(): string
    {
        return rtrim((string) config('cms.game_assets.uploads_path', public_path('uploads/game-assets')), '\\/');
    }

    private function serverId(GameServer|int $server): int
    {
        return $server instanceof GameServer ? (int) $server->getKey() : $server;
    }

    /** @return list<string> */
    private function itemKeys(string $profile, int $itemId): array
    {
        $keys = [(string) $itemId];
        $profile = $this->catalogs->normalizeProfile($profile);
        $entry = $profile !== null ? $this->catalogs->find($profile, $itemId) : null;
        $icon = $this->safeKey($entry['icon'] ?? null);

        if ($icon !== null && ! str_contains($icon, '/') && ! in_array($icon, $keys, true)) {
            $keys[] = $icon;
        }

        return $keys;
    }

    /** @param list<string> $keys */
    private function resolveUploaded(string $category, ?int $serverId, array $keys): ?string
    {
        $scopeBases = [];
        if ($serverId !== null && $serverId > 0) {
            $scopeBases[] = $category.'/servers/'.$serverId;
        }
        $scopeBases[] = $category.'/common';

        foreach ($scopeBases as $scopeBase) {
            foreach ($keys as $key) {
                foreach (self::EXTENSIONS as $extension) {
                    $relativePath = $scopeBase.'/'.$key.'.'.$extension;
                    $absolutePath = $this->rootPath().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

                    if (File::isFile($absolutePath)) {
                        return asset('uploads/game-assets/'.$relativePath);
                    }
                }
            }
        }

        return null;
    }

    private function safeKey(mixed $key): ?string
    {
        if (! is_string($key)) {
            return null;
        }

        $key = trim(str_replace('\\', '/', $key));
        if ($key === '' || strlen($key) > 190 || str_starts_with($key, '/') || str_ends_with($key, '/')) {
            return null;
        }

        return preg_match(
            '/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,62}(?:\/[a-zA-Z0-9][a-zA-Z0-9._-]{0,62}){0,7}\z/D',
            $key,
        ) === 1 ? $key : null;
    }
}
