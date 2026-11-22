<?php

namespace KaevCMS\Modules\Forum\Services;

use App\Services\Html\SafeHtmlSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Image hosts the Owner allows in forum posts. Readers' browsers load the pictures
 * directly from these hosts; the CMS never fetches or stores them.
 */
final class ForumImageHosts
{
    public const MAX_HOSTS = 30;

    /** @var list<string>|null */
    private ?array $hosts = null;

    /** @return list<string> */
    public function all(): array
    {
        if ($this->hosts !== null) {
            return $this->hosts;
        }

        // Before the module migration runs, keep the bundled defaults.
        if (! Schema::hasColumn('module_forum_settings', 'image_hosts')) {
            return $this->hosts = SafeHtmlSanitizer::DEFAULT_FORUM_IMAGE_HOSTS;
        }

        $stored = DB::table('module_forum_settings')->where('id', 1)->value('image_hosts');
        $decoded = is_string($stored) ? json_decode($stored, true) : null;
        if (! is_array($decoded)) {
            return $this->hosts = SafeHtmlSanitizer::DEFAULT_FORUM_IMAGE_HOSTS;
        }

        // Stored values are re-validated: a damaged row can never widen the list.
        return $this->hosts = array_values(array_filter(
            array_map(static fn ($host): ?string => is_string($host) ? self::normalizeHost($host) : null, $decoded),
        ));
    }

    public function forget(): void
    {
        $this->hosts = null;
    }

    /**
     * Parses the settings textarea: one host per line (commas and spaces also work).
     * A pasted address like https://iili.io/abc.png is reduced to its host name.
     *
     * @return array{hosts: list<string>, invalid: list<string>}
     */
    public static function parse(string $text): array
    {
        $hosts = [];
        $invalid = [];
        foreach (preg_split('/[\s,;]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            $host = self::normalizeHost($entry);
            if ($host === null) {
                $invalid[] = mb_substr($entry, 0, 80);
            } elseif (! in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return ['hosts' => $hosts, 'invalid' => $invalid];
    }

    public static function normalizeHost(string $value): ?string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('~^https?://~', '', $value) ?? $value;
        $value = explode('/', $value, 2)[0];
        $value = rtrim($value, '.');

        // Plain public DNS names only: no ports, credentials, wildcards, IPs or local names.
        if ($value === '' || strlen($value) > 253 || filter_var($value, FILTER_VALIDATE_IP) !== false
            || $value === 'localhost' || str_ends_with($value, '.localhost') || str_ends_with($value, '.local')
            || preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]\z/', $value) !== 1) {
            return null;
        }

        return $value;
    }
}
