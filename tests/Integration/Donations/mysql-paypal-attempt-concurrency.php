<?php

/**
 * Disposable MySQL ONLY:
 * APP_ENV=testing DONATIONS_TEST_ALLOW_CONCURRENCY=1 php tests/Integration/Donations/mysql-paypal-attempt-concurrency.php
 * Verifies one local attempt and one in-flight Create claim across separate PHP processes.
 */

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\User;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use KaevCMS\Modules\Donations\Models\DonationPaymentPreparation;
use KaevCMS\Modules\Donations\Services\DonationOrders;
use KaevCMS\Modules\Donations\Services\DonationRates;
use KaevCMS\Modules\Donations\Services\DonationSettings;
use KaevCMS\Modules\Donations\Services\PayPalAttempts;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
});

$db = (string) DB::connection()->getDatabaseName();
if ((string) config('app.env') !== 'testing' || DB::connection()->getDriverName() !== 'mysql'
    || ! preg_match('/(?:test|testing)/i', $db)
    || getenv('DONATIONS_TEST_ALLOW_CONCURRENCY') !== '1') {
    fwrite(STDERR, "Refusing: use disposable MySQL testing database and explicit concurrency flag.\n");
    exit(2);
}

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    [$name, $value] = array_pad(explode('=', ltrim($arg, '-'), 2), 2, '');
    $args[$name] = $value;
}

if (isset($args['worker'])) {
    while (microtime(true) < (float) ($args['start'] ?? 0)) {
        usleep(2000);
    }
    $service = app(PayPalAttempts::class);
    $preparation = DonationPaymentPreparation::query()->findOrFail((int) ($args['preparation'] ?? 0));
    $attempt = $service->reserveLive($preparation, 'concurrency_merchant', 'MERCHANTABC123');
    echo $attempt->id.':'.($service->claimCreate((int) $attempt->id) ? 'won' : 'skipped')."\n";
    exit(0);
}

if (! function_exists('proc_open')) {
    fwrite(STDERR, "proc_open unavailable.\n");
    exit(2);
}

$module = $app->make(ModuleManager::class)->enable('donations');
$app->make(ModuleRuntime::class)->bootModule($module);
$admin = Admin::factory()->create(['role' => AdminRole::Owner]);
app(DonationSettings::class)->updateVisibility(true, $admin);
app(DonationRates::class)->saveRate('USD', '1.00', true, $admin);
$user = User::factory()->create();
$order = app(DonationOrders::class)->createDraft($user, 'USD', 10, (string) Str::uuid());
$preparation = new DonationPaymentPreparation;
$preparation->forceFill([
    'order_id' => $order->id, 'provider_code' => 'paypal', 'mode' => 'live',
    'checkout_method' => 'GET', 'provider_label' => $order->order_uuid,
    'payer_amount_minor' => $order->total_minor, 'currency_code' => 'USD',
])->save();

$start = microtime(true) + 1.5;
$processes = [];
for ($i = 0; $i < 10; $i++) {
    $pipes = [];
    $proc = proc_open([PHP_BINARY, __FILE__, '--worker=1', '--start='.$start,
        '--preparation='.$preparation->id], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($proc)) {
        throw new RuntimeException('Failed to start concurrency worker.');
    }
    fclose($pipes[0]);
    $processes[] = [$proc, $pipes];
}
$results = [];
foreach ($processes as [$proc, $pipes]) {
    $line = trim(stream_get_contents($pipes[1]));
    $err = trim(stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) {
        throw new RuntimeException('Failed worker: '.$err);
    }
    $results[] = $line;
}
$ids = array_unique(array_map(static fn (string $line): string => explode(':', $line)[0], $results));
$won = count(array_filter($results, static fn (string $line): bool => str_ends_with($line, ':won')));
if (count($ids) !== 1 || $won !== 1
    || DB::table('module_donation_paypal_attempts')->where('order_id', $order->id)->count() !== 1
    || DB::table('module_donation_paypal_attempts')->where('order_id', $order->id)->value('status') !== 'creating'
    || DB::table('wallet_entries')->where('wallet_id', $user->wallet()->value('id'))->exists()) {
    throw new RuntimeException('PayPal Create reservation is not serialized correctly.');
}
echo "PASS: 10 parallel workers, one PayPal attempt, one active Create, no Wallet credit.\n";
