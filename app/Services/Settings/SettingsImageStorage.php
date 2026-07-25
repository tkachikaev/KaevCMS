<?php

namespace App\Services\Settings;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

final class SettingsImageStorage
{
    private const IMAGE_MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const ICON_MIME_TYPES = [
        'image/x-icon',
        'image/vnd.microsoft.icon',
        'application/ico',
        'application/x-ico',
        'application/octet-stream',
    ];

    private const PATH_PATTERN = '~^settings/(logo|favicon)/[a-f0-9-]+\.(?:jpg|png|webp|ico)$~i';

    public function validateUpload(UploadedFile $file, string $kind): ?string
    {
        if (! in_array($kind, ['logo', 'favicon'], true)) {
            return __('Unknown image type.');
        }

        if (! $file->isValid() || ! is_string($file->getRealPath())) {
            return __('The file could not be uploaded.');
        }

        $path = $file->getRealPath();
        $mime = (string) File::mimeType($path);
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if ($kind === 'favicon' && $extension === 'ico') {
            if (! in_array($mime, self::ICON_MIME_TYPES, true) || ! $this->isValidIco($path)) {
                return __('The ICO file is damaged or has an unsupported format.');
            }

            return null;
        }

        if (! isset(self::IMAGE_MIME_EXTENSIONS[$mime])) {
            return $kind === 'logo'
                ? __('The logo must be a JPG, PNG or WebP image.')
                : __('The favicon must be a PNG, WebP or ICO image.');
        }

        $detectedExtension = self::IMAGE_MIME_EXTENSIONS[$mime];

        if ($kind === 'favicon' && $detectedExtension === 'jpg') {
            return __('The favicon must be a PNG, WebP or ICO image.');
        }

        $size = @getimagesize($path);
        if (! is_array($size)) {
            return __('The file is not a valid image.');
        }

        $maxDimension = $kind === 'logo' ? 6000 : 2048;
        if ($size[0] < 1 || $size[1] < 1 || $size[0] > $maxDimension || $size[1] > $maxDimension) {
            return __('The image dimensions must not exceed :width×:height pixels.', ['width' => $maxDimension, 'height' => $maxDimension]);
        }

        return null;
    }

    public function store(UploadedFile $file, string $kind): string
    {
        $error = $this->validateUpload($file, $kind);
        if ($error !== null) {
            throw new RuntimeException($error);
        }

        $extension = $this->resolvedExtension($file, $kind);
        $relativeDirectory = 'settings/'.$kind;
        $filename = Str::uuid()->toString().'.'.$extension;
        $absoluteDirectory = $this->rootPath().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);

        File::ensureDirectoryExists($absoluteDirectory, 0755, true);
        $file->move($absoluteDirectory, $filename);

        return $relativeDirectory.'/'.$filename;
    }

    public function delete(?string $path, string $kind): bool
    {
        $path = $this->normalizePath($path, $kind);
        if ($path === null) {
            return false;
        }

        return File::delete($this->absolutePath($path));
    }

    public function publicUrl(?string $path): ?string
    {
        $path = $this->normalizePath($path);

        return $path === null ? null : asset('uploads/'.$path);
    }

    public function normalizePath(?string $path, ?string $kind = null): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $path = ltrim(str_replace('\\', '/', trim($path)), '/');

        if (str_contains($path, '..') || preg_match(self::PATH_PATTERN, $path, $matches) !== 1) {
            return null;
        }

        if ($kind !== null && $matches[1] !== $kind) {
            return null;
        }

        return $path;
    }

    public function rootPath(): string
    {
        return rtrim((string) config('cms.settings.uploads_path', public_path('uploads')), '\\/');
    }

    private function absolutePath(string $path): string
    {
        return $this->rootPath().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    private function resolvedExtension(UploadedFile $file, string $kind): string
    {
        $clientExtension = strtolower((string) $file->getClientOriginalExtension());
        if ($kind === 'favicon' && $clientExtension === 'ico') {
            return 'ico';
        }

        $mime = (string) File::mimeType($file->getRealPath());
        $extension = self::IMAGE_MIME_EXTENSIONS[$mime] ?? null;

        if ($extension === null || ($kind === 'favicon' && $extension === 'jpg')) {
            throw new RuntimeException('Unsupported settings image type.');
        }

        return $extension;
    }

    private function isValidIco(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        $fileSize = @filesize($path);

        if ($handle === false || ! is_int($fileSize) || $fileSize < 22) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            return false;
        }

        $header = fread($handle, 6);
        if (! is_string($header) || strlen($header) !== 6) {
            fclose($handle);

            return false;
        }

        $data = unpack('vreserved/vtype/vcount', $header);
        if (! is_array($data)) {
            fclose($handle);

            return false;
        }

        $count = (int) ($data['count'] ?? 0);

        if (($data['reserved'] ?? -1) !== 0 || ($data['type'] ?? -1) !== 1 || $count < 1 || $count > 64) {
            fclose($handle);

            return false;
        }

        $directorySize = 6 + ($count * 16);
        if ($directorySize > $fileSize) {
            fclose($handle);

            return false;
        }

        for ($index = 0; $index < $count; $index++) {
            $entry = fread($handle, 16);
            if (! is_string($entry) || strlen($entry) !== 16) {
                fclose($handle);

                return false;
            }

            $entryData = unpack('Cwidth/Cheight/Ccolors/Creserved/vplanes/vbits/Vbytes/Voffset', $entry);
            $bytes = is_array($entryData) ? (int) ($entryData['bytes'] ?? 0) : 0;
            $offset = is_array($entryData) ? (int) ($entryData['offset'] ?? 0) : 0;

            if ($bytes < 4 || $offset < $directorySize || $offset > $fileSize || $bytes > ($fileSize - $offset)) {
                fclose($handle);

                return false;
            }
        }

        fclose($handle);

        return true;
    }
}
