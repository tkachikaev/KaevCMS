<?php

namespace App\Services\News;

use App\Models\News;
use App\Models\NewsTranslation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

final class NewsImageStorage
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const NEWS_PATH_PATTERN = '~^news/(?:covers|content)/\d{4}/\d{2}/[a-f0-9-]+\.(?:jpe?g|png|webp)$~i';

    private const CONTENT_PATH_PATTERN = '~(?:^|["\'])/uploads/(news/content/\d{4}/\d{2}/[a-f0-9-]+\.(?:jpe?g|png|webp))(?:["\']|$)~i';

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

        $absolutePath = $this->absolutePath($path);

        if (! File::isFile($absolutePath)) {
            return false;
        }

        $deleted = File::delete($absolutePath);

        if ($deleted) {
            $this->deleteEmptyParentDirectories(dirname($absolutePath));
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

        if (! isset(self::MIME_EXTENSIONS[$mime])) {
            throw new RuntimeException('Unsupported image MIME type.');
        }

        $contents = File::get($file->getRealPath());

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }

    public function publicUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return asset('uploads/'.ltrim(str_replace('\\', '/', $path), '/'));
    }

    public function publicPath(string $path): string
    {
        return '/uploads/'.ltrim(str_replace('\\', '/', $path), '/');
    }

    public function rootPath(): string
    {
        return rtrim((string) config('cms.news.uploads_path', public_path('uploads')), '\\/');
    }

    public function normalizeNewsPath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');

        if (str_contains($path, '..') || preg_match(self::NEWS_PATH_PATTERN, $path) !== 1) {
            return null;
        }

        return $path;
    }

    private function store(UploadedFile $file, string $scope): string
    {
        $mime = (string) $file->getMimeType();
        $extension = self::MIME_EXTENSIONS[$mime] ?? null;

        if ($extension === null) {
            throw new RuntimeException('Unsupported image MIME type.');
        }

        $directory = $scope.'/'.now()->format('Y/m');
        $filename = Str::uuid()->toString().'.'.$extension;
        $absoluteDirectory = $this->rootPath().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory);

        File::ensureDirectoryExists($absoluteDirectory, 0755, true);
        $file->move($absoluteDirectory, $filename);

        return $directory.'/'.$filename;
    }

    private function absolutePath(string $path): string
    {
        return $this->rootPath().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    private function delete(?string $path, string $requiredPrefix): void
    {
        $path = $this->normalizeNewsPath($path);

        if ($path === null || ! str_starts_with($path, $requiredPrefix)) {
            return;
        }

        $absolutePath = $this->absolutePath($path);

        if (File::delete($absolutePath)) {
            $this->deleteEmptyParentDirectories(dirname($absolutePath));
        }
    }

    private function deleteEmptyParentDirectories(string $directory): void
    {
        $newsRoot = $this->rootPath().DIRECTORY_SEPARATOR.'news';
        $directory = rtrim($directory, '\\/');

        while (str_starts_with($directory, $newsRoot) && $directory !== $newsRoot) {
            if (! File::isDirectory($directory) || count(File::files($directory)) > 0 || count(File::directories($directory)) > 0) {
                break;
            }

            File::deleteDirectory($directory);
            $directory = dirname($directory);
        }
    }
}
