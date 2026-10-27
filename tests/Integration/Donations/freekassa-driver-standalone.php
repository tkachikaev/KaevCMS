<?php

/** PHP-only smoke test: php tests/Integration/Donations/freekassa-driver-standalone.php */

declare(strict_types=1);

use KaevCMS\Modules\Donations\Data\PaymentCheckout;
use KaevCMS\Modules\Donations\Data\PaymentIntent;
use KaevCMS\Modules\Donations\Data\ProviderCallback;
use KaevCMS\Modules\Donations\Providers\FreeKassaProvider;
use KaevCMS\Modules\Donations\Providers\FreeKassaSignedNotification;

$base = dirname(__DIR__, 3).'/modules/donations/src/';
foreach ([
    'Contracts/PaymentProvider.php', 'Contracts/RoutablePaymentProvider.php',
    'Data/PaymentCheckout.php', 'Data/PaymentIntent.php', 'Data/ProviderCallback.php',
    'Data/VerifiedPayment.php', 'Exceptions/InvalidPaymentSignature.php',
    'Services/DonationMoney.php', 'Providers/FreeKassaSignedNotification.php',
    'Providers/FreeKassaProvider.php',
] as $file) {
    require_once $base.$file;
}

function ensure(bool $yes, string $reason): void
{
    if (! $yes) {
        throw new RuntimeException($reason);
    }
}

function rejects(callable $check, string $reason): void
{
    try {
        $check();
    } catch (InvalidArgumentException $e) {
        return;
    }
    throw new RuntimeException('Not rejected: '.$reason);
}

$provider = new FreeKassaProvider;
$credentials = ['merchant_id' => '7012', 'secret_1' => 'form-secret-value', 'secret_2' => 'notification-secret-value'];
$uuid = '11111111-2222-4333-8444-555555555555';
$intent = new PaymentIntent($uuid, 100, 'EUR', 10000, 2, 'https://site.invalid/payments/freekassa/notify', 'https://site.invalid/order');
$checkout = $provider->beginPayment($intent, $credentials, 'test');
ensure($checkout->redirectUrl === 'https://pay.fk.money/' && $checkout->method === 'GET', 'SCI checkout');
ensure($checkout->fields['s'] === md5('7012:100.00:form-secret-value:EUR:'.$uuid), 'SCI signature');
$provider->assertCheckout($checkout, $intent, $credentials, $uuid);
rejects(fn () => $provider->assertConfiguration($credentials, 'live'), 'API key is required for live');
$provider->assertConfiguration([...$credentials, 'api_key' => 'verified-server-api-key'], 'live');
ensure($provider->beginPayment($intent, [...$credentials, 'api_key' => 'verified-server-api-key'], 'live')->method === 'GET', 'live SCI checkout');
rejects(fn () => $provider->assertCheckout(new PaymentCheckout(null, 'https://evil.invalid', 'GET', $checkout->fields), $intent, $credentials, $uuid), 'malicious redirect');
foreach (['100', '100.0', '100.00'] as $amount) {
    $signature = md5('7012:'.$amount.':notification-secret-value:'.$uuid);
    $body = http_build_query(['MERCHANT_ID' => '7012', 'AMOUNT' => $amount, 'intid' => '77788',
        'MERCHANT_ORDER_ID' => $uuid, 'CUR_ID' => '6', 'SIGN' => $signature], '', '&', PHP_QUERY_RFC3986);
    $callback = new ProviderCallback($body, [], '');
    $parsed = FreeKassaSignedNotification::verify($callback, '7012', $credentials['secret_2']);
    ensure($parsed->amountMinor === 10000 && $parsed->operationId === '77788', 'normalized signed minor units');
    ensure($provider->referenceFromCallback($callback) === $uuid, 'routing hint');
    rejects(fn () => FreeKassaSignedNotification::verify(new ProviderCallback($body.'&AMOUNT=1', [], ''), '7012', $credentials['secret_2']), 'duplicate amount');
    rejects(fn () => FreeKassaSignedNotification::verify(new ProviderCallback($body.'&AMOUNT%5B%5D=1', [], ''), '7012', $credentials['secret_2']), 'array injection');
    rejects(fn () => FreeKassaSignedNotification::verify(new ProviderCallback(str_replace('SIGN=', 'SIGN=0000', $body), [], ''), '7012', $credentials['secret_2']), 'tampered sign');
    rejects(fn () => FreeKassaSignedNotification::verify(new ProviderCallback($body, [], 'foo=1'), '7012', $credentials['secret_2']), 'query smuggling');
}
rejects(fn () => FreeKassaSignedNotification::parseAmountMinor('100.001'), 'excess precision');
rejects(fn () => FreeKassaSignedNotification::parseAmountMinor('1e2'), 'scientific notation');
ensure($provider->supportsTestMode() && count($provider->supportedCurrencies()) === 5, 'currency list');
echo "FreeKassa standalone driver smoke tests: OK\n";
