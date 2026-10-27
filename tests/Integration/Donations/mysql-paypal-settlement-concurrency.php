<?php

/** Real SQL/process races against a strict local HTTP fake; no external PayPal requests. */

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
use KaevCMS\Modules\Donations\Models\DonationOrder;
use KaevCMS\Modules\Donations\Models\DonationPaymentPreparation;
use KaevCMS\Modules\Donations\Models\PayPalAttempt;
use KaevCMS\Modules\Donations\Services\DonationOrders;
use KaevCMS\Modules\Donations\Services\DonationPaymentSettlements;
use KaevCMS\Modules\Donations\Services\DonationRates;
use KaevCMS\Modules\Donations\Services\DonationSettings;
use KaevCMS\Modules\Donations\Services\PayPalAdminSettings;
use KaevCMS\Modules\Donations\Services\PayPalAttempts;
use KaevCMS\Modules\Donations\Services\PayPalLiveReturns;
use KaevCMS\Modules\Donations\Services\PayPalManualReconciliation;
use KaevCMS\Modules\Donations\Services\PayPalOrderOperations;
use KaevCMS\Modules\Donations\Services\PayPalVerifiedPayments;

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
config(['wallet.enabled_sources.donations' => true, 'donation-payments.credits_enabled' => true,
    'donation-payments.callbacks_enabled' => true]);
Http::preventStrayRequests();
Http::fake(static function ($request) {
    if (DB::transactionLevel() !== 0) {
        throw new RuntimeException('Provider HTTP inside a financial transaction.');
    }
    $url = $request->url();
    if (! str_starts_with($url, 'https://api-m.paypal.com/')) {
        throw new RuntimeException('Unexpected provider environment/host.');
    }
    if (str_ends_with($url, '/v1/oauth2/token')) {
        return Http::response(['access_token' => 'qa-local-token', 'expires_in' => 3600]);
    }
    if (str_ends_with($url, '/v1/notifications/verify-webhook-signature')) {
        return Http::response(['verification_status' => 'SUCCESS']);
    }
    if (! preg_match('#/(?:checkout/orders/QAORDER|payments/captures/QACAPTURE)([0-9]+)(/capture)?$#D', $url, $match)) {
        throw new RuntimeException('Unexpected provider request: '.$url);
    }
    $attempt = PayPalAttempt::query()->findOrFail((int) $match[1]);
    if ($request->method() === 'POST') {
        if (($match[2] ?? '') !== '/capture'
            || $request->header('PayPal-Request-Id') !== [$attempt->capture_request_id]) {
            throw new RuntimeException('Invalid Capture request identity.');
        }
        DB::table('qa_paypal_remote')->where('id', $attempt->id)->increment('capture_posts');
        usleep(150000); // Other processes can observe the completed remote Capture before its response.
    }
    $completed = (int) DB::table('qa_paypal_remote')->where('id', $attempt->id)->value('capture_posts') > 0;
    $capture = ['id' => 'QACAPTURE'.$attempt->id, 'status' => 'COMPLETED',
        'amount' => ['currency_code' => 'USD', 'value' => '10.00']];
    if (str_contains($url, '/payments/captures/')) {
        if (! $completed) {
            return Http::response(['error' => 'not_found'], 404);
        }

        return Http::response([...$capture, 'supplementary_data' => ['related_ids' => [
            'order_id' => 'QAORDER'.$attempt->id,
        ]]]);
    }

    return Http::response(['id' => 'QAORDER'.$attempt->id, 'status' => $completed ? 'COMPLETED' : 'APPROVED',
        'purchase_units' => [['invoice_id' => $attempt->invoice_id, 'payee' => ['merchant_id' => 'MERCHANTABC123'],
            'amount' => ['currency_code' => 'USD', 'value' => '10.00'],
            'payments' => ['captures' => $completed ? [$capture] : []]]]], $request->method() === 'POST' ? 201 : 200);
});

if (($argv[1] ?? '') === '--worker') {
    [$attemptId, $mode, $start] = array_slice($argv, 2);
    $attempt = PayPalAttempt::query()->findOrFail((int) $attemptId);
    $order = DonationOrder::query()->findOrFail($attempt->order_id);
    while (microtime(true) < (float) $start) {
        usleep(1000);
    }
    if ($mode === 'capture') {
        app(PayPalOrderOperations::class)->capture($attempt->id, app(PayPalAdminSettings::class)->credentials('live'));
    } elseif ($mode === 'return') {
        app(PayPalLiveReturns::class)->returned(User::query()->findOrFail($order->user_id), $order, $attempt->paypal_order_id);
    } elseif ($mode === 'manual') {
        app(PayPalManualReconciliation::class)->check($attempt->id, $order->id);
    } elseif ($mode === 'replay') {
        app(DonationPaymentSettlements::class)->retryPending();
    } elseif ($mode === 'webhook') {
        $body = json_encode(['id' => 'WH-QA-'.$attempt->id, 'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => ['id' => 'QACAPTURE'.$attempt->id, 'supplementary_data' => ['related_ids' => [
                'order_id' => $attempt->paypal_order_id,
            ]]]], JSON_THROW_ON_ERROR);
        $request = Request::create('https://qa.invalid/payments/paypal/notify', 'POST', [], [], [], [
            'HTTPS' => 'on', 'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYPAL_TRANSMISSION_ID' => 'qa-transmission',
            'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-10-27T12:00:00Z',
            'HTTP_PAYPAL_CERT_URL' => 'https://api.paypal.com/v1/notifications/certs/QA',
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'HTTP_PAYPAL_TRANSMISSION_SIG' => 'QUJDREVGR0hJSktMTU4=',
        ], $body);
        $kernel = app(Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('PayPal callback failed: '.$response->getStatusCode());
        }
    } else {
        throw new RuntimeException('Unknown worker mode.');
    }
    echo "ok\n";
    exit(0);
}

