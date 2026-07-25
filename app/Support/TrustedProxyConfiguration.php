<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\Request;

final class TrustedProxyConfiguration
{
    /**
     * @return array{valid: list<string>, invalid: list<string>, trusts_all: bool}
     */
    public static function parse(mixed $value): array
    {
        $entries = self::entries($value);
        $valid = [];
        $invalid = [];
        $trustsAll = false;

        foreach ($entries as $entry) {
            if ($entry === '*') {
                $trustsAll = true;

                continue;
            }

            if (self::isAddressOrCidr($entry)) {
                $valid[] = $entry;

                continue;
            }

            $invalid[] = $entry;
        }

        return [
            'valid' => array_values(array_unique($valid)),
            'invalid' => array_values(array_unique($invalid)),
            'trusts_all' => $trustsAll,
        ];
    }

    /**
     * @return list<string>|string|null
     */
    public static function addresses(mixed $value): array|string|null
    {
        $configuration = self::parse($value);

        if ($configuration['trusts_all']) {
            return '*';
        }

        return $configuration['valid'] === [] ? null : $configuration['valid'];
    }

    public static function headers(): int
    {
        return Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO;
    }

    /**
     * @return list<string>
     */
    private static function entries(mixed $value): array
    {
        if (is_array($value)) {
            $entries = $value;
        } elseif (is_string($value)) {
            $entries = preg_split('/[\s,;]+/', trim($value)) ?: [];
        } elseif (is_scalar($value)) {
            $entries = [(string) $value];
        } else {
            $entries = [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $entry): string => trim(is_scalar($entry) ? (string) $entry : ''),
            $entries,
        ), static fn (string $entry): bool => $entry !== ''));
    }

    private static function isAddressOrCidr(string $value): bool
    {
        if (! str_contains($value, '/')) {
            return filter_var($value, FILTER_VALIDATE_IP) !== false;
        }

        [$address, $prefix] = array_pad(explode('/', $value, 2), 2, null);

        if (! is_string($address) || ! is_string($prefix) || ! ctype_digit($prefix) || filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $maximumPrefix = str_contains($address, ':') ? 128 : 32;

        return (int) $prefix <= $maximumPrefix;
    }
}
