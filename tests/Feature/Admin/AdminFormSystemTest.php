<?php

namespace Tests\Feature\Admin;

use App\Auth\AdminRole;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AdminFormSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_form_components_define_the_canonical_field_toggle_and_error_contract(): void
    {
        $field = File::get(resource_path('views/components/admin/field.blade.php'));
        $toggle = File::get(resource_path('views/components/admin/toggle.blade.php'));
        $css = File::get(public_path('assets/admin/css/components.css'));
        $contentCss = File::get(public_path('assets/admin/css/content.css'));
        $extensionsCss = File::get(public_path('assets/admin/css/extensions.css'));

        $this->assertStringContainsString("'admin-field'", $field);
        $this->assertStringContainsString("'has-error' => filled(\$message)", $field);
        $this->assertStringContainsString('class="admin-field-error" role="alert"', $field);
        $this->assertStringContainsString("'admin-toggle-row'", $toggle);
        $this->assertStringContainsString('class="admin-switch-control"', $toggle);
        $this->assertStringContainsString("'inputAttributes' => []", $toggle);
        $this->assertStringContainsString('{{ $inputAttributes }}', $toggle);
        $this->assertStringContainsString(".admin-field,\n.form-group,\n.settings-field {", $css);
        $this->assertStringContainsString('.admin-toggle-row {', $css);
        $this->assertStringContainsString('.admin-switch-control input:checked + span', $css);
        $this->assertStringContainsString('.admin-field.has-error > input', $css);
        $this->assertStringNotContainsString('.admin-field + .admin-field', $css);
        $this->assertStringNotContainsString('.form-group select {', $contentCss);
        $this->assertStringContainsString('.registration-policy-single-field .admin-field', $extensionsCss);
    }

    public function test_reference_settings_pages_use_the_shared_field_and_toggle_components(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);

        $this->actingAs($owner, 'admin')
            ->get('/admin/settings/security')
            ->assertOk()
            ->assertSee('class="admin-field', false)
            ->assertSee('class="admin-field-label"', false)
            ->assertDontSee('class="settings-field', false);

        $this->actingAs($owner, 'admin')
            ->get('/admin/settings/game-accounts')
            ->assertOk()
            ->assertSee('class="admin-field', false)
            ->assertSee('class="admin-toggle-row', false)
            ->assertSee('class="admin-switch-control"', false)
            ->assertDontSee('class="settings-field', false)
            ->assertDontSee('class="switch-row', false);

        $this->actingAs($owner, 'admin')
            ->get('/admin/settings/registration')
            ->assertOk()
            ->assertSee('class="admin-field', false)
            ->assertSee('class="admin-toggle-row', false)
            ->assertDontSee('class="settings-toggle-row', false);
    }

    public function test_admin_forms_no_longer_emit_the_three_legacy_error_classes(): void
    {
        $files = array_merge(
            File::allFiles(resource_path('views/admin/settings')),
            File::allFiles(base_path('modules')),
        );

        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (! str_ends_with($path, '.blade.php')) {
                continue;
            }

            if (str_contains($path, '/modules/') && ! str_contains($path, '/resources/views/admin/')) {
                continue;
            }

            $contents = File::get($file->getPathname());

            $this->assertStringNotContainsString('class="form-error"', $contents, $path);
            $this->assertStringNotContainsString('class="field-error"', $contents, $path);
            $this->assertStringNotContainsString('class="error-text"', $contents, $path);
        }
    }
}
