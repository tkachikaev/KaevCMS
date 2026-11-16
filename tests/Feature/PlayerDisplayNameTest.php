<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\User;
use App\Rules\PlayerDisplayName;
use App\Support\Themes\AccountThemeManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PlayerDisplayNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_name_never_falls_back_to_private_login(): void
    {
        $user = User::factory()->create(['name' => 'private_login']);
        app()->setLocale('ru');
        $this->assertSame('Игрок #'.$user->id, $user->publicName());
        app()->setLocale('en');
        $this->assertSame('Player #'.$user->id, $user->publicName());
        $user->forceFill(['display_name' => 'Воин_123'])->save();
        $this->assertSame('Воин_123', $user->publicName());
        $this->assertArrayNotHasKey('display_name_key', $user->toArray());
    }

    public function test_own_profile_updates_clear_and_retries_preserve_login_credentials(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $original = $user->only(['name', 'email', 'password']);
        $this->actingAs($user)->put('/account/profile/display-name', ['display_name' => 'Герой_123', 'user_id' => $other->id, 'name' => 'hacked'])->assertRedirect();
        $this->assertSame('Герой_123', $user->fresh()->publicName());
        $this->put('/account/profile/display-name', ['display_name' => 'Герой_123'])->assertRedirect();
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertSame($original, $user->fresh()->only(array_keys($original)));
        $this->assertNull($other->fresh()->display_name);
        $audit = DB::table('audit_logs')->first();
        $this->assertStringNotContainsString($user->email, $audit->details);
        $this->put('/account/profile/display-name', ['display_name' => ''])->assertRedirect();
        $this->assertNull($user->fresh()->display_name);
        $this->assertNull($user->fresh()->display_name_key);
    }

    public function test_invalid_and_impersonating_names_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['ab', str_repeat('x', 25), '<b>Name</b>', "Hero\n", "\tHero", 'Hero Name', 'Аdmin', 'Adm1n', 'Ａｄｍｉｎ', 'Администратор', 'GM_12', 'support', 'Hero😀', ['bad']] as $name) {
            $this->put('/account/profile/display-name', ['display_name' => $name])->assertSessionHasErrors('display_name');
        }
    }

    public function test_case_insensitive_uniqueness_in_validation_and_database(): void
    {
        $owner = User::factory()->create();
        $owner->forceFill(['display_name' => 'Рыцарь'])->save();
        $other = User::factory()->create();
        $this->actingAs($other)->put('/account/profile/display-name', ['display_name' => 'рЫЦАРЬ'])->assertSessionHasErrors('display_name');
        $this->expectException(UniqueConstraintViolationException::class);
        $other->forceFill(['display_name' => 'РЫЦАРЬ'])->save();
    }

    public function test_late_collision_is_returned_as_validation_error(): void
    {
        $target = User::factory()->create();
        $competitor = User::factory()->create();
        User::saving(function (User $user) use ($target, $competitor): void {
            if ($user->id === $target->id && $user->isDirty('display_name')) {
                DB::table('users')->where('id', $competitor->id)->update(['display_name' => 'Racer', 'display_name_key' => PlayerDisplayName::key('Racer')]);
            }
        });
        $this->actingAs($target)->put('/account/profile/display-name', ['display_name' => 'racer'])->assertSessionHasErrors('display_name');
        $this->assertNull($target->fresh()->display_name);
    }

    public function test_all_themes_and_localized_profile_routes_share_the_form(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        foreach (['kaev-classic', 'kaev-aurelia', 'luxury'] as $theme) {
            app(AccountThemeManager::class)->activate($theme);
            foreach (['/account/profile', '/ru/account/profile', '/en/account/profile'] as $path) {
                $response = $this->get($path)->assertOk()->assertSee('id="account-display-name"', false)
                    ->assertDontSee('display-name-help', false);
                preg_match('/<input[^>]+id="account-display-name"[^>]+>/', $response->getContent(), $input);
                $this->assertStringContainsString('value=""', $input[0]);
                $this->assertStringContainsString('placeholder="'.$user->publicName().'"', $input[0]);
                $this->assertStringNotContainsString('Player_', $input[0]);
            }
        }
        $this->put('/en/account/profile/display-name', ['display_name' => 'Player-123'])->assertRedirect();
        $this->assertSame('Player-123', $user->fresh()->publicName());
    }

    public function test_guest_and_foreign_profile_routes_cannot_update_names(): void
    {
        $this->put('/account/profile/display-name', ['display_name' => 'Stranger'])->assertRedirect();
        $user = User::factory()->create();
        $this->actingAs($user)->put('/account/profile/999/display-name', ['display_name' => 'Stranger'])->assertNotFound();
        $this->assertNull($user->fresh()->display_name);
    }

    public function test_previous_login_still_authenticates(): void
    {
        $user = User::factory()->create(['name' => 'private_login', 'password' => 'Password123!']);
        $user->forceFill(['display_name' => 'PublicHero'])->save();
        $this->post('/login', ['login' => 'private_login', 'password' => 'Password123!'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_phonetic_decorative_and_mixed_service_name_impersonation_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['adᴍin', 'ᴀdmin', 'ADMIN', 'аdmin', 'adмin', 'Ａｄｍｉｎ', '𝒂𝒅𝒎𝒊𝒏', 'ｍoderator', 'ᴍoderator', 'ѕupport', 'ɡm'] as $name) {
            $this->put('/account/profile/display-name', ['display_name' => $name])->assertSessionHasErrors('display_name');
            $this->assertNull(auth()->user()->fresh()->display_name);
        }
        $this->put('/account/profile/display-name', ['display_name' => 'Рыцарь_42'])->assertSessionHasNoErrors();
        $this->put('/account/profile/display-name', ['display_name' => 'Éclair-7'])->assertSessionHasNoErrors();
    }

    public function test_profile_shows_actual_saved_empty_and_invalid_old_input_without_generating_a_name(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->put('/account/profile/display-name', ['display_name' => 'SavedHero'])->assertRedirect();
        $this->actingAs($user->refresh());
        $this->get('/account/profile')->assertSee('value="SavedHero"', false);
        $this->from('/account/profile')->put('/account/profile/display-name', ['display_name' => 'Invalid Name'])->assertSessionHasErrors('display_name');
        $this->get('/account/profile')->assertSee('value="Invalid Name"', false);
        $this->put('/account/profile/display-name', ['display_name' => ''])->assertRedirect();
        $this->actingAs($user->refresh());
        $this->get('/account/profile')->assertSee('value=""', false)->assertSee('placeholder="'.$user->fresh()->publicName().'"', false);
        $this->assertNull($user->fresh()->display_name);
    }

    public function test_display_name_action_has_ru_en_labels_in_the_admin_journal(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->put('/account/profile/display-name', ['display_name' => 'PublicHero'])->assertRedirect();
        $event = AuditLog::query()->where('action', 'user.display_name_updated')->sole();
        foreach (['ru' => 'Отображаемое имя изменено', 'en' => 'Display name changed'] as $locale => $label) {
            app()->setLocale($locale);
            $this->assertSame($label, $event->actionLabel());
            $admin = Admin::factory()->create(['locale' => $locale]);
            $this->actingAs($admin, 'admin')->get('/admin/logs?category=user')->assertOk()->assertSee($label);
        }
        $this->assertSame(['display_name_set' => true], $event->details);
    }

    public function test_database_unique_index_serializes_competing_processes(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'kaev-display-');
        $this->assertIsString($file);
        $barrier = $file.'.start';
        $database = new PDO('sqlite:'.$file);
        foreach (DB::select("SELECT sql FROM sqlite_master WHERE tbl_name = 'users' AND sql IS NOT NULL ORDER BY type DESC") as $schema) {
            $database->exec($schema->sql);
        }
        $worker = <<<'PHP'
        $db = new PDO('sqlite:'.$argv[1]);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA busy_timeout = 10000');
        $deadline = microtime(true) + 10;
        while (!file_exists($argv[5]) && microtime(true) < $deadline) usleep(1000);
        try {
            $statement = $db->prepare('INSERT INTO users (name,email,password,display_name,display_name_key) VALUES (?,?,?,?,?)');
            $statement->execute([$argv[4],$argv[4],'unused-test-hash',$argv[2],$argv[3]]);
            exit(0);
        } catch (PDOException $e) {
            exit(($e->errorInfo[1] ?? null) === 19 ? 10 : 20);
        }
        PHP;
        $processes = [];
        try {
            foreach (['Рыцарь', 'рЫЦАРЬ'] as $index => $name) {
                $process = new Process([PHP_BINARY, '-r', $worker, $file, $name, PlayerDisplayName::key($name), 'race'.$index.'@example.test', $barrier]);
                $process->start();
                $processes[] = $process;
            }
            touch($barrier);
            $results = array_map(fn (Process $process) => $process->wait(), $processes);
            sort($results);
            $this->assertSame([0, 10], $results);
            $this->assertSame(1, (int) $database->query('SELECT COUNT(*) FROM users')->fetchColumn());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            $database = null;
            @unlink($barrier);
            @unlink($file);
        }
    }
}
