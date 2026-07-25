<?php

namespace App\Services\Account;

use Illuminate\Support\Facades\File;
use Throwable;

final class AccountAvatarCatalog
{
    private const EXTENSIONS = ['webp', 'png', 'jpg', 'jpeg'];

    /** @var list<array{filename: string, url: string}>|null */
    private ?array $avatars = null;

    /** @return list<array{filename: string, url: string}> */
    public function all(): array
    {
        if ($this->avatars !== null) {
            return $this->avatars;
        }

        $root = $this->rootPath();
        if (! File::isDirectory($root) || ! is_readable($root)) {
            return $this->avatars = [];
        }

        $rootRealPath = realpath($root);
        if (! is_string($rootRealPath)) {
            return $this->avatars = [];
        }

        $rootPrefix = rtrim(str_replace('\\', '/', $rootRealPath), '/').'/';
        $avatars = [];

        try {
            $files = File::files($root);
        } catch (Throwable) {
            return $this->avatars = [];
        }

        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $filename = $file->getFilename();
            $extension = strtolower($file->getExtension());
            $realPath = $file->getRealPath();
            $normalizedRealPath = is_string($realPath) ? str_replace('\\', '/', $realPath) : null;

            if (! in_array($extension, self::EXTENSIONS, true)
                || ! $this->safeFilename($filename)
                || $normalizedRealPath === null
                || ! str_starts_with($normalizedRealPath, $rootPrefix)) {
                continue;
            }

            try {
                $modifiedAt = $file->getMTime();
            } catch (Throwable) {
                continue;
            }

            $avatars[] = [
                'filename' => $filename,
                'url' => asset('uploads/account-avatars/'.$filename).'?v='.$modifiedAt,
            ];
        }

        usort(
            $avatars,
            static fn (array $left, array $right): int => strnatcasecmp($left['filename'], $right['filename']),
        );

        return $this->avatars = $avatars;
    }

    public function contains(?string $filename): bool
    {
        if ($filename === null || ! $this->safeFilename($filename)) {
            return false;
        }

        foreach ($this->all() as $avatar) {
            if (hash_equals($avatar['filename'], $filename)) {
                return true;
            }
        }

        return false;
    }

    public function url(?string $filename): ?string
    {
        if ($filename === null) {
            return null;
        }

        foreach ($this->all() as $avatar) {
            if (hash_equals($avatar['filename'], $filename)) {
                return $avatar['url'];
            }
        }

        return null;
    }

    public function rootPath(): string
    {
        return rtrim((string) config(
            'cms.account_avatars.uploads_path',
            public_path('uploads/account-avatars'),
        ), '\\/');
    }

    private function safeFilename(string $filename): bool
    {
        return strlen($filename) <= 190
            && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]*\z/D', $filename) === 1;
    }
}
