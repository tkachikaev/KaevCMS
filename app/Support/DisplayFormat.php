<?php

namespace App\Support;

/**
 * Locale-aware display formatting for numbers, sizes and percentages (see docs/en/DESIGN.md).
 *
 * Russian: no-break space between thousands, decimal comma, no-break space before units
 * and the percent sign ("184 500", "1,2 МБ", "14,2 %"). English: "184,500", "1.2 MB", "14.2%".
 * The no-break spaces keep a number and its unit on one line.
 */
final class DisplayFormat
{
    public const NBSP = "\u{00A0}";

    public static function number(int|float $value, int $decimals = 0, ?string $locale = null): string
    {
        $russian = self::russian($locale);
        $formatted = number_format($value, $decimals, $russian ? ',' : '.', $russian ? self::NBSP : ',');

        return $decimals > 0 ? self::trimZeros($formatted, $russian ? ',' : '.') : $formatted;
    }

    /**
     * Binary sizes with at most one decimal: "512 Б", "1,2 МБ", "707,3 ГБ".
     */
    public static function bytes(int $bytes, ?string $locale = null): string
    {
        $bytes = max(0, $bytes);
        if ($bytes < 1024) {
            return self::number($bytes, 0, $locale).self::NBSP.__('B', [], $locale);
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return self::number($value, 1, $locale).self::NBSP.__($units[$unit], [], $locale);
    }

    /**
     * Percent of a value already expressed in percent: "76 %", "14,2 %" / "14.2%".
     */
    public static function percent(int|float $value, int $decimals = 1, ?string $locale = null): string
    {
        $number = self::number($value, $decimals, $locale);

        return self::russian($locale) ? $number.self::NBSP.'%' : $number.'%';
    }

    private static function russian(?string $locale): bool
    {
        return str_starts_with(strtolower($locale ?? app()->getLocale()), 'ru');
    }

    /** "1,20" -> "1,2", "8,0" -> "8"; integers are untouched. */
    private static function trimZeros(string $formatted, string $decimalSeparator): string
    {
        if (! str_contains($formatted, $decimalSeparator)) {
            return $formatted;
        }

        return rtrim(rtrim($formatted, '0'), $decimalSeparator);
    }
}
