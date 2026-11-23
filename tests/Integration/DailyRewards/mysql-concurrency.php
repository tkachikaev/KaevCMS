<?php

/**
 * Destructive opt-in concurrency check for the Daily Rewards claim limits.
 *
 * APP_ENV=testing DB_CONNECTION=mysql DB_DATABASE=daily_rewards_test \
 * DAILY_REWARDS_TEST_ALLOW_CONCURRENCY=1 SCHEMA_TEST_ALLOW=1 \
 * php tests/Integration/DailyRewards/mysql-concurrency.php
 *
 * Use a disposable database on MySQL 8.4 and MariaDB separately.
 * This script runs migrate:fresh. Never run it against real data.
 */

use App\Models\GameServer;
use App\Models\User;
use App\Models\UserGameAccount;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use KaevCMS\Modules\DailyRewards\Exceptions\DailyRewardClaimException;
use KaevCMS\Modules\DailyRewards\Models\DailyRewardCalendar;
use KaevCMS\Modules\DailyRewards\Models\DailyRewardClaim;
use KaevCMS\Modules\DailyRewards\Services\DailyRewardClaimService;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
});

$database = (string) DB::connection()->getDatabaseName();
if (config('app.env') !== 'testing' || DB::connection()->getDriverName() !== 'mysql'
    || preg_match('/daily_rewards_test/i', $database) !== 1
    || getenv('DAILY_REWARDS_TEST_ALLOW_CONCURRENCY') !== '1'
    || getenv('SCHEMA_TEST_ALLOW') !== '1') {
    throw new RuntimeException('Requires APP_ENV=testing, disposable daily_rewards_test MySQL database and both opt-in flags.');
}

