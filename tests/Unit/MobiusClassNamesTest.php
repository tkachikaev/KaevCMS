<?php

namespace Tests\Unit;

use App\Services\GameAccounts\MobiusClassNames;
use Tests\TestCase;

class MobiusClassNamesTest extends TestCase
{
    public function test_known_legacy_class_keeps_its_english_label(): void
    {
        app()->setLocale('en');

        $this->assertSame('Dark Mystic', app(MobiusClassNames::class)->name(38));
    }

    public function test_unknown_modern_class_uses_safe_english_numeric_fallback(): void
    {
        app()->setLocale('en');

        $this->assertSame('Class #123', app(MobiusClassNames::class)->name(123));
    }

    public function test_class_labels_follow_the_active_locale(): void
    {
        app()->setLocale('ru');

        $this->assertSame('Тёмный мистик', app(MobiusClassNames::class)->name(38));
        $this->assertSame('Класс #123', app(MobiusClassNames::class)->name(123));
    }
}
