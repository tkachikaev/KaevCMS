<?php

namespace App\Services\Infrastructure;

final class PublicUploadProtection
{
    private const GITIGNORE = "*\n!.gitignore\n!.htaccess\n";

    private const HTACCESS = <<<'HTACCESS'
<FilesMatch "\.(?:php[0-9]?|phtml|phar)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>
HTACCESS;

    public function ensure(?string $root = null): void
    {
        $root = rtrim($root ?? public_path('uploads'), '/\\');
        if ($root === '' || (! is_dir($root) && ! @mkdir($root, 0775, true) && ! is_dir($root))) {
            return;
        }

        if (! is_writable($root)) {
            return;
        }

        $this->writeIfMissing($root.DIRECTORY_SEPARATOR.'.gitignore', self::GITIGNORE);
        $this->writeIfMissing($root.DIRECTORY_SEPARATOR.'.htaccess', self::HTACCESS."\n");
    }

    private function writeIfMissing(string $path, string $contents): void
    {
        if (is_file($path)) {
            return;
        }

        @file_put_contents($path, $contents, LOCK_EX);
    }
}
