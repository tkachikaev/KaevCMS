# Donations 1.17.7

YooMoney setup uses one administration screen: wallet number, notification secret and a single enable/disable control. Players can create an order and continue directly to YooMoney, without test-draft or provider-selection steps when one live provider is available. Separate provider settings and signed settlement/Wallet APIs remain intact.

Requires a publicly reachable HTTPS callback for genuine payments. See `docs/ru/DONATIONS.md` and `docs/en/DONATIONS.md`.

Optional Game Coin Exchange: configure per-server item ID, fixed integer exchange ratio and enable toggle under Donations → Transfer coins to game. Player debits CMS Wallet and receives an item in Web Inventory atomically. No direct game DB writes.

## PayPal Orders v2 (iteration 2; inactive)

The REST client (`PayPalOrdersApi`) and transaction-safe orchestration (`PayPalOrderOperations`) exist as isolated internal services. PayPal is **not** registered in the player provider registry and has no admin credentials page, webhook or settlement. No payment can be started from the CMS in this release.

Implementations must provide a `PayPalApiCredentials(environment, clientId, clientSecret)` object from authenticated, encrypted admin settings in iteration 3. Do not put keys in `.env`, logs, templates or client-side JavaScript. Stable merchant identities derive from environment + client ID; OAuth token cache additionally includes the secret. The no-user Sandbox attempt remains explicitly isolated. Never run any PayPal HTTP method inside a database transaction. Create timeout retries use one persisted request ID for at most the documented 6-hour window; unknown Capture must be resolved by GET, not by a second POST. Even `capture_completed_unsettled` is **not** proof to credit Wallet until iteration 4 server-side checks and settlement exist.

Contract test: `php artisan test --filter=DonationPayPalOrdersApiTest`. Pure parser tests (without vendor): `php -d zend.assertions=1 -d assert.exception=1 tests/Integration/Donations/paypal-orders-v2-standalone.php`.
