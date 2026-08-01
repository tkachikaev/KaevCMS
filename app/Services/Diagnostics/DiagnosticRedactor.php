<?php

namespace App\Services\Diagnostics;

use DateTimeInterface;
use Illuminate\Support\Str;

final class DiagnosticRedactor
{
    private const REDACTED = '[REDACTED]';

    /** @var list<string> */
    private const SENSITIVE_KEY_PARTS = [
        'app_key',
        'password',
        'passwd',
        'pwd',
        'secret',
        'token',
        'api_key',
        'private_key',
        'authorization',
        'cookie',
        'session_id',
        'database_host',
        'database_name',
        'database_username',
        'database_password',
        'db_host',
        'db_database',
        'db_username',
        'db_password',
        'mail_username',
        'mail_password',
        'smtp_username',
        'smtp_password',
        'email',
        'ip_address',
        'username',
    ];

    public function value(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= 10) {
            return '[DEPTH LIMIT]';
        }

        if (is_array($value)) {
            $result = [];

            foreach ($value as $key => $item) {
                $stringKey = (string) $key;
                $result[$key] = $this->sensitiveKey($stringKey)
                    ? self::REDACTED
                    : $this->value($item, $depth + 1);
            }

            return $result;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_object($value)) {
            return $this->value((array) $value, $depth + 1);
        }

        if (is_string($value)) {
            return $this->text($value);
        }

        return is_scalar($value) || $value === null ? $value : (string) $value;
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $value
     * @return array<TKey, mixed>
     */
    public function arrayValue(array $value): array
    {
        $redacted = $this->value($value);
        if (! is_array($redacted)) {
            return [];
        }

        /** @var array<TKey, mixed> $redacted */
        return $redacted;
    }

    public function text(string $text): string
    {
        $text = $this->redactProjectPaths($text);

        $patterns = [
            '/(?<prefix>["\']?(?:app_key|password|passwd|pwd|secret|token|api_key|private_key|authorization|cookie|session_id|database_(?:host|name|username|password)|db_(?:host|database|username|password)|mail_(?:username|password)|smtp_(?:username|password)|email|ip_address|username|login)["\']?\s*(?:=>|:|=)\s*)(?<value>"(?:\\\\.|[^"])*"|\'(?:\\\\.|[^\'])*\'|[^\s,}\]]+)/i',
            '/(?<prefix>\bAuthorization\s*:\s*(?:Bearer|Basic)\s+)(?<value>[^\s]+)/i',
            '/(?<prefix>[?&](?:password|passwd|pwd|secret|token|api_key|key|authorization|session|email|login)=)(?<value>[^&\s]+)/i',
        ];

        foreach ($patterns as $pattern) {
            $text = (string) preg_replace_callback(
                $pattern,
                static fn (array $matches): string => (string) $matches['prefix'].self::REDACTED,
                $text,
            );
        }

        $text = (string) preg_replace(
            '~\b(?:mysql|mariadb|pgsql|postgres|sqlsrv):[^\s"\']+~i',
            '[DSN]',
            $text,
        );
        $text = (string) preg_replace(
            '~\b(?:https?|ftp)://[^\s/@:]+:[^\s/@]+@[^\s]+~i',
            '[URL WITH CREDENTIALS]',
            $text,
        );
        $text = (string) preg_replace('~\bbase64:[A-Za-z0-9+/=]{16,}~', self::REDACTED, $text);
        $text = (string) preg_replace('~\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b~', self::REDACTED, $text);
        $text = (string) preg_replace('~\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b~i', '[EMAIL]', $text);
        $text = $this->redactIpAddresses($text);
        $text = (string) preg_replace('~(?<![A-Za-z0-9])[A-Za-z]:\\\\[^\s"\']+~', '[PATH]', $text);
        $text = (string) preg_replace('~(?<![:/])/(?:home|var|srv|opt|tmp|mnt|Users)/[^\s"\']+~', '[PATH]', $text);

        return $text;
    }

    private function redactIpAddresses(string $text): string
    {
        $text = (string) preg_replace_callback(
            '~(?<![A-Za-z0-9_.-])(?:\d{1,3}\.){3}\d{1,3}(?![A-Za-z0-9_.-])~',
            static function (array $matches): string {
                $candidate = $matches[0];

                return filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                    ? '[IP]'
                    : $candidate;
            },
            $text,
        );

        return (string) preg_replace_callback(
            '~(?<![A-Za-z0-9:])(?:[A-Fa-f0-9]{0,4}:){2,8}[A-Fa-f0-9]{0,4}(?![A-Za-z0-9:])~',
            static function (array $matches): string {
                $candidate = $matches[0];

                return filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                    ? '[IP]'
                    : $candidate;
            },
            $text,
        );
    }

    private function sensitiveKey(string $key): bool
    {
        $normalized = Str::lower($key);

        if (in_array($normalized, self::SENSITIVE_KEY_PARTS, true)) {
            return true;
        }

        return Str::endsWith($normalized, [
            '_password',
            '_passwd',
            '_secret',
            '_token',
            '_api_key',
            '_private_key',
            '_authorization',
            '_cookie',
            '_session_id',
            '_email',
            '_username',
            '_ip_address',
        ]);
    }

    private function redactProjectPaths(string $text): string
    {
        $paths = array_values(array_unique([
            base_path(),
            public_path(),
            storage_path(),
        ]));

        usort($paths, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach ($paths as $path) {
            $normalized = rtrim(str_replace('\\', '/', $path), '/');
            $windows = rtrim(str_replace('/', '\\', $path), '\\');
            $text = str_ireplace([$normalized, $windows], '[PROJECT]', $text);
        }

        return $text;
    }
}
