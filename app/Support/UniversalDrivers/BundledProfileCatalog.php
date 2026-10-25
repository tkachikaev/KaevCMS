<?php

namespace App\Support\UniversalDrivers;

use RuntimeException;

/** Read-only release-owned profile references. Not connected to production resolvers. */
final class BundledProfileCatalog
{
    public function __construct(
        private readonly ProfileValidator $validator = new ProfileValidator,
    ) {}

    /** @return array<string,array<string,mixed>> Keys are type:id, not display names. */
    public function all(): array
    {
        $directory = dirname(__DIR__, 3).'/driver-profiles/bundled';
        $result = [];
        foreach (glob($directory.'/*.json') ?: [] as $path) {
            if (! is_file($path) || is_link($path)) {
                throw new RuntimeException('Invalid bundled profile file.');
            }
            $raw = file_get_contents($path);
            if ($raw === false) {
                throw new RuntimeException('Unable to read bundled profile.');
            }
            $profile = $this->validator->fromJson($raw);
            $key = $profile['type'].':'.$profile['id'];
            if (isset($result[$key])) {
                throw new ProfileValidationException('Duplicate bundled driver ID.');
            }
            $result[$key] = $profile;
        }
        ksort($result);

        return $result;
    }
}
