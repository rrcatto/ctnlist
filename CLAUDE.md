# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

# ctnlist mailing list

ctnlist (v5.0.1-restored) is a web-based mailing-list application. v5.0 is the **behavioural baseline**: changes should preserve established v5 workflows rather than redesign them. See `README.md` and `RESTORED-FUNCTIONALITY.md` for the full feature and invariant list.

## Development software

- PHP v8.5.11 (`composer.json` declares `>=8.4 <9.0`, platform pinned to 8.4.0)
- PostgreSQL v16.15
- Currently using the FatFreeFramework v3.9.2
- Migrating off F3 to Symfony 8.1 (8.1.8 is current)
- Other dependencies: Phinx (migrations), Symfony Mailer, vlucas/phpdotenv; PHPStan and PHPUnit for dev

## Local development environment (podman)

`bin/dev` drives a podman-compose stack (`compose.yaml`, files in `dev/podman/`): nginx 1.30 → PHP 8.5-FPM, PostgreSQL 16 and Mailpit. Containers mirror the production layout (repo mounted at `CTNLIST_SHARED_DIRECTORY`, `index.php` in `/var/www/ctnlist/public_html`, `.env` one level up).

```bash
bin/dev up                  # build, start, composer install (first run), run migrations, seed admin@ctnlist.test
bin/dev seed-admin [email]  # create/promote another administrator
bin/dev help                # all commands: down, restart, ps, logs, migrate, status, psql, shell, composer, php, reset-db
```

- App http://localhost:8180, Mailpit http://localhost:8125 (all mail is captured there; log in via the magic link it receives), PostgreSQL `localhost:5434` (ctnlist/ctnlist). Port 8181 is reserved for the Symfony app.
- First `bin/dev up` generates `dev/podman/ctnlist.env` (gitignored) from `ctnlist.env.dist` with random `APP_SECRET`/`APP_INSTANCE_ID`. Never commit generated secrets or use placeholder values.
- The dev stack has no banlist database: it runs with `SUPPRESSION_PROVIDER=none`, which `index.php` only accepts when `APP_ENV=development`. The catto-mail smarthost is planned to replace the banlist as the suppression source.
- Run PHP tooling inside the container (`bin/dev composer …`, `bin/dev analyse`); there is no host PHP.
- `bin/dev reset-db` deletes the `ctnlist_pgdata` volume and re-runs `up`. It prompts for the word `reset`, so a non-interactive run needs `echo reset | bin/dev reset-db`.

## Commands

`vendor/` is not committed; `bin/dev up` runs `composer install` on first start. In dev, run these through `bin/dev` (e.g. `bin/dev composer migrate-paralegal`).

```bash
bin/dev analyse                      # composer analyse: PHPStan level 5 over classes/ and index.php
bin/dev lint                         # php -l over index.php and classes/*.php (how the tree is validated during packaging)
bin/dev test                         # PHPUnit; no tests or phpunit.xml exist yet
bin/dev test --filter <TestName>     # single test, once tests exist

composer migrate-paralegal  # main DB migrations (database/migrations/domain)  = bin/dev migrate
composer status-paralegal   #                                                 = bin/dev status
composer migrate-banlist    # global suppression DB migrations (database/migrations/banlist); not used in dev
composer status-banlist
```

Phinx configs live in `phinx/`. `phinx-paralegal.php` loads `.env` from `INSTANCE_ENV_DIR` (default `/home/paralegal`; the dev container sets `/var/www/ctnlist`); `phinx-banlist.php` loads `ban.env` from `GDB_ENV_DIRECTORY`/`GDB_ENV_FILE`. Export those before running migrations against another installation.

`database/reset-development.sql` and `database/reset-banlist-development.sql` are **destructive** and only for disposable dev databases.

The podman stack covers the main DB, mail capture and browser workflows. There is no banlist database or real SMTP relay in dev, so suppression-DB behaviour and live delivery must be tested on a target installation.

## Deployment layout

`index.php` is the per-installation front controller placed in an instance's `public_html/`. It hard-codes `CTNLIST_SHARED_DIRECTORY = /usr/local/lib/php/ctnlist/5.0.1/`, from which it loads `vendor/`, `classes/`, `views/` and `theme/`. Per-installation config is `.env` one directory **above** `public_html` (start from `.env.example`). The suppression DB config (`ban.env`) lives outside the source tree.

