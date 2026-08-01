<?php

namespace Tests\Feature\Admin;

use App\Contracts\GameServerDatabaseGateway;
use App\Models\Admin;
use App\Models\GameServer;
use App\Models\User;
use App\Models\UserGameAccount;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KaevCMS\Modules\SupportTickets\Enums\SupportTicketCategory;
use KaevCMS\Modules\SupportTickets\Enums\SupportTicketStatus;
use KaevCMS\Modules\SupportTickets\Models\SupportTicket;
use Tests\TestCase;

final class DashboardPlayerOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Schema::create('characters', static function (Blueprint $table): void {
            $table->id();
            $table->integer('accesslevel')->default(0);
            $table->unsignedBigInteger('deletetime')->default(0);
        });

        DB::table('characters')->insert([
            ['accesslevel' => 0, 'deletetime' => 0],
            ['accesslevel' => 0, 'deletetime' => 0],
            ['accesslevel' => 0, 'deletetime' => 123],
            ['accesslevel' => 1, 'deletetime' => 0],
        ]);

        $this->app->instance(GameServerDatabaseGateway::class, new class implements GameServerDatabaseGateway
        {
            public function run(GameServer $server, callable $callback): mixed
            {
                return $callback(DB::connection());
            }
        });
    }

    public function test_administrator_dashboard_shows_player_counts_without_exposing_private_data(): void
    {
        $administrator = Admin::factory()->administrator()->create();
        $users = User::factory()->count(3)->create();
        $server = GameServer::factory()->create();

        UserGameAccount::factory()->for($users[0])->registeredOn($server)->create();
        UserGameAccount::factory()->for($users[1])->registeredOn($server)->create([
            'creation_status' => UserGameAccount::STATUS_FAILED,
        ]);

        $this->actingAs($administrator, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('data-testid="dashboard-players-card"', false)
            ->assertSee('Игроки')
            ->assertSee('Зарегистрировано')
            ->assertSee('Игровые аккаунты')
            ->assertSee('Персонажи')
            ->assertSee('>3<', false)
            ->assertSee('>1<', false)
            ->assertSee('>2<', false)
            ->assertDontSee($users[0]->email)
            ->assertDontSee($users[0]->name);
    }

    public function test_support_metric_uses_the_enabled_module_navigation_badge(): void
    {
        $owner = Admin::factory()->create();
        $user = User::factory()->create();
        $modules = app(ModuleManager::class);
        $module = $modules->enable('support-tickets');
        app(ModuleRuntime::class)->bootModule($module);

        SupportTicket::query()->create([
            'user_id' => $user->id,
            'user_name_snapshot' => $user->name,
            'user_email_snapshot' => $user->email,
            'category' => SupportTicketCategory::TechnicalProblem,
            'status' => SupportTicketStatus::New,
            'subject' => 'Dashboard attention counter',
            'last_message_at' => now(),
            'last_player_message_at' => now(),
        ]);

        Cache::flush();

        $this->actingAs($owner, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('Требуют ответа')
            ->assertSee('href="'.route('admin.module-pages.support-tickets.index').'"', false);
    }

    public function test_editor_dashboard_does_not_expose_player_administration_summary(): void
    {
        $editor = Admin::factory()->editor()->create();
        User::factory()->create();

        $this->actingAs($editor, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('data-testid="dashboard-players-card"', false);
    }

    public function test_dashboard_reuses_existing_cards_metrics_and_links(): void
    {
        $view = file_get_contents(resource_path('views/admin/dashboard.blade.php'));
        $css = file_get_contents(public_path('assets/admin/css/infrastructure.css'));

        $this->assertIsString($view);
        $this->assertIsString($css);
        $this->assertStringContainsString('admin-data-card dashboard-monitor-card dashboard-players-card', $view);
        $this->assertStringContainsString('dashboard-storage-metrics dashboard-player-metrics', $view);
        $this->assertStringContainsString('dashboard-player-metric-link', $css);
        $this->assertStringNotContainsString('<canvas', $view);
    }
}
