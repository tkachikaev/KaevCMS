<?php

namespace App\Services\Pages;

use App\Models\PageTranslation;
use App\Services\Images\PublicImageStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class PageImageStorage
{
    private const PAGE_PATH_PATTERN = '~^pages/content/\d{4}/\d{2}/[a-f0-9-]+\.(?:jpe?g|png|webp)$~i';

    private const CONTENT_PATH_PATTERN = '~(?:^|["\'])/uploads/(pages/content/\d{4}/\d{2}/[a-f0-9-]+\.(?:jpe?g|png|webp))(?:["\']|$)~i';

    public function __construct(private readonly PublicImageStorage $storage) {}

    public function storeContent(UploadedFile $file): string
    {
        $mime = (string) $file->getMimeType();
        $extension = $this->storage->extensionForMime($mime);
        if ($extension === null) {
            throw new RuntimeException('Unsupported image MIME type.');
        }

        return $this->storage->store(
            $file,
            $this->rootPath(),
            'pages/content/'.now()->format('Y/m'),
            $extension,
        );
    }

    public function deleteIfUnreferenced(?string $path): bool
    {
        $path = $this->normalizePath($path);
        if ($path === null || $this->isReferenced($path)) {
            return false;
        }

        $absolutePath = $this->storage->absolutePath($this->rootPath(), $path);
        if (! File::isFile($absolutePath)) {
            return false;
        }

        $deleted = File::delete($absolutePath);
        if ($deleted) {
            $this->storage->deleteEmptyParentDirectories($this->rootPath(), 'pages', dirname($absolutePath));
        }

        return $deleted;
    }

    public function isReferenced(string $path): bool
    {
        $path = $this->normalizePath($path);
        if ($path === null) {
            return true;
        }

        return PageTranslation::query()
            ->where('body', 'like', '%'.$this->publicPath($path).'%')
            ->exists();
    }

    /** @return list<string> */
    public function extractContentPaths(string $html): array
    {
        preg_match_all(self::CONTENT_PATH_PATTERN, $html, $matches);
        $paths = [];

        foreach ($matches[1] as $path) {
            $normalized = $this->normalizePath($path);
            if ($normalized !== null) {
                $paths[strtolower($normalized)] = $normalized;
            }
        }

        return array_values($paths);
    }

    public function publicPath(string $path): string
    {
        return $this->storage->publicPath($path);
    }

    public function rootPath(): string
    {
        return rtrim((string) config('cms.pages.uploads_path', public_path('uploads')), '\\/');
    }

    public function normalizePath(?string $path): ?string
    {
        return $this->storage->normalizePath($path, self::PAGE_PATH_PATTERN);
    }
}
