<?php

namespace App\Services\News;

use App\Models\News;
use App\Models\NewsTranslation;
use App\Services\Images\PublicImageStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class NewsImageStorage
{
    private const NEWS_PATH_PATTERN = '~^news/(?:covers|content)/\d{4}/\d{2}/[a-f0-9-]+\.(?:jpe?g|png|webp)$~i';

    private const CONTENT_PATH_PATTERN = '~(?:^|["\'])/uploads/(news/content/\d{4}/\d{2}/[a-f0-9-]+\.(?:jpe?g|png|webp))(?:["\']|$)~i';

    public function __construct(private readonly PublicImageStorage $storage) {}

    public function storeCover(UploadedFile $file): string
    {
        return $this->store($file, 'news/covers');
    }

    public function storeContent(UploadedFile $file): string
    {
        return $this->store($file, 'news/content');
    }

    public function deleteCover(?string $path): void
    {
        $this->delete($path, 'news/covers/');
    }

    public function deleteContent(?string $path): void
    {
        $this->delete($path, 'news/content/');
    }

    public function deleteIfUnreferenced(?string $path): bool
    {
        $path = $this->normalizeNewsPath($path);

        if ($path === null || $this->isReferenced($path)) {
            return false;
        }

        $absolutePath = $this->storage->absolutePath($this->rootPath(), $path);

        if (! File::isFile($absolutePath)) {
            return false;
        }

        $deleted = File::delete($absolutePath);

        if ($deleted) {
            $this->storage->deleteEmptyParentDirectories($this->rootPath(), 'news', dirname($absolutePath));
        }

        return $deleted;
    }

    public function isReferenced(string $path): bool
    {
        $path = $this->normalizeNewsPath($path);

        if ($path === null) {
            return true;
        }

        if (str_starts_with($path, 'news/covers/')) {
            return News::withTrashed()->where('image', $path)->exists();
        }

        $publicPath = $this->publicPath($path);

        return News::withTrashed()
            ->where('body', 'like', '%'.$publicPath.'%')
            ->exists()
            || NewsTranslation::query()
                ->where('body', 'like', '%'.$publicPath.'%')
                ->exists();
    }

    /**
     * @return list<string>
     */
    public function extractContentPaths(string $html): array
    {
        preg_match_all(self::CONTENT_PATH_PATTERN, $html, $matches);

        $paths = [];
        foreach ($matches[1] as $path) {
            $normalized = $this->normalizeNewsPath($path);
            if ($normalized !== null && str_starts_with($normalized, 'news/content/')) {
                $paths[strtolower($normalized)] = $normalized;
            }
        }

        return array_values($paths);
    }

    public function previewDataUrl(UploadedFile $file): string
    {
        $mime = (string) $file->getMimeType();

        return $this->storage->previewDataUrl($file, $mime);
    }

    public function publicUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return $this->storage->publicUrl($path);
    }

    public function publicPath(string $path): string
    {
        return $this->storage->publicPath($path);
    }

    public function rootPath(): string
    {
        return rtrim((string) config('cms.news.uploads_path', public_path('uploads')), '\\/');
    }

    public function normalizeNewsPath(?string $path): ?string
    {
        return $this->storage->normalizePath($path, self::NEWS_PATH_PATTERN);
    }

    private function store(UploadedFile $file, string $scope): string
    {
        $mime = (string) $file->getMimeType();
        $extension = $this->storage->extensionForMime($mime);

        if ($extension === null) {
            throw new RuntimeException('Unsupported image MIME type.');
        }

        return $this->storage->store(
            $file,
            $this->rootPath(),
            $scope.'/'.now()->format('Y/m'),
            $extension,
        );
    }

    private function delete(?string $path, string $requiredPrefix): void
    {
        $path = $this->normalizeNewsPath($path);

        if ($path === null || ! str_starts_with($path, $requiredPrefix)) {
            return;
        }

        $absolutePath = $this->storage->absolutePath($this->rootPath(), $path);

        if (File::delete($absolutePath)) {
            $this->storage->deleteEmptyParentDirectories($this->rootPath(), 'news', dirname($absolutePath));
        }
    }
}
