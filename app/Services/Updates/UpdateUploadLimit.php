<?php

namespace App\Services\Updates;

use App\Support\DisplayFormat;

/**
 * The largest update package a browser upload can deliver on this server: PHP's
 * upload_max_filesize and post_max_size (often 8M on shared hosting) and the CMS limit.
 */
final class UpdateUploadLimit
{
    /**
     * @param  array<string, string>|null  $iniOverride  ini values used instead of the running PHP's (tests)
     */
    public function __construct(private readonly ?array $iniOverride = null) {}

    public function bytes(): int
    {
        $limits = [max(1024, (int) config('cms.updates.maximum_upload_kilobytes', 524288)) * 1024];

        foreach (['upload_max_filesize', 'post_max_size'] as $directive) {
            $bytes = self::parse($this->ini($directive));
            // 0 disables the limit for that directive.
            if ($bytes > 0) {
                $limits[] = $bytes;
            }
        }

        return min($limits);
    }

    /**
     * Size of one part for chunked uploads: comfortably below the limit (the multipart
     * envelope and form fields also count toward post_max_size), at most 4 MB.
     */
    public function chunkBytes(): int
    {
        $limit = $this->bytes();

        return max(64 * 1024, min(4 * 1024 * 1024, $limit - max(64 * 1024, intdiv($limit, 10))));
    }

    public function label(): string
    {
        return DisplayFormat::bytes($this->bytes());
    }

    /**
     * Parses php.ini shorthand such as 8M, 512K or 1G.
     */
    public static function parse(string $value): int
    {
        $value = trim($value);
        if (preg_match('/\A(\d+)\s*([KMG]?)\z/i', $value, $matches) !== 1) {
            return 0;
        }

        $multiplier = match (strtoupper($matches[2])) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            default => 1,
        };

        return (int) $matches[1] * $multiplier;
    }

    private function ini(string $directive): string
    {
        if ($this->iniOverride !== null) {
            return $this->iniOverride[$directive] ?? '0';
        }

        return (string) ini_get($directive);
    }
}
