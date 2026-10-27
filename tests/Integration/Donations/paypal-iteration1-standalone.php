<?php

/** Pure-PHP receipt compatibility tests. Run with -d zend.assertions=1 -d assert.exception=1. */
$root = dirname(__DIR__, 3);
require_once $root.'/modules/donations/src/Data/VerifiedPayment.php';
require_once $root.'/modules/donations/src/Data/VerifiedReceiptEnvelope.php';

use KaevCMS\Modules\Donations\Data\VerifiedPayment;
use KaevCMS\Modules\Donations\Data\VerifiedReceiptEnvelope;

$uuid = '12345678-1234-1234-1234-123456789abc';
$legacy = new VerifiedPayment($uuid, '444444', 'received_unsettled', 'RUB', 10000, 9750);
$legacyHash = hash('sha256', json_encode([
    $legacy->orderUuid, '444444', 'received_unsettled', 'RUB', 10000, 9750, false, 'live',
], JSON_THROW_ON_ERROR));
assert(VerifiedReceiptEnvelope::fingerprint($legacy) === $legacyHash);
$original = VerifiedReceiptEnvelope::seal($legacy, 'signed', 'yoomoney');
assert(str_contains($original, 'kaevcms-signed-yoomoney-v1'));
assert(VerifiedReceiptEnvelope::restore($original)['verified']->receivedAmountMinor === 9750);
$fk = VerifiedReceiptEnvelope::seal($legacy, 'api-proof', 'freekassa');
assert(str_contains($fk, 'kaevcms-signed-provider-v2'));
assert(VerifiedReceiptEnvelope::restore($fk)['verified']->receivedAmountMinor === 9750);

$paypal = new VerifiedPayment($uuid, 'PAYPAL-1', 'received_unsettled', 'USD', 12000,
    null, false, 'live');
$withoutNet = VerifiedReceiptEnvelope::seal($paypal, '{"event":"completed"}', 'paypal');
assert(VerifiedReceiptEnvelope::restore($withoutNet)['verified']->receivedAmountMinor === null);

$cross = new VerifiedPayment($uuid, 'PAYPAL-2', 'received_unsettled', 'USD', 12000,
    11000, false, 'live', 'EUR', 1000, 'GBP');
$v3 = VerifiedReceiptEnvelope::seal($cross, '{"event":"completed"}', 'paypal');
assert(str_contains($v3, 'kaevcms-signed-provider-v3'));
$restored = VerifiedReceiptEnvelope::restore($v3)['verified'];
assert($restored->receivedCurrencyCode === 'EUR');
assert($restored->feeCurrencyCode === 'GBP');
assert($restored->feeAmountMinor === 1000);
assert(VerifiedReceiptEnvelope::fingerprint($restored) === VerifiedReceiptEnvelope::fingerprint($cross));

foreach ([
    str_replace('"feeCurrencyCode":"GBP"', '"feeCurrencyCode":null', $v3),
    str_replace('"receivedCurrencyCode":"EUR"', '"receivedCurrencyCode":"INVALID"', $v3),
    str_replace('"feeAmountMinor":1000', '"feeAmountMinor":-1', $v3),
] as $bad) {
    try {
        VerifiedReceiptEnvelope::restore($bad);
        throw new RuntimeException('Invalid receipt metadata was accepted.');
    } catch (InvalidArgumentException) { /* fail closed */
    }
}
$raw = str_repeat('a', 60000);
assert(VerifiedReceiptEnvelope::restore(VerifiedReceiptEnvelope::seal($paypal, $raw, 'paypal'))['rawBody'] === $raw);
try {
    VerifiedReceiptEnvelope::seal($legacy, $raw, 'yoomoney');
    throw new RuntimeException('Legacy oversized body accepted.');
} catch (InvalidArgumentException) { /* historical limit remains */
}

echo "PayPal iteration 1 receipt compatibility: OK\n";
