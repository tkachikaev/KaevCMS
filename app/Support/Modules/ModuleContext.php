<?php

namespace App\Support\Modules;

use InvalidArgumentException;

final readonly class ModuleContext
{
    /** @param array<string, mixed> $manifest */
    public function __construct(
        public string $id,
        public string $rootPath,
        public array $manifest,
    ) {}

    public function viewNamespace(): string
    {
        return 'module-'.$this->id;
    }

    public function translationNamespace(): string
    {
        return 'module-'.$this->id;
    }

    public function publicRouteNamePrefix(): string
    {
        return 'modules.'.$this->id.'.';
    }

    public function adminRouteNamePrefix(): string
    {
        return 'admin.module-pages.'.$this->id.'.';
    }

    public function path(string $relativePath = ''): string
    {
        $relativePath = trim($relativePath);

        if ($relativePath === '') {
            return $this->rootPath;
        }

        if (
            str_contains($relativePath, "\0")
            || str_contains($relativePath, '\\')
            || str_starts_with($relativePath, '/')
            || preg_match('/(?:^|\/)\.\.(?:\/|$)/', $relativePath) === 1
        ) {
            throw new InvalidArgumentException('Module paths must be relative and cannot leave the module directory.');
        }

        return rtrim($this->rootPath, '/\\').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }
}
