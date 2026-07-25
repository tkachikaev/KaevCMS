<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\GameServerManager;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

class AdminGameServerSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_open_game_server_settings(): void
    {
        $this->get('/admin/settings/game-server')
            ->assertRedirect(route('admin.login'));
    }

    public function test_admin_can_open_game_server_settings(): void
    {
        $this->actingAs($this->createAdmin(), 'admin')
            ->get('/admin/settings/game-server')
            ->assertOk()
            ->assertSee('Игровых серверов')
            ->assertSee('Добавить игровой сервер')
            ->assertSee('Имя сервера')
            ->assertSee('Рейты сервера')
            ->assertSee('Хроники')
            ->assertSee('Режим')
            ->assertSee('Подключение к базе данных')
            ->assertSee('Настроить');
    }

    public function test_legacy_game_server_mutation_routes_are_not_registered(): void
    {
        $this->assertFalse(Route::has('admin.settings.game-server.store'));
        $this->assertFalse(Route::has('admin.settings.game-server.update'));
        $this->assertFalse(Route::has('admin.settings.game-server.destroy'));

        $this->actingAs($this->createAdmin(), 'admin');

        $this->post('/admin/settings/game-server')->assertStatus(405);
        $this->put('/admin/settings/game-server/1')->assertNotFound();
        $this->delete('/admin/settings/game-server/1')->assertNotFound();
    }

    public function test_only_default_server_name_is_required_in_livewire_form(): void
    {
        $this->actingAs($this->createAdmin(), 'admin');

        Livewire::test(GameServerManager::class)
            ->call('create')
            ->set('translations.ru', '   ')
            ->set('translations.en', '')
            ->set('serverRates', '')
            ->set('serverChronicle', '')
            ->set('serverMode', '')
            ->call('save')
            ->assertHasErrors(['translations.ru' => 'required'])
            ->assertHasNoErrors([
                'translations.en',
                'serverRates',
                'serverChronicle',
                'serverMode',
            ]);
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'name' => 'Main Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);
    }
}
