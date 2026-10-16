<?php

/**
 * Run ONLY on a disposable MySQL/MariaDB testing database:
 * APP_ENV=testing DONATIONS_TEST_ALLOW_CONCURRENCY=1 php tests/Integration/Donations/mysql-order-concurrency.php
 * Needs pcntl-free proc_open workers. Never run against real player balances/orders.
 */

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use KaevCMS\Modules\Donations\Models\DonationOrder;
use KaevCMS\Modules\Donations\Services\DonationOrders;
use KaevCMS\Modules\Donations\Services\DonationRates;
use KaevCMS\Modules\Donations\Services\DonationSettings;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$db = (string) DB::connection()->getDatabaseName();
if ((string) config('app.env') !== 'testing'
    || DB::connection()->getDriverName() !== 'mysql'
    || ! preg_match('/(?:test|testing)/i', $db)
    || getenv('DONATIONS_TEST_ALLOW_CONCURRENCY') !== '1') {
    fwrite(STDERR, "Refusing: requires APP_ENV=testing, MySQL test database, DONATIONS_TEST_ALLOW_CONCURRENCY=1.\n");
    exit(2);
}

$args = [];
foreach (array_slice($argv, 1) as $argument) {
    $parts = explode('=', ltrim($argument, '-'), 2);
    $args[$parts[0]] = $parts[1] ?? true;
}

if (isset($args['worker'])) {
    $start = (float) ($args['start'] ?? 0);
    while (microtime(true) < $start) {
        usleep(2000);
    }
    try {
        $user = User::query()->findOrFail((int) ($args['user'] ?? 0));
        $orders = app(DonationOrders::class);
        if ($args['mode'] === 'create') {
            $orders->createDraft($user, 'RUB', 100, (string) $args['key']);
        } elseif ($args['mode'] === 'cancel') {
            $orders->cancelDraft($user, (string) $args['order']);
        } elseif ($args['mode'] === 'rate') {
            $admin = Admin::query()->findOrFail((int) ($args['admin'] ?? 0));
            app(DonationRates::class)->saveRate('RUB', '20', true, $admin);
        }
        echo "ok\n";
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, get_class($exception)."\n");
        exit(11);
    }
}

if (! function_exists('proc_open')) {
    fwrite(STDERR, "proc_open unavailable.\n");
    exit(2);
}

/** @param list<array<string, string>> $batch */
function workers(array $batch): void
{
    $start = microtime(true) + 1.5;
    $processes = [];
    foreach ($batch as $params) {
        $command = [PHP_BINARY, __FILE__, '--worker', '--start='.$start];
        foreach ($params as $name => $value) {
            $command[] = '--'.$name.'='.$value;
        }
        $pipes = [];
        $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($proc)) {
            throw new RuntimeException('Unable to start worker.');
        }
        fclose($pipes[0]);
        $processes[] = [$proc, $pipes];
    }
    foreach ($processes as [$proc, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($proc) !== 0 || trim($stdout) !== 'ok') {
            throw new RuntimeException('Concurrent worker failed: '.trim($stderr));
        }
    }
}

$module = $app->make(\App\Support\Modules\ModuleManager::class)->enable('donations');
$app->make(\App\Support\Modules\ModuleRuntime::class)->bootModule($module);
$owner = Admin::factory()->create(['role' => AdminRole::Owner]);
$user = User::factory()->create();
app(DonationSettings::class)->updateVisibility(true, $owner);
app(DonationRates::class)->saveRate('RUB', '10', true, $owner);
$key = (string) Str::uuid();

$create = array_fill(0, 10, ['mode' => 'create', 'user' => (string) $user->id, 'key' => $key]);
workers($create);
$created = DonationOrder::query()->where('user_id', $user->id)->where('idempotency_key', $key)->get();
if ($created->count() !== 1 || (int) $created->first()->total_minor !== 100000) {
    throw new RuntimeException('Concurrent creation was not idempotent.');
}
$order = $created->first();
workers(array_fill(0, 10, ['mode' => 'cancel', 'user' => (string) $user->id, 'order' => (string) $order->order_uuid]));
if ($order->fresh()->status !== 'cancelled'
    || DB::table('module_donation_order_events')->where('order_id', $order->id)->count() !== 2) {
    throw new RuntimeException('Concurrent cancellation did not record exactly one transition.');
}
// Interleave new quote and administrative rate change: result must be exactly the old or the new quote.
$key = (string) Str::uuid();
workers([
    ['mode' => 'create', 'user' => (string) $user->id, 'key' => $key],
    ['mode' => 'rate', 'user' => (string) $user->id, 'admin' => (string) $owner->id],
]);
$quoted = DonationOrder::query()->where('user_id', $user->id)->where('idempotency_key', $key)->firstOrFail();
if (! in_array((int) $quoted->total_minor, [100000, 200000], true)) {
    throw new RuntimeException('Concurrent rate change caused inconsistent order price.');
}
if (DB::table('wallet_entries')->where('wallet_id', $user->wallet()->value('id'))->exists()) {
    throw new RuntimeException('Test drafts must never credit coins.');
}
echo "PASS: concurrent create, cancellation, and rate snapshot.\n";
