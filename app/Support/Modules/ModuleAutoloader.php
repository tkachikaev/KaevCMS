<?php

namespace App\Support\Modules;

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final class ModuleAutoloader
{
    /** @var array<string, true> */
    private array $autoloaded = [];

    public function __construct(
        private readonly Filesystem $files,
    ) {}

    /** @param array<string, mixed> $module */
    public function register(array $module): void
    {
        $id = (string) $module['id'];
        if (isset($this->autoloaded[$id])) {
            return;
        }

        $namespace = $module['namespace'] ?? null;
        $autoloadPath = $module['autoload_path'] ?? null;
        if (! is_string($namespace) || ! is_string($autoloadPath)) {
            $this->autoloaded[$id] = true;

            return;
        }

        if (! $this->files->isDirectory($autoloadPath)) {
            throw new RuntimeException("Module [$id] autoload directory is unavailable.");
        }

        $loaders = ClassLoader::getRegisteredLoaders();
        $loader = reset($loaders);
        if (($loader instanceof ClassLoader) === false) {
            throw new RuntimeException('Composer PSR-4 autoloader is unavailable.');
        }

        $loader->addPsr4($namespace, $autoloadPath, true);
        $this->autoloaded[$id] = true;
    }
}
