# ctnlist 6.0.2

ctnlist is a web-based mailing-list application created by Richard Royston Catto in 2009. Versions 5.0.x and 6.0.x are an incremental modernisation of the working v5.0 application, not a replacement of its established workflows.

Versions 5.0.2 to 6.0 keep the behaviour of 5.0.1-restored and move the application from the Fat-Free Framework to Symfony 8.1. Version 6.0 completes the migration: every route is a Symfony controller and Fat-Free has been removed (see [Migration status](#migration-status)).

Version 5.0.3 moves sign-in, logout, permission checks and CSRF to Symfony Security, renders every page in a single Twig layout, and ports the home, privacy and store pages.

Version 5.0.4 ports list, role and ACL administration to Symfony and adds the Symfony campaign core: global suppression, the Send Log and message log, mail transports with failover, the transactional and campaign mailers, template rendering, archives, messages and the delivery queue. These services are covered by integration tests against a separate test database; the subscriber-facing and administrator routes move onto them in the next phases, so live campaign delivery still uses the Fat-Free code.

Version 5.0.5 moves every subscriber-facing page onto Symfony and the new services: sign-in link requests, the profile pages, the confirm/unsubscribe links and `/subscribe`, forwarding, likes and dislikes, resends, the open-tracking pixel, the archives and the contact form. Sign-in links, consent notifications, forwards, resends and contact acknowledgements are now sent through the Symfony mailers. Administration (subscribers, bulk operations, import/export, messages, templates, the queue) and the reports still run on Fat-Free and the legacy mailer.

Version 6.0 moves the administration pages (messages and templates, queueing, sending and proofs, subscribers, bulk operations, import, export, synchronisation and the Ecwid endpoint) and the Send Log, Site Log and message activity reports onto Symfony. All mail now goes through the Symfony mailers, the Fat-Free Framework, its bridge and the legacy code are removed, the Site Log again records every request, and the code passes PHPStan level 6. Import logs and export files are written to `APP_LOG_DIR` (default: the installation's `logs/`).

Version 6.0.1 removes the defunct Ecwid store (the Store page, its links, `POST /ecwid-subscribe` and `APP_STORE_URL`; a leftover `{STORE}` placeholder renders as nothing) and rebuilds the frontend on plain Bootstrap 5.3: a sticky header with an administration bar that shows only the tools each user's permissions allow, a simple footer, Bootstrap Icons and the system font. Unify, Font Awesome, jQuery and the Google Fonts request are gone; the application's CSS and JavaScript are served by Symfony AssetMapper, and CKEditor loads only on the editors. Every administration screen follows one pattern (page header with the main actions, filter panels, responsive tables, status badges, empty states, confirmation before deleting, clearing or suppressing). `/subscribe` is now a subscribe form for visitors and a list of the subscriber's lists when signed in, with list descriptions. Custom roles can be renamed, deleted and removed from subscribers, each role lists its members, subscribers are found by search, and nobody but an administrator can hand out permissions they do not hold. Proofs go to any address without a subscriber record, and their subscriber links explain that a proof has no subscriber. Sign-in links sent to confirm a list say so. A new Settings page lets administrators override selected `.env` settings, with SMTP secrets encrypted by `APP_SETTINGS_KEY` (see [Settings page](#settings-page)).

Version 6.0.2 arranges the Settings page as tabs, one per section, each with its own form and Save button; fields are laid out on a grid, the SMTP tab shows the main server and each failover server as its own block, and the URL (e.g. `/settings#smtp`) opens and returns to a tab.

## Platform and upgrades

- PHP 8.4.1+ (developed on 8.5), Composer
- Symfony 8.1 (including Symfony Security, Twig and Mailer) with Doctrine DBAL 4
- PostgreSQL with versioned Phinx migrations
- Symfony Mailer: the application's mail services (`src/Mail/`: transports with failover and throttling, transactional and campaign mailers) send all mail
- permanent RFC 9562 UUIDv7 subscriber identifiers
- `subscribers` as the canonical identity table
- passwordless one-time email authentication (Symfony Security from 5.0.3)
- application-owned database sessions
- multiple mailing lists and per-list consent
- immutable `ALL` system list as an optional audience
- a Settings page for overriding selected `.env` settings (site identity, contact details, mail sender, SMTP servers, contact form, subscription messages, archives, sign-in limits), with SMTP secrets encrypted by `APP_SETTINGS_KEY`
- roles, permissions and ACL: custom roles can be created, renamed, deleted and given permissions; subscribers are found by search to assign roles, and each role lists its members (`roles.manage` manages roles and membership, `acl.manage` additionally changes permissions; system roles are fixed)
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

All campaign-content paths—including queue delivery, forwarding, proof copies and deliberate resends—use the campaign mail path and update `sendlog`; deliveries to subscribers also update `smlog`. A proof can go to any valid address (by default `MAIL_TEST_ADDRESS`), which needs no subscriber record: it is logged as PROOF in `sendlog` only and does not affect the queue, consent, memberships or the once-only guard.

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
config/         Symfony configuration; phinx/ (migration configs, default ban.env location)
database/       Phinx migrations (domain/, banlist/), destructive development reset scripts
assets/         application CSS and JavaScript for Symfony AssetMapper (app.js, styles/app.css, ckeditor/ editor configuration, images/)
importmap.php   AssetMapper import map (entry point: assets/app.js)
public_html/    per-installation web root: front controller (index.php) and vendor/ckeditor5/ (prebuilt CKEditor 5 bundle)
src/            application code, namespace App\ (Controller/, Repository/ and domain folders: Campaign/, Queue/, Mail/, Log/, Subscriber/, Security/, Suppression/)
templates/      Twig templates: base.html.twig and layout/ (navbar, admin bar, flash messages, footer) form the site layout
tests/          PHPUnit: Unit/, Integration/ (application against the ctnlist_test database), Smoke/ (HTTP against the running stack)
dev/podman/     development container files
```

## Deployment

The repository is a shared code tree installed once per version at `/usr/local/lib/php/ctnlist/6.0.2/` (the path is set in `public_html/index.php`). Run `composer install --no-dev` there.

Each installation has its own directory containing:

- `public_html/`: a copy of the repository's `public_html/`, used as the web server's document root. nginx serves existing files directly and passes everything else to `index.php` (see `dev/podman/nginx.conf`);
- `.env`, one level above `public_html/` (start from `.env.example`);
- writable `var/` (Symfony cache and logs) and `logs/` (contact log, import log and export files) directories.

The compiled Symfony container embeds absolute paths, so clear each installation's cache after deploying a new version (or empty its `var/cache/`).

The application's CSS, JavaScript and images (`assets/`) are served by Symfony AssetMapper. In production compile them once per installation after copying `public_html/`; the versioned files are written to that installation's `public_html/assets/` and nginx serves them as static files. Without this step the pages load without their styles and scripts. In development (`APP_DEBUG=true`) PHP serves them directly and no compile step is needed. Bootstrap and Bootstrap Icons come from the jsDelivr CDN with subresource integrity; there is no Node or npm build.

Console commands and migrations act on one installation, selected with `CTNLIST_INSTANCE_DIR`:

```bash
CTNLIST_INSTANCE_DIR=/var/www/example bin/console cache:clear
CTNLIST_INSTANCE_DIR=/var/www/example bin/console asset-map:compile
CTNLIST_INSTANCE_DIR=/var/www/example composer migrate-paralegal
```

## Configuration

Per-installation settings are read from `.env` outside the public web directory. Start with `.env.example`. `APP_ENV` is `prod`, `dev` or `test`. `TRUSTED_PROXIES` lists reverse proxies whose `X-Forwarded-*` headers are trusted.

The main application uses `DB_*` settings. The separate global suppression database uses `GDB_*` settings in an externally managed `ban.env`. Set `GDB_ENV_DIRECTORY` and, when necessary, `GDB_ENV_FILE` in the installation `.env` (default: `config/phinx/ban.env` in the shared tree); the same variables can be exported when running the banlist Phinx configuration. `SUPPRESSION_PROVIDER=none` disables the suppression database and is only accepted with `APP_ENV` `dev` or `test`.

SMTP can be configured with a single `MAILER_DSN` or an optional `MAIL_SMTP_SERVERS_JSON` array for per-server batching, delay, rate and failover. Optional legacy synchronisation targets use `SYNC_DATABASES_JSON`.

Sign-in links expire after `AUTH_MAGIC_LINK_TTL` seconds and are rate-limited per address (`AUTH_MAGIC_LINK_MAX_PER_EMAIL` within `AUTH_MAGIC_LINK_EMAIL_WINDOW`) and per client IP (`AUTH_MAGIC_LINK_MAX_PER_IP` within `AUTH_MAGIC_LINK_IP_WINDOW`); a signed-in session lasts `AUTH_SESSION_TTL` seconds. Contact-form submissions are appended to `CONTACT_LOG_FILE` (default: the installation's `logs/contact.log`). Bulk-subscribe and import logs (`emails_added.txt`) and the export files (`export-subscribers.txt`, `export-remove.txt`) are written to `APP_LOG_DIR` (default: the installation's `logs/`).

### Settings page

Administrators, and any role given the `settings.manage` permission, can change a selected set of settings at **Admin → Settings** without editing `.env`: site identity, contact details and message links, the mail sender and proof address, the SMTP servers (the main server and the campaign failover servers, in order) and sending pace, the contact form, the subscription messages, the archive and the sign-in limits.

- Each setting resolves as **database override → `.env` → built-in default**, and the page shows which one is in effect (Database, .env or Default). Overrides are stored in the `options` table; nothing is written to `.env`. Database overrides are optional: an installation without any runs exactly from its `.env`.
- Saving a value equal to the inherited one, or "Reset override" / "Reset section", removes the override so `.env` (or the default) applies again. Changes apply from the next page or email; a send that is already running keeps the SMTP settings it started with.
- SMTP passwords are encrypted in the database with AES-256-GCM using the dedicated `APP_SETTINGS_KEY` from `.env` (32 random bytes, Base64; generate with `openssl rand -base64 32`). The key is needed only once a secret is saved there; without it the SMTP section explains what to add. Passwords are never sent to the browser (leave the field empty to keep the current one) and never logged.
- **Do not change `APP_SETTINGS_KEY` while encrypted settings exist**: they become unreadable, and mail that needs them fails (the Settings page reports it) until they are entered again or reset. A future key rotation must decrypt with the old key and re-encrypt with the new key before the new key is deployed.
- Stay in `.env` only: `APP_SECRET`, `APP_SETTINGS_KEY`, `APP_ENV`/`APP_DEBUG`, `APP_INSTANCE_ID`, the database and suppression-database settings, `TRUSTED_PROXIES`, `APP_BASE_URL`/`APP_DOMAIN`, `APP_TIMEZONE`, file paths (`CONTACT_LOG_FILE`, `APP_LOG_DIR`), the auth cookie name, `APP_ADMIN_EMAIL` and the synchronisation targets.

Deployment is otherwise unchanged (including `asset-map:compile` per installation).

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
bin/dev up          # build and start, composer install, migrations (including the test databases), seed admin@ctnlist.test
bin/dev help        # all commands
```

The application is at http://localhost:8180. All outgoing mail is captured by Mailpit at http://localhost:8125: sign in as `admin@ctnlist.test` and follow the magic link it receives. The development instance runs without a banlist database (`SUPPRESSION_PROVIDER=none`).

## Testing and validation

```bash
bin/dev lint        # php -l over public_html/index.php, src/ and tests/
bin/dev analyse     # PHPStan
bin/dev test        # PHPUnit: unit, integration and smoke suites
```

`tests/Smoke/RouteSmokeTest` requests every GET route of the running stack over HTTP, anonymously and as the development administrator, and checks status codes, login redirects, access control and PHP error output. It was the route-parity check for the Symfony migration. `tests/Smoke/AuthFlowTest` covers sign-in links, auth cookies, logout and CSRF, `tests/Smoke/LayoutTest` the site layout and error pages, `tests/Smoke/AdminListsRolesTest` list and role administration, `AdminMessagesTemplatesTest`, `AdminQueueTest`, `AdminSubscribersTest` and `ReportsTest` the rest of the administration and the Site Log, and `ProfileTest`, `ConsentTest`, `MessageActionTest` and `ArchiveContactTest` the subscriber-facing pages. `tests/Unit` holds unit tests; `tests/Integration` boots the application against the `ctnlist_test` database (each test rolled back) and covers the services: suppression (including the banlist database, `ctnlist_banlist_test`), the send and message logs, mail, rendering, messages, the delivery queue, consent, contact and subscriber administration.

The banlist suppression database and real SMTP delivery are not part of the development stack and must be tested on a target installation.

## Migration status

The move from Fat-Free to Symfony was made in phases; each left a working application with lint, PHPStan and the smoke suite passing.

| Phase | Status |
|---|---|
| 0. Route smoke suite and PHPStan baseline | done |
| 1. Directory layout (`public_html/`, `src/`, `templates/`, `config/`) | done |
| 2. Symfony kernel as front controller, Fat-Free bridge, container-built legacy services | done |
| 3. Authentication and security (Symfony Security, magic-link authenticator, ACL voter, CSRF) | done |
| 4. Twig layout and simple pages | done |
| 5. Lists, roles and ACL administration | done |
| 6. Campaign core services on DBAL repositories | done |
| 7. Subscriber-facing routes | done |
| 8. Administrator campaign routes | done |
| 9. Reports | done |
| 10. Remove Fat-Free; PHPStan level 6 | done |

## Licence

ctnlist is released under the MIT Licence.

Copyright (c) 2009-2026 Richard Royston Catto
