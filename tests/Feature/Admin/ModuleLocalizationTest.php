<?php

namespace Tests\Feature\Admin;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModulePresentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

final class ModuleLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_module_catalogue_changes_language_without_executing_modules(): void
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Owner, 'locale' => 'ru']);
        $this->actingAs($admin, 'admin')->get('/admin/modules')->assertOk()
            ->assertSee('Пожертвования')->assertSee('Ежедневные награды')
            ->assertSee('Промокоды')->assertSee('Техническая поддержка')
            ->assertSee('Пополнение монет через ЮMoney и настраиваемый обмен монет CMS на игровые предметы в веб-инвентаре.');
        $this->assertDatabaseCount('cms_modules', 0);
        $admin->update(['locale' => 'en']);
        $this->actingAs($admin, 'admin')->get('/admin/modules')->assertOk()
            ->assertSee('Donations')->assertSee('Daily Rewards')->assertSee('Promo Codes')->assertSee('Support Tickets')
            ->assertDontSee('Пожертвования');
    }

    public function test_catalogue_fallback_is_resolved_at_render_time(): void
    {
        $module = app(ModuleManager::class)->inspect('donations');
        app()->setLocale('ru');
        $this->assertSame('Пожертвования', ModulePresentation::text($module, 'name'));
        app()->setLocale('en');
        $this->assertSame('Donations', ModulePresentation::text($module, 'name'));
        app()->setLocale('de');
        config(['app.fallback_locale' => 'en']);
        $this->assertSame('Donations', ModulePresentation::text($module, 'name'));
        $this->assertSame('External module', ModulePresentation::text(['name' => 'External module'], 'name'));
    }

    public function test_saved_activity_is_localized_without_changing_its_action_code(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create(['role' => AdminRole::Owner, 'locale' => 'ru']);
        $event = AuditLog::create(['category' => 'module', 'action' => 'donations.payment_prepared',
            'actor_type' => 'user', 'actor_id' => $user->id, 'actor_name' => $user->name, 'result' => 'success']);
        $this->actingAs($admin, 'admin')->get('/admin/users/'.$user->id)->assertOk()
            ->assertSee('Подготовлен переход к оплате')->assertDontSee('Donations Payment Prepared');
        $this->get('/admin/logs/'.$event->id)->assertOk()
            ->assertSee('Подготовлен переход к оплате')->assertSee('donations.payment_prepared');
        $admin->update(['locale' => 'en']);
        $this->actingAs($admin, 'admin')->get('/admin/users/'.$user->id)->assertOk()->assertSee('Payment checkout prepared');
        $this->assertSame('donations.payment_prepared', $event->fresh()->action);
    }

    public function test_bundled_audit_labels_have_matching_languages_and_unknown_events_keep_the_fallback(): void
    {
        $ru = Arr::dot(require lang_path('ru/audit.php'));
        $en = Arr::dot(require lang_path('en/audit.php'));
        $this->assertSame(array_keys($ru), array_keys($en));
        foreach (['ru' => $ru, 'en' => $en] as $locale => $labels) {
            app()->setLocale($locale);
            foreach ($labels as $key => $label) {
                $this->assertNotSame('', $label);
                $event = new AuditLog(['action' => substr($key, strlen('actions.'))]);
                $this->assertSame($label, $event->actionLabel());
            }
        }
        $this->assertSame('External Event', (new AuditLog(['action' => 'external.event']))->actionLabel());
        $this->assertSame('Donations', (new AuditLog(['action' => 'donations']))->actionLabel());
    }
}
