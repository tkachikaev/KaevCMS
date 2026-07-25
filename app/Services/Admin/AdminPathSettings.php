<?php

namespace App\Services\Admin;

use App\Services\CmsSettings;
use InvalidArgumentException;

final class AdminPathSettings
{
    public const PREFIX = 'admin';

    public const SETTING_KEY = 'admin.path_suffix';

    public const MAX_SUFFIX_LENGTH = 40;

    public function __construct(private readonly CmsSettings $settings) {}

    public function suffix(): string
    {
        $stored = trim((string) $this->settings->get(self::SETTING_KEY, ''));

        return $this->isValidSuffix($stored) ? $stored : '';
    }

    public function path(): string
    {
        $suffix = $this->suffix();

        return $suffix === '' ? self::PREFIX : self::PREFIX.'-'.$suffix;
    }

    public function displayPath(): string
    {
        return '/'.$this->path();
    }

    public function matches(?string $path): bool
    {
        return is_string($path) && hash_equals($this->path(), $path);
    }

    public function updateSuffix(string $suffix): void
    {
        $suffix = trim($suffix);

        if (! $this->isValidSuffix($suffix)) {
            throw new InvalidArgumentException(
                'The suffix must be empty or contain 3 to 40 lowercase Latin letters, numbers, and single hyphens.'
            );
        }

        $this->settings->set(self::SETTING_KEY, $suffix);
    }

    public function reset(): void
    {
        $this->updateSuffix('');
    }

    public function isValidSuffix(string $suffix): bool
    {
        if ($suffix === '') {
            return true;
        }

        if (strlen($suffix) < 3 || strlen($suffix) > self::MAX_SUFFIX_LENGTH) {
            return false;
        }

        return preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $suffix) === 1;
    }
}
