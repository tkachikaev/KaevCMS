<?php

namespace Tests\Feature\Admin;

use App\Auth\AdminRole;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDocumentationLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_and_mail_pages_point_to_their_own_documentation_and_other_pages_to_the_home(): void
    {
        config()->set('cms.docs.base_url', 'https://docs.example.test');
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');

        $expected = [
            '/admin/settings/drivers' => 'https://docs.example.test/guide/driver-builder',
            '/admin/settings/mail' => 'https://docs.example.test/guide/mail',
            '/admin/settings/mail/custom' => 'https://docs.example.test/guide/mail',
            '/admin/settings/mail/delivery' => 'https://docs.example.test/guide/mail-modes',
            '/admin/settings' => 'https://docs.example.test/',
        ];

        foreach ($expected as $path => $url) {
            $this->get($path)->assertOk()->assertSee('data-admin-docs-url="'.$url.'"', false);
        }
    }

    public function test_the_documentation_follows_the_interface_language(): void
    {
        config()->set('cms.docs.base_url', 'https://docs.example.test');
        $admin = Admin::factory()->create(['role' => AdminRole::Owner, 'locale' => 'en']);

        $this->actingAs($admin, 'admin')->get('/admin/settings/mail')->assertOk()
            ->assertSee('data-admin-docs-url="https://docs.example.test/en/guide/mail"', false);
    }
}
