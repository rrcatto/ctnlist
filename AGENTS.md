# AGENTS.md

This file provides guidance to Codex (Codex.ai/code) when working with code in this repository.

# ctnlist mailing list

ctnlist (v5.0.4) is a web-based mailing-list application. v5.0 is the **behavioural baseline**: changes should preserve established v5 workflows rather than redesign them. See `README.md` and `RESTORED-FUNCTIONALITY.md` for the full feature and invariant list.

## Development software

- PHP v8.5.11 (`composer.json` declares `>=8.4.1 <9.0`, platform pinned to 8.4.1, the Symfony 8.1 minimum)
- PostgreSQL v16.15
- Symfony 8.1 (framework-bundle 8.1.8, runtime, dotenv, twig-bundle, security-bundle, security-csrf, mailer) with Doctrine DBAL 4 / DoctrineBundle 3; Fat-Free Framework 3.9.2 still runs the unported routes
- Other dependencies: Phinx (migrations); PHPStan and PHPUnit for dev

## Local development environment (podman)

`bin/dev` drives a podman-compose stack (`compose.yaml`, files in `dev/podman/`): nginx 1.30 → PHP 8.5-FPM, PostgreSQL 16 and Mailpit. Containers mirror the production layout (repo mounted at the shared-code path `/usr/local/lib/php/ctnlist/5.0.4`, the repo's `public_html/` mounted read-only at `/var/www/ctnlist/public_html`, `.env` one level up, writable `var/` volume).

```bash
bin/dev up                  # build, start, composer install (first run), clear the instance cache, run migrations (also ctnlist_test, ctnlist_banlist_test), seed admin@ctnlist.test
bin/dev seed-admin [email]  # create/promote another administrator
bin/dev console <args>      # bin/console as www-data for the dev installation (e.g. bin/dev console debug:router)
bin/dev help                # all commands: down, restart, ps, logs (follows; never pipe it), migrate, status, psql, shell, composer, php, reset-db
```

- App http://localhost:8180, Mailpit http://localhost:8125 (all mail is captured there; log in via the magic link it receives), PostgreSQL `localhost:5434` (ctnlist/ctnlist).
- First `bin/dev up` generates `dev/podman/ctnlist.env` (gitignored) from `ctnlist.env.dist` with random `APP_SECRET`/`APP_INSTANCE_ID`. Never commit generated secrets or use placeholder values.
- The dev stack has no banlist database: it runs with `SUPPRESSION_PROVIDER=none`, which is only accepted when `APP_ENV` is `dev` or `test` (`LegacyFramework`, `SuppressionCheckerFactory`). The catto-mail smarthost is planned to replace the banlist as the suppression source.
- Run PHP tooling inside the container (`bin/dev composer …`, `bin/dev analyse`); there is no host PHP.
- `bin/dev reset-db` deletes the `ctnlist_pgdata` volume and re-runs `up`. It prompts for the word `reset`, so a non-interactive run needs `echo reset | bin/dev reset-db`.

## Commands

`vendor/` is not committed; `bin/dev up` runs `composer install` on first start. In dev, run these through `bin/dev` (e.g. `bin/dev composer migrate-paralegal`).

```bash
bin/dev analyse                      # composer analyse: PHPStan level 5 over src/, public_html/index.php and tests/
bin/dev lint                         # php -l over public_html/index.php, src/ and tests/ (how the tree is validated during packaging)
bin/dev test                         # PHPUnit (phpunit.dist.xml): suites unit, integration, smoke (--testsuite unit,integration skips HTTP)
bin/dev test --filter <TestName>     # single test or data-set, e.g. --filter 'testRouteRendersForAdministrator@/lists'

composer migrate-paralegal  # main DB migrations (database/migrations/domain)  = bin/dev migrate
composer status-paralegal   #                                                 = bin/dev status
composer migrate-banlist    # global suppression DB migrations (database/migrations/banlist); not used in dev
composer status-banlist
```

Tests: `tests/Unit` (plain PHPUnit), `tests/Integration` and `tests/Smoke`.
- Integration tests extend `IntegrationTestCase`: the kernel in the `test` env against the `ctnlist_test` database, each test inside a rolled-back transaction (create any rows you need; fixture helpers `createSubscriber()`, `createList()`, `setMembership()`, `createMessage()`), mail on the null transport with Symfony's mailer assertions. Env comes from `phpunit.dist.xml` (SUPPRESSION_PROVIDER=none, MAILER_DSN=null://null, …). In the test env every `App\` service is kept public (`Kernel::build()`) so tests can fetch services nothing uses yet. `tests/bootstrap.php` recompiles the test container on every run (APP_DEBUG=0 would keep a stale one) and removes the handlers that compiling installs (it autoloads F3's `base.php`, which constructs `Base`). The banlist checker is tested against `ctnlist_banlist_test` (`dev/podman/banlist-test.env`). Tests run as root in the container; `bin/dev test` first makes `var/cache` www-data-owned so PHP-FPM can still build its cache.
- Smoke tests: HTTP against the running stack; base class `SmokeTestCase` provides `client()`, `request()`, `issueLoginToken()`, `loginAsAdmin()`. `AuthFlowTest` covers magic links, auth cookies, logout and CSRF; `LayoutTest` the layout and error pages; `AdminListsRolesTest` list/role administration and ACL. `list_subscription_events` is append-only (trigger), so subscribers with consent events can never be deleted: tests must reuse persistent fixture subscribers (e.g. `smoke-editor@ctnlist.test`) rather than create and delete them. `tests/Smoke/RouteSmokeTest` requests every GET route of the running stack over HTTP (from the app container to `http://web`), anonymously and as `admin@ctnlist.test` (logged in by inserting a magic-link token). It creates and deletes its own message/template/archive fixtures in the dev DB. It is the route-parity check for the Symfony migration: add new GET routes to it. The app writes timestamps in PHP's timezone (Africa/Johannesburg) while PostgreSQL runs in UTC, so compute timestamps in PHP when inserting rows the app compares against `date()`.

