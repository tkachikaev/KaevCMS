<?php

namespace Tests\Feature;

use Tests\TestCase;

class StaticAnalysisRegressionTest extends TestCase
{
    public function test_character_contract_fields_are_not_wrapped_in_redundant_null_coalescing(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Account/GameAccountController.php'));

        $this->assertNotFalse($controller);
        $this->assertStringContainsString('$classId = $character[\'class_id\'];', $controller);
        $this->assertStringContainsString('$race = $character[\'race\'];', $controller);
        $this->assertStringContainsString('$gender = $character[\'gender\'];', $controller);
        $this->assertStringNotContainsString('$character[\'class_id\'] ??', $controller);
        $this->assertStringNotContainsString('$character[\'race\'] ??', $controller);
        $this->assertStringNotContainsString('$character[\'gender\'] ??', $controller);
    }

    public function test_home_ranking_does_not_reindex_an_already_list_shaped_map(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/HomeController.php'));

        $this->assertNotFalse($controller);
        $this->assertStringContainsString('return array_map(', $controller);
        $this->assertStringNotContainsString('return array_values(array_map(', $controller);
    }
}