function assertDailyRewards(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function bootDailyRewards(): void
{
    $module = app(ModuleManager::class)->enable('daily-rewards');
    app(ModuleRuntime::class)->bootModule($module);
}

if (($argv[1] ?? '') === '--worker') {
    $startArg = (string) end($argv);
    if (! str_starts_with($startArg, '--start=')) {
        throw new RuntimeException('Concurrency start timestamp is required.');
    }
    bootDailyRewards();
    $start = (float) substr($startArg, 8);
    while (microtime(true) < $start) {
        usleep(2000);
    }

    $action = (string) ($argv[2] ?? '');
    try {
        if ($action === 'claim') {
            app(DailyRewardClaimService::class)->claim(
                User::query()->findOrFail((int) ($argv[3] ?? 0)),
                (int) ($argv[4] ?? 0),
                (int) ($argv[5] ?? 0),
                (string) ($argv[6] ?? ''),
            );
            echo "accepted\n";
        } elseif ($action === 'switch') {
            // Mirrors the admin update: lock the calendar, refuse when today already has claims.
            $calendarId = (int) ($argv[3] ?? 0);
            $limit = (string) ($argv[4] ?? '');
            $switched = DB::transaction(static function () use ($calendarId, $limit): bool {
                $calendar = DailyRewardCalendar::query()->lockForUpdate()->findOrFail($calendarId);
                if (DailyRewardClaim::query()
                    ->where('calendar_id', $calendar->id)
                    ->whereDate('reward_date', CarbonImmutable::now($calendar->runtimeTimezone())->toDateString())
                    ->exists()) {
                    return false;
                }
                $calendar->update(['claim_limit' => $limit]);

                return true;
            }, 3);
            echo $switched ? "accepted\n" : "rejected\n";
        } else {
            throw new RuntimeException('Unknown worker action.');
        }
        exit(0);
    } catch (DailyRewardClaimException) {
        echo "rejected\n";
        exit(0);
    }
}

/**
 * @param  list<list<string>>  $jobs
 * @return list<string>
 */
function raceDailyRewards(array $jobs): array
{
    assertDailyRewards(function_exists('proc_open'), 'proc_open is unavailable for concurrent testing.');
    $start = microtime(true) + 2.5;
    $workers = [];

    foreach ($jobs as $args) {
        $pipes = [];
        $command = [PHP_BINARY, __FILE__, '--worker', ...$args, '--start='.$start];
        $process = proc_open($command, [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, dirname(__DIR__, 3));
        assertDailyRewards(is_resource($process), 'Unable to start a worker.');
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }

    $outcomes = [];
    foreach ($workers as [$process, $pipes]) {
        $out = trim((string) stream_get_contents($pipes[1]));
        $err = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        assertDailyRewards(proc_close($process) === 0, 'Worker failed: '.$err);
        assertDailyRewards(in_array($out, ['accepted', 'rejected'], true), 'Unexpected worker output: '.$out);
        $outcomes[] = $out;
    }

    return $outcomes;
}

/** @param list<string> $outcomes */
function acceptedCount(array $outcomes): int
{
    return count(array_filter($outcomes, static fn (string $outcome): bool => $outcome === 'accepted'));
}

function makeCalendar(GameServer $server, string $limit): DailyRewardCalendar
{
    $now = CarbonImmutable::now((string) config('app.timezone', 'UTC'));
    $calendar = DailyRewardCalendar::query()->create([
        'game_server_id' => $server->id,
        'year' => $now->year,
        'month' => $now->month,
        'timezone' => (string) config('app.timezone', 'UTC'),
        'enabled' => true,
        'claim_limit' => $limit,
    ]);
    foreach (range(1, $calendar->daysInMonth()) as $dayNumber) {
        $day = $calendar->days()->create(['day_number' => $dayNumber, 'enabled' => true]);
        $day->items()->create(['item_id' => 57, 'amount' => 100, 'sort_order' => 0]);
    }

    return $calendar;
}

Artisan::call('migrate:fresh', ['--force' => true]);
bootDailyRewards();

// 1. Site-user mode: nine accounts of one user race with different UUIDs.
$server = GameServer::factory()->create();
$calendar = makeCalendar($server, DailyRewardCalendar::CLAIM_LIMIT_SITE_USER);
$user = User::factory()->create();
$accounts = UserGameAccount::factory()->count(9)->for($user)->registeredOn($server)->create();
$outcomes = raceDailyRewards($accounts->map(static fn (UserGameAccount $account): array => [
    'claim', (string) $user->id, (string) $calendar->id, (string) $account->id, (string) Str::uuid(),
])->all());
assertDailyRewards(acceptedCount($outcomes) === 1, 'Site-user mode admitted '.acceptedCount($outcomes).' parallel claims.');
assertDailyRewards(DailyRewardClaim::query()->where('calendar_id', $calendar->id)->count() === 1, 'Unexpected site-user claim rows.');
assertDailyRewards(DB::table('reward_inventory_grants')->where('user_id', $user->id)->count() === 1, 'Blocked claims created inventory grants.');
assertDailyRewards(DB::table('reward_inventory_items')->where('user_id', $user->id)->count() === 1, 'Blocked claims created inventory items.');
echo "PASS: nine parallel site-user claims with different UUIDs, exactly one admitted\n";

// 2. Game-account mode: the same nine-way race lets every account claim once.
$gameServer = GameServer::factory()->create();
$gameCalendar = makeCalendar($gameServer, DailyRewardCalendar::CLAIM_LIMIT_GAME_ACCOUNT);
$gameUser = User::factory()->create();
$gameAccounts = UserGameAccount::factory()->count(9)->for($gameUser)->registeredOn($gameServer)->create();
$outcomes = raceDailyRewards($gameAccounts->map(static fn (UserGameAccount $account): array => [
    'claim', (string) $gameUser->id, (string) $gameCalendar->id, (string) $account->id, (string) Str::uuid(),
])->all());
assertDailyRewards(acceptedCount($outcomes) === 9, 'Game-account mode admitted '.acceptedCount($outcomes).' of nine claims.');
assertDailyRewards(DB::table('reward_inventory_grants')->where('user_id', $gameUser->id)->count() === 9, 'Game-account grants mismatch.');
echo "PASS: nine parallel game-account claims, each account admitted once\n";

// 3. One request token raced six times creates a single claim.
$tokenServer = GameServer::factory()->create();
$tokenCalendar = makeCalendar($tokenServer, DailyRewardCalendar::CLAIM_LIMIT_SITE_USER);
$tokenUser = User::factory()->create();
$tokenAccount = UserGameAccount::factory()->for($tokenUser)->registeredOn($tokenServer)->create();
$token = (string) Str::uuid();
raceDailyRewards(array_fill(0, 6, ['claim', (string) $tokenUser->id, (string) $tokenCalendar->id, (string) $tokenAccount->id, $token]));
assertDailyRewards(DailyRewardClaim::query()->where('request_token', $token)->count() === 1, 'One request token produced several claims.');
assertDailyRewards(DB::table('reward_inventory_grants')->where('user_id', $tokenUser->id)->count() === 1, 'One request token granted twice.');
echo "PASS: six parallel requests with one UUID, one claim and one grant\n";

// 4. A mode switch racing claims never lands after a claim of the same day.
$raceServer = GameServer::factory()->create();
$raceCalendar = makeCalendar($raceServer, DailyRewardCalendar::CLAIM_LIMIT_GAME_ACCOUNT);
$raceUser = User::factory()->create();
$raceAccounts = UserGameAccount::factory()->count(4)->for($raceUser)->registeredOn($raceServer)->create();
$jobs = $raceAccounts->map(static fn (UserGameAccount $account): array => [
    'claim', (string) $raceUser->id, (string) $raceCalendar->id, (string) $account->id, (string) Str::uuid(),
])->all();
array_splice($jobs, 2, 0, [['switch', (string) $raceCalendar->id, DailyRewardCalendar::CLAIM_LIMIT_SITE_USER]]);
raceDailyRewards($jobs);
$raceCalendar->refresh();
$claims = DailyRewardClaim::query()->where('calendar_id', $raceCalendar->id)->orderBy('id')->get();
if ($raceCalendar->claim_limit === DailyRewardCalendar::CLAIM_LIMIT_SITE_USER) {
    // The switch won: every claim was made afterwards under the site-user rule.
    assertDailyRewards($claims->count() === 1 && $claims->first()?->site_user_limit_id === $raceUser->id, 'Claims ignored the new site-user limit.');
} else {
    assertDailyRewards($claims->count() === 4 && $claims->every(static fn (DailyRewardClaim $claim): bool => $claim->site_user_limit_id === null), 'Claims mixed both limits.');
}
echo 'PASS: mode switch racing four claims stays consistent (limit: '.$raceCalendar->claim_limit.")\n";
