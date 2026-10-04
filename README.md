# ctnlist 5.0.1 restored

ctnlist is a web-based mailing-list application created by Richard Royston Catto in 2009. Version 5.0.1 is an incremental modernisation of the working v5.0 application, not a replacement of its established workflows.

## Retained v5.0.1 upgrades

- PHP 8.4+, Fat-Free Framework 3.9 and Composer
- PostgreSQL with versioned Phinx migrations
- Symfony Mailer behind the application-owned `mailer` wrapper
- permanent RFC 9562 UUIDv7 subscriber identifiers
- `subscribers` as the canonical identity table
- passwordless one-time email authentication
- application-owned database sessions
- multiple mailing lists and per-list consent
- immutable `ALL` system list as an optional audience
- roles, permissions and ACL
- CSRF protection for state-changing actions
- separate PostgreSQL global email/domain suppression database

## Restored v5 functionality

### Message and template workflow

Messages and templates can be saved as incomplete drafts. A message can have no lists, one list or several lists. The system stores exactly the lists selected by the administrator and never silently attaches `ALL`.

The message list again provides the operational controls used by v5:

- edit message
- inspect message activity
- add a message to the queue
- process that message's queue
- send a proof/test copy
- review sent, queued, read, like and dislike statistics

### Queue and campaign delivery

The delivery system restores:

- multi-list audience union with one queue row per subscriber/message
- engaged-subscriber priority ordering
- ordinary and advanced queueing
- per-message maximum-send handling
- queue inspection, deletion, clearing, stopping and processing
- SMTP batching, throttling, retry and failover
- archive creation when a campaign is first queued
- queue-time subscriber state updates

### Once-only delivery and `smlog`

The ordinary campaign workflow sends a message once to a subscriber. The unique `(subscriber UUID, message MUID)` record in `smlog` is used as the delivery guard.

All campaign-content paths—including queue delivery, forwarding, proof copies and deliberate resends—use the campaign mail path and update both `sendlog` and `smlog`.

`smlog` again records list context, sent/read activity, likes, dislikes, forwards, profile updates, confirmations, subscriptions, unsubscriptions and bookings/contact activity.

### Transactional email and Send Log

Subscriber state-changing actions again send transactional notifications. The mailer wrapper records every successful email handoff in `sendlog`, including:

- campaign messages
- magic login links
- list confirmation invitations
- subscription confirmations
- unsubscription notifications
- profile-update notifications
- forwarded campaign copies
- forwarding notifications
- proof and resend copies
- contact/order acknowledgements

### Subscriber administration and integrations

Restored administrator workflows include:

- subscriber search, filters and pagination
- active-reader views
- per-list membership display and consent management
- bulk subscribe and unsubscribe
- file import
- active/removal exports
- cross-installation synchronisation
- Ecwid subscription endpoint
- contextual contact/order forms

### Archives, reports and logs

The tree includes:

- immutable campaign archives and archive forwarding
- message activity reports with search and pagination
- Send Log reports and filtering
- Site Log recording plus an administrator search/filter frontend
- roles and ACL administration

## Consent model

A subscriber identity does not itself grant bulk-mail consent. A subscriber is eligible for a selected list only when its `list_subscribers` record has:

```text
ls_confirmed = TRUE
ls_unsubscribed = FALSE
```

A message can be saved without any audience. `ALL` is available for general campaigns but is not mandatory.

## Configuration

Per-installation settings are read from `.env` outside the public web directory. Start with `.env.example`.

The main application uses `DB_*` settings. The separate global suppression database uses `GDB_*` settings in an externally managed `ban.env`. Set `GDB_ENV_DIRECTORY` and, when necessary, `GDB_ENV_FILE` in the installation `.env`; the same variables can be exported when running the banlist Phinx configuration.

SMTP can be configured with a single `MAILER_DSN` or an optional `MAIL_SMTP_SERVERS_JSON` array for per-server batching, delay, rate and failover. Optional legacy synchronisation targets use `SYNC_DATABASES_JSON`.

## Database setup

The v5.0.1 schema is managed by Phinx as a single migration, `20260716120000_create_phase3a_schema.php`. ctnlist is not yet in production, so schema changes are made in that migration and the development database is rebuilt; there are no upgrade migrations. The package includes destructive reset scripts for disposable development databases:

```text
database/reset-development.sql
database/reset-banlist-development.sql
```

Do not use those reset scripts for a database whose contents must be preserved.

## Validation status

The complete PHP source tree is syntax checked during packaging. Live PostgreSQL migration, SMTP-delivery and browser workflow testing must be performed in the target installation because those external services are not available in the build environment.

## Licence

ctnlist is released under the MIT Licence.

Copyright (c) 2009-2026 Richard Royston Catto