/** @param list<string> $modes */
function racePayPal(int $attemptId, array $modes): void
{
    $start = microtime(true) + 2;
    $workers = [];
    foreach ($modes as $mode) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', (string) $attemptId, $mode, (string) $start],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start PayPal worker.');
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
            $errors[] = trim($err).' / '.trim($out);
        }
    }
    if ($errors !== []) {
        throw new RuntimeException('Concurrent PayPal workers failed: '.implode('; ', $errors));
    }
}

/** A local immutable preparation; hosted Create is separately covered by API/attempt tests. */
function preparedPayPal(): PayPalAttempt
{
    $user = User::factory()->create();
    $order = app(DonationOrders::class)->createDraft($user, 'USD', 10, (string) Str::uuid());
    $preparation = new DonationPaymentPreparation;
    $preparation->forceFill(['order_id' => $order->id, 'provider_code' => 'paypal', 'mode' => 'live',
        'checkout_method' => 'GET', 'provider_label' => $order->order_uuid,
        'payer_amount_minor' => $order->total_minor, 'currency_code' => 'USD'])->save();
    $credentials = app(PayPalAdminSettings::class)->credentials('live');
    $attempt = app(PayPalAttempts::class)->reserveLive($preparation, $credentials->merchantIdentity(), 'MERCHANTABC123');
    app(PayPalAttempts::class)->claimCreate($attempt->id);
    app(PayPalAttempts::class)->markCreated($attempt->id, 'QAORDER'.$attempt->id);
    DB::table('qa_paypal_remote')->insert(['id' => $attempt->id, 'capture_posts' => 0]);

    return $attempt->fresh();
}

function assertPayPalCredit(PayPalAttempt $attempt): void
{
    $order = DonationOrder::query()->findOrFail($attempt->order_id);
    if (DB::table('wallet_entries')->where('source_key', 'donation:'.$order->order_uuid)->count() !== 1
        || DB::table('module_donation_payment_receipts')->where('order_id', $order->id)->count() !== 1
        || DB::table('module_donation_payment_settlements')->where('order_id', $order->id)->count() !== 1
        || (int) DB::table('wallets')->where('user_id', $order->user_id)->value('balance') !== 10
        || DB::table('module_donation_paypal_attempts')->where('id', $attempt->id)->value('status') !== 'settled'
        || (int) DB::table('qa_paypal_remote')->where('id', $attempt->id)->value('capture_posts') !== 1) {
        throw new RuntimeException('PayPal exactly-once invariant failed.');
    }
}

$module = app(ModuleManager::class)->enable('donations');
app(ModuleRuntime::class)->bootModule($module);
Schema::create('qa_paypal_remote', static function ($table): void {
    $table->unsignedBigInteger('id')->primary();
    $table->unsignedInteger('capture_posts');
});
$owner = Admin::factory()->create(['role' => AdminRole::Owner]);
app(DonationSettings::class)->updateVisibility(true, $owner);
app(DonationRates::class)->saveRate('USD', '1.00', true, $owner);
app(PayPalAdminSettings::class)->save($owner, ['USD'], [
    'live_client_id' => 'QaClient', 'live_client_secret' => 'QaSecret',
    'live_merchant_id' => 'MERCHANTABC123', 'live_webhook_id' => 'QAHOOK',
], true);

$attempt = preparedPayPal();
racePayPal($attempt->id, array_fill(0, 10, 'capture'));
if (DB::table('wallet_entries')->exists()
    || (int) DB::table('qa_paypal_remote')->where('id', $attempt->id)->value('capture_posts') !== 1) {
    throw new RuntimeException('Parallel Capture charged more than once or bypassed verification.');
}
racePayPal($attempt->id, array_fill(0, 10, 'webhook'));
assertPayPalCredit($attempt);
if (DB::table('module_donation_paypal_webhook_events')->where('attempt_id', $attempt->id)->count() !== 1) {
    throw new RuntimeException('Duplicate webhook inbox records.');
}
echo "PASS: 10 parallel Capture calls => one remote POST; 10 identical webhooks => one credit/inbox.\n";

$attempt = preparedPayPal();
DB::table('qa_paypal_remote')->where('id', $attempt->id)->update(['capture_posts' => 1]);
DB::table('module_donation_paypal_attempts')->where('id', $attempt->id)->update(['status' => 'capture_unknown']);
racePayPal($attempt->id, ['webhook', 'return', 'manual', 'webhook', 'return', 'manual', 'webhook', 'return', 'manual', 'webhook']);
assertPayPalCredit($attempt);
echo "PASS: 10 mixed webhook/return/read-only recovery workers => one credit, no repeated Capture.\n";

$attempt = preparedPayPal();
app(PayPalOrderOperations::class)->capture($attempt->id, app(PayPalAdminSettings::class)->credentials('live'));
config()->set('donation-payments.credits_enabled', false);
if (app(PayPalVerifiedPayments::class)->reconcile($attempt->id) !== 'pending') {
    throw new RuntimeException('Closed credit guard did not retain payment.');
}
config()->set('donation-payments.credits_enabled', true);
// New payments disabled and secrets rotated; authenticated retained evidence still settles.
app(PayPalAdminSettings::class)->save($owner, ['USD'], ['live_client_secret' => 'QaRotatedSecret'], false);
racePayPal($attempt->id, array_fill(0, 10, 'replay'));
assertPayPalCredit($attempt);
echo "PASS: 10 retained-receipt replays after disable/secret rotation => one credit.\n";
