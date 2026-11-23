# Daily Rewards module

The bundled `daily-rewards` module adds monthly reward calendars for KaevCMS game servers. It is disabled after a clean installation and can be enabled by the owner under **Modules**.

## Calendar behavior

- Every game server and calendar month has its own calendar.
- KaevCMS creates 28, 29, 30, or 31 day records for the selected month.
- A day may contain up to 100 distinct item rewards, each with an item ID and amount.
- A new calendar is always created disabled. Configure rewards before enabling it.
- A reward may be claimed only on its actual calendar day using the time zone from the main KaevCMS settings. Missed days expire.
- How many times a day reward can be claimed is set by the calendar's **Reward claim limit** (see below).
- Only active game accounts can claim: accounts that are still being created or failed to be created are not eligible.
- Items are added to the selected game server Web Inventory. After a claim, a result dialog shows the exact item names, icons, and amounts and links directly to the Web Inventory.

## Administrator setup

1. Open **Modules** and enable **Daily Rewards**.
2. Open the new **Daily rewards** administration section.
3. Select **Create calendar**.
4. Select a game server, year, month, and reward claim limit. The time zone is read automatically from the main KaevCMS settings.
5. Click a day in the visual month grid. A modal editor opens with item names, available icons, IDs, and amounts.
6. Enable the day, add or remove `Item ID` + `Amount` rows, then select **Apply**. The dialog updates the visual calendar but does not save the whole month yet.
7. Use **Copy previous day** and **Fill empty days with this reward** for faster setup. Bulk filling requires confirmation. An unsaved-changes badge remains visible until **Save calendar** is selected.

Unknown item IDs may be saved for custom server items. When the item catalogue knows an ID, the localized name and available icon are displayed.

## Reward claim limit

Every calendar has a **Reward claim limit** setting:

- **Once per day for each site user**: a day reward can be claimed once with any one of the user's game accounts. After it is claimed with one account, the day shows as claimed on the user's other accounts, with the account that claimed it. The limit applies per calendar, that is per game server: a LoginServer shared by several game servers does not merge their limits. This is the default for new calendars.
- **Once per day for each game account**: every account of the user may claim the day reward. This is how all calendars worked before module version 1.4.0; existing calendars keep this mode after the update.

The limit can be changed while editing the calendar, but not on a day when someone has already claimed from that calendar: the change is refused and can be made the next day. Changing the mode does not alter the history of rewards already granted.

Both modes are checked on the server in one transaction that locks the calendar, and are also backed by database unique indexes, so parallel requests from different accounts cannot bypass the limit.

## Immutable history

After the first claim:

- the claimed day reward cannot be changed;
- the journal preserves a snapshot of the items actually granted;
- disabling the calendar or module does not delete history or Web Inventory items.

Repeated submissions are protected by a request UUID, a transaction, and a database unique constraint.

## Permissions

- The owner manages calendars and module state.
- An administrator with module-view access may inspect calendars and the journal in read-only mode.
- A player sees the section only while the module is enabled and may claim only for owned eligible game accounts on the matching LoginServer.

## Disabling

Disabling the module removes its pages and navigation entries. Calendar, day, item, and claim tables remain intact and are reused when the module is enabled again.
