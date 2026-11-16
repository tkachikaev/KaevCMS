<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\CmsSettings;
use App\Services\RegistrationSettings;
use App\Support\Themes\AccountThemeManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DisplayNamePolicyTest extends TestCase
{
    use RefreshDatabase;

    private function policy(bool $enabled = true, int $days = 7): void
    {
        app(CmsSettings::class)->setMany([
            RegistrationSettings::KEY_DISPLAY_NAME_CHANGES_ENABLED => $enabled ? '1' : '0',
            RegistrationSettings::KEY_DISPLAY_NAME_CHANGE_DAYS => (string) $days,
        ]);
    }

    public function test_first_change_noop_cooldown_boundary_and_utc_storage(): void
    {
        config(['app.timezone' => 'Europe/Moscow']);
        $this->travelTo(Carbon::parse('2026-11-16 12:00:00', 'UTC'));
        $user = User::factory()->create();
        $credentials = $user->only(['name', 'email', 'password']);
        $this->assertTrue(app(RegistrationSettings::class)->values()['display_name_changes_enabled']);
        $this->assertSame(7, app(RegistrationSettings::class)->values()['display_name_change_days']);
        $this->actingAs($user)->put('/account/profile/display-name', ['display_name' => 'FirstHero'])->assertSessionHasNoErrors();
        $this->assertSame('2026-11-16 12:00:00', $user->fresh()->getRawOriginal('display_name_changed_at'));
        $this->travel(1)->days();
        $this->put('/account/profile/display-name', ['display_name' => 'FirstHero'])->assertSessionHasNoErrors();
        $this->assertSame('2026-11-16 12:00:00', $user->fresh()->getRawOriginal('display_name_changed_at'));
        $this->assertSame(1, AuditLog::where('action', 'user.display_name_updated')->count());
        $this->put('/account/profile/display-name', ['display_name' => 'SecondHero'])->assertSessionHasErrors('display_name');
        $this->travel(6)->days();
        $this->put('/account/profile/display-name', ['display_name' => 'SecondHero'])->assertSessionHasNoErrors();
        $this->assertSame('SecondHero', $user->fresh()->display_name);
        $this->assertSame($credentials, $user->fresh()->only(array_keys($credentials)));
    }

    public function test_clearing_keeps_cooldown_and_legacy_name_gets_one_change(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['display_name' => 'LegacyHero'])->save();
        $this->assertNull($user->getRawOriginal('display_name_changed_at'));
        $this->actingAs($user)->put('/account/profile/display-name', ['display_name' => ''])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->display_name);
        $this->assertNotNull($user->fresh()->getRawOriginal('display_name_changed_at'));
        $this->put('/account/profile/display-name', ['display_name' => 'NewHero'])->assertSessionHasErrors('display_name');
        $this->assertNull($user->fresh()->display_name);
        $this->assertSame(['display_name_set' => false], AuditLog::where('action', 'user.display_name_updated')->sole()->details);
    }

    public function test_disabled_policy_blocks_initial_and_existing_names_and_can_be_reenabled(): void
    {
        $this->policy(false);
        $user = User::factory()->create();
        $this->actingAs($user)->put('/account/profile/display-name', ['display_name' => 'FirstHero'])->assertForbidden();
        $this->assertNull($user->fresh()->display_name);
        $user->forceFill(['display_name' => 'LegacyHero'])->save();
        $this->put('/account/profile/display-name', ['display_name' => 'OtherHero'])->assertForbidden();
        $this->assertSame('LegacyHero', $user->fresh()->display_name);
        $this->assertSame(0, AuditLog::where('action', 'user.display_name_updated')->count());
        $this->policy();
        $this->put('/account/profile/display-name', ['display_name' => 'NewHero'])->assertSessionHasNoErrors();
    }

    public function test_policy_changes_apply_immediately_and_zero_disables_waiting(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->put('/account/profile/display-name', ['display_name' => 'FirstHero'])->assertSessionHasNoErrors();
        $this->travel(2)->days();
        $this->policy(days: 1);
        $this->put('/account/profile/display-name', ['display_name' => 'SecondHero'])->assertSessionHasNoErrors();
        $this->policy(days: 365);
        $this->put('/account/profile/display-name', ['display_name' => 'ThirdHero'])->assertSessionHasErrors('display_name');
        $this->policy(days: 0);
        $this->put('/account/profile/display-name', ['display_name' => 'ThirdHero'])->assertSessionHasNoErrors();
        $this->put('/account/profile/display-name', ['display_name' => 'FourthHero'])->assertSessionHasNoErrors();
        $this->assertSame('FourthHero', $user->fresh()->display_name);
    }

    public function test_stale_authenticated_user_cannot_bypass_the_locked_database_state(): void
    {
        $user = User::factory()->create();
        $stale = $user->fresh();
        $this->actingAs($user)->put('/account/profile/display-name', ['display_name' => 'WinnerHero'])->assertSessionHasNoErrors();
        // A second request can have loaded its auth user before the winner committed.
        $this->actingAs($stale)->put('/account/profile/display-name', ['display_name' => 'LateHero'])->assertSessionHasErrors('display_name');
        $this->assertSame('WinnerHero', $user->fresh()->display_name);
        $this->assertSame(1, AuditLog::where('action', 'user.display_name_updated')->count());
    }

    public function test_two_processes_cannot_change_the_same_profile_twice(): void
    {
        $user = User::factory()->create();
        $file = tempnam(sys_get_temp_dir(), 'kaev-name-policy-');
        $this->assertIsString($file);
        $database = new PDO('sqlite:'.$file);
        foreach (DB::select("SELECT sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY type DESC") as $schema) {
            $database->exec($schema->sql);
        }
        foreach (['users', 'cms_settings'] as $table) {
            foreach (DB::table($table)->get() as $row) {
                $values = (array) $row;
                $statement = $database->prepare('INSERT INTO '.$table.' ('.implode(',', array_keys($values)).') VALUES ('.implode(',', array_fill(0, count($values), '?')).')');
                $statement->execute(array_values($values));
            }
        }
        $worker = <<<'PHP'
        require $argv[1].'/vendor/autoload.php';
        $app = require $argv[1].'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>$argv[2], 'cache.default'=>'array', 'session.driver'=>'array']);
        Illuminate\Support\Facades\DB::purge();
        $user = App\Models\User::findOrFail($argv[3]);
        $request = App\Http\Requests\Account\UpdateDisplayNameRequest::create('/account/profile/display-name', 'PUT', ['display_name'=>$argv[4]]);
        $request->setContainer($app)->setRedirector($app['redirect']);
        $request->setUserResolver(fn()=>$user);
        $request->validateResolved();
        touch($argv[2].'.ready'.$argv[4]);
        $deadline = microtime(true)+10;
        while (!file_exists($argv[2].'.start') && microtime(true)<$deadline) usleep(1000);
        try {
            $app->make(App\Http\Controllers\Account\ProfileController::class)->updateDisplayName($request);
            exit(0);
        } catch (Illuminate\Validation\ValidationException $e) {
            exit(isset($e->errors()['display_name']) ? 10 : 20);
        }
        PHP;
        $processes = [];
        try {
            foreach (['RaceHeroOne', 'RaceHeroTwo'] as $name) {
                $process = new Process([PHP_BINARY, '-r', $worker, base_path(), $file, (string) $user->id, $name], base_path(), ['APP_ENV' => 'testing']);
                $process->setTimeout(30)->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while ((! file_exists($file.'.readyRaceHeroOne') || ! file_exists($file.'.readyRaceHeroTwo')) && microtime(true) < $deadline) {
                usleep(1000);
            }
            touch($file.'.start');
            $codes = [];
            foreach ($processes as $process) {
                $codes[] = $process->wait();
                $this->assertContains($process->getExitCode(), [0, 10], $process->getErrorOutput().$process->getOutput());
            }
            sort($codes);
            $this->assertSame([0, 10], $codes);
            $this->assertSame(1, (int) $database->query("SELECT count(*) FROM audit_logs WHERE action='user.display_name_updated'")->fetchColumn());
            $this->assertNotFalse($database->query('SELECT display_name_changed_at FROM users')->fetchColumn());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            $statement = null;
            $database = null;
            foreach ([$file, $file.'.start', $file.'.readyRaceHeroOne', $file.'.readyRaceHeroTwo', $file.'-wal', $file.'-shm'] as $temporary) {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }
    }

    public function test_all_themes_localize_disabled_and_waiting_states_without_editable_form(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 12:00:00', 'UTC'));
        $user = User::factory()->create();
        foreach (['kaev-classic', 'kaev-aurelia', 'luxury'] as $theme) {
            app(AccountThemeManager::class)->activate($theme);
            foreach (['ru', 'en'] as $locale) {
                $this->policy(false);
                $this->actingAs($user->refresh())->get('/'.$locale.'/account/profile')->assertOk()
                    ->assertSee($locale === 'ru' ? 'Игрок #'.$user->id : 'Player #'.$user->id)
                    ->assertSee($locale === 'ru' ? 'Изменение публичного ника отключено администратором.' : 'Display name changes are disabled by the administrator.')
                    ->assertDontSee('id="account-display-name"', false);
                $user->forceFill(['display_name_changed_at' => now('UTC')->format('Y-m-d H:i:s')])->save();
                $this->policy();
                $this->actingAs($user->refresh())->get('/'.$locale.'/account/profile')->assertOk()
                    ->assertSee($locale === 'ru' ? 'Изменить публичный ник можно после' : 'You can change your display name after')
                    ->assertSee($locale === 'ru' ? 'ноября' : 'November')
                    ->assertDontSee('id="account-display-name"', false);
                $this->policy(days: 0);
                $this->get('/'.$locale.'/account/profile')->assertOk()->assertSee('id="account-display-name"', false);
            }
        }
    }

    public function test_admin_settings_validate_persist_and_audit_using_existing_registration_page(): void
    {
        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin')->get('/admin/settings/registration')->assertOk()->assertSee('Публичные имена игроков');
        foreach ([-1, 366, '1.5', 'bad', ''] as $days) {
            $this->put('/admin/settings/registration', ['display_name_change_days' => $days])->assertSessionHasErrors('display_name_change_days');
        }
        $this->put('/admin/settings/registration', ['display_name_changes_enabled' => '0', 'display_name_change_days' => 12])->assertRedirect();
        $values = app(RegistrationSettings::class)->values();
        $this->assertFalse($values['display_name_changes_enabled']);
        $this->assertSame(12, $values['display_name_change_days']);
        $user = User::factory()->create();
        $user->forceFill(['display_name_changed_at' => '2026-11-16 12:00:00'])->save();
        $this->assertSame('2026-11-28 12:00:00', app(RegistrationSettings::class)->nextDisplayNameChange($user)->format('Y-m-d H:i:s'));
        $details = AuditLog::where('action', 'settings.registration_updated')->sole()->details;
        $this->assertArrayHasKey('display_name_changes_enabled', $details['changes']);
        $this->assertArrayHasKey('display_name_change_days', $details['changes']);
    }

    public function test_required_spoofs_are_rejected_and_ordinary_names_remain_valid(): void
    {
        $this->policy(days: 0);
        $this->actingAs(User::factory()->create());
        foreach (['admin', 'adᴍin', 'adΜin', 'admın', 'аdmin', 'Adm1n', 'GM_12', 'ѕupport', 'Владелец', 'Персонал', 'Гейммастер'] as $name) {
            $this->put('/account/profile/display-name', ['display_name' => $name])->assertSessionHasErrors('display_name');
        }
        foreach (['Рыцарь_42', 'Éclair-7', 'Dark-Elf'] as $name) {
            $this->put('/account/profile/display-name', ['display_name' => $name])->assertSessionHasNoErrors();
        }
    }
}
