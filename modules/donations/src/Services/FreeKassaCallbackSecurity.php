<?php

namespace KaevCMS\Modules\Donations\Services;

use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * IP allowlist for FreeKassa callbacks, independent of other payment providers.
 * The reverse-proxy list is deliberately separate from the provider sender list.
 * Never trust a forwarded client IP unless the immediate TCP peer is configured
 * as a trusted reverse proxy.
 */
final class FreeKassaCallbackSecurity
{
    public const SENDERS_KEY = '_freekassa_callback_sender_ips';

    public const PROXIES_KEY = '_freekassa_callback_proxy_ips';

    /** Official SCI callback senders as published 2026-10-27; only used until the merchant saves a custom list. */
    public const INITIAL_SENDERS = [
        '168.119.157.136', '168.119.60.227', '178.154.197.79', '51.250.54.238',
    ];

    public function __construct(private readonly DonationProviderSettings $settings) {}

    /** @return array{senders:list<string>,proxies:list<string>,customized:bool} */
    public function current(): array
    {
        return $this->settings->freeKassaCallbackPolicy();
    }

    /**
     * @param mixed $submitted
     * @return list<string>
     */
    public static function parseSubmitted(mixed $submitted, bool $proxies = false): array
    {
        if (! is_string($submitted) || strlen($submitted) > 8192) {
            throw new InvalidArgumentException('Invalid FreeKassa trusted IP configuration.');
        }
        $items = preg_split('/[\s,;]+/', trim($submitted)) ?: [];
        $items = array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
        if (count($items) > 64) {
            throw new InvalidArgumentException('Too many FreeKassa trusted addresses.');
        }
        foreach ($items as $item) {
            if (! self::isValidAddress($item, $proxies)) {
                throw new InvalidArgumentException('Invalid FreeKassa trusted IP address.');
            }
        }

        return array_values(array_unique($items));
    }

    /** @param mixed $items */
    public static function validateStored(mixed $items, bool $proxies = false): bool
    {
        if (! is_array($items) || ! array_is_list($items) || count($items) > 64) {
            return false;
        }
        foreach ($items as $item) {
            if (! is_string($item) || ! self::isValidAddress($item, $proxies)) {
                return false;
            }
        }

        return count(array_unique($items)) === count($items);
    }

    public function allows(Request $request): bool
    {
        try {
            $policy = $this->current();
            if ($policy['senders'] === []) {
                return false; // Empty merchant allowlist never means permit-all.
            }
            $remote = $request->server('REMOTE_ADDR');
            if (! is_string($remote) || filter_var($remote, FILTER_VALIDATE_IP) === false) {
                return false;
            }
            $source = $remote;
            if (self::matches($remote, $policy['proxies'])) {
                // Read only X-Forwarded-For from a specifically trusted immediate
                // peer. Work right-to-left: skip only known proxies, and take the
                // first untrusted hop. Client-controlled earlier hops are ignored.
                $forwarded = $request->headers->get('X-Forwarded-For');
                if (! is_string($forwarded) || strlen($forwarded) > 1024) {
                    return false;
                }
                $chain = array_map('trim', explode(',', $forwarded));
                if (count($chain) < 1 || count($chain) > 16) {
                    return false;
                }
                foreach ($chain as $hop) {
                    if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                        return false;
                    }
                }
                $source = '';
                for ($i = count($chain) - 1; $i >= 0; $i--) {
                    if (! self::matches($chain[$i], $policy['proxies'])) {
                        $source = $chain[$i];
                        break;
                    }
                }
                if ($source === '') {
                    return false;
                }
            }

            return self::matches($source, $policy['senders']);
        } catch (\Throwable) {
            // A damaged encrypted setting cannot disable callback security.
            return false;
        }
    }

    /** @param list<string> $ranges */
    private static function matches(string $ip, array $ranges): bool
    {
        $raw = @inet_pton($ip);
        if ($raw === false) {
            return false;
        }
        foreach ($ranges as $range) {
            if (! str_contains($range, '/')) {
                if ($raw === @inet_pton($range)) {
                    return true;
                }
                continue;
            }
            [$network, $prefix] = explode('/', $range, 2);
            $base = @inet_pton($network);
            if ($base === false || strlen($base) !== strlen($raw)) {
                continue;
            }
            $bits = (int) $prefix;
            $whole = intdiv($bits, 8);
            $remaining = $bits % 8;
            if (substr($raw, 0, $whole) !== substr($base, 0, $whole)) {
                continue;
            }
            if ($remaining === 0 || ((ord($raw[$whole]) ^ ord($base[$whole])) & (0xff << (8 - $remaining))) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function isValidAddress(string $value, bool $allowCidr): bool
    {
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        if (! $allowCidr || substr_count($value, '/') !== 1) {
            return false;
        }
        [$network, $bits] = explode('/', $value, 2);
        if (filter_var($network, FILTER_VALIDATE_IP) === false
            || ! preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/D', $bits)) {
            return false;
        }
        $max = str_contains($network, ':') ? 128 : 32;
        $prefix = (int) $bits;

        // 0/0 would make a single configured proxy trust the whole internet.
        return $prefix >= ($max === 32 ? 8 : 32) && $prefix <= $max;
    }
}