Phinx configs live in `config/phinx/`. `phinx-paralegal.php` loads `.env` from `CTNLIST_INSTANCE_DIR` (required; the dev container sets `/var/www/ctnlist`); `phinx-banlist.php` loads `ban.env` from `GDB_ENV_DIRECTORY`/`GDB_ENV_FILE`. Export those before running migrations against another installation.

`database/reset-development.sql` and `database/reset-banlist-development.sql` are **destructive** and only for disposable dev databases.

The podman stack covers the main DB, mail capture and browser workflows. There is no banlist database or real SMTP relay in dev, so suppression-DB behaviour and live delivery must be tested on a target installation.

## Migration to Symfony (in progress)

ctnlist is being migrated from F3 to Symfony 8.1 in phases; each phase leaves a working app on :8180 with lint, PHPStan and the smoke suite green. Decisions: one app (no side-by-side), Doctrine DBAL repositories replace the F3 Mappers, Twig replaces string-built HTML area by area, constructor-injection DI via Symfony autowiring, and the shared-code/per-instance deployment model is kept. Done: phase 0 (smoke suite), phase 1 (directory layout), phase 2 (Symfony kernel as the front controller, with a temporary F3 bridge), phase 3 (Symfony Security: authentication, logout, ACL voter, CSRF), phase 4 (Twig layout for every page, `SiteConfig`, home/privacy/store controllers, error pages), phase 5 (list, role and ACL administration), phase 6 (campaign core services with integration tests, not yet wired to routes). Next: phase 7, subscriber-facing routes on those services (subscribe/confirm/unsubscribe, profile, forward, reactions, resend, `/ut`, archives, contact form, sign-in link requests).

Unported code lives in `src/Legacy/` (namespace `App\Legacy`) and `config/legacy/design.ini` (CSS classes for legacy HTML strings); each phase moves what it ports into its final home (`src/Controller`, `src/Repository`, domain folders, `templates/`) and deletes it from Legacy.

## Deployment layout

The repository is the shared code tree, installed at `/usr/local/lib/php/ctnlist/5.0.4/` (hard-coded as `$sharedDirectory` in `public_html/index.php`). Each installation has its own directory containing:
- `public_html/`: a copy of the repo's `public_html/` (`index.php`, `css/`, `js/`), used as the web root;
- `.env` one directory **above** `public_html` (start from `.env.example`; `APP_ENV` is `prod`, `dev` or `test`). The Symfony runtime loads it (runtime option `project_dir` = the installation directory);
- a writable `var/` (Symfony cache and logs, F3 compiled templates in `var/tmp/`) and `logs/`.

`App\Kernel` takes the installation directory as a third constructor argument: `getProjectDir()` is the shared tree, `getCacheDir()`/`getLogDir()` are under the installation's `var/`, and `%kernel.instance_dir%` is available to services. `bin/console` needs `CTNLIST_INSTANCE_DIR`.

The suppression DB config (`ban.env`) lives outside the source tree; `config/phinx/` is its default directory.

## Architecture

