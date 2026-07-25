<?php

namespace App\Services\Pages;

use App\Models\PageTranslation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

final class PageImageStorage
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const PAGE_PATH_PATTERN = '~^pages/content/\d{4}/\d{2}/[a-f0-9-]+\.(?:jpe?g|png|webp)$~i';

    private const CONTENT_PATH_PATTERN = '~(?:^|["\'])/uploads/(pages/content/\d{4}/\d{2}/[a-f0-9-]+\.(?:jpe?g|png|webp))(?:["\']|$)~i';

    public function storeContent(UploadedFile $file): string
    {
        $mime = (string) $file->getMimeType();
        $extension = self::MIME_EXTENSIONS[$mime] ?? null;
        if ($extension === null) {
            throw new RuntimeException('Unsupported image MIME type.');
        }

        $directory = 'pages/content/'.now()->format('Y/m');
        $filename = Str::uuid()->toString().'.'.$extension;
        $absoluteDirectory = $this->rootPath().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory);

        File::ensureDirectoryExists($absoluteDirectory, 0755, true);
        $file->move($absoluteDirectory, $filename);

        return $directory.'/'.$filename;
    }

    public function deleteIfUnreferenced(?string $path): bool
    {
        $path = $this->normalizePath($path);
        if ($path === null || $this->isReferenced($path)) {
            return false;
        }

        $absolutePath = $this->rootPath().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
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
        return '/uploads/'.ltrim(str_replace('\\', '/', $path), '/');
    }

    public function rootPath(): string
    {
        return rtrim((string) config('cms.pages.uploads_path', public_path('uploads')), '\\/');
    }

    public function normalizePath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');

        if (str_contains($path, '..') || preg_match(self::PAGE_PATH_PATTERN, $path) !== 1) {
            return null;
        }

        return $path;
    }

    private function deleteEmptyParentDirectories(string $directory): void
    {
        $pagesRoot = $this->rootPath().DIRECTORY_SEPARATOR.'pages';
        $directory = rtrim($directory, '\\/');

        while (str_starts_with($directory, $pagesRoot) && $directory !== $pagesRoot) {
            if (! File::isDirectory($directory) || count(File::files($directory)) > 0 || count(File::directories($directory)) > 0) {
                break;
            }

            File::deleteDirectory($directory);
            $directory = dirname($directory);
        }
    }
}
