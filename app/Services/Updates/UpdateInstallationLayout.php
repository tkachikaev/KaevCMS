<?php

namespace App\Services\Updates;

use RuntimeException;

final class UpdateInstallationLayout
{
    public function __construct(
        private readonly ?string $coreRootOverride = null,
        private readonly ?string $publicRootOverride = null,
    ) {}

    public function type(): string
    {
        return $this->isSplit() ? 'split' : 'standard';
    }

    public function isSplit(): bool
    {
        $base = $this->normalize($this->coreRoot());
        $public = $this->normalize($this->publicRoot());

        return ! str_starts_with($public.'/', $base.'/');
    }

    public function coreRoot(): string
    {
        return $this->coreRootOverride ?? base_path();
    }

    public function publicRoot(): string
    {
        return $this->publicRootOverride ?? public_path();
    }

    public function resolveTarget(string $logicalPath): string
    {
        if (str_starts_with($logicalPath, 'core/')) {
            return $this->join($this->coreRoot(), substr($logicalPath, 5));
        }

        if (str_starts_with($logicalPath, 'public/')) {
            return $this->join($this->publicRoot(), substr($logicalPath, 7));
        }

        throw new RuntimeException(__('Update target must use the core/ or public/ prefix.'));
    }

    public function relativeForDisplay(string $absolutePath): string
    {
        $path = $this->normalize($absolutePath);
        $base = $this->normalize($this->coreRoot());
        $public = $this->normalize($this->publicRoot());

        if (str_starts_with($path.'/', $public.'/')) {
            return 'public/'.ltrim(substr($path, strlen($public)), '/');
        }

        if (str_starts_with($path.'/', $base.'/')) {
            return 'core/'.ltrim(substr($path, strlen($base)), '/');
        }

        return basename($path);
    }

    private function join(string $root, string $relative): string
    {
        $relative = str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $path = rtrim($root, '/\\').DIRECTORY_SEPARATOR.ltrim($relative, '/\\');
        $normalizedRoot = $this->normalize($root);
        $normalizedPath = $this->normalize($path);

        if ($normalizedPath !== $normalizedRoot && ! str_starts_with($normalizedPath.'/', $normalizedRoot.'/')) {
            throw new RuntimeException(__('Update target escaped its allowed installation root.'));
        }

        $cursor = rtrim($root, '/\\');
        foreach (array_filter(explode(DIRECTORY_SEPARATOR, $relative), static fn (string $segment): bool => $segment !== '') as $segment) {
            $cursor .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($cursor)) {
                throw new RuntimeException(__('Update targets cannot pass through symbolic links.'));
            }
        }

        return $path;
    }

    private function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        $prefix = str_starts_with($path, '/') ? '/' : '';

        return rtrim($prefix.implode('/', $segments), '/');
    }
}
