# YooMoney in KaevCMS 0.50.11 / Donations 1.13.0

## Configure everything from the administration panel

1. Open **Donations → Currencies and prices**, enable a RUB price per coin and display top-ups in the player account.
2. Open **Donations → Payment systems → YooMoney**; enter your wallet and notification secret, enable the provider in **live** mode with RUB.
3. In your YooMoney account configure HTTP notifications to the HTTPS callback address shown in the CMS (`/payments/yoomoney/notify`). The CMS cannot configure the external provider account.
4. On the same admin page choose **Pilot payment**, search for an active CMS player and save. Only this player can see the real payment form. **No editing .env, PHP or running Artisan commands.**
5. Make one small genuine transfer and confirm funds in YooMoney, `credited` status in CMS, a single Wallet credit and no duplicates on webhook replay.
6. Once acceptance is complete, select **Live — all players**.

New installations and upgrades start **off**. Switching off new sales from the administration panel never disables the independent signed callback and settlement path for in-flight payments. When the CMS is unavailable an unrecorded callback may require later delivery or manual investigation.

Enabling live requires production without debugging, public HTTPS, MySQL/MariaDB, enabled Donations player UI, a valid enabled RUB tariff and an enabled live YooMoney provider with valid credentials. Readiness is a local check only; it neither makes an API call nor proves an external transfer.

Only authenticated HMAC-SHA256 notifications can credit the Wallet after immutable order, currency and gross `withdraw_amount` validation. `amount` records the net merchant receipt; duplicate notifications never cause a second credit. Test notifications are ineligible for live credits. Preserve the site's APP_KEY for encryption continuity.

The operator is responsible for verifying whether their personal YooMoney wallet terms permit collecting payments for game currency; YooMoney and YooKassa are different products.