## Architecture

**Single front controller.** All routing lives in `index.php` as F3 closures (`$fat->route(...)`), about 90 routes. The file bootstraps env → F3 hive → two PDO connections → DB session handler → controllers, then defines routes. Shared helpers defined there:
- `$render($title, $html, $layout)` — controllers return HTML **strings**; `$render` runs them through F3 `Template::parse/resolve` (so `{{@BaseURL}}` etc. work inside controller output) and wraps them in `views/unify-main-template.html`.
- `$admin('perm.key')` — allows if the user has the ACL permission, otherwise requires `uadmin === 1`, else 403.
- `$loggedIn()` — redirects to `/login`.

**Controllers / models (`classes/`, Composer classmap autoload, no namespaces).**
- `*Controller.php` — business logic plus HTML generation (forms/tables built as strings, using `formfield`/`htmlhelper` and CSS classes from `theme/design.ini`, which is loaded into the F3 hive).
- `*M.php` — F3 `\DB\SQL\Mapper` subclasses, one per table.
- Controllers are wired manually in `index.php` with constructor injection plus setter injection (`SetMailer`, `SetQueue`, …) to break circular dependencies. Add new dependencies there.
- `ListService`, `AclService` — raw-SQL services for lists/membership and role permissions.
- State shared via the F3 hive: `dbPDO` (main DB), `gdbPDO` (suppression DB), `uloggedin`, `uadmin`, `uid`, `acl_permissions`, plus the non-secret config keys set in `index.php`.

**Two PostgreSQL databases.** Main DB (`DB_*`) and a separate, shared global suppression DB (`GDB_*`, accessed only by `GlobalUnsubscribeM` / `GlobalDomainUnsubscribeM` via `GlobalUnsubscribeController` / `GlobalDomainUnsubscribeController`). Each has its own migration directory and Phinx config. `SUPPRESSION_PROVIDER` (`banlist` default, `none` dev-only) selects whether the suppression DB is used; those two controllers are the seam for swapping in catto-mail.

**Identity and consent.** `subscribers` is the canonical identity table, keyed publicly by a permanent UUIDv7 (`s_uuid`, see `UuidV7`); URLs use it as `@token`. Auth is passwordless magic-link (`UsersController`, `AuthLoginTokenM`, `AuthSessionM`) with `DatabaseSessionHandler`. A subscriber is eligible for a list only when `list_subscribers.ls_confirmed = TRUE AND ls_unsubscribed = FALSE`. The `ALL` system list is immutable, optional, and must **never** be attached to a message automatically. Its shortcode is `ALL` (`ListsM::ALL_SHORTCODE`); list shortcodes are 3–6 uppercase letters/digits.

**Mail delivery.** All email goes through the `mailer` class (wrapping Symfony Mailer with batching, throttling, retry and multi-server failover from `MAIL_SMTP_SERVERS_JSON`). Messages are queued per subscriber/message (`QueueController`, union of selected lists, deduplicated, engagement-priority ordered) and sent by `QueueController::ProcessQueue()`, which is triggered from a POST route in `index.php`.

## Invariants to preserve

- Every successful email handoff is logged to `sendlog`.
- Every campaign-content delivery (queue, proof, resend, forward) uses the campaign mail path and logs to `smlog`. The unique `(subscriber UUID, message MUID)` row in `smlog` is the once-only delivery guard for normal queue sends.
- Global email/domain suppression is checked at confirmation, queue build and immediately before send. A global unsubscribe disables all local list memberships.
- Messages and templates may be saved as incomplete drafts with no lists; store exactly what the administrator selected.
- State-changing POST routes call `Csrf::requireValid($fat)`, and forms include `Csrf::field($fat)`.
- **No backward compatibility.** ctnlist is not in production and has no data to preserve. Do not add aliases, legacy link/route forms or upgrade migrations: change the source of truth directly (schema changes go into `20260716120000_create_phase3a_schema.php`, then `bin/dev reset-db`). This applies until the user announces go-live.
