# Daily Rewards module

The bundled `daily-rewards` module adds monthly reward calendars for KaevCMS game servers. It is disabled after a clean installation and can be enabled by the owner under **Modules**.

## Calendar behavior

- Every game server and calendar month has its own calendar.
- KaevCMS creates 28, 29, 30, or 31 day records for the selected month.
- A day may contain up to 100 distinct item rewards, each with an item ID and amount.
- A new calendar is always created disabled. Configure rewards before enabling it.
- A reward may be claimed only on its actual calendar day using the time zone from the main KaevCMS settings. Missed days expire.
- Every eligible game account may claim one day only once.
- Items are added to the selected game server Web Inventory. After a claim, a result dialog shows the exact item names, icons, and amounts and links directly to the Web Inventory.

## Administrator setup

1. Open **Modules** and enable **Daily Rewards**.
2. Open the new **Daily rewards** administration section.
3. Select **Create calendar**.
4. Select a game server, year, and month. The time zone is read automatically from the main KaevCMS settings.
5. Click a day in the visual month grid. A modal editor opens with item names, available icons, IDs, and amounts.
6. Enable the day, add or remove `Item ID` + `Amount` rows, then close the day dialog.
7. Use **Copy previous day** and **Fill empty days with this reward** for faster setup, then save and enable the calendar.

Unknown item IDs may be saved for custom server items. When the item catalogue knows an ID, the localized name and available icon are displayed.

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
