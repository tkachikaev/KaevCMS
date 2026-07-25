<?php

namespace App\Services\Updates;

final class UpdatePathPolicy
{
    /** @var list<string> */
    private const FORBIDDEN_CORE_PREFIXES = [
        'core/.git',
        'core/storage',
        'core/vendor',
        'core/public',
        'core/bootstrap/kaevcms-public-path.php',
        'core/bootstrap/cache',
    ];

    /** @var list<string> */
    private const FORBIDDEN_PUBLIC_PREFIXES = [
        'public/uploads',
        'public/storage',
        'public/kaevcms-path.php',
    ];

    public function isAbsoluteFilesystemPath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('~\A[A-Za-z]:[\\\\/]~', $path) === 1;
    }

    public function isSafeArchivePath(string $path): bool
    {
        if ($path === ''
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || str_contains($path, ':')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return false;
        }

        if (str_starts_with($path, '/') || preg_match('/\A[A-Za-z]:\//', $path) === 1) {
            return false;
        }

        $segments = explode('/', rtrim($path, '/'));

        return ! in_array('.', $segments, true)
            && ! in_array('..', $segments, true)
            && ! in_array('', $segments, true);
    }

    public function isSafeTarget(string $path): bool
    {
        if (str_ends_with($path, '/') || ! $this->isSafeArchivePath($path)) {
            return false;
        }

        if (! str_starts_with($path, 'core/') && ! str_starts_with($path, 'public/')) {
            return false;
        }

        $normalized = strtolower(rtrim($path, '/'));
        if (in_array($normalized, ['core', 'public'], true)) {
            return false;
        }

        $segments = explode('/', $normalized);
        foreach ($segments as $segment) {
            if (($segment === '.env' || str_starts_with($segment, '.env.'))
                && $normalized !== 'core/.env.example') {
                return false;
            }
        }

        if (str_starts_with($normalized, 'core/database/') && str_ends_with($normalized, '.sqlite')) {
            return false;
        }

        if (in_array($normalized, ['public/uploads/.gitignore', 'public/uploads/.htaccess'], true)) {
            return true;
        }

        foreach ([...self::FORBIDDEN_CORE_PREFIXES, ...self::FORBIDDEN_PUBLIC_PREFIXES] as $forbidden) {
            $forbidden = strtolower($forbidden);
            if ($normalized === $forbidden || str_starts_with($normalized, $forbidden.'/')) {
                return false;
            }
        }

        return true;
    }

    public function isSafePayloadSource(string $path): bool
    {
        return $this->isSafeArchivePath($path)
            && str_starts_with($path, 'payload/')
            && ! str_ends_with($path, '/');
    }
}
