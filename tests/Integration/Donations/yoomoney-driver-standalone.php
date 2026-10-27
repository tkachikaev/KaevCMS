<?php

use KaevCMS\Modules\Donations\Data\PaymentIntent;
use KaevCMS\Modules\Donations\Data\ProviderCallback;
use KaevCMS\Modules\Donations\Data\VerifiedPayment;
use KaevCMS\Modules\Donations\Data\VerifiedReceiptEnvelope;
use KaevCMS\Modules\Donations\Providers\YooMoneyProvider;

/** Run without Laravel/vendor: php tests/Integration/Donations/yoomoney-driver-standalone.php */
$root = dirname(__DIR__, 3);
foreach ([
    'src/Contracts/PaymentProvider.php', 'src/Contracts/RoutablePaymentProvider.php',
    'src/Exceptions/InvalidPaymentSignature.php', 'src/Data/PaymentCheckout.php',
    'src/Data/PaymentIntent.php', 'src/Data/ProviderCallback.php',
    'src/Data/VerifiedPayment.php', 'src/Data/VerifiedReceiptEnvelope.php', 'src/Services/DonationMoney.php',
    'src/Providers/YooMoneyProvider.php',
] as $path) {
    require_once $root.'/modules/donations/'.$path;
}

$driver = new YooMoneyProvider;
$credentials = ['receiver' => '410012345678901', 'notification_secret' => 'secret123'];
$intent = new PaymentIntent('12345678-1234-1234-1234-123456789abc', 100, 'RUB', 100000, 2, '', 'https://example.org/orders');
$checkout = $driver->beginPayment($intent, $credentials, 'live');
assert($checkout->method === 'POST');
assert($checkout->redirectUrl === YooMoneyProvider::CHECKOUT_URL);
assert($checkout->fields['sum'] === '1000.00');
assert($checkout->providerPaymentId === null);
$example = 'amount=98.00&codepro=false&currency=643&datetime=2013-12-26T08%3A28%3A34Z&label=kaevcms-12345678-1234-1234-1234-123456789abc&notification_type=p2p-incoming&operation_id=441361714955017004&sender=41000000000&unaccepted=false&withdraw_amount=100.00';
$sign = hash_hmac('sha256', $example, 'secret123');
$callback = new ProviderCallback($example.'&sign='.$sign, [], '');
$verified = $driver->verifyCallback($callback, $credentials, 'live');
assert($verified->amountMinor === 10000 && $verified->receivedAmountMinor === 9800);
assert($verified->orderUuid === $intent->orderUuid);
assert($verified->testNotification === false);
assert($verified->status === 'received_unsettled');
$exampleLabel = 'kaevcms-'.$intent->orderUuid;
assert($driver->referenceForOrder($intent->orderUuid) === $exampleLabel);
assert($driver->referenceFromCallback($callback) === $exampleLabel);
$driver->assertCheckout($checkout, $intent, $credentials, $exampleLabel);
$bad = [
    str_replace('withdraw_amount=100.00', 'withdraw_amount=101.00', $callback->rawBody),
    $callback->rawBody.'&amount=98.00',
    str_replace('currency=643', 'currency=840', $callback->rawBody),
    str_replace('sign='.$sign, 'sign='.str_repeat('0', 64), $callback->rawBody),
    str_replace('withdraw_amount=100.00', 'withdraw_amount=1e2', $callback->rawBody),
];
foreach ($bad as $body) {
    try {
        $driver->verifyCallback(new ProviderCallback($body, [], ''), $credentials, 'live');
        throw new RuntimeException('Forged notification was accepted.');
    } catch (InvalidArgumentException) {
        // Expected: fail closed.
    }
}
// Canonical parameters must be alphabetically sorted before signing.
$body = str_replace('&unaccepted=false', '&test_notification=true&unaccepted=false', $example);
$test = $driver->verifyCallback(new ProviderCallback($body.'&sign='.hash_hmac('sha256', $body, 'secret123'), [], ''), $credentials, 'live');
assert($test->testNotification === true);
$testMode = $driver->verifyCallback($callback, $credentials, 'test');
assert($testMode->environment === 'test');
// Official canonical HMAC-SHA256 example from YooMoney documentation, including legacy
// sha1_hash AS A SIGNED FIELD. It must not be verified using SHA-1 instead of sign.
$official = 'amount=98.00&codepro=false&currency=643&datetime=2013-12-26T08%3A28%3A34Z&label=ML23045&notification_type=p2p-incoming&operation_id=441361714955017004&sender=41000000000&sha1_hash=ac13833bd6ba9eff1fa9e4bed76f3d6ebb57f6c0&unaccepted=false&withdraw_amount=100.00';
assert(hash_hmac('sha256', $official, 'secret123') === 'a452af731650e2c5b39abcdc7c28dd27db7b3b654c2230ad2c386e64afb98605');
// Authenticated evidence is built ONLY from a previously signed and verified
// notification, and includes all immutable fields needed for offline retries.
$sealed = VerifiedReceiptEnvelope::seal($verified, $callback->rawBody);
$restored = VerifiedReceiptEnvelope::restore($sealed);
if ($restored === null || $restored['rawBody'] !== $callback->rawBody
    || $restored['providerCode'] !== 'yoomoney'
    || VerifiedReceiptEnvelope::fingerprint($restored['verified']) !== VerifiedReceiptEnvelope::fingerprint($verified)
    || VerifiedReceiptEnvelope::restore($callback->rawBody) !== null) {
    throw new RuntimeException('Verified callback evidence round-trip failed.');
}
foreach ([
    str_replace('"amountMinor":10000', '"amountMinor":0', $sealed),
    str_replace('"providerPaymentId":"441361714955017004"', '"providerPaymentId":"invalid"', $sealed),
    str_replace('"_format":"kaevcms-signed-yoomoney-v1"', '"_format":"unknown"', $sealed),
] as $invalid) {
    try {
        VerifiedReceiptEnvelope::restore($invalid);
        throw new RuntimeException('Invalid stored evidence was accepted.');
    } catch (InvalidArgumentException) {
        // Expected. Real stored evidence is additionally authenticated by Crypt.
    }
}
// v2 evidence includes authenticated provider identity and currency, but it cannot
// be replayed as a legacy YooMoney envelope.
$other = new VerifiedPayment(
    $verified->orderUuid, 'PAYMENT-A_42', 'received_unsettled', 'USD', 10000, 9900,
);
$sealedV2 = VerifiedReceiptEnvelope::seal($other, 'test-signed-payload', 'fixture_gamma');
$v2 = VerifiedReceiptEnvelope::restore($sealedV2);
if ($v2 === null || $v2['providerCode'] !== 'fixture_gamma' || $v2['verified']->currencyCode !== 'USD') {
    throw new RuntimeException('Provider-specific evidence v2 round-trip failed.');
}
foreach ([
    str_replace('"providerCode":"fixture_gamma"', '"providerCode":"!!!"', $sealedV2),
    str_replace('"receivedAmountMinor":9900', '"receivedAmountMinor":10001', $sealedV2),
] as $tampered) {
    try {
        VerifiedReceiptEnvelope::restore($tampered);
        throw new RuntimeException('Invalid v2 evidence was accepted.');
    } catch (InvalidArgumentException) {
        // Expected: Laravel Crypt supplies additional integrity protection in storage.
    }
}
echo "YooMoney standalone driver, legacy/v2 evidence and signature contracts passed\n";
