<?php

/** No-vendor smoke checks for I3's isolated admin currencies and sandbox-only guard. */
$root = dirname(__DIR__, 3);
require_once $root.'/modules/donations/src/Services/PayPalMoney.php';
require_once $root.'/modules/donations/src/Services/PayPalAdminSettings.php';
require_once $root.'/modules/donations/src/Services/PayPalAdminTestPayments.php';

use KaevCMS\Modules\Donations\Services\PayPalAdminSettings;
use KaevCMS\Modules\Donations\Services\PayPalMoney;

$reflection = new ReflectionClass(PayPalAdminSettings::class);
$codes = $reflection->getReflectionConstant('PAYPAL_CURRENCIES')->getValue();
assert(in_array('USD', $codes, true) && in_array('JPY', $codes, true));
assert(! in_array('RUB', $codes, true));
assert(count($codes) === count(array_unique($codes)));
foreach (['JPY' => 0, 'USD' => 2] as $currency => $exponent) {
    PayPalMoney::assertExponent($currency, $exponent);
}
foreach (['HUF', 'TWD'] as $currency) {
    try {
        PayPalMoney::assertExponent($currency, 2);
        throw new RuntimeException('Unsafe PayPal decimals accepted.');
    } catch (InvalidArgumentException) {
        // Expected: excluded by precision intersection.
    }
}
$serviceSource = file_get_contents($root.'/modules/donations/src/Services/PayPalAdminTestPayments.php');
assert(str_contains($serviceSource, "'purpose' => 'admin_test'"));
assert(str_contains($serviceSource, "'environment' => 'sandbox'"));
assert(str_contains($serviceSource, "'order_id' => null, 'preparation_id' => null"));
assert(! str_contains($serviceSource, 'WalletGateway'));
$controllerSource = file_get_contents($root.'/app/Http/Controllers/Payments/PayPalNotificationPendingController.php');
assert(str_contains($controllerSource, "response('PayPal webhook verification not active', 503)"));
echo "PayPal I3 admin Sandbox contracts OK\n";
