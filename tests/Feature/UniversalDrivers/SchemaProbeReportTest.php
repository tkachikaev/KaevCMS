<?php

namespace Tests\Feature\UniversalDrivers;

use Tests\TestCase;

final class SchemaProbeReportTest extends TestCase
{
    public function test_schema_issues_are_localized_and_identifiers_are_escaped(): void
    {
        foreach (['ru' => 'уникальный индекс', 'en' => 'unique index'] as $locale => $expected) {
            app()->setLocale($locale);
            $html = view('livewire.admin._database-report', [
                'report' => [
                    'connected' => true, 'compatible' => false, 'driver_ready' => true,
                    'profile_issues' => [[
                        'key' => 'drivers.schema_unique_login',
                        'replace' => ['column' => '<script>bad</script>.login'],
                    ]],
                ],
                'showChecks' => false,
            ])->render();
            $this->assertStringContainsString($expected, $html);
            $this->assertStringContainsString('&lt;script&gt;bad&lt;/script&gt;.login', $html);
            $this->assertStringNotContainsString('<script>bad</script>', $html);
            $this->assertStringNotContainsString('drivers.schema_unique_login', $html);
        }
    }
}
