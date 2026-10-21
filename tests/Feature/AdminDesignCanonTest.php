<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * docs/*\/DESIGN.md and the admin CSS variables must describe the same canon.
 */
class AdminDesignCanonTest extends TestCase
{
    public function test_every_variable_named_in_the_canon_is_defined_in_the_base_stylesheet(): void
    {
        $base = File::get(public_path('assets/admin/css/base.css'));

        foreach ($this->canonVariables('en') as $variable) {
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($variable, '/').'\s*:/',
                $base,
                "{$variable} is named in docs/en/DESIGN.md but not defined in base.css.",
            );
        }
    }

    public function test_russian_and_english_canon_name_the_same_variables(): void
    {
        $this->assertNotEmpty($this->canonVariables('en'));
        $this->assertSame($this->canonVariables('en'), $this->canonVariables('ru'));
    }

    public function test_dark_theme_overrides_every_light_colour_token(): void
    {
        $base = File::get(public_path('assets/admin/css/base.css'));
        $appearance = File::get(public_path('assets/admin/css/appearance.css'));
        preg_match('/html\[data-admin-color-scheme="dark"\]\s*\{([^}]*)\}/', $appearance, $dark);
        $this->assertNotEmpty($dark, 'The dark theme token block is missing.');

        preg_match_all('/(--admin-(?:page|surface|border|text|primary|row|table|success|warning|danger|info|focus|shadow|sidebar)[a-z0-9-]*)\s*:/', $base, $light);
        foreach (array_unique($light[1]) as $token) {
            if ($token === '--admin-text-muted' || ! str_starts_with($token, '--admin-text-')) {
                $this->assertStringContainsString($token.':', $dark[1], "{$token} has no dark-theme value.");
            }
        }
    }

    public function test_formatter_helpers_named_in_the_canon_exist(): void
    {
        foreach (['format_number', 'format_bytes', 'format_percent'] as $helper) {
            $this->assertTrue(function_exists($helper), "{$helper}() is part of the canon.");
        }
    }

    /** @return list<string> */
    private function canonVariables(string $locale): array
    {
        preg_match_all('/`(--admin-[a-z0-9-]+)`/', File::get(base_path("docs/{$locale}/DESIGN.md")), $matches);
        $variables = array_values(array_unique($matches[1]));
        sort($variables);

        return $variables;
    }
}
