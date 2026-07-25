<?php

namespace App\Services;

use App\Models\Admin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminTwoFactorAuthentication
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const PERIOD = 30;

    private const DIGITS = 6;

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function provisioningUri(Admin $admin, string $secret): string
    {
        $issuer = (string) config('app.name', 'KaevCMS');
        $label = rawurlencode($issuer.':'.$admin->email);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $normalized = preg_replace('/\D+/', '', $code) ?? '';

        if (strlen($normalized) !== self::DIGITS) {
            return false;
        }

        $time = $timestamp ?? time();
        $counter = intdiv($time, self::PERIOD);

        foreach ([-1, 0, 1] as $offset) {
            if (hash_equals($this->codeForCounter($secret, $counter + $offset), $normalized)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];

        while (count($codes) < max(0, $count)) {
            $code = $this->randomRecoverySegment().'-'.$this->randomRecoverySegment();
            $codes[$code] = $code;
        }

        return array_values($codes);
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    public function hashRecoveryCodes(array $codes): array
    {
        return array_map(
            static fn (string $code): string => Hash::make(Str::upper(trim($code))),
            $codes,
        );
    }

    public function consumeRecoveryCode(Admin $admin, string $code): bool
    {
        $normalized = Str::upper(trim($code));

        if ($normalized === '') {
            return false;
        }

        return DB::transaction(function () use ($admin, $normalized): bool {
            /** @var Admin $lockedAdmin */
            $lockedAdmin = Admin::query()->lockForUpdate()->findOrFail($admin->getKey());
            $hashes = $lockedAdmin->twoFactorRecoveryCodeHashes();

            if ($hashes === null) {
                return false;
            }

            foreach ($hashes as $index => $hash) {
                if (! Hash::check($normalized, $hash)) {
                    continue;
                }

                unset($hashes[$index]);
                $lockedAdmin->forceFill([
                    'two_factor_recovery_codes' => array_values($hashes),
                ])->save();

                return true;
            }

            return false;
        });
    }

    public function codeAt(string $secret, int $timestamp): string
    {
        return $this->codeForCounter($secret, intdiv($timestamp, self::PERIOD));
    }

    private function codeForCounter(string $secret, int $counter): string
    {
        $binarySecret = $this->base32Decode($secret);
        $counterBytes = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
        $hash = hash_hmac('sha1', $counterBytes, $binarySecret, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function randomRecoverySegment(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $segment = '';

        for ($index = 0; $index < 5; $index++) {
            $segment .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $segment;
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $encoded;
    }

    private function base32Decode(string $value): string
    {
        $normalized = Str::upper(preg_replace('/[^A-Z2-7]/i', '', $value) ?? '');
        $bits = '';

        foreach (str_split($normalized) as $character) {
            $position = strpos(self::BASE32_ALPHABET, $character);

            if ($position === false) {
                continue;
            }

            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }

        return $decoded;
    }
}
