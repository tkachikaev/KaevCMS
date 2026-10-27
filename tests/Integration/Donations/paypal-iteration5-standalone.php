<?php

/** Source-level protection checks. Not a replacement for PHPUnit + MySQL testing. */
$root = dirname(__DIR__, 3);
$webhooks = file_get_contents($root.'/modules/donations/src/Services/PayPalWebhooks.php');
$recovery = file_get_contents($root.'/modules/donations/src/Services/PayPalManualReconciliation.php');
$view = file_get_contents($root.'/modules/donations/resources/views/admin/order.blade.php');
$routes = file_get_contents($root.'/modules/donations/routes/admin.php');
$checks = [
    'read_only_recovery' => str_contains($recovery, '->showOrder(') && ! str_contains($recovery, '->captureOrder(') && ! str_contains($recovery, '->capture('),
    'safe_settlement' => str_contains($recovery, '$this->payments->reconcile('),
    'unknown_capture_requires_review' => str_contains($recovery, "'capture_unknown'") && str_contains($recovery, "'requires_review'"),
    'owner_admin_notice' => str_contains($webhooks, 'AdminRole::Owner') && str_contains($webhooks, 'AdminRole::Administrator'),
    'only_paid_capture_warning' => str_contains($webhooks, "->where('status', 'credited')") && str_contains($webhooks, "->where('provider_payment_id', ".'$captureId)'),
    'never_wallet_debit' => ! str_contains($webhooks, 'WalletGateway') && ! str_contains($recovery, '->debit('),
    'no_refund_api' => ! str_contains($webhooks, 'refundOrder') && ! str_contains($recovery, 'refundOrder'),
    'admin_post_csrf' => str_contains($view, '@csrf') && str_contains($routes, 'paypal-recovery'),
    'order_warning' => str_contains($view, 'data-testid="paypal-notice"'),
    'notification_dedup' => str_contains($webhooks, 'notifyOnce(') && str_contains($webhooks, 'donations:paypal:notice:'),
];
foreach ($checks as $name => $passed) {
    if (! $passed) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }
    echo "OK: $name\n";
}
