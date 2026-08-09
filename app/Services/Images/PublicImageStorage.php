<?php

namespace App\Services\Images;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

final class PublicImageStorage
{
    private const RASTER_MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function extensionForMime(string $mime): ?string
    {
        return self::RASTER_MIME_EXTENSIONS[$mime] ?? null;
    }

    public function store(UploadedFile $file, string $rootPath, string $relativeDirectory, string $extension): string
    {
        if (! in_array($extension, ['jpg', 'png', 'webp', 'ico'], true)) {
            throw new RuntimeException('Unsupported image storage extension.');
        }

        $relativeDirectory = trim(str_replace('\\', '/', $relativeDirectory), '/');
        if ($relativeDirectory === '' || str_contains($relativeDirectory, '..')) {
            throw new RuntimeException('Invalid image storage directory.');
        }

        $filename = Str::uuid()->toString().'.'.$extension;
        $absoluteDirectory = rtrim($rootPath, '\\/').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);

        File::ensureDirectoryExists($absoluteDirectory, 0755, true);
        $file->move($absoluteDirectory, $filename);

        return $relativeDirectory.'/'.$filename;
    }

    public function normalizePath(?string $path, string $pattern, bool $trim = false): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        if ($trim) {
            $path = trim($path);
        }

        if ($path === '') {
            return null;
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');

        if (str_contains($path, '..') || preg_match($pattern, $path) !== 1) {
            return null;
        }

        return $path;
    }

    public function absolutePath(string $rootPath, string $path): string
    {
        return rtrim($rootPath, '\\/').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    public function publicPath(string $path): string
    {
        return '/uploads/'.ltrim(str_replace('\\', '/', $path), '/');
    }

    public function publicUrl(string $path): string
    {
        return asset(ltrim($this->publicPath($path), '/'));
    }

    public function previewDataUrl(UploadedFile $file, string $mime): string
    {
        if ($this->extensionForMime($mime) === null) {
            throw new RuntimeException('Unsupported image MIME type.');
        }

        $realPath = $file->getRealPath();
        if (! is_string($realPath)) {
            throw new RuntimeException('The image upload is not readable.');
        }

        return 'data:'.$mime.';base64,'.base64_encode(File::get($realPath));
    }

    public function deleteEmptyParentDirectories(
        string $rootPath,
        string $protectedRelativeRoot,
        string $directory,
    ): void {
        $protectedRoot = rtrim($rootPath, '\\/').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, trim($protectedRelativeRoot, '/'));
        $directory = rtrim($directory, '\\/');
        $protectedPrefix = $protectedRoot.DIRECTORY_SEPARATOR;

        while ($directory !== $protectedRoot && str_starts_with($directory, $protectedPrefix)) {
            if (! File::isDirectory($directory) || count(File::files($directory)) > 0 || count(File::directories($directory)) > 0) {
                break;
            }

            File::deleteDirectory($directory);
            $directory = dirname($directory);
        }
    }
}
