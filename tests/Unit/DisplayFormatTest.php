<?php

namespace Tests\Unit;

use App\Support\DisplayFormat;
use Tests\TestCase;

class DisplayFormatTest extends TestCase
{
    private const NBSP = "\u{00A0}";

    public function test_russian_numbers_use_no_break_thousands_and_a_decimal_comma(): void
    {
        $this->assertSame('184'.self::NBSP.'500', DisplayFormat::number(184500, 0, 'ru'));
        $this->assertSame('1'.self::NBSP.'234'.self::NBSP.'567', DisplayFormat::number(1234567, 0, 'ru'));
        $this->assertSame('1,6', DisplayFormat::number(1.61, 1, 'ru'));
        $this->assertSame('8', DisplayFormat::number(8.0, 1, 'ru'));
        $this->assertSame('0', DisplayFormat::number(0, 0, 'ru'));
    }

    public function test_english_numbers_use_comma_thousands_and_a_decimal_point(): void
    {
        $this->assertSame('184,500', DisplayFormat::number(184500, 0, 'en'));
        $this->assertSame('1.6', DisplayFormat::number(1.61, 1, 'en'));
    }

    public function test_sizes_keep_the_unit_on_the_same_line_and_one_decimal_at_most(): void
    {
        $this->assertSame('512'.self::NBSP.'Б', DisplayFormat::bytes(512, 'ru'));
        $this->assertSame('8'.self::NBSP.'МБ', DisplayFormat::bytes(8 * 1024 ** 2, 'ru'));
        $this->assertSame('15,6'.self::NBSP.'МБ', DisplayFormat::bytes((int) (15.6 * 1024 ** 2), 'ru'));
        $this->assertSame('1'.self::NBSP.'ГБ', DisplayFormat::bytes(1024 ** 3, 'ru'));
        $this->assertSame('15.6'.self::NBSP.'MB', DisplayFormat::bytes((int) (15.6 * 1024 ** 2), 'en'));
        $this->assertSame('0'.self::NBSP.'B', DisplayFormat::bytes(-5, 'en'));
    }

    public function test_percent_follows_the_language_rule(): void
    {
        $this->assertSame('14,2'.self::NBSP.'%', DisplayFormat::percent(14.2, 1, 'ru'));
        $this->assertSame('76'.self::NBSP.'%', DisplayFormat::percent(76.0, 1, 'ru'));
        $this->assertSame('14.2%', DisplayFormat::percent(14.2, 1, 'en'));
    }

    public function test_template_helpers_use_the_application_locale(): void
    {
        app()->setLocale('ru');
        $this->assertSame('1,2'.self::NBSP.'МБ', format_bytes((int) (1.2 * 1024 ** 2)));
        $this->assertSame('5'.self::NBSP.'421', format_number(5421));
        $this->assertSame('52,6'.self::NBSP.'%', format_percent(52.6));

        app()->setLocale('en');
        $this->assertSame('5,421', format_number(5421));
    }
}
