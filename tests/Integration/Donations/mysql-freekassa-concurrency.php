<?php

/** Real SQL/process concurrency with an isolated, strict fake merchant API. No external requests. */
use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\User;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use App\Support\Payments\DonationsTrustedAutoload;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
});
if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql'
    || ! preg_match('/(?:test|testing)/i', DB::connection()->getDatabaseName())
    || getenv('DONATIONS_TEST_ALLOW_CONCURRENCY') !== '1') {
    throw new RuntimeException('Requires isolated MySQL/MariaDB test DB and explicit opt-in.');
}
DonationsTrustedAutoload::register();
config()->set('wallet.enabled_sources.donations', true);

if (($argv[1] ?? '') === '--worker') {
    [$uuid, $operation, $mode, $start] = array_slice($argv, 2);
    Http::preventStrayRequests();
    Http::fake(['api.fk.life/v1/orders' => static function ($request) use ($operation) {
        $payload = $request->data();
        $signature = $payload['signature'];
        unset($payload['signature']);
        ksort($payload, SORT_STRING);
        if (! hash_equals(hash_hmac('sha256', implode('|', $payload), 'qa-merchant-api-key'), $signature)) {
            throw new RuntimeException('Invalid merchant API signature.');
        }
        // Force the lower nonce to arrive later unless API dispatch is serialized.
        if ($payload['nonce'] % 2 !== 0) {
            usleep(300000);
        }
        $accepted = DB::transaction(static function () use ($payload): bool {
            $state = DB::table('qa_freekassa_api_nonce')->where('id', 1)->lockForUpdate()->first();
            if ($state === null || $payload['nonce'] <= (int) $state->last_nonce) {
                return false;
            }
            DB::table('qa_freekassa_api_nonce')->where('id', 1)->update(['last_nonce' => $payload['nonce']]);

            return true;
        });
        if (! $accepted) {
            return Http::response(['type' => 'error', 'message' => 'Nonce must increase'], 400);
        }

        return Http::response(['type' => 'success', 'pages' => 1, 'orders' => [[
            'merchant_order_id' => $payload['paymentId'], 'fk_order_id' => $operation,
            'amount' => '100.00', 'currency' => 'RUB', 'status' => 1,
        ]]]);
    }]);
    while (microtime(true) < (float) $start) {
        usleep(1000);
    }
    if ($mode === 'recovery') {
        $result = app(DonationPaymentSettlements::class)->recoverFreeKassaByApi($uuid);
        if ($result !== 'credited') {
            throw new RuntimeException('Recovery did not settle.');
        }
    } else {
        $body = http_build_query(['MERCHANT_ID' => '7012', 'AMOUNT' => '100.00',
            'MERCHANT_ORDER_ID' => $uuid, 'intid' => '999888', 'CUR_ID' => '4',
            'SIGN' => md5('7012:100.00:qa-callback-secret:'.$uuid)]);
        $request = Request::create('https://qa.invalid/payments/freekassa/notify', 'POST', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.19', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], $body);
        $kernel = app(Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        if ($response->getStatusCode() !== 200 || $response->getContent() !== 'YES') {
            throw new RuntimeException('FreeKassa callback failed: '.$response->getStatusCode());
        }
    }
    echo "ok\n";
    exit(0);
}

function concurrentFreeKassa(string $uuid, bool $mixed = false): void
{
    $start = microtime(true) + 2;
    $workers = [];
    foreach (range(0, 9) as $i) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $uuid, '555777',
            $mixed && $i % 2 === 0 ? 'recovery' : 'callback', (string) $start],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not launch FreeKassa worker.');
        }
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $errors = [];
    foreach ($workers as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || trim($out) !== 'ok') {
            $errors[] = trim($err);
        }
    }
    if ($errors !== []) {
        throw new RuntimeException('Concurrent FreeKassa workers failed: '.implode('; ', $errors));
    }
}

$module = app(ModuleManager::class)->enable('donations');
app(ModuleRuntime::class)->bootModule($module);
Schema::create('qa_freekassa_api_nonce', static function ($table): void {
    $table->unsignedInteger('id')->primary();
    $table->unsignedBigInteger('last_nonce');
});
DB::table('qa_freekassa_api_nonce')->insert(['id' => 1, 'last_nonce' => 0]);
$owner = Admin::factory()->create(['role' => AdminRole::Owner]);
$user = User::factory()->create();
app(DonationSettings::class)->updateVisibility(true, $owner);
app(DonationRates::class)->saveRate('RUB', '10', true, $owner);
app(DonationProviderSettings::class)->save($owner, 'freekassa', true, 'live', ['RUB'], [
    'merchant_id' => '7012', 'secret_1' => 'qa-checkout-secret',
    'secret_2' => 'qa-callback-secret', 'api_key' => 'qa-merchant-api-key',
], ['198.51.100.19'], []);
$order = app(DonationOrders::class)->createDraft($user, 'RUB', 10, (string) Str::uuid());
app(DonationPaymentPreparations::class)->prepare($user, $order->order_uuid, 'freekassa');
concurrentFreeKassa($order->order_uuid);
if (DB::table('wallet_entries')->where('source_key', 'donation:'.$order->order_uuid)->count() !== 1
    || DB::table('module_donation_payment_settlements')->where('order_id', $order->id)->count() !== 1
    || DB::table('module_donation_payment_receipts')->where('order_id', $order->id)->count() !== 1
    || (int) DB::table('wallets')->where('user_id', $user->id)->value('balance') !== 10) {
    throw new RuntimeException('Duplicate FreeKassa callback violated exactly-once settlement.');
}
echo "FreeKassa: 10 simultaneous callbacks, strict API nonce order, exactly one credit: PASS\n";
