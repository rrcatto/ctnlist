# ctnlist 5.0.3

ctnlist is a web-based mailing-list application created by Richard Royston Catto in 2009. Version 5.0.x is an incremental modernisation of the working v5.0 application, not a replacement of its established workflows.

Versions 5.0.2 and later keep the behaviour of 5.0.1-restored and move the application from the Fat-Free Framework to Symfony 8.1. The migration is in progress: Symfony is the front controller and owns sessions, authentication, CSRF, the page layout, errors and responses, while routes not yet ported still run on Fat-Free through a temporary bridge (see [Migration status](#migration-status)).

Version 5.0.3 moves sign-in, logout, permission checks and CSRF to Symfony Security, renders every page in a single Twig layout, and ports the home, privacy and store pages.

## Platform and upgrades

- PHP 8.4.1+ (developed on 8.5), Composer
- Symfony 8.1 (including Symfony Security and Twig) with Doctrine DBAL 4; Fat-Free Framework 3.9 for the routes not yet ported
- PostgreSQL with versioned Phinx migrations
- Symfony Mailer behind the application-owned `mailer` wrapper
- permanent RFC 9562 UUIDv7 subscriber identifiers
- `subscribers` as the canonical identity table
- passwordless one-time email authentication (Symfony Security from 5.0.3)
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

## Directory layout

```text
bin/            console (Symfony), dev (podman development environment)
config/         Symfony configuration; phinx/ (migration configs, default ban.env location); legacy/ (F3 design.ini)
database/       Phinx migrations (domain/, banlist/), destructive development reset scripts
public_html/    per-installation front controller (index.php), css/, js/
src/            application code, namespace App\ (src/Legacy/ holds the Fat-Free code still to be ported)
templates/      Twig templates (base.html.twig is the site layout; legacy/page.html.twig wraps HTML from unported routes)
tests/          PHPUnit (tests/Smoke: route smoke suite)
dev/podman/     development container files
```

## Deployment

The repository is a shared code tree installed once per version at `/usr/local/lib/php/ctnlist/5.0.3/` (the path is set in `public_html/index.php`). Run `composer install --no-dev` there.

Each installation has its own directory containing:

- `public_html/`: a copy of the repository's `public_html/`, used as the web server's document root. nginx serves `css/` and `js/` directly and passes everything else to `index.php` (see `dev/podman/nginx.conf`);
- `.env`, one level above `public_html/` (start from `.env.example`);
- writable `var/` (Symfony cache and logs, compiled templates) and `logs/` directories.

The compiled Symfony container embeds absolute paths, so clear each installation's cache after deploying a new version (or empty its `var/cache/`).

Console commands and migrations act on one installation, selected with `CTNLIST_INSTANCE_DIR`:

```bash
CTNLIST_INSTANCE_DIR=/var/www/example bin/console cache:clear
CTNLIST_INSTANCE_DIR=/var/www/example composer migrate-paralegal
```

## Configuration

Per-installation settings are read from `.env` outside the public web directory. Start with `.env.example`. `APP_ENV` is `prod`, `dev` or `test`. `TRUSTED_PROXIES` lists reverse proxies whose `X-Forwarded-*` headers are trusted.

The main application uses `DB_*` settings. The separate global suppression database uses `GDB_*` settings in an externally managed `ban.env`. Set `GDB_ENV_DIRECTORY` and, when necessary, `GDB_ENV_FILE` in the installation `.env` (default: `config/phinx/ban.env` in the shared tree); the same variables can be exported when running the banlist Phinx configuration. `SUPPRESSION_PROVIDER=none` disables the suppression database and is only accepted with `APP_ENV=dev`.

SMTP can be configured with a single `MAILER_DSN` or an optional `MAIL_SMTP_SERVERS_JSON` array for per-server batching, delay, rate and failover. Optional legacy synchronisation targets use `SYNC_DATABASES_JSON`.

## Database setup

The schema is managed by Phinx as a single migration, `20260716120000_create_phase3a_schema.php`. ctnlist is not yet in production, so schema changes are made in that migration and the development database is rebuilt; there are no upgrade migrations. The package includes destructive reset scripts for disposable development databases:

```text
database/reset-development.sql
database/reset-banlist-development.sql
```

Do not use those reset scripts for a database whose contents must be preserved.

## Development environment

`bin/dev` runs a podman-compose stack that mirrors the production layout: nginx, PHP 8.5-FPM, PostgreSQL 16 and Mailpit.

```bash
bin/dev up          # build and start, composer install, migrations, seed admin@ctnlist.test
bin/dev help        # all commands
```

The application is at http://localhost:8180. All outgoing mail is captured by Mailpit at http://localhost:8125: sign in as `admin@ctnlist.test` and follow the magic link it receives. The development instance runs without a banlist database (`SUPPRESSION_PROVIDER=none`).

## Testing and validation

```bash
bin/dev lint        # php -l over public_html/index.php, src/ and tests/
bin/dev analyse     # PHPStan
bin/dev test        # PHPUnit
```

`tests/Smoke/RouteSmokeTest` requests every GET route of the running stack over HTTP, anonymously and as the development administrator, and checks status codes, login redirects, access control and PHP error output. It is the route-parity check for the Symfony migration. `tests/Smoke/AuthFlowTest` covers sign-in links, auth cookies, logout and CSRF, and `tests/Smoke/LayoutTest` the site layout and error pages; `tests/Unit` holds unit tests.

The banlist suppression database and real SMTP delivery are not part of the development stack and must be tested on a target installation.

## Migration status

The move from Fat-Free to Symfony proceeds in phases; each leaves a working application with lint, PHPStan and the smoke suite passing.

| Phase | Status |
|---|---|
| 0. Route smoke suite and PHPStan baseline | done |
| 1. Directory layout (`public_html/`, `src/`, `templates/`, `config/`) | done |
| 2. Symfony kernel as front controller, Fat-Free bridge, container-built legacy services | done |
| 3. Authentication and security (Symfony Security, magic-link authenticator, ACL voter, CSRF) | done |
| 4. Twig layout and simple pages | done |
| 5. Lists, roles and ACL administration | next |
| 6. Campaign core services on DBAL repositories | |
| 7. Subscriber-facing routes | |
| 8. Administrator campaign routes | |
| 9. Reports | |
| 10. Remove Fat-Free | |

## Licence

ctnlist is released under the MIT Licence.

Copyright (c) 2009-2026 Richard Royston Catto
