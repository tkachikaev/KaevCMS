# Donations 1.13.0

Independent KaevCMS module: per-currency coin prices, orders, YooMoney checkout and verified Wallet settlement. Configured by Owner/Administrator exclusively via **Donations → Payment systems → YooMoney**. Default activation is **off**. Pilot restricts checkout to one active CMS player, live enables all eligible players. No donation-specific .env editing.

Signed callbacks remain available independently of payment mode and module UI, so disabling sales does not discard already submitted payments. Automatic Wallet credit uses verified signed events only, transactionally and idempotently. Invalid, conflicting or test notifications cannot credit. Payment credentials are encrypted under APP_KEY.

See `docs/ru/DONATIONS.md` and `docs/en/DONATIONS.md`. Local readiness does not prove a real external transfer. Confirm merchant eligibility and one small live payment before public rollout.
