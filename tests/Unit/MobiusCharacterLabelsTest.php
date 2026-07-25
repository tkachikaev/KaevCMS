<?php

namespace Tests\Unit;

use App\Services\GameWorld\MobiusCharacterLabels;
use Tests\TestCase;

class MobiusCharacterLabelsTest extends TestCase
{
    public function test_modern_race_labels_follow_the_active_locale(): void
    {
        $labels = app(MobiusCharacterLabels::class);

        app()->setLocale('en');
        $this->assertSame('Ertheia', $labels->raceName(6));
        $this->assertSame('Sylph', $labels->raceName(7));

        app()->setLocale('ru');
        $this->assertSame('Эртея', $labels->raceName(6));
        $this->assertSame('Сильф', $labels->raceName(7));
    }

    public function test_unknown_race_and_gender_keep_safe_localized_labels(): void
    {
        app()->setLocale('ru');
        $labels = app(MobiusCharacterLabels::class);

        $this->assertSame('Раса не определена', $labels->raceName(999));
        $this->assertSame('Пол не определён', $labels->genderName(999));
    }
}
