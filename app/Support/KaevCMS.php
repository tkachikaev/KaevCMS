<?php

namespace App\Support;

final class KaevCMS
{
    public static function version(): string
    {
        $path = base_path('VERSION');

        if (! is_file($path) || ! is_readable($path)) {
            return __('Not defined');
        }

        $version = trim((string) file_get_contents($path));

        if (! preg_match('/\A\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?\z/', $version)) {
            return __('Not defined');
        }

        return $version;
    }
}
