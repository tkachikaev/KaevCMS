# Technical Support module

## Purpose

The module adds private player tickets and a dedicated administration interface. All records are stored in the main KaevCMS database. LoginServer and GameServer databases are not used.

## Player features

- open the “My tickets” list first;
- reveal the new-ticket form only after pressing “Create ticket”;
- create a ticket using an approved category;
- use the private conversation without full-page reloads;
- New, In progress, Awaiting your reply and Closed statuses;
- close an own ticket;
- keep reading a closed ticket without reopening it.

Categories: gameplay, game account, technical problem, website error, donations and bonuses, complaint and other.

## Staff features

Owner and Administrator can view and process every ticket. Auditor remains read-only.

Editor access is configured separately for:

- ticket viewing;
- replies, assignment and status actions;
- internal notes.

Editors never receive global module-management permissions and cannot change support settings. Reply and note permissions are automatically disabled when viewing is disabled.

Staff can edit only their own replies or notes. The previous body, editor and edit time are preserved. Replies, assignment, close/reopen actions, edits and internal notes use Livewire without full-page reloads. The internal-note composer is collapsed by default and uses a compact expandable panel.

## Status labels

Players see New, In progress, Awaiting your reply and Closed. In the administration panel, `in_progress` is rendered as “Awaiting your reply”. A red badge next to “Technical Support” counts new tickets and conversations requiring staff action; it is hidden at zero and displays `99+` above 99. After a staff reply, the player sees “Awaiting your reply” while staff sees “Awaiting player reply”.

## Limits

Owner configures the limits in module settings. Secure defaults are:

- subject: 3–120 characters;
- first message: 3–3000 characters;
- reply or note: up to 2000 characters;
- at most 5 simultaneously open tickets;
- at most 10 new tickets per day;
- at most 100 player messages per day;
- at most 300 messages in one ticket;
- at most 20 stored revisions of one message.

Allowed ranges:

- new tickets per day: 1–50;
- player messages per day: 10–1000;
- messages per ticket: 20–2000;
- revisions per message: 1–100;
- simultaneously open tickets: 1–50;
- subject maximum length: 30–120;
- first-message maximum length: 300–10,000;
- reply or note maximum length: 100–10,000.

Identical messages within one minute are rejected and route throttles provide an additional request-rate limit. Every value is enforced server-side and the interface uses the same limits for field attributes and character counters. Only Owner can change these settings.

## Administration interface

The ticket catalogue uses the same compact filter bar as the Users section. On a ticket page, the conversation stays in the main column while player data, category, assignment and timestamps are shown in a dedicated right-side panel.

Owner settings are split into tabs:

- “General settings” — editor permissions, protective limits and retention period;
- “Database cleanup” — deletion preview and manual cleanup actions.

The save action belongs only to the general form and appears after all of its sections.

## Pagination

The conversation is contained in a dedicated scrollable panel. The latest 50 messages are shown first and older history is loaded with “Show previous messages”. Internal notes are never included in player queries.

## Retention and cleanup

Closed tickets are kept for 24 months by default. Owner can select 6, 12, 24 or 36 months, or keep them forever.

Cleanup:

- never removes active tickets;
- preserves tickets marked “Keep indefinitely”;
- deletes in batches of 100 tickets;
- cascades to messages, internal notes and revisions;
- stops without deleting data when settings cannot be read;
- can run automatically once per day;
- provides a dry-run preview.

Commands:

```bash
php artisan kaevcms:support-tickets-cleanup --dry-run
php artisan kaevcms:support-tickets-cleanup
php artisan kaevcms:support-tickets-cleanup --batch=100
```

SQLite can be rebuilt manually during maintenance and after a verified backup:

```bash
php artisan kaevcms:support-tickets-cleanup --vacuum
```

Normal deletes make pages reusable but may not immediately shrink the SQLite file. `VACUUM` rebuilds the file and can temporarily block writes. Scheduled cleanup never runs `VACUUM`.

## Installation and updates

Module version 1.5.2 requires KaevCMS 0.44.28 or newer. After updating the CMS, approve the module update from the administration Modules section. Version 1.5.2 adds no migration. It hides the administration badge at zero, restricts support text areas to vertical resizing, and replaces the large edit link with a compact accessible pencil button. The existing 1.5.0 migration still safely creates a missing settings row and never overwrites Owner changes.

The module is bundled with KaevCMS. Module migrations are applied with SHA256 tracking. Applied migrations must not be modified or removed. Disabling the module hides routes and navigation without deleting data.

## Module artwork

The bundled module image is stored at the shared module path:

```text
modules/support-tickets/assets/module.webp
```

The file is WebP, 512×512 and no larger than 2 MB. KaevCMS validates it before display; no `module.json` field is required.

## Tables

- `module_support_ticket_settings`;
- `module_support_tickets`;
- `module_support_ticket_messages`;
- `module_support_ticket_message_revisions`.

## Checks

```powershell
.\deployment\windows\quality.ps1
.\deployment\windows\browser-quality.ps1
```

Primary tests:

```text
tests/Feature/Modules/SupportTicketsModuleTest.php
tests/Unit/ModuleAdminAccessRegistryTest.php
tests/browser/specs/support-tickets.spec.mjs
```

Administration Livewire actions use the shared `ModuleAdminAuthorizer`; the normal registered route name and `/livewire/update` use the same access decision.
