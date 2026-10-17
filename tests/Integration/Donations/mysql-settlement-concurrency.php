<?php

/**
 * DESTRUCTIVE on disposable MySQL TEST DB only (never production):
 * APP_ENV=testing DONATIONS_TEST_ALLOW_CONCURRENCY=1 php tests/Integration/Donations/mysql-settlement-concurrency.php
 * Requires vendor and proc_open. Exercises real MySQL row locks and uniqueness.
 */
use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\User;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use App\Support\Payments\DonationsTrustedAutoload;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use KaevCMS\Modules\Donations\Data\ProviderCallback;
use KaevCMS\Modules\Donations\Services\DonationOrders;
use KaevCMS\Modules\Donations\Services\DonationPaymentPreparations;
use KaevCMS\Modules\Donations\Services\DonationPaymentSettlements;
use KaevCMS\Modules\Donations\Services\DonationProviderSettings;
use KaevCMS\Modules\Donations\Services\DonationRates;
use KaevCMS\Modules\Donations\Services\DonationSettings;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// These standalone scripts run outside Artisan's command exit-code handling.
set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
});

$db = (string) DB::connection()->getDatabaseName();
if ((string) config('app.env') !== 'testing'
    || DB::connection()->getDriverName() !== 'mysql'
    || ! preg_match('/(?:test|testing)/i', $db)
    || getenv('DONATIONS_TEST_ALLOW_CONCURRENCY') !== '1') {
    fwrite(STDERR, "Refusing: requires APP_ENV=testing, MySQL TEST database, and explicit opt-in.\n");
    exit(2);
}
if (! function_exists('proc_open')) {
    fwrite(STDERR, "proc_open unavailable.\n");
    exit(2);
}
DonationsTrustedAutoload::register();
config()->set('donation-payments.callbacks_enabled', true);
config()->set('donation-payments.credits_enabled', true);
config()->set('wallet.enabled_sources.donations', true);

/** @return string URL-encoded, signed, unaltered provider notification. */
function signed(string $uuid, string $operation): string
{
    $fields = [
        'notification_type' => 'p2p-incoming', 'operation_id' => $operation,
        'amount' => '98.00', 'withdraw_amount' => '100.00', 'currency' => '643',
        'datetime' => '2026-10-17T12:00:00Z', 'sender' => '410012345666666',
        'codepro' => 'false', 'label' => 'kaevcms-'.$uuid, 'unaccepted' => 'false',
        'test_notification' => 'false',
    ];
    ksort($fields, SORT_STRING);
    $base = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);

    return $base.'&sign='.hash_hmac('sha256', $base, 'concurrency-secret');
}

if (($argv[1] ?? '') === '--worker') {
    $start = (float) ($argv[4] ?? 0);
    while (microtime(true) < $start) {
        usleep(1000);
    }
    try {
        $body = signed((string) $argv[2], (string) $argv[3]);
        if (($argv[5] ?? '') === 'http') {
            // In-process HTTP dispatch exercises the real public route and
            // middleware without connecting to any external host or provider.
            $request = Request::create('https://qa.invalid/payments/yoomoney/notify', 'POST', [], [], [], [
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'REMOTE_ADDR' => '127.0.0.1',
            ], $body);
            $kernel = app(Illuminate\Contracts\Http\Kernel::class);
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);
            if ($response->getStatusCode() !== 200 || ! in_array($response->getContent(), ['OK', 'Recorded'], true)) {
                throw new RuntimeException('Webhook HTTP request was not acknowledged.');
            }
        } else {
            $status = app(DonationPaymentSettlements::class)->receive(new ProviderCallback($body, [], ''));
            if (! in_array($status, ['credited', 'review'], true)) {
                throw new RuntimeException('Unexpected uncredited status.');
            }
        }
        echo "ok\n";
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception::class."\n");
        exit(11);
    }
}

/** @param list<string> $operations */
function runConcurrent(string $uuid, array $operations, bool $http = false): void
{
    $start = microtime(true) + 1.5;
    $workers = [];
    foreach ($operations as $id) {
        $cmd = [PHP_BINARY, __FILE__, '--worker', $uuid, $id, (string) $start, $http ? 'http' : 'service'];
        $pipes = [];
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($proc)) {
            throw new RuntimeException('Cannot start settlement worker.');
        }
        fclose($pipes[0]);
        $workers[] = [$proc, $pipes];
    }
    foreach ($workers as [$proc, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($proc) !== 0 || trim($out) !== 'ok') {
            throw new RuntimeException('Parallel settlement worker failed: '.trim($err));
        }
    }
}

$module = app(ModuleManager::class)->enable('donations');
app(ModuleRuntime::class)->bootModule($module);
$owner = Admin::factory()->create(['role' => AdminRole::Owner]);
$user = User::factory()->create();
app(DonationSettings::class)->updateVisibility(true, $owner);
app(DonationRates::class)->saveRate('RUB', '10', true, $owner);
app(DonationProviderSettings::class)->save($owner, 'yoomoney', true, 'live', ['RUB'], [
    'receiver' => '410012345678901', 'notification_secret' => 'concurrency-secret',
]);
$order = app(DonationOrders::class)->createDraft($user, 'RUB', 10, (string) Str::uuid());
app(DonationPaymentPreparations::class)->prepare($user, $order->order_uuid, 'yoomoney');
runConcurrent($order->order_uuid, array_fill(0, 10, '440010001'));
runConcurrent($order->order_uuid, array_map(static fn ($n): string => (string) (440010002 + $n), range(0, 9)));
if (DB::table('module_donation_payment_settlements')->where('order_id', $order->id)->count() !== 1
    || DB::table('wallet_entries')->where('source', 'donations')->where('source_key', 'donation:'.$order->order_uuid)->count() !== 1
    || DB::table('module_donation_payment_receipts')->where('order_id', $order->id)->where('status', 'credited')->count() !== 1
    || DB::table('module_donation_payment_receipts')->where('order_id', $order->id)->where('status', 'review')->count() !== 10
    || (int) DB::table('wallets')->where('user_id', $user->id)->value('balance') !== 10) {
    throw new RuntimeException('Concurrent settlement violated exactly-once or review invariants.');
}
echo "Concurrent YooMoney settlement verified on disposable MySQL test DB\n";

$httpOrder = app(DonationOrders::class)->createDraft($user, 'RUB', 10, (string) Str::uuid());
app(DonationPaymentPreparations::class)->prepare($user, $httpOrder->order_uuid, 'yoomoney');
runConcurrent($httpOrder->order_uuid, array_fill(0, 10, '550010001'), true);
runConcurrent($httpOrder->order_uuid, array_map(static fn ($n): string => (string) (550010002 + $n), range(0, 9)), true);
if (DB::table('module_donation_payment_settlements')->where('order_id', $httpOrder->id)->count() !== 1
    || DB::table('wallet_entries')->where('source', 'donations')->where('source_key', 'donation:'.$httpOrder->order_uuid)->count() !== 1
    || DB::table('module_donation_payment_receipts')->where('order_id', $httpOrder->id)->where('status', 'credited')->count() !== 1
    || DB::table('module_donation_payment_receipts')->where('order_id', $httpOrder->id)->where('status', 'review')->count() !== 10
    || (int) DB::table('wallets')->where('user_id', $user->id)->value('balance') !== 20) {
    throw new RuntimeException('Concurrent public HTTP webhook violated exactly-once invariants.');
}
echo "Concurrent HTTP webhook verified: 10 duplicate and 10 distinct notifications, exactly one credit per order\n";
