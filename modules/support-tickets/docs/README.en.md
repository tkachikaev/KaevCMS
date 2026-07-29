# Technical Support module

## Purpose

The module adds private player tickets and a dedicated administration interface. All data is stored in the main KaevCMS database. LoginServer and GameServer databases are not used.

## Player features

- create a ticket;
- fixed categories: Gameplay, Game account, Technical problem, Website error, Donations and bonuses, Complaint, Other;
- “My tickets” list;
- private conversation history;
- close an own ticket;
- read a closed ticket without replying or reopening it.

Player-facing statuses:

- **New** — the ticket was created;
- **In progress** — staff accepted it or the player sent a new reply;
- **Awaiting your reply** — staff replied to the player;
- **Closed** — the conversation is complete.

## Staff features

- Owner and Administrator can view and process tickets;
- Auditor has read-only access;
- Editor receives access only when “Allow editors to process tickets” is enabled;
- assign a ticket to self;
- public player replies;
- staff-only internal notes;
- close and reopen tickets;
- status, category and assignment filters;
- search by number, subject, player name or email;
- edit only the staff member’s own messages;
- preserve the previous body, editor and edit timestamp for every revision.

Enabling Editor access does not grant global module-management permissions and does not allow Editors to change support settings.

## Limits

- subject: 3–120 characters;
- first message: 3–3000 characters;
- replies and internal notes: up to 2000 characters;
- at most 5 simultaneously open tickets per player;
- identical messages within one minute are rejected;
- ticket creation: at most 3 per 10 minutes;
- player replies: at most 10 per minute;
- staff messages: at most 30 per minute.

Limits are enforced by both browser fields and server-side validation.

## Installation and updates

The module is bundled with KaevCMS. The Owner enables it from Modules. On first enable KaevCMS applies the module migrations and records their SHA256 hashes. Applied migrations must not be modified or removed.

Disabling the module removes its routes and navigation but preserves tickets, messages, notes and revision history.

## Module artwork

Place the prepared image at:

```text
modules/support-tickets/assets/module.webp
```

Requirements: WebP, 512×512, no larger than 2 MB. No manifest field is required.

## Tables

- `module_support_ticket_settings`;
- `module_support_tickets`;
- `module_support_ticket_messages`;
- `module_support_ticket_message_revisions`.

Messages are not silently replaced. Players cannot see internal notes or previous revisions of staff replies.

## Developer checks

```powershell
.\deployment\windows\quality.ps1
.\deployment\windows\browser-quality.ps1
```

Module tests:

```text
tests/Feature/Modules/SupportTicketsModuleTest.php
tests/Unit/ModuleAdminAccessRegistryTest.php
tests/browser/specs/support-tickets.spec.mjs
```
