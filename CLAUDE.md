# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

# ctnlist mailing list

Date: 2026/10/03 18:38 SAST
Project name: ctnlist

ctnlist (v5.0.1-restored) is a web-based mailing-list application. v5.0 is the **behavioural baseline**: changes should preserve established v5 workflows rather than redesign them. See `README.md` and `RESTORED-FUNCTIONALITY.md` for the full feature and invariant list.

## Development software

- PHP v8.5.11 (`composer.json` declares `>=8.4 <9.0`, platform pinned to 8.4.0)
- PostgreSQL v16.15
- Currently using the FatFreeFramework v3.9.2
- Would like to migrate off F3 to Symfony v8.2
- Other dependencies: Phinx (migrations), Symfony Mailer, vlucas/phpdotenv; PHPStan and PHPUnit for dev

## Commands

`vendor/` is not committed; run `composer install` first.

```bash
composer analyse            # PHPStan, level 5, over classes/ and index.php
composer test               # PHPUnit (no tests exist yet)
vendor/bin/phpunit --filter <TestName>   # single test, once tests exist
php -l <file>               # syntax check (how the tree is validated during packaging)

composer migrate-paralegal  # main DB migrations (database/migrations/domain)
composer status-paralegal
composer migrate-banlist    # global suppression DB migrations (database/migrations/banlist)
composer status-banlist
```

Phinx configs live in `phinx/`. `phinx-paralegal.php` loads `.env` from `INSTANCE_ENV_DIR` (default `/home/paralegal`); `phinx-banlist.php` loads `ban.env` from `GDB_ENV_DIRECTORY`/`GDB_ENV_FILE`. Export those before running migrations against another installation.

`database/reset-development.sql` and `database/reset-banlist-development.sql` are **destructive** and only for disposable dev databases.

No live PostgreSQL/SMTP is available in the build environment; migration, delivery and browser workflows must be tested on a target installation.

## Deployment layout

`index.php` is the per-installation front controller placed in an instance's `public_html/`. It hard-codes `CTNLIST_SHARED_DIRECTORY = /usr/local/lib/php/ctnlist/5.0.1/`, from which it loads `vendor/`, `classes/`, `views/` and `theme/`. Per-installation config is `.env` one directory **above** `public_html` (start from `.env.example`). The suppression DB config (`ban.env`) lives outside the source tree.

## Architecture

**Single front controller.** All routing lives in `index.php` as F3 closures (`$fat->route(...)`), roughly 95 routes. The file bootstraps env → F3 hive → two PDO connections → DB session handler → controllers, then defines routes. Shared helpers defined there:
- `$render($title, $html, $layout)` — controllers return HTML **strings**; `$render` runs them through F3 `Template::parse/resolve` (so `{{@BaseURL}}` etc. work inside controller output) and wraps them in `views/unify-main-template.html`.
- `$admin('perm.key')` — allows if the user has the ACL permission, otherwise requires `uadmin === 1`, else 403.
- `$loggedIn()` — redirects to `/login`.

**Controllers / models (`classes/`, Composer classmap autoload, no namespaces).**
- `*Controller.php` — business logic plus HTML generation (forms/tables built as strings, using `formfield`/`htmlhelper` and CSS classes from `theme/design.ini`, which is loaded into the F3 hive).
- `*M.php` — F3 `\DB\SQL\Mapper` subclasses, one per table.
- Controllers are wired manually in `index.php` with constructor injection plus setter injection (`SetMailer`, `SetQueue`, …) to break circular dependencies. Add new dependencies there.
- `ListService`, `AclService` — raw-SQL services for lists/membership and role permissions.
- State shared via the F3 hive: `dbPDO` (main DB), `gdbPDO` (suppression DB), `uloggedin`, `uadmin`, `uid`, `acl_permissions`, plus the non-secret config keys set in `index.php`.

**Two PostgreSQL databases.** Main DB (`DB_*`) and a separate, shared global suppression DB (`GDB_*`, accessed only by `GlobalUnsubscribeM` / `GlobalDomainUnsubscribeM`). Each has its own migration directory and Phinx config.

**Identity and consent.** `subscribers` is the canonical identity table, keyed publicly by a permanent UUIDv7 (`s_uuid`, see `UuidV7`); URLs use it as `@token`. Auth is passwordless magic-link (`UsersController`, `AuthLoginTokenM`, `AuthSessionM`) with `DatabaseSessionHandler`. A subscriber is eligible for a list only when `list_subscribers.ls_confirmed = TRUE AND ls_unsubscribed = FALSE`. `ALL00` (`ListsM::ALL_SHORTCODE`) is an immutable system list that is optional and must **never** be attached to a message automatically.

**Mail delivery.** All email goes through the `mailer` class (wrapping Symfony Mailer with batching, throttling, retry and multi-server failover from `MAIL_SMTP_SERVERS_JSON`). Messages are queued per subscriber/message (`QueueController`, union of selected lists, deduplicated, engagement-priority ordered) and sent by `ProcessQueue`.

## Invariants to preserve

- Every successful email handoff is logged to `sendlog`.
- Every campaign-content delivery (queue, proof, resend, forward) uses the campaign mail path and logs to `smlog`. The unique `(subscriber UUID, message MUID)` row in `smlog` is the once-only delivery guard for normal queue sends.
- Global email/domain suppression is checked at confirmation, queue build and immediately before send. A global unsubscribe disables all local list memberships.
- Messages and templates may be saved as incomplete drafts with no lists; store exactly what the administrator selected.
- State-changing POST routes call `Csrf::requireValid($fat)`, and forms include `Csrf::field($fat)`.
- Old v5 link formats (`/confirm/@token/@muid`, `/unsubscribe/...`, etc.) must keep working; they map to the `ALL` list.
- Write schema changes as new Phinx migrations; do not edit migrations that have already run.
