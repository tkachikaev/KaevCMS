# Donations 1.16.2

YooMoney setup uses one administration screen: wallet number, notification secret and a single enable/disable control. Players can create an order and continue directly to YooMoney, without test-draft or provider-selection steps when one live provider is available. Separate provider settings and signed settlement/Wallet APIs remain intact.

Requires a publicly reachable HTTPS callback for genuine payments. See `docs/ru/DONATIONS.md` and `docs/en/DONATIONS.md`.

Optional Game Coin Exchange: configure per-server item ID, fixed integer exchange ratio and enable toggle under Donations → Transfer coins to game. Player debits CMS Wallet and receives an item in Web Inventory atomically. No direct game DB writes.
