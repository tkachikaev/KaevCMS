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

        return $this->resolveUploaded('items', $this->serverId($server), [(string) $itemId])
            ?? $this->externalItemIcon($this->catalogs->profileForServer($server), $itemId);
    }

    public function itemIconForProfile(string $profile, int $itemId): ?string
    {
        if ($itemId <= 0) {
            return null;
        }

        return $this->resolveUploaded('items', null, [(string) $itemId])
            ?? $this->externalItemIcon($profile, $itemId);
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

        $uploaded = $this->resolveUploaded(
            'characters',
            $server === null ? null : $this->serverId($server),
            $safeKeys,
        );

        return $uploaded
            ?? $this->externalCharacterAvatar($this->catalogs->profileForServer($server), $safeKeys);
    }

    public function rootPath(): string
    {
        return rtrim((string) config('cms.game_assets.uploads_path', public_path('uploads/game-assets')), '\\/');
    }

    public function standardRootPath(): string
    {
        return rtrim((string) config('cms.game_assets.standard_path', public_path('game-assets')), '\\/');
    }

    private function serverId(GameServer|int $server): int
    {
        return $server instanceof GameServer ? (int) $server->getKey() : $server;
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

    private function externalItemIcon(string $profile, int $itemId): ?string
    {
        $profile = $this->catalogs->normalizeProfile($profile);
        $entry = $profile !== null ? $this->catalogs->find($profile, $itemId) : null;
        $icon = $this->safeKey($entry['icon'] ?? null);
        if ($profile === null || $icon === null || str_contains($icon, '/')) {
            return null;
        }

        foreach ([
            'items/'.$icon.'.webp',
            'items/'.$profile.'/'.$icon.'.webp',
        ] as $relativePath) {
            $url = $this->standardAssetUrl($relativePath);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    /** @param list<string> $keys */
    private function externalCharacterAvatar(string $profile, array $keys): ?string
    {
        $profile = $this->catalogs->normalizeProfile($profile);
        if ($profile === null) {
            return null;
        }

        foreach ($keys as $key) {
            foreach (self::EXTENSIONS as $extension) {
                $relativePath = 'characters/'.$profile.'/'.$key.'.'.$extension;
                $url = $this->standardAssetUrl($relativePath);
                if ($url !== null) {
                    return $url;
                }
            }
        }

        return null;
    }

    private function standardAssetUrl(string $relativePath): ?string
    {
        $absolutePath = $this->standardRootPath()
            .DIRECTORY_SEPARATOR
            .str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        return File::isFile($absolutePath) ? asset('game-assets/'.$relativePath) : null;
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