**Request flow.** `public_html/index.php` → Symfony runtime → `App\Kernel`. Symfony owns the session (`App\Session\DatabaseSessionHandler`, DBAL, `sessions` table), error handling and the response. Ported routes will be Symfony controllers; everything else hits the catch-all `legacy` route in `config/routes.yaml` (keep it last), served by `App\Legacy\LegacyBridge`:
- it starts the Symfony session first (legacy code uses top-level `$_SESSION` keys; Symfony keeps its own under `_sf2_attributes`, both in the same session);
- `LegacyFramework::create()` builds the F3 hive (config from env, `\DB\SQL` connections, suppression provider), removes F3's error/exception handlers, sets `HALT=false` and makes `ONREROUTE`/`ONERROR` throw `LegacyRedirect`/`LegacyHttpError`. The bridge turns those into a `RedirectResponse` or the legacy error page. Never let F3 `die`;
- `src/Legacy/routes.php` holds the F3 route closures (about 85), called with the container-built legacy services and a `LegacyPage`; output is buffered, and headers sent with `header()`/`setcookie()` are moved onto the Symfony response (except the session cookie, which Symfony's session listener sets).

Helpers defined in `routes.php`:
- `$render($title, $html)` — legacy controllers return HTML **strings**; `$render` resolves F3 tokens in them (`{{@BaseURL}}`, design.ini classes like `{{@pclass}}`) with `Template::parse/resolve` and stores the result in `LegacyPage`. The bridge then renders `templates/legacy/page.html.twig`, i.e. the Twig layout. Output echoed without `$render` (images, JSON) is sent unchanged. Legacy HTTP errors render the same Twig error templates as Symfony (`templates/bundles/TwigBundle/Exception/`).
- `$admin('perm.key')` — allows if the user has the ACL permission, otherwise requires `uadmin === 1`, else 403.
- `$loggedIn()` — redirects to `/login`.

**Legacy controllers / models (`src/Legacy/`, PSR-4 `App\` → `src/`).**
- `*Controller.php` — business logic plus HTML generation (forms/tables built as strings, using `formfield`/`htmlhelper` and CSS classes from `config/legacy/design.ini`, which is loaded into the F3 hive).
- `*M.php` — F3 `\DB\SQL\Mapper` subclasses, one per table.
- Legacy controllers are Symfony services (`config/services.yaml`): autowired constructors (`Base` and `DB\SQL` come from factories on `LegacyFramework`) plus `calls:` for the setter injection (`SetMailer`, `SetQueue`, …) that breaks their circular dependencies. Mappers and helpers are still created with `new` inside the legacy code. Resolution order in `LegacyBridge::SERVICES` matters: right after building UsersController the bridge calls `UsersController::authenticateAs(SubscriberUser)`, which fills the hive user context, and SiteLogController logs the request in its constructor.
- `ListService` — lists/membership/message-list operations still used by the legacy subscriber and message code (list CRUD itself is ported: `App\Subscriber\ListManager`).
- State shared via the F3 hive: `dbPDO` (main DB), `gdbPDO` (suppression DB), `uloggedin`, `uadmin`, `uid`, `acl_permissions`, plus the site settings `LegacyFramework` copies from `SiteConfig` under their old hive names (`BaseURL`, `ListName`, `OrderEmail`, …).

**Symfony pages.** Controllers live in `src/Controller/` (attribute routes, imported before the catch-all). Every page extends `templates/base.html.twig`, which uses `app.user`, `is_granted('ROLE_ADMINISTRATOR')` and two globals from `App\Twig\AppExtension`: `site` (`App\Config\SiteConfig`, the non-secret installation settings built from env with the v5 defaults and fallbacks) and `counters` (`AdminCounters`, lazily queried admin menu counts). The layout shows flash messages (`addFlash('info'|'danger', …)`) as Bootstrap alerts.

Controller conventions (see `src/Controller/Admin/`): `#[IsGranted('perm.key')]` with ACL permission keys; state changes are POST with `#[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]`, then redirect with a flash message (post/redirect/get); domain services (`ListManager`, `RoleManager`) throw `\InvalidArgumentException` with a message for the user and `*NotFound` exceptions that controllers turn into 404s. Anonymous visitors on protected pages get the 403 page (`AccessDeniedEntryPoint`), as in v5. Value objects and exceptions under `src/` must be excluded from service registration in `config/services.yaml` (e.g. `SubscriberUser`), or controller arguments typed with them break autowiring.

**Two PostgreSQL databases.** Main DB (`DB_*`) and a separate, shared global suppression DB (`GDB_*`). Each has its own migration directory and Phinx config. `SUPPRESSION_PROVIDER` (`banlist` default, `none` for dev/test) selects the source. Symfony code uses `App\Suppression\SuppressionChecker` (`BanlistSuppressionChecker` reads `ban.env` itself and connects lazily; `NullSuppressionChecker`); this interface is the seam for swapping in catto-mail. Legacy code still uses `GlobalUnsubscribeM` / `GlobalDomainUnsubscribeM` until phases 7–8. `isSuppressed()` applies the v5 address cleanup rules first (`App\Subscriber\EmailNormaliser`, moved verbatim from `SubscribersM`; they deliberately make .org, .gov(.za), .ac.za and similar addresses invalid, and an unusable address counts as suppressed).

**Identity and consent.** `subscribers` is the canonical identity table, keyed publicly by a permanent UUIDv7 (`s_uuid`, see `UuidV7`); URLs use it as `@token`. Auth is passwordless magic-link, on Symfony Security (`config/packages/security.yaml`, `src/Security/`):
- Requesting a link (`POST /login`, `POST /auth/request`) is still legacy (`UsersController::requestMagicLink`, rate-limited, sent through the legacy mailer); it stores a SHA-256 hash in `auth_login_tokens` with a return action.
- The `main` firewall is **stateless**: auth state is the auth cookie (`AUTH_SESSION_COOKIE`, a random token whose hash identifies an `auth_sessions` row), not the PHP session. `MagicLinkAuthenticator` handles `GET /auth/verify` (claims the token atomically, records the login, bootstraps the `APP_ADMIN_EMAIL` administrator, creates the auth session, sets the cookie, redirects via `LoginReturnPath`). `AuthSessionAuthenticator` authenticates every other request carrying the cookie; a bad cookie leaves the request anonymous and `AuthCookieClearingListener` clears it. `/logout` is the firewall logout (`LogoutListener` revokes the auth session).
- `SubscriberUser` (identifier: `s_uuid`) carries role keys (roles `ROLE_<KEY>`, e.g. `ROLE_ADMINISTRATOR`) and ACL permission keys. Authorise Symfony code with `is_granted('lists.manage')` / `#[IsGranted]`; `AclVoter` grants administrators everything, matching legacy `$admin()`.
- Repositories (`src/Repository/`) are DBAL, return arrays, and take timestamps from the injected clock in PHP's timezone (`Kernel::boot()` sets it from `APP_TIMEZONE`). DBAL's `fetchOne()` returns `false` both for "no row" and for a boolean `FALSE` column, so read boolean columns with `fetchAssociative()`. A subscriber is eligible for a list only when `list_subscribers.ls_confirmed = TRUE AND ls_unsubscribed = FALSE`. The `ALL` system list is immutable, optional, and must **never** be attached to a message automatically. Its shortcode is `ALL` (`ListsM::ALL_SHORTCODE`); list shortcodes are 3–6 uppercase letters/digits.

**Mail delivery.** Legacy routes still send through the legacy `mailer` class and queue/send with `QueueController` (`ProcessQueue()` is triggered from a POST route in `src/Legacy/routes.php`). The Symfony replacements exist (phase 6) and are covered by integration tests; phases 7–8 wire routes to them:
- `App\Mail`: `SmtpServerPool` (campaign queues fail over across active `MAIL_SMTP_SERVERS_JSON` entries or `MAILER_DSN`; transactional mail prefers `MAILER_DSN`), `MailConnection` (one opened transport per use, created via `mailer.transport_factory` so test mail is captured; throttled to the server's send rate), `TransactionalMailer` (notifications with admin BCC, sign-in links, list invitations, contact acknowledgements), `CampaignMailer` (the campaign mail path: v5 headers, Send Log + smlog sent mark).
- `App\Log`: `SendLog` (every handoff), `MessageLog` (smlog once-only guard, activity counters via `MessageActivity`).
- `App\Campaign`: `TemplateRenderer` (v5 MergeTemplate ported replacement-for-replacement and verified identical; replacement order matters), `ArchiveService`, `MessageService` (drafts saved exactly as supplied, first-queue preparation, proof/resend sends).
- `App\Queue`: `QueueBuilder` (union of selected lists, once-only, suppression → unsubscribe from all lists, rotation), `QueueProcessor` (v5 ProcessQueue contract; pauses via the Symfony clock).
- `App\Subscriber\Engagement`: v5 priority bump/set/reset.

## Invariants to preserve

- Every successful email handoff is logged to `sendlog`.
- Every campaign-content delivery (queue, proof, resend, forward) uses the campaign mail path and logs to `smlog`. The unique `(subscriber UUID, message MUID)` row in `smlog` is the once-only delivery guard for normal queue sends.
- Global email/domain suppression is checked at confirmation, queue build and immediately before send. A global unsubscribe disables all local list memberships.
- Messages and templates may be saved as incomplete drafts with no lists; store exactly what the administrator selected.
- State-changing POST routes validate CSRF and forms include the token: legacy code uses `Csrf::requireValid($fat)` / `Csrf::field($fat)`, Symfony code `isCsrfTokenValid(App\Security\Csrf::TOKEN_ID, …)` / `csrf_token('ctnlist')`. Both use Symfony's session-stored token manager (field name `csrf`).
- **No backward compatibility.** ctnlist is not in production and has no data to preserve. Do not add aliases, legacy link/route forms or upgrade migrations: change the source of truth directly (schema changes go into `20260716120000_create_phase3a_schema.php`, then `bin/dev reset-db`). This applies until the user announces go-live.
