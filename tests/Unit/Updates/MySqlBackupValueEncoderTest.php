<?php

namespace Tests\Unit\Updates;

use App\Services\Updates\MySqlBackupValueEncoder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MySqlBackupValueEncoderTest extends TestCase
{
    public function test_text_numeric_temporal_and_json_values_keep_their_character_meaning(): void
    {
        $encoder = new MySqlBackupValueEncoder;

        // A bare 0x35 would be read as the number 53 in numeric contexts and is rejected by MySQL 8 JSON columns.
        $this->assertSame('CONVERT(0x35 USING utf8mb4)', $encoder->literal('5', 'int'));
        $this->assertSame('CONVERT(0x31322e3530 USING utf8mb4)', $encoder->literal('12.50', 'decimal'));
        $this->assertSame('CONVERT('.'0x'.bin2hex('{"a":1}').' USING utf8mb4)', $encoder->literal('{"a":1}', 'json'));
        $this->assertSame('CONVERT('.'0x'.bin2hex('2026-10-20 12:00:00').' USING utf8mb4)', $encoder->literal('2026-10-20 12:00:00', 'datetime'));
        $this->assertSame('CONVERT('.'0x'.bin2hex('Привет').' USING utf8mb4)', $encoder->literal('Привет', 'varchar'));
        $this->assertSame('CONVERT(0x37 USING utf8mb4)', $encoder->literal(7, 'BIGINT'));
    }

    public function test_binary_columns_keep_raw_hex_literals(): void
    {
        $encoder = new MySqlBackupValueEncoder;

        $this->assertSame('0x00ff10', $encoder->literal("\x00\xff\x10", 'varbinary'));
        $this->assertSame('0x01', $encoder->literal("\x01", 'bit'));
        $this->assertSame('0x'.bin2hex('blob'), $encoder->literal('blob', 'LONGBLOB'));
    }

    public function test_null_and_empty_values_produce_valid_sql(): void
    {
        $encoder = new MySqlBackupValueEncoder;

        $this->assertSame('NULL', $encoder->literal(null, 'varchar'));
        // The previous encoder produced a bare "0x", which is a MySQL syntax error.
        $this->assertSame("CONVERT('' USING utf8mb4)", $encoder->literal('', 'varchar'));
        $this->assertSame("X''", $encoder->literal('', 'blob'));
    }

    public function test_connection_charset_is_used_and_validated(): void
    {
        $this->assertSame('CONVERT(0x61 USING utf8mb3)', (new MySqlBackupValueEncoder('UTF8MB3'))->literal('a', 'char'));

        $this->expectException(InvalidArgumentException::class);
        new MySqlBackupValueEncoder('utf8mb4) ; DROP TABLE users; --');
    }

    public function test_generated_columns_are_detected_for_mysql_and_mariadb(): void
    {
        $this->assertTrue(MySqlBackupValueEncoder::isGenerated('VIRTUAL GENERATED'));
        $this->assertTrue(MySqlBackupValueEncoder::isGenerated('STORED GENERATED'));
        $this->assertTrue(MySqlBackupValueEncoder::isGenerated('persistent generated'));
        $this->assertFalse(MySqlBackupValueEncoder::isGenerated('auto_increment'));
        $this->assertFalse(MySqlBackupValueEncoder::isGenerated('DEFAULT_GENERATED'));
        $this->assertFalse(MySqlBackupValueEncoder::isGenerated('DEFAULT_GENERATED on update CURRENT_TIMESTAMP'));
    }
}
