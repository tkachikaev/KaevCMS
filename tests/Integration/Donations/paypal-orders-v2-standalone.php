<?php

/** Pure PHP parser/URL contract checks, runnable before composer install. */
$root = dirname(__DIR__, 3);
require_once $root.'/modules/donations/src/Exceptions/PayPalApiException.php';
require_once $root.'/modules/donations/src/Services/PayPalMoney.php';
require_once $root.'/modules/donations/src/Services/PayPalOrdersApi.php';
require_once $root.'/modules/donations/src/Data/PayPalApiCredentials.php';

use KaevCMS\Modules\Donations\Data\PayPalApiCredentials;
use KaevCMS\Modules\Donations\Exceptions\PayPalApiException;
use KaevCMS\Modules\Donations\Services\PayPalMoney;
use KaevCMS\Modules\Donations\Services\PayPalOrdersApi;

$creds = new PayPalApiCredentials('sandbox', 'client', 'secret');
assert($creds->merchantIdentity() !== (new PayPalApiCredentials('live', 'client', 'secret'))->merchantIdentity());
assert($creds->tokenCacheKey() !== (new PayPalApiCredentials('sandbox', 'client', 'rotated'))->tokenCacheKey());
assert(PayPalMoney::matches('100', 10000, 2));
assert(PayPalMoney::matches('100.0', 10000, 2));
assert(PayPalMoney::matches('100.00', 10000, 2));
assert(! PayPalMoney::matches('100.000', 10000, 2));
assert(! PayPalMoney::matches('99.99', 10000, 2));
assert(! PayPalMoney::matches('1e2', 10000, 2));
PayPalMoney::assertExponent('JPY', 0);
try {
    PayPalMoney::assertExponent('TWD', 2);
    throw new RuntimeException('TWD minor units wrongly accepted.');
} catch (InvalidArgumentException) {
    // Expected.
}
$order = 'ORDER12345';
$good = 'https://www.sandbox.paypal.com/checkoutnow?token='.$order;
assert(PayPalOrdersApi::validApprovalHost($good, 'sandbox', $order));
assert(! PayPalOrdersApi::validApprovalHost($good, 'live', $order));
assert(! PayPalOrdersApi::validApprovalHost('https://www.sandbox.paypal.com.evil.test/checkoutnow?token='.$order, 'sandbox', $order));
assert(! PayPalOrdersApi::validApprovalHost('https://www.sandbox.paypal.com/checkoutnow?token=OTHER&token='.$order, 'sandbox', $order));
assert(! PayPalOrdersApi::validApprovalHost('https://www.sandbox.paypal.com@evil.test/checkoutnow?token='.$order, 'sandbox', $order));
assert(! PayPalOrdersApi::validApprovalHost('http://www.sandbox.paypal.com/checkoutnow?token='.$order, 'sandbox', $order));
assert(PayPalOrdersApi::approvalUrl(['links' => [['rel' => 'payer-action', 'href' => $good]]], 'sandbox', $order) === $good);
try {
    PayPalOrdersApi::approvalUrl(['links' => [['rel' => 'approve', 'href' => 'https://evil.test']]], 'sandbox', $order);
    throw new RuntimeException('Malicious redirect accepted.');
} catch (PayPalApiException) {
    // Expected.
}
echo "PayPal Orders v2 URL/amount contracts OK\n";
