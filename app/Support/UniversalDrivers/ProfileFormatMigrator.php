<?php

namespace App\Support\UniversalDrivers;

/** Explicit migrations only. Never guess how to interpret a future/unknown format. */
final class ProfileFormatMigrator
{
    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public function toCurrent(array $profile): array
    {
        if (($profile['format_version'] ?? null) !== ProfileFormat::CURRENT_VERSION) {
            throw new ProfileValidationException('Unsupported driver profile format_version. No migration exists for this version.');
        }

        return $profile;
    }
}
