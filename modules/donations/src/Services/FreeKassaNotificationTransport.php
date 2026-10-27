<?php

namespace KaevCMS\Modules\Donations\Services;

use Illuminate\Http\Request;
use InvalidArgumentException;

/** Transport-only, strict duplicate-safe form decoding. Returns URL-encoded body for signed parser. */
final class FreeKassaNotificationTransport
{
    public static function decode(Request $request): string
    {
        $body = $request->getContent();
        $contentType = (string) $request->header('content-type');
        if (strlen($body) > 16384 || $request->getQueryString() !== null) {
            throw new InvalidArgumentException('Invalid FreeKassa notification transport.');
        }
        if (preg_match('~\Aapplication/x-www-form-urlencoded(?:\s*;.*)?\z~iD', $contentType)) {
            if ($body === '') {
                throw new InvalidArgumentException('Empty FreeKassa notification.');
            }

            return $body;
        }
        if (! preg_match('~\Amultipart/form-data\s*;\s*boundary=(?:"([A-Za-z0-9_\-]{1,70})"|([A-Za-z0-9_\-]{1,70}))\z~iD',
            $contentType, $m)) {
            throw new InvalidArgumentException('Unsupported FreeKassa notification Content-Type.');
        }
        $boundary = $m[1] ?: $m[2];
        if ($body === '') {
            // PHP normally consumes multipart bodies before Laravel runs. Use
            // only its parsed form map, without merging query or file inputs.
            // The exact same canonical map reaches routing and signature checks.
            $length = $request->server('CONTENT_LENGTH');
            $fields = $request->request->all();
            if (! is_string($length) || ! preg_match('/\A[1-9][0-9]{0,4}\z/D', $length)
                || (int) $length > 16384 || $request->files->all() !== []
                || $fields === [] || count($fields) > 33) {
                throw new InvalidArgumentException('Invalid PHP-parsed FreeKassa multipart data.');
            }
            foreach ($fields as $name => $value) {
                if (! preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/D', $name)
                    || ! is_string($value) || strlen($value) > 2048) {
                    throw new InvalidArgumentException('Invalid PHP-parsed FreeKassa multipart field.');
                }
            }
            $encoded = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
            if (strlen($encoded) > 16384) {
                throw new InvalidArgumentException('Oversized FreeKassa multipart data.');
            }

            return $encoded;
        }
        $parts = explode('--'.$boundary, $body);
        $count = count($parts);
        if ($count < 3 || $count > 35 || $parts[0] !== ''
            || ! in_array($parts[$count - 1], ['--', "--\r\n"], true)) {
            throw new InvalidArgumentException('Malformed FreeKassa multipart data.');
        }
        $fields = [];
        for ($i = 1; $i < $count - 1; $i++) {
            $part = $parts[$i];
            if (! str_starts_with($part, "\r\n") || ! str_ends_with($part, "\r\n")) {
                throw new InvalidArgumentException('Malformed FreeKassa multipart field.');
            }
            $part = substr($part, 2, -2);
            $sep = strpos($part, "\r\n\r\n");
            if ($sep === false) {
                throw new InvalidArgumentException('Malformed FreeKassa multipart headers.');
            }
            $header = substr($part, 0, $sep);
            $value = substr($part, $sep + 4);
            if (! preg_match('/\AContent-Disposition: form-data; name="([A-Za-z][A-Za-z0-9_]{0,63})"\z/iD', $header, $name)
                || array_key_exists($name[1], $fields) || strlen($value) > 2048) {
                throw new InvalidArgumentException('Invalid FreeKassa multipart field.');
            }
            $fields[$name[1]] = $value;
        }

        return http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
    }
}
