<?php

/** Isolated, no-vendor tests for raw webhook input and untrusted refund URL parsing. */

declare(strict_types=1);

require_once __DIR__.'/../../../modules/donations/src/Services/PayPalWebhookVerifier.php';
require_once __DIR__.'/../../../modules/donations/src/Services/PayPalWebhooks.php';

use KaevCMS\Modules\Donations\Services\PayPalWebhookVerifier;
use KaevCMS\Modules\Donations\Services\PayPalWebhooks;

$passed = 0;
$check = static function (bool $valid, string $reason) use (&$passed): void {
    if (! $valid) {
        throw new RuntimeException('FAIL: '.$reason);
    }
    $passed++;
};

$check(PayPalWebhookVerifier::isJsonObject('{ "id": "evt", "amount": 1.00 }'), 'raw JSON object accepted');
foreach (['', '[]', '"string"', 'null', '{bad json}', '{"deep":'.str_repeat('[', 45).'0'.str_repeat(']', 45).'}'] as $bad) {
    $check(! PayPalWebhookVerifier::isJsonObject($bad), 'non-object or invalid JSON rejected');
}

$reflect = new ReflectionClass(PayPalWebhooks::class);
$instance = $reflect->newInstanceWithoutConstructor();
$extract = $reflect->getMethod('captureFromUpLink');
$valid = [
    'links' => [[
        'rel' => 'up', 'method' => 'GET',
        'href' => 'https://api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE_123',
    ]],
];
$check($extract->invoke($instance, $valid) === 'CAPTURE_123', 'trusted capture up-link is parsed without fetching');
$check($extract->invoke($instance, ['links' => []]) === null, 'missing up-link cannot invent capture');
$check($extract->invoke($instance, ['links' => [[
    'rel' => 'up', 'method' => 'GET',
    'href' => 'https://api-m.paypal.com/v2/checkout/orders/ORDER_456',
]]]) === null, 'order up-link is not capture proof');

foreach ([
    'http://api-m.paypal.com/v2/payments/captures/ABC',
    'https://evil.example/v2/payments/captures/ABC',
    'https://api-m.paypal.com@evil.example/v2/payments/captures/ABC',
    'https://api-m.paypal.com/v2/payments/captures/ABC?redirect=1',
    'https://api-m.paypal.com/v2/payments/captures/ABC#fragment',
    'https://api-m.paypal.com:8080/v2/payments/captures/ABC',
    'https://api-m.paypal.com/v2/payments/captures/ABC/extra',
] as $url) {
    $bad = ['links' => [['rel' => 'up', 'method' => 'GET', 'href' => $url]]];
    $rejected = false;
    try {
        $extract->invoke($instance, $bad);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $check($rejected, 'unsafe capture up-link rejected: '.$url);
}
$rejected = false;
try {
    $extract->invoke($instance, [
        'links' => [
            $valid['links'][0],
            ['rel' => 'up', 'method' => 'GET', 'href' => 'https://api-m.sandbox.paypal.com/v2/payments/captures/OTHER_123'],
        ],
    ]);
} catch (InvalidArgumentException) {
    $rejected = true;
}
$check($rejected, 'contradictory capture IDs rejected');

fwrite(STDOUT, 'PayPal iteration 6 standalone: '.$passed." checks passed\n");
