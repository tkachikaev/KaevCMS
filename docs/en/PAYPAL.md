# PayPal Orders v2 — KaevCMS setup

> **Version 0.55.11 (Donations 1.17.11) is a QA candidate.** The Live code is implemented, but production acceptance requires the full test suite, a real Sandbox transaction, and an explicitly approved small Live payment.

PayPal is an independent Donations provider for server owners using **their own PayPal Business account**. KaevCMS is not an intermediary, processor or payee. Purchased coins are credited to the CMS Wallet, not directly to game characters.

## 1. Prepare the account

1. Host KaevCMS at a publicly reachable **HTTPS** address with **MySQL/MariaDB** and enable Donations.
2. Configure the required currency and coin price under **Donations → Currencies and prices**. The PayPal currency selector offers only codes supported by both the CMS and PayPal with compatible decimal precision.
3. In [PayPal Developer Dashboard](https://developer.paypal.com/dashboard/applications/), create or select separate **Sandbox** and **Live** REST applications. Collect their respective **Client ID** and **Client Secret** plus the Live payee **Merchant ID (PayPal account ID)**.
4. Go to **Donations → Payment systems → PayPal** and save Sandbox and Live credentials independently. Enable only the currencies you intend to support. Secrets are encrypted and are never displayed again. No PayPal `.env` switches are required.

## 2. Set up webhooks

1. Copy the webhook **URL** displayed in the PayPal admin card into the webhook registration for the appropriate environment in PayPal Developer Dashboard.
2. Subscribe, where available for your app, to `CHECKOUT.ORDER.APPROVED`, `CHECKOUT.PAYMENT-APPROVAL.REVERSED`, `PAYMENT.CAPTURE.PENDING`, `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.DENIED`, `PAYMENT.CAPTURE.REFUNDED`, `PAYMENT.CAPTURE.REVERSED`, and `CUSTOMER.DISPUTE.CREATED`. Verify actual availability in the Dashboard and official documentation.
3. Copy the generated **Webhook ID** (not the webhook URL) into the matching Sandbox or Live settings in KaevCMS.

The endpoint accepts HTTPS POST JSON only, enforces a 64 KiB limit and uses PayPal REST signature verification with the original event body. Even a valid signed webhook **cannot credit the wallet by itself**: the server also independently verifies the completed Order and Capture.

## 3. Admin-only Sandbox purchase

1. Use **Check credentials** for Sandbox. An OAuth success verifies API access only, not webhook delivery or Live merchant eligibility.
2. Select a supported currency and amount under **Sandbox**, then initiate the test purchase.
3. Approve it with a **Sandbox buyer account**, separate from the Sandbox merchant account, and return to the CMS.
4. Perform the separate administrative **Capture** and verify that the attempt becomes `sandbox_verified`. Confirm that the Wallet balance and credit history are unchanged.
5. Check that a real Sandbox webhook is delivered and handled. A simulated webhook event is not a substitute for actual delivery and signature testing.

This feature is restricted to Owner/Administrator. Sandbox payments are never visible to players and never credit Wallet.

## 4. Live activation and control transaction

Before enabling Live, run PHPUnit/Feature, MySQL/MariaDB migration and concurrency checks, legacy-provider regressions, PHPStan/Pint, and browser tests. An explicitly approved **real control payment** by the CMS owner is required before declaring production readiness.

After acceptance, enter **Live Client ID, Client Secret, Merchant ID and Webhook ID**, choose supported currencies, and enable **PayPal Live**. Confirm that the coin rate is enabled and Donations accepts payments for **all players**. PayPal does not support the legacy single-player pilot mode.

Players approve the purchase on PayPal's hosted page. **A browser return is not proof of payment.** After the server verifies `COMPLETED`, the merchant ID, gross amount, currency and saved order/capture identifiers, Wallet receives exactly one credit, including under webhook replay.

## 5. Unknown outcomes, refunds and disputes

- For an uncertain Capture, the order may show `requires_review`. Use **Check with PayPal API** in the admin order card: this performs GET-only lookups with saved IDs, never another Capture POST. If proof remains ambiguous, check PayPal Business directly.
- Verified refund/reversal/dispute notices are linked to a **previously credited** order and original Capture. The order card shows an informational warning and the existing Notification Center sends one deduplicated notice to Owner/Administrator.
- **No automatic refunds, Wallet debits, player bans or dispute resolution workflows exist.** The original credit and paid-order status remain intact; administrators handle the matter outside KaevCMS.
- Rotating merchant credentials may make verification of older attempts unavailable without their original configuration. Do not rotate live keys in the middle of active purchases without a recovery plan.

## 6. Troubleshooting

Check public HTTPS and webhook routing; environment-specific keys and webhook IDs; recipient Merchant ID; currency rate; subscribed event types and actual deliveries in PayPal Developer Dashboard; administrator permissions; PayPal REST availability. Never disable signature checks or credit a payment based on a browser redirect or unverified PayPal message. Keep secrets out of logs and reports.

**Official references:** [REST Orders v2](https://developer.paypal.com/api/rest/integration/orders-api), [webhook subscriptions](https://developer.paypal.com/payment-methods/webhooks/), [Sandbox testing](https://developer.paypal.com/sandbox-testing/accounts).

Merchants are responsible for verifying that their PayPal account, country and applicable PayPal policies allow payments for virtual game coins.
