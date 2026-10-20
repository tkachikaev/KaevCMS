<?php

namespace App\Services\Updates;

use InvalidArgumentException;

/**
 * Encodes one fetched MySQL/MariaDB value as an SQL literal for the update backup.
 *
 * Values are always hex-encoded so no escaping rules apply. Character data keeps its
 * text meaning through CONVERT(... USING charset): a bare 0x literal is a binary string,
 * which MySQL 8 refuses for JSON columns and can read as a number in numeric contexts.
 * Only genuinely binary columns keep the bare 0x form.
 */
final class MySqlBackupValueEncoder
{
    private const BINARY_TYPES = [
        'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit',
        'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring',
        'multipolygon', 'geometrycollection', 'geomcollection',
    ];

    private readonly string $charset;

    public function __construct(string $charset = 'utf8mb4')
    {
        $charset = strtolower($charset);
        if (preg_match('/\A[a-z0-9_]{1,32}\z/D', $charset) !== 1) {
            throw new InvalidArgumentException('Invalid MySQL connection character set.');
        }

        $this->charset = $charset;
    }

    public function literal(mixed $value, string $dataType): string
    {
        if ($value === null) {
            return 'NULL';
        }

        $type = strtolower($dataType);

        // PDO with native types returns BIT columns as integers; restore them numerically.
        if ($type === 'bit' && is_int($value)) {
            return (string) $value;
        }

        $bytes = match (true) {
            is_bool($value) => (string) (int) $value,
            // (string) on a float keeps only `precision` (14) digits; var_export round-trips exactly.
            is_float($value) => var_export($value, true),
            default => (string) $value,
        };
        $binary = in_array($type, self::BINARY_TYPES, true);

        // A bare "0x" is a MySQL syntax error, so empty values need their own literal.
        if ($bytes === '') {
            return $binary ? "X''" : "CONVERT('' USING {$this->charset})";
        }

        $hex = '0x'.bin2hex($bytes);

        return $binary ? $hex : "CONVERT({$hex} USING {$this->charset})";
    }

    /** MySQL 8 marks plain DEFAULT CURRENT_TIMESTAMP columns as DEFAULT_GENERATED; those are stored. */
    public static function isGenerated(string $extra): bool
    {
        return preg_match('/\b(?:VIRTUAL|STORED|PERSISTENT)\s+GENERATED\b/i', $extra) === 1;
    }
}
