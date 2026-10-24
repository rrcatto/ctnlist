# ctnlist 6.1.0

ctnlist is a web-based mailing-list application created by Richard Royston Catto in 2009. Versions 5.0.x and 6.0.x are an incremental modernisation of the working v5.0 application, not a replacement of its established workflows.

Since 6.0 every route is a Symfony 8.1 controller and the Fat-Free Framework has been removed. The release history is in [CHANGELOG.md](CHANGELOG.md).

## Platform and upgrades

- PHP 8.5+, Composer
- Symfony 8.1 (including Symfony Security, Twig and Mailer) with Doctrine DBAL 4
- PostgreSQL with versioned Phinx migrations
- Symfony Mailer for transactional mail only (`src/Mail/`: sign-in links, invitations, notifications, contact-form mail); campaign content (queue sends, proofs, resends, forwards) is delivered by the catto-mail smarthost through its HTTPS API (`src/CattoMail/`), except proofs when `MAIL_PROOF_SMTP` hands them to a Mailpit instance ([Proofs to Mailpit](#proofs-to-mailpit))
- Symfony Forms and Validator for every input form; symfony/html-sanitizer (archive pages) and symfony/rate-limiter (contact form, forwards and resends, global opt-out changes)
- Bootstrap 6 (6.0.0-alpha.1) and Bootstrap Icons served by the site through Symfony AssetMapper (no CDN, no Node or npm), under a strict Content-Security-Policy; Bootstrap 6 needs a recent browser (Chrome or Edge 130, Firefox 132, Safari 18 or later)
- PHP `gd` (with JPEG and WebP) and `exif` for profile pictures
- permanent RFC 9562 UUIDv7 subscriber identifiers
- `subscribers` as the canonical identity table
- passwordless one-time email authentication (Symfony Security from 5.0.3)
- application-owned database sessions
- multiple mailing lists and per-list consent
- immutable `ALL` system list as an optional audience
- profile pictures: on Edit profile a subscriber chooses a picture, positions, zooms and rotates it with a live preview, and saves it; it appears beside their name in the site menu (signed-out visitors see a placeholder) and on their profile
- a Settings page for overriding selected `.env` settings (site identity, contact details, mail sender, the transactional SMTP server, contact form, subscription messages, archives, sign-in limits), with SMTP secrets encrypted by `APP_SETTINGS_KEY`
- roles, permissions and ACL: custom roles can be created, renamed, deleted and given permissions; subscribers are found by search to assign roles, and each role lists its members (`roles.manage` manages roles and membership, `acl.manage` additionally changes permissions; system roles are fixed)
- CSRF protection for state-changing actions
- separate PostgreSQL global email/domain suppression database
- production templates (nginx, PHP-FPM, cron, systemd) and a production preflight, `ctnlist:diagnose --production`
- an acceptable use policy page, `/acceptable-use-policy`, linked from every page's footer and built from `docs/AUP.md`: the site's own rules for its subscribers and for whoever writes and sends its messages. The document names no product or domain; the page fills in the installation's list name, domain and organisation from Settings, and sends abuse reports and complaints to `abuse@<APP_DOMAIN>`, which must be a mailbox that is read. That is the only address on the page: the administrator's address is not shown there, not even in the footer (replace the file to publish a different policy)

## Restored v5 functionality

### Message and template workflow

Messages and templates can be saved as incomplete drafts. A message needs only a subject (at most 250 characters), by which the message list shows it; its content can be added later, but a blank message cannot be sent: queueing it, sending the queue, a proof, a resend and a forward all refuse it and say why. A message can have no lists, one list or several lists. The system stores exactly the lists selected by the administrator and never silently attaches `ALL`.

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
- per-message maximum-send handling (a hard limit, as in v5: 0 sends none, and the queue run says when the maximum stopped it)
- queue inspection, deletion, clearing, stopping and processing
- delivery through the catto-mail smarthost (see [catto-mail integration](#catto-mail-integration)): ctnlist renders every recipient's message, catto-mail sends it
- archive creation when a campaign is first queued
- queue-time subscriber state updates

### Once-only delivery and `smlog`

The ordinary campaign workflow sends a message once to a subscriber. The unique `(subscriber UUID, message MUID)` record in `smlog` is used as the delivery guard.

All campaign-content paths—including queue delivery, forwarding, proof copies and deliberate resends—are delivered by catto-mail and update `sendlog` when catto-mail has accepted them (the send job is sealed); deliveries to subscribers also update `smlog`. The one exception is a proof when `MAIL_PROOF_SMTP` is set: it is handed to Mailpit instead and logged when Mailpit takes it ([Proofs to Mailpit](#proofs-to-mailpit)). A proof can go to any valid address (by default `MAIL_TEST_ADDRESS`), which needs no subscriber record: it is logged as PROOF in `sendlog` only and does not affect the queue, consent, memberships or the once-only guard.

`smlog` again records list context, sent/read activity, likes, dislikes, forwards, profile updates, confirmations, subscriptions, unsubscriptions and contact forms sent from a message (`sml_bookings`, shown in the message's activity report).

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
- contact forms: the organisation's copy and the submitter's acknowledgement

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
install-ctnlist the installer, run as root on a server: a new installation, or an installation moved to another release (Deployment)
bin/            console (Symfony), dev (podman development environment)
config/         Symfony configuration; phinx/ (migration configs, default ban.env location)
database/       Phinx migrations (domain/, banlist/), destructive development reset scripts
assets/         application CSS and JavaScript for Symfony AssetMapper (app.js, styles/app.css, ckeditor/ editor configuration, images/, lib/ vendored Bootstrap and Bootstrap Icons)
importmap.php   AssetMapper import map (entry point: assets/app.js)
public_html/    per-installation web root: front controller (index.php) and vendor/ckeditor5/ (prebuilt CKEditor 5 bundle and its emoji list)
deploy/         production templates: nginx server block and FastCGI snippet, PHP-FPM pool, cron entries, systemd units, Mailpit for proofs (mailpit/, nginx/mailpit.location.conf.example); podman/, the kit for running an installation in a rootless Podman pod (Rootless Podman)
resources/      sample-templates/: the three sample email templates (HTML and text parts) that ctnlist:templates:samples installs
src/            application code, namespace App\ (Controller/, Repository/ and domain folders: Campaign/, Queue/, Mail/, CattoMail/, Log/, Subscriber/, Security/, Suppression/, Maintenance/; Config/ (settings, production rules), Http/ (headers, CSP, rate limits, pagination), Session/, Twig/, Util/; Form/ and Validator/; Command/ for console commands)
templates/      Twig templates: base.html.twig and layout/ (navbar, admin bar, flash messages, footer) form the site layout
tests/          PHPUnit: Unit/, Integration/ (application against the ctnlist_test database), Smoke/ (HTTP against the stack's smoke installation), Support/ (FakeCattoMail)
docs/           project documentation; AUP.md is also the source of the site's acceptable use policy page, so docs/ is part of an installed release
dev/podman/     development container files
dev/installer-test/  the stand-in server and the test of install-ctnlist (bin/dev test-installer)
dev/podman-kit-test/ the stand-in server and the test of the Podman kit (bin/dev test-podman-kit)
```

## Deployment

### Requirements

- PHP 8.5 or later with PHP-FPM and the extensions ctnlist uses, `pdo` and `pdo_pgsql`, `openssl` (encrypted settings), `gd` with JPEG and WebP support (profile pictures), `mbstring`, `fileinfo` (upload type checks), `filter`, `json` and `session`, and those its packages require: `dom` (the archive's HTML sanitiser), `xml` and `iconv`. `composer install` refuses a PHP without them (except `mbstring`, for which it accepts the slower symfony/polyfill-mbstring) and `ctnlist:diagnose` reports any missing one as an ERROR (run it with the PHP version PHP-FPM uses). `intl`, `curl` and `exif` (turns phone photos upright) are recommended; diagnose warns when they are missing.
- PostgreSQL 16 (the main database; the shared suppression database where this server hosts it, unless global suppression is catto-mail's: [Configuration](#configuration)).
- nginx in front of PHP-FPM (never Apache). There is no Node or npm build: frontend files are served by Symfony AssetMapper.

### Layout and permissions

The repository is a shared code tree installed once per version at `/usr/local/lib/php/ctnlist/6.1.0/` (the path is set in `public_html/index.php`): owned by root, read-only to the PHP-FPM user (`www-data`), with `composer install --no-dev --optimize-autoloader` run there. Each installation has its own directory, e.g. `/var/www/<installation>`:

| Path | Owner, mode | What |
|---|---|---|
| `.env` | root (or the deploying user) : `www-data`, `640` | configuration and secrets, one level above the web root; start from `.env.example` |
| `public_html/` | root, read-only | the web root: a copy of the release's `public_html/` (`index.php`, `vendor/ckeditor5/`) |
| `public_html/assets/` | `www-data`, writable | compiled AssetMapper files (`asset-map:compile`) |
| `var/` | `www-data`, writable | Symfony cache and logs; not backed up |
| `logs/` | `www-data` : the deploying user's group, `750`, writable | contact log, import log, export files (they hold subscriber addresses) |

Nothing else is writable by PHP. Only `public_html/` is web-accessible; `.env`, the code tree, `vendor/`, `var/` and `logs/` are never served. Run every console command for the installation as `www-data` (`sudo -u www-data env CTNLIST_INSTANCE_DIR=… php bin/console …`) so the cache it builds stays writable by PHP-FPM.

### The installer

`install-ctnlist`, at the root of every release from 6.0.11 on, does the steps of the two sections below in one command, as root, on a Debian or Ubuntu server that meets the requirements above (it checks them and names what is missing; it installs no packages):

```bash
curl -fsSLO https://raw.githubusercontent.com/rrcatto/ctnlist/vX.Y.Z/install-ctnlist
sudo bash install-ctnlist --installation <installation> --version vX.Y.Z
```

`<installation>` is the directory name under `/var/www`. Without `--version` it takes the newest release. Each of its 14 steps says PASS, WARN or FAIL; a FAIL says what failed, why and how to fix it, and running it again is safe. Its log is `/var/log/ctnlist-install.log`, and it holds no secret.

- **A new installation** (no `/var/www/<installation>/.env` yet). It asks for the host name, the administrator's address, the list's name, the sender, the SMTP server and the suppression database (`--help` lists them as options, for an unattended run). It then installs the code, writes `.env` with generated secrets, creates the database (`ctnlist_<installation>`, on this server's PostgreSQL) and the schema, builds the cache and the assets, adds a PHP-FPM pool and an nginx site, and schedules the worker and maintenance as systemd timers. The suppression database is the one whose `ban.env` you name (`--banlist-env`), or a new one on this server (`--create-banlist`, kept in `/etc/ctnlist/ban.env` and found there by later installations).
- **An existing installation.** It installs the new release beside the old one, dumps the database to `/var/backups/ctnlist/` and asks before it switches: front controller and editor files, cache, assets, the scheduled commands and a PHP-FPM reload. `.env` and the data are kept; settings the new release adds are named. Naming an earlier release with `--version` switches back in the same way.
- **It never replaces what is there:** an `.env`, a database, a role, a PHP-FPM pool or an nginx site that exists is kept. Where nginx already serves the host name (an older site, for one), the new site is written beside it as `/etc/nginx/conf.d/ctnlist-<installation>.conf.new` and you switch over when ready. Scheduled commands in `/etc/cron.d` are moved to the new release; a user's crontab is named for you to change.
- **The certificate.** The site uses `/etc/ctnlist/tls/<installation>/`, which links to Let's Encrypt's certificate for the host when there is one (or to `--certificate` and `--certificate-key`), and otherwise to a temporary self-signed certificate. Get the real one (`certbot certonly --webroot -w /var/www/<installation>/public_html -d <host>`), then run the installer again: it moves the links and reloads nginx.
- **A release with another database schema.** ctnlist has no upgrade migrations before go-live, so the installer compares the two releases' migrations and stops, with nothing changed, when they differ. `--recreate-database` then dumps the database, empties it and creates the schema again: every subscriber, list and message is lost, so it is only for an installation with nothing to keep. To restore a dump: `pg_restore --clean --if-exists -d <database> <dump>`, with the release it was taken from.
- At the end it runs `ctnlist:diagnose --production` and shows its errors and warnings; a new installation has some until the mail service is connected. `--sample-templates` adds the three sample templates, and `--no-web-server` leaves nginx and PHP-FPM to you.

It does not install Mailpit ([Proofs to Mailpit](#proofs-to-mailpit)) and does not connect the mail service (step 11 below). `bin/dev test-installer` runs it in a stand-in server ([Testing and validation](#testing-and-validation)).

### Rootless Podman

The other way to install, for a server that already hosts other sites (a control panel such as Virtualmin, its nginx and its PHP): the installation runs as its own Linux user in a rootless Podman pod, `deploy/podman/` ([its README](deploy/podman/README.md)). The server's nginx keeps ports 80 and 443 and the certificate and forwards the site to the pod on `127.0.0.1:<port>`; PostgreSQL and the mail server stay on the server, which the pod reaches at `10.0.2.2`.

- **The pod:** nginx and PHP-FPM, and Mailpit for proofs with `--mailpit`. The PHP image is built from the release (`deploy/podman/Containerfile`: an exact PHP 8.5 release, the extensions above, the pool of `deploy/php-fpm`), so the server's PHP does not matter. The worker and maintenance are systemd user timers that run in the pod.
- **Read-only:** the code, `.env` and `public_html` are read-only in every container; PHP writes `var/` and `logs/` only, and cannot reach the user's other files or the server's other sites.
- **Root, once:** `root-prepare.sh <user>` gives the user the ids rootless containers need and linger, with `--database` the site's database, and with `--banlist-env` (or `--create-banlist`) the suppression database's settings; without those, global suppression is catto-mail's (`SUPPRESSION_PROVIDER=cattomail`). Then root makes nginx forward the site (`deploy/podman/host-nginx.conf.example`).
- **The site's user, from then on:** `ctnlist-podman.sh install --version vX.Y.Z --port <port>` is a new installation, or the switch to that release, in 13 steps like the installer's: the release in `~/ctnlist/releases/`, `~/ctnlist/site/.env` with generated secrets, the schema, the cache and the assets, the pod and its timers, then `ctnlist:diagnose --production`. It refuses a database holding other tables, dumps the database before a switch, and refuses a release with another schema without `--recreate-database`.
- **Inside the pod** the layout is the one above (the code at `/usr/local/lib/php/ctnlist/<version>`, the installation at `/var/www/<installation>`), so the front controller and the console commands are unchanged; on the server everything is under `~/ctnlist`.

`bin/dev test-podman-kit` runs the kit in a stand-in server ([Testing and validation](#testing-and-validation)). Use the installer or the kit for an installation, not both.

### New installation

The steps by hand, which the installer automates. The new server starts from an empty database; there are no upgrade migrations (see Database setup).

1. Create the PostgreSQL role and database (a generated password, e.g. `openssl rand -base64 24`).
2. Install the code at `/usr/local/lib/php/ctnlist/<version>/` and run `composer install --no-dev --optimize-autoloader`.
3. Create the installation directory with the layout above; copy the release's `public_html/` into it.
4. Write `.env` from `.env.example`. Generate each secret (`APP_SECRET`: `openssl rand -hex 32`; `APP_INSTANCE_ID`: `openssl rand -hex 16`; `APP_SETTINGS_KEY`: `openssl rand -base64 32`), never reuse documentation or another installation's values; `APP_ENV=prod`, `APP_DEBUG=false`, the public `https://` `APP_BASE_URL`, `DB_*`, `GDB_ENV_DIRECTORY`/`GDB_ENV_FILE` (the suppression database's `ban.env`), `MAILER_DSN`, and the real sender and administrator addresses (`APP_ADMIN_EMAIL` becomes the first administrator at its first sign-in).
5. Create the schema: `CTNLIST_INSTANCE_DIR=/var/www/<installation> composer migrate-paralegal` (and `GDB_ENV_DIRECTORY=<directory of ban.env> composer migrate-banlist` where this server hosts the suppression database: the banlist configuration does not read the installation `.env`; see Database setup).
6. As `www-data`: `bin/console cache:clear` (which warms the production cache), then `bin/console asset-map:compile`. Always in this order: in production the cache is not refreshed when code changes, so assets compiled with an older cache are stale.
7. PHP-FPM pool from `deploy/php-fpm/ctnlist.conf.example`; nginx from `deploy/nginx/` (`nginx -t`, reload); TLS certificate.
8. The scheduled commands from `deploy/cron/ctnlist.cron.example` or the systemd timers in `deploy/systemd/` (one of the two).
9. Preflight: `sudo -u www-data env CTNLIST_INSTANCE_DIR=/var/www/<installation> php bin/console ctnlist:diagnose --production` must report no ERROR (the worker and maintenance warnings clear after their first runs). `https://<host>/health` answers `OK`.
10. Optional: `sudo -u www-data env CTNLIST_INSTANCE_DIR=/var/www/<installation> php bin/console ctnlist:templates:samples` adds three sample email templates (a newsletter, an announcement and a letter) to adapt under **Admin → Templates**; and Mailpit for proofs ([Proofs to Mailpit](#proofs-to-mailpit)).
11. Connect the mail service: sign in as the administrator, **Admin → Settings → Delivery**, enter its address and approve there ([catto-mail integration](#catto-mail-integration), *Connecting*). The key and the webhook endpoint are created by that; nothing is copied into `.env`. Then check delivery end to end with the [Release checklist](#release-checklist)'s proof, test campaign and webhook round trip before campaigns are sent. (`bin/dev test-cattomail` is the development stack's end-to-end check against a local catto-mail and refuses any other; see [catto-mail integration](#catto-mail-integration).)

### New release

By hand (the installer does the same): install the new version beside the old one (`/usr/local/lib/php/ctnlist/<new version>/`, `composer install --no-dev --optimize-autoloader`), then per installation: copy the release's `public_html/index.php` and `public_html/vendor/`, run `cache:clear` and then `asset-map:compile` as `www-data` (the installation's cache still belongs to the old release), point the cron entries or systemd units at the new path, reload PHP-FPM, and run `ctnlist:diagnose --production`, which reports a front controller that still loads the old tree, a missing CKEditor copy and stale compiled assets. Compiled assets are written to the installation's own `public_html/assets/`; installations never share them.

### Release checklist

Before a release goes live. The items marked † need the production server and the real catto-mail, so for the first release on the new server they are done there.

- [ ] `bin/dev check` passes (lint, PHPStan, every test suite, `composer validate` and `composer audit`) and the release is committed and tagged.
- [ ] The schema migrates into an empty database (New installation, step 5) and `ctnlist:diagnose` finds it up to date.
- [ ] As `www-data`, for each installation: `cache:clear`, then `asset-map:compile`.
- [ ] † `.env` written from `.env.example` with generated secrets, `640` root:`www-data`; `APP_SETTINGS_KEY` (it also decrypts the mail service connection) and `APP_SECRET` kept with the installation's secrets ([Backups and recovery](#backups-and-recovery)).
- [ ] † A database backup taken before the switch, and the backup routine in place.
- [ ] † PHP-FPM pool and nginx from `deploy/` validated (`php-fpm8.5 -t` for PHP 8.5, `nginx -t`) and reloaded; the TLS certificate valid and plain HTTP redirected to HTTPS.
- [ ] † The worker and maintenance scheduled (cron or systemd timers) and each seen running.
- [ ] † `ctnlist:diagnose --production` as `www-data` reports no ERROR; `https://<host>/health` answers `OK`.
- [ ] † The mail service connected on the Delivery page (or set in `.env`, with `<APP_BASE_URL>cattomail/webhook` registered by hand), and the page shows that the From address's domain can send; `ctnlist:cattomail:check` reports it reachable and authenticated, and `bin/dev test-cattomail` has passed against a development catto-mail.
- [ ] † `abuse@<APP_DOMAIN>` is a mailbox that is read: the acceptable use policy sends abuse reports and complaints there, and shows no other address.
- [ ] † If proofs go to Mailpit (`MAIL_PROOF_SMTP`): `ctnlist-mailpit` is running, its SMTP and web ports listen on 127.0.0.1 only, `https://<host>/mailpit/` asks for the password, and a proof sent from the message list appears there.
- [ ] † A proof to an address you can read, a small campaign to a test list, a webhook round trip (delivery and bounce events on **Admin → Settings → Delivery**) and an address-validation job.
- [ ] † Recovery sanity check: the Delivery page shows no stuck work, and a send job without a final webhook is reconciled after `CATTOMAIL_RECONCILE_AFTER_SECONDS`.

### Request limits

| Request | Limit |
|---|---|
| Any request | nginx `client_max_body_size 32m`; PHP `post_max_size 32M`, `upload_max_filesize 24M`, `max_execution_time 300`, which queue runs and subscriber imports raise for themselves (`deploy/php-fpm`) |
| Subscriber import file | 20 MB, `.txt`/`.csv` (form validation); pasted address lists up to 10 MB |
| Profile picture | the editor posts only the cropped square (well under 1 MB); without JavaScript the original (up to 8 MB) |
| catto-mail webhook | 1 MiB: nginx `client_max_body_size 1m` on `/cattomail/webhook`, and the application refuses a larger declared or actual body before verifying the signature |
| Message subject, names, settings | field lengths in the forms (e.g. subject 250, one-line settings 2,000 characters) |

### Health check

`GET /health` answers `OK` (`text/plain`, `no-store`) while PHP-FPM and the application run, for an uptime monitor or load balancer. It reveals nothing else, queries no database and is not recorded in the Site Log. Configuration and worker health are in `ctnlist:diagnose` and on **Admin → Settings → Delivery**, never public.

### Console commands

Console commands and migrations act on one installation, selected with `CTNLIST_INSTANCE_DIR` (in production, run them as `www-data`):

```bash
CTNLIST_INSTANCE_DIR=/var/www/example bin/console cache:clear
CTNLIST_INSTANCE_DIR=/var/www/example bin/console asset-map:compile
CTNLIST_INSTANCE_DIR=/var/www/example composer migrate-paralegal
CTNLIST_INSTANCE_DIR=/var/www/example bin/console ctnlist:diagnose --production
CTNLIST_INSTANCE_DIR=/var/www/example bin/console ctnlist:templates:samples
```

## Configuration

Per-installation settings are read from `.env` outside the public web directory. Start with `.env.example`. `APP_ENV` is `prod`, `dev` or `test`. `TRUSTED_PROXIES` lists reverse proxies whose `X-Forwarded-*` headers are trusted (client IP for rate limits and logs, HTTPS detection). With nginx passing requests straight to PHP-FPM (`deploy/nginx`), PHP sees the client address and HTTPS directly and the snippet drops client-supplied forwarding headers; list a proxy only when one sits in front of nginx, and never `0.0.0.0/0` (`ctnlist:diagnose` warns).

The main application uses `DB_*` settings. Global suppression comes from `SUPPRESSION_PROVIDER`:
- `banlist` (the default): the separate global suppression database, shared by installations, with `GDB_*` settings in an externally managed `ban.env`. Set `GDB_ENV_DIRECTORY` and, when necessary, `GDB_ENV_FILE` in the installation `.env` (default: `config/phinx/ban.env` in the shared tree); the same variables can be exported when running the banlist Phinx configuration.
- `cattomail`: no suppression database. catto-mail, which delivers every campaign, refuses suppressed recipients when a send is staged and suppresses hard-bounced and complaining addresses itself, and ctnlist marks those subscribers from its outcomes ([catto-mail integration](#catto-mail-integration)). ctnlist still applies the v5 address rules at confirmation, queue build and before each send. A subscriber's "all lists" unsubscribe then unsubscribes them from this site's lists only, and the administrator's bulk scopes that only record global suppressions (domains, bounces, spam complaints) are refused. `GDB_*` are not read.
- `none` disables suppression altogether and is only accepted with `APP_ENV` `dev` or `test`.

Transactional mail (sign-in links, invitations, notifications, contact-form mail) goes through the SMTP server in `MAILER_DSN`, one connection per message. Campaign content goes through catto-mail (`CATTOMAIL_*`, below). Optional legacy synchronisation targets use `SYNC_DATABASES_JSON`.

Sign-in links expire after `AUTH_MAGIC_LINK_TTL` seconds and are rate-limited per address (`AUTH_MAGIC_LINK_MAX_PER_EMAIL` within `AUTH_MAGIC_LINK_EMAIL_WINDOW`) and per client IP (`AUTH_MAGIC_LINK_MAX_PER_IP` within `AUTH_MAGIC_LINK_IP_WINDOW`); a signed-in session lasts `AUTH_SESSION_TTL` seconds. The sign-in page and email state the link lifetime in words from the same setting. Contact messages are limited per hour per client IP and per address (`CONTACT_RATE_LIMIT`, default 5), forwards and resends per subscriber (`FORWARD_RATE_LIMIT`, default 10; holders of `messages.manage` are exempt), and requests or withdrawals of the catto-mail global opt-out to six per subscriber per hour (each one calls catto-mail); over a limit the page answers 429. Public subscribe requests and the confirmation prompts on subscriber links send sign-in links, so the sign-in limits apply to them. There is no CAPTCHA. Contact-form submissions are appended to `CONTACT_LOG_FILE` (default: the installation's `logs/contact.log`). Bulk-subscribe and import logs (`emails_added.txt`) and the export files (`export-subscribers.txt`, `export-remove.txt`) are written to `APP_LOG_DIR` (default: the installation's `logs/`).

### Settings page

Administrators, and any role given the `settings.manage` permission, can change a selected set of settings at **Admin → Settings** without editing `.env`: site identity, contact details, the mail sender and proof address, the transactional SMTP server, the contact form, the subscription messages, the archive and the sign-in limits. The SMTP server is for administrators only: it receives every sign-in link, so whoever could change it could sign in as anyone.

- Each setting resolves as **database override → `.env` → built-in default**, and the page shows which one is in effect (Database, .env or Default). Overrides are stored in the `options` table; nothing is written to `.env`. Database overrides are optional: an installation without any runs exactly from its `.env`.
- Saving a value equal to the inherited one, or "Reset override" / "Reset section", removes the override so `.env` (or the default) applies again. Changes apply from the next page or email; a send that is already running keeps the SMTP settings it started with.
- Secret settings are encrypted in the database with AES-256-GCM using the dedicated `APP_SETTINGS_KEY` from `.env` (32 random bytes, Base64; generate with `openssl rand -base64 32`), and `APP_SETTINGS_KEY` is required to decrypt all of them. The secret values are the SMTP server (`MAILER_DSN`, which carries its password) and, for a mail service connected on the Delivery page, its API key and webhook secret. The key is needed only once a secret is saved; without it the SMTP section and the Delivery page explain what to add. Secrets are never sent to the browser (leave the field empty to keep the current one) and never logged.
- **Do not change `APP_SETTINGS_KEY` while encrypted settings exist**: they become unreadable, and mail that needs them fails (the Settings page and the Delivery page report it) until they are entered again or reset, and the mail service is connected again. A future key rotation must decrypt with the old key and re-encrypt with the new key before the new key is deployed.
- The mail service's address, API key and webhook secret are not on this page: they come from Connect on the Delivery page or from `.env` (which wins).
- Stay in `.env` only: `APP_SECRET`, `APP_SETTINGS_KEY`, `APP_ENV`/`APP_DEBUG`, `APP_INSTANCE_ID`, the other catto-mail settings (`CATTOMAIL_*`: opt-out capability, tracking, timeouts, retention), the database and suppression-database settings, `TRUSTED_PROXIES`, `APP_BASE_URL`/`APP_DOMAIN`, `APP_TIMEZONE`, file paths (`CONTACT_LOG_FILE`, `APP_LOG_DIR`), the cookie names (`AUTH_SESSION_COOKIE`, `PHP_SESSION_COOKIE`), `APP_ADMIN_EMAIL` and the synchronisation targets.
- Web addresses must be `http(s)://`; one-line values (names, subjects) cannot contain line breaks.

### Security notes

- Secrets are generated, never typed: `APP_SECRET` with `openssl rand -hex 32`, `APP_INSTANCE_ID` with `openssl rand -hex 16`, `APP_SETTINGS_KEY` with `openssl rand -base64 32` (`bin/dev ensure-env` does this for the development stack and never replaces an existing key). `.env.example` leaves every secret empty; `ctnlist:diagnose --production` refuses short or placeholder values.
- **Methods and CSRF.** GET never changes state (tracking excepted: the open pixel, the archive view counter, the contact-link engagement bump). Every POST is a Symfony form or carries the `ctnlist` CSRF token (checked by the firewall for sign-in and logout), except the signed catto-mail webhook and the signed one-click unsubscribe link. A wrong method gets 405 before any controller runs.
- **Access control** is enforced by the controllers (`#[IsGranted]` with ACL permissions; administrators hold every permission); links are only hidden in the templates. Roles and permissions can only be granted by someone who holds them.
- **Sign-in.** Links carry 256-bit random tokens, stored only as SHA-256 hashes, valid once and for `AUTH_MAGIC_LINK_TTL`. Opening a link only shows a page with a Sign in button (Continue for a list confirmation); the link is used up by that button's POST, after its CSRF token is checked, so mail scanners and link previews that open links use nothing up. Parallel sign-ins with one link sign in once; used, expired and invalid links say so and offer a new one. Requesting a link answers the same for every address. Sign-in rotates the PHP session id (CSRF tokens and messages) and logout destroys it, besides revoking the auth session. Return paths are built from fixed internal routes, never from request values.
- **Cookies.** The auth cookie (`AUTH_SESSION_COOKIE`) is HttpOnly, SameSite=Lax, host-only, path `/`, lives `AUTH_SESSION_TTL`, and is Secure whenever the request is HTTPS or `APP_BASE_URL` is https. The PHP session cookie (CSRF tokens, flash messages) is HttpOnly, SameSite=Lax, a browser-session cookie, and Secure on HTTPS requests (`cookie_secure: auto`), so the development stack keeps working over plain http. Anonymous pages without a form set no cookie.
- **Headers.** Every application response carries `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin` (subscriber UUIDs are in paths), a `Permissions-Policy`, HSTS over HTTPS, and a Content-Security-Policy (`App\Http\ContentSecurityPolicy`): `default-src 'self'`; scripts from the site plus a per-request nonce for the import map (no `unsafe-eval`, no `unsafe-inline`); styles, fonts and connections from the site only; images from the site, `data:` and `blob:` (the profile picture editor); `object-src 'none'`, `base-uri 'none'`, `form-action 'self'`, and `frame-ancestors 'none'` (no page may be framed; nothing needs embedding). The message and template editors (CKEditor writes inline styles) and the public archive page (authored campaign HTML keeps its inline styles and remote images) allow inline styles and https images; scripts stay nonce-only there too. In debug mode Symfony removes the policy from its own exception pages. nginx adds `nosniff`, caching and HSTS to the static files it serves (`deploy/nginx`).
- **Frontend files are served by the site.** Bootstrap 6.0.0-alpha.1 and Bootstrap Icons 1.13.1 (with their font files) are vendored under `assets/lib/` and served through AssetMapper, verified against their published checksums (`assets/lib/README.md`); there is no runtime CDN. Bootstrap 6 is a pre-release and supports only recent browsers (Chrome and Edge 130, Firefox 132, Safari 18 on macOS and iOS, or later): on older ones pages do not render correctly, the public subscribe, sign-in and unsubscribe pages included. The site always uses the light colour mode. CKEditor 5 (48.3.0) is a prebuilt bundle in `public_html/vendor/ckeditor5/`, with the emoji list it would otherwise fetch from CKEditor's CDN, loaded only on the message and template editors.
- **Output.** Twig escapes everything. The only HTML printed as such on the site is message HTML on the public archive, after the sanitiser (`config/packages/html_sanitizer.yaml`: no scripts, event handlers, `javascript:` links, frames or forms). Mail is sent as composed; subscriber names and configured URLs are escaped in its HTML part. Web addresses in Settings must be http(s); one-line settings (sender name, subjects) cannot contain line breaks.
- **Contact links.** A message's `{contact}` link opens the contact form for anyone who holds it, because messages are forwarded. The form is filled in with the subscriber's details only for that subscriber, signed in, and the acknowledgement sent to whoever submits it holds only what they wrote. The subscriber and message of the link are named in the organisation's copy, the contact log and the message's activity report.
- **Errors.** With `APP_DEBUG=false` error pages show no exception, SQL, path or trace, also while the database is unavailable; the details go to the log.
- **Logs** never contain secrets, setting values, tokens or message bodies; the Site Log omits query strings and masks one-click unsubscribe signatures. The nginx template's access-log format records no query strings (the sign-in link carries its token there).

### Message placeholders

A template wraps a message: `{content}` in the template's HTML and text parts is where the message's own HTML and text go, and every other placeholder works in both. Three samples to use and adapt (a newsletter, an announcement and a letter; `resources/sample-templates/`) are added by `bin/console ctnlist:templates:samples`; development has them from `bin/dev up`. They are ordinary templates afterwards: edit, rename or delete them under **Admin → Templates**.

Placeholders are case-insensitive. For a subscriber: `{firstname}`, `{lastname}`, `{emailsleft}`, `{subscription}` (the subscription message, itself filled), `{unsubscribe}`, `{confirm}`, `{forward}`, `{preferences}`, `{like}`, `{dislike}`, `{contact}` (the contact form, recording the subscriber and the message), `{usertrack}` (open-tracking pixel, HTML only), `{suid}`, `{muid}`, `{baseurl}`, `{listshortcode}`; for everyone: `{subscribe}`, `{archive}`, `{listname}`, `{domain}`, `{organisation}`, `{facebook}` (`APP_FACEBOOK_URL`), `{twitter}` (`APP_X_URL`). In archives subscriber links become plain labels (UNSUBSCRIBE, FORWARD, UPDATE, OPT IN, YES, NO, CONTACT FORM), names and identifiers are empty. Site links whose URL is not configured render nothing. `{STORE}`, `{lms-booking}`, `{booking}` and `{advertise}` are retired and render nothing: write such a link in the message or template instead, where `{suid}` and `{muid}` identify the recipient and the message (`https://example.com/book?s={suid}&m={muid}`). Proofs fill names with test values and point subscriber links at a page explaining that a proof has no subscriber.

### Proofs to Mailpit

A proof ("Send to me" on the message list, **Send proof**) is the message merged with its template for a test recipient. By default it goes through the mail service like all campaign mail and arrives in the mailbox of the address given. Setting `MAIL_PROOF_SMTP` in the installation's `.env` to the host and port of a [Mailpit](https://mailpit.axllent.org/) instance's SMTP listener sends proofs straight there instead, bypassing the mail service:

```dotenv
MAIL_PROOF_SMTP=127.0.0.1:1025
```

- Mailpit keeps what it is given and delivers nothing. The proof is read on Mailpit's web page; **it does not reach the address it is made out to**, which only appears as its recipient. The proof page and its result say so.
- Proofs only. Queue sends, resends and forwards always go through the mail service.
- A proof handed to Mailpit is logged in the Send Log as PROOF, like any proof. It needs no mail service: proofs work before one is connected, and do not count against its quota.
- If Mailpit cannot be reached the proof is refused with the reason; it does not fall back to the mail service and nothing is kept for a retry.
- The setting is `.env` only (not on the Settings page), plain SMTP without credentials or TLS, so use an address on the same server. `ctnlist:diagnose` reports where proofs go, an unusable value as an ERROR and, in production, an address that is not on this server as a WARN.
- Empty (the default): proofs go through the mail service.

**Development.** `dev/podman/ctnlist.env.dist` sets `MAIL_PROOF_SMTP=mailpit:1025`, the stack's own Mailpit (http://localhost:8125), so proofs work without a mail service. An environment file from an earlier checkout is not edited: add the line yourself (`bin/dev up` says when it is missing). `bin/dev test-cattomail` still sends its proof through the mail service.

**Production**, with Mailpit on the ctnlist server (one Mailpit serves every installation on it):

1. Install the Mailpit binary as `/usr/local/bin/mailpit` (a single file from its [releases](https://github.com/axllent/mailpit/releases); check the published checksum).
2. Create its user and password file. The password protects the web page and the API; Mailpit checks it itself (basic auth, an htpasswd file):
   ```bash
   useradd --system --no-create-home --shell /usr/sbin/nologin mailpit
   install -d -m 750 -o root -g mailpit /etc/ctnlist
   htpasswd -B -c /etc/ctnlist/mailpit-users <name>          # from apache2-utils; asks for the password
   chown root:mailpit /etc/ctnlist/mailpit-users && chmod 640 /etc/ctnlist/mailpit-users
   ```
3. Install `deploy/mailpit/ctnlist-mailpit.service` as `/etc/systemd/system/ctnlist-mailpit.service`, then `systemctl daemon-reload && systemctl enable --now ctnlist-mailpit`. It listens for SMTP on `127.0.0.1:1025` and serves its page on `127.0.0.1:8025` under `/mailpit/`; neither is reachable from outside the server. It keeps the newest 500 messages for at most 30 days in `/var/lib/ctnlist-mailpit`.
4. Publish the page through nginx: put `deploy/nginx/mailpit.location.conf.example` into the installation's HTTPS server block (`nginx -t`, reload). Proofs are then read at `https://<host>/mailpit/`, after Mailpit's own name and password prompt; the password only ever travels inside TLS.
5. Set `MAIL_PROOF_SMTP=127.0.0.1:1025` in each installation's `.env`. It applies from the next request.
6. Check: `ctnlist:diagnose` says "proofs go to Mailpit at 127.0.0.1:1025"; send a proof and find it at `https://<host>/mailpit/`; `curl -I https://<host>/mailpit/` without a password answers 401.

Port 1025 must stay closed to the outside (it is bound to loopback; do not publish or forward it): anything that can connect to it can put mail into Mailpit. A proof holds the message's content with test values ("Test Recipient"), never a subscriber's details, but it is unpublished content: give the password only to the people who write messages.

## catto-mail integration

ctnlist is the first client of the [catto-mail](https://github.com/rrcatto/catto-mail) smarthost. The two applications stay separate: ctnlist talks to catto-mail only through its HTTPS `/v1` API (`App\CattoMail\CattoMailClient`, the only class that does) and receives its signed webhooks. ctnlist never touches catto-mail's database. ctnlist keeps subscribers, lists, consent and ordinary unsubscribes, campaigns, templates, audience selection and the per-recipient rendering. catto-mail does address validation, SMTP delivery, DSNs, complaints, global transport suppression, tracking and metering.

**Connecting.** An administrator connects the mail service on **Admin → Settings → Delivery**, with catto-mail's connect flow (catto-mail's integration guide, section 15: an authorisation-code flow with PKCE):
1. Enter the mail service's address (the address you sign in to it at; the address of any of its pages will do) and press **Connect**. Your browser is sent to the mail service.
2. Sign in there (or open an account, where the service offers that), choose the client to connect and approve. Only an admin of an active client can approve, and the client needs room for one more API key and webhook endpoint.
3. Your browser comes back, and ctnlist collects from the mail service, server to server, what it hands over once: the API address, an API key named after this site (`APP_LIST_NAME`) and the signing secret of a webhook endpoint for `<APP_BASE_URL>cattomail/webhook`, subscribed to the six events ctnlist handles. They are stored in the Settings store (`options`), the key and the secret encrypted with `APP_SETTINGS_KEY`. Nothing secret passes through the browser, a log or a page.

The page then shows what is connected (the service, the catto-mail client, who connected it and when), and whether the domain of the site's From address (`MAIL_FROM_ADDRESS`) is one of that client's sending domains and can send (`GET /v1/sending-domains`; read when the connection is made and by **Check connection**). A domain that is missing or not yet verified is a warning: catto-mail refuses mail from it. A message or list with its own sender needs that domain registered in the same way.

- **Disconnect** asks the mail service to end the connection (`DELETE /v1/connection`: it revokes the key and removes the webhook endpoint), then clears the stored values. If the service cannot be reached nothing changes, so it can be tried again. **Forget without revoking** only clears the stored values, for a service that no longer answers or a stored key that can no longer be read; the key then stays valid at the service until the connection is ended in its dashboard (Settings › Connected applications).
- **Connect again** replaces the connection: catto-mail ends the earlier connection to the same webhook address when the new one is collected.
- Only administrators can connect or disconnect, whatever else a role's `settings.manage` allows: whoever chooses the mail service receives every campaign and every recipient's address.
- The approval must be finished within an hour of pressing Connect, and catto-mail's code within 10 minutes of approving. A refusal (declined, expired, the client at its limit, the service unreachable) stores nothing and says why.
- Outside development both this site and the mail service must be on https.
- A looping worker (`ctnlist:cattomail:work --loop`) reads the connection when it starts: restart it after connecting or disconnecting. A worker started from cron every minute needs nothing.
- If `APP_SETTINGS_KEY` is replaced, the stored key can no longer be read: the page says so, and the remedy is to restore the key or to connect again.

**Configuration in `.env`** (instead of a connection, or beside it). The address, the key and the secret can also be set in the installation's `.env`; **a value set there always wins over the connection**, and Connect is refused while one of the three is set, because the connection would not be used. They are never editable Settings and never committed; a changed value applies from the next request.

| Variable | Purpose |
|---|---|
| `CATTOMAIL_API_BASE_URL` | catto-mail's HTTPS origin (`/v1` is added). |
| `CATTOMAIL_API_KEY` | This installation's API key, sent as `Authorization: Bearer`; never rendered or logged (errors and log lines are redacted). Created by hand in catto-mail's dashboard when it is set here. |
| `CATTOMAIL_WEBHOOK_SECRET` | The signing secret catto-mail showed when the webhook endpoint `<APP_BASE_URL>cattomail/webhook` was registered there by hand. |
| `CATTOMAIL_WEBHOOK_SECRET_PREVIOUS` | Only during a secret rotation: the previous secret, for catto-mail's overlap window. After catto-mail rotates, put the new secret in `CATTOMAIL_WEBHOOK_SECRET` and the old one here; remove it when the overlap has passed. |
| `CATTOMAIL_GLOBAL_OPTOUT_ENABLED` | `true` only when catto-mail's operator granted this client the global-opt-out capability. |
| `CATTOMAIL_CA_FILE`, `CATTOMAIL_API_CONNECT_HOST`, `CATTOMAIL_WEBHOOK_URL` | Development only: trust catto-mail's self-signed certificate; reach its API host through another name and, as `name:port`, port; ask a connection to deliver webhooks to an address catto-mail's development pod can reach (the last two are ignored outside `dev`/`test`). |
| `CATTOMAIL_RECONCILE_AFTER_SECONDS` | Poll jobs that have had no final webhook after this long (default 900; 300 in development). |
| `CATTOMAIL_TRACK_OPENS`, `CATTOMAIL_TRACK_CLICKS` | catto-mail open/click tracking per send job (default off). |

**Sending.** Every campaign delivery (queue sends, proofs, resends, forwards) is rendered by ctnlist for its recipient and handed to catto-mail. catto-mail does no mail merge.
- A sending run (one queue run, proof, resend or forward) gets send jobs, one per message and list, of at most 10,000 recipients each: a bigger run gets several jobs, numbered `<run uuid>/<n>` (their `external_reference`).
- Recipients are uploaded in batches of at most 500, and smaller ones when the message is large (each recipient carries its own rendered copy, and catto-mail accepts at most 10 MiB per request); then the job is submitted. A message too large for one upload is refused before anything is staged, and its queue rows stay.
- Each recipient carries its final subject, HTML and text, `external_recipient_reference` (the ctnlist delivery's UUID) and, for subscription mail, a per-recipient one-click `unsubscribe_url`. That URL is `https://…/unsubscribe-link/…`, signed, without tracking, and unsubscribes from that list only.
- Proofs, and resends of a draft without lists, are transactional jobs. With `MAIL_PROOF_SMTP` set, proofs do not come here at all: they go to Mailpit ([Proofs to Mailpit](#proofs-to-mailpit)).
- A message's From domain must be a sending domain registered in catto-mail, and subscription mail needs an https `APP_BASE_URL`.
- **Admin → Settings → Delivery (catto-mail)** shows each message's send jobs, every recipient's message state, and a recipient's event history read live from catto-mail. `remote_accepted` is acceptance by the receiving server, not inbox delivery; `open_recorded` is not proof of reading.
- Only one queue run sends at a time per database (a PostgreSQL advisory lock): a second one answers "The queue is already being sent." A run that dies (time limit, PHP-FPM reload, reboot) blocks nothing, because the database frees its lock.
- A queue row is removed only in the same transaction that stores its staged delivery, and only once: a run that finds the row already taken rolls back. The database also allows only one active campaign delivery of a message to a subscriber.

**Integration status** (**Admin → Settings → Delivery**, permission `logs.view`). The page shows:
- the mail service: connected (with Connect again, Disconnect and Forget for administrators), configured in `.env`, or not connected (with Connect), and whether the From address's domain can send;
- the configuration (the API key and secrets are never shown, only whether they are set and where they come from);
- **Check connection**: one read-only authenticated request (`GET /v1/send-jobs/<an id that cannot exist>`; the API has no health endpoint, and 404 proves network, TLS and the key). The result is one of: not configured, host name not found, cannot connect, TLS failure, API key rejected, client not permitted, temporarily unavailable, or reachable and authenticated;
- the last successful call and the last API error;
- the worker's last run, with a warning when it seems not to run;
- pending or stuck work: unsealed or refused jobs, staged recipients, empty jobs left collecting, pending validations and opt-outs, and unprocessed or failed webhook events;
- recent send runs, each with its own page listing the catto-mail jobs it became;
- a webhook-event page (all, not processed, failed, ignored), with links to the job each event concerns.

From the command line, `bin/console ctnlist:cattomail:check` shows the same configuration and connection check (exit status 0 only when reachable and authenticated).

Error messages name the fix: a rejected key points to `CATTOMAIL_API_KEY`, an unregistered From domain to catto-mail's sending domains, an unreachable API says the work is kept and retried. catto-mail's field errors (where it gives the reason) are included; keys and secrets are redacted everywhere.

**Send-job states.** ctnlist's own states come first: `open` (staging) and `ready` (closed, not yet sealed at catto-mail). After that it stores catto-mail's states unchanged:
- `queued`: sealed by submit;
- `processing`;
- `dispatched`: everything handed to Postfix, suppressed or failed;
- `completed`: every message has a final state, which can take days;
- `failed` or `cancelled`.

A job only moves forward, decided in one SQL statement, so a late webhook or a stale poll can never move it back. catto-mail's `collecting` never replaces ctnlist's own state. Recipient states are catto-mail's message states (`remote_accepted`, `soft_bounced`, `hard_bounced`, `complained`, `failed`, `suppressed`, …). A final state is never replaced by a non-final one, and a hard bounce or complaint is never replaced at all.

**What "sent" means.** A recipient's Send Log row and smlog sent mark are written when catto-mail seals its job (`POST /send-jobs/{id}/submit` answers 202; when that answer was lost, as soon as catto-mail reports the job sealed). That is ctnlist handing the message to its mail transport. It does not mean the receiving server accepted it (`remote_accepted`) or that it reached an inbox. The Delivery pages show those outcomes.

**Empty jobs.** If every response to a job's creation was lost, catto-mail can hold an empty job in `collecting`. Its recipients are then staged in a new job, sent once. The contract has no way to cancel a job, and an empty job can't be submitted, so ctnlist marks it cancelled on its side and shows the count on the status page. Such a job sends nothing.

**Retries.** Timeouts, connection failures, 5xx, 408, 425, 429 and `idempotency-in-progress` are retried up to three times in place with the same key, honouring `Retry-After` up to 10 seconds; after that the worker retries later. Other 4xx answers are refusals and are not retried. A refused job's recipients are marked `not_sent` and can be returned to the queue.

**Matching.** Results are matched by reference, never by position:
- message states by `external_recipient_reference` (the ctnlist delivery UUID);
- validation results by `external_address_reference` (the subscriber UUID);
- batches by their own key.

**Idempotency.** Each operation's `Idempotency-Key` (validation job, send job, every recipient batch, global opt-out) is generated once and stored with its row before the request is sent. Every retry, in place or by the worker, resends the same key and the same body, so a timeout can never create a second job, batch or opt-out. Rendered content is kept until its batch is accepted.

**Webhooks.**
- `POST /cattomail/webhook` takes no session and no CSRF token.
- It verifies `Smarthost-Signature` (`t=<unix>,v1=<hex HMAC-SHA256 over "<t>.<raw body>">`) against the current and previous secret, in constant time, before parsing. Timestamps more than 5 minutes off are rejected.
- It stores the event under its unique event id and answers 200 at once; a duplicate gets 200 and no second effect.
- Processing happens after the response is sent.
- Handled: `validation.completed`/`failed` (results fetched), `send.completed`/`failed` (job and message states fetched), `message.hard_bounced`, `message.complained`. Unknown types are kept and ignored.
- States never move backwards, so late or reordered events change nothing.

**Worker.** Webhooks are the primary path; the worker is the safety net. Run `ctnlist:cattomail:work` every minute per installation, as the PHP-FPM user, from cron or a systemd timer (templates in `deploy/`, see [Commands and cron](#commands-and-cron)):

```cron
* * * * *  www-data  CTNLIST_INSTANCE_DIR=/var/www/example php /usr/local/lib/php/ctnlist/<version>/bin/console ctnlist:cattomail:work --no-interaction
```

Each pass:
- processes stored webhook events not yet processed;
- retries unsealed send jobs, pending opt-outs and withdrawals, and validation submissions (same keys);
- reconciles by polling jobs without a final state whose last check is older than `CATTOMAIL_RECONCILE_AFTER_SECONDS` (`GET /v1/send-jobs/{id}` with its messages, `GET /v1/validation-jobs/{id}` with its results).

Running it every minute keeps retries prompt; polling still waits for the reconciliation interval.

Output and safety:
- It prints one line per pass that did or failed something (every pass with `-v`): counts only, never keys, secrets or content.
- Exit status 1 means an item failed and stays for the next pass. Nothing to do, or another worker already running, is status 0.
- Only one worker runs at a time per database (a PostgreSQL advisory lock), and every effect is idempotent besides.
- Each pass is recorded for the status page.

**Hard bounces and complaints** (from webhooks or polling, applied once per delivery). catto-mail already suppresses these addresses globally; ctnlist only keeps its business state:
- A hard bounce sets the subscriber's delivery state to `hard_bounced`, adds to `s_bounces` and the message's `m_bounces`, and removes their queued deliveries. Consent and memberships are unchanged.
- A complaint sets `complained` and unsubscribes them from every ctnlist list, with the reason "Spam complaint reported through catto-mail".
- Either state keeps the subscriber out of campaign selection until an administrator clears it on the subscriber page.

**Unsubscribe and global opt-out.** Every ordinary unsubscribe (one list, the one-click link, all ctnlist lists, the ctnlist suppression database) is ctnlist state and is never sent to catto-mail. Separately, when `CATTOMAIL_GLOBAL_OPTOUT_ENABLED` is true, a signed-in subscriber can explicitly ask for "no email from any sender using our mail service" (unsubscribe page or profile):
- ctnlist records it and reports `POST /v1/global-suppressions`;
- because they want no email at all, ctnlist also unsubscribes them from all its lists;
- withdrawing it reports `POST /v1/global-suppressions/{id}/lift` and does not re-subscribe them.

**Address validation.** **Admin → Subscribers → Address validation** submits every member of a list who has not unsubscribed, each with the subscriber's UUID as `external_address_reference`. A list over 10,000 members (catto-mail's limit per job) becomes several jobs, "part 1 of N", and the administrator is told the total and the number of jobs; nobody is left out. Each job shows its counts per classification, the suggestions separately, and its submission, last-check and completion times. Results are mapped back by that reference and stored exactly as classified:
- `unknown` stays unknown, and `temporarily_unverifiable` is not invalid;
- a suggested address is shown, never applied;
- a deliverable address says nothing about consent;
- nothing about a subscriber changes automatically.

**Webhook secret rotation** (nothing rotates automatically):
1. When catto-mail rotates the endpoint's secret, it signs with both secrets during its overlap window.
2. Put the new secret in `CATTOMAIL_WEBHOOK_SECRET` and the old one in `CATTOMAIL_WEBHOOK_SECRET_PREVIOUS`.
3. Once the overlap has passed, remove the previous secret; from then on the old secret no longer validates.

The status page shows which of the two are set.

With a connection made on the Delivery page there is no secret in `.env` to replace: **Connect again** gives the endpoint a new secret (catto-mail replaces the earlier connection). If the secret of a connected endpoint is rotated by hand in catto-mail's dashboard instead, put the new one in `CATTOMAIL_WEBHOOK_SECRET`, where it wins over the stored one.

**Headers.** catto-mail accepts no arbitrary MIME headers:
- It sets the envelope (VERP return path), `List-Id` (from the job's `list_id`) and `List-Unsubscribe` with `List-Unsubscribe-Post` (from each recipient's signed `unsubscribe_url`).
- v5's `Sender`, `List-Owner`, `List-Post`, `List-Subscribe`, `List-Archive` and `X-ctnlist-*` headers are not sent.
- None of them is needed for delivery or one-click unsubscribe. `List-Archive`/`List-Subscribe` would need a catto-mail contract change (see below).

**What is stored.**
- Rendered recipient content is kept only until its batch is accepted, then cleared.
- Recipient rows keep the address, subject, states and references.
- Webhook events are kept for diagnosis and de-duplication; their raw bodies (`cattomail_webhook_events.cwe_payload`, which can contain recipient addresses and remote diagnostics) only until the retention period below.
- Address-validation results are kept per job.

`ctnlist:maintenance` removes webhook bodies after `CATTOMAIL_WEBHOOK_BODY_RETENTION_DAYS` and other temporary detail after `CATTOMAIL_DETAIL_RETENTION_DAYS` (see [Operations](#operations)).

**Possible catto-mail contract enhancements** (not needed for correct operation; not worked around):
- an authenticated health or identity endpoint, instead of reading a job that cannot exist;
- a way to cancel an empty `collecting` send job;
- structured `List-Archive`/`List-Subscribe` values per job.

### catto-mail development route

The development stacks are separate (catto-mail's pod is on an internal network, its dashboard and API published on `127.0.0.1:8443`; its webhook worker only delivers to public addresses, or to private hosts listed in its `APP_WEBHOOK_ALLOWED_PRIVATE_HOSTS`). The route is a dedicated internal Podman network, `cattomail-dev`:

1. `bin/dev cattomail-link` creates `cattomail-dev` and attaches ctnlist's web container (alias `ctnlist-web`) and app container. `bin/dev up` re-attaches them by itself whenever the network exists; without it, `up` works as usual and catto-mail stays optional. `bin/dev cattomail-status` lists what the route has and lacks (it never prints secret values).
2. catto-mail's side, in the catto-mail repository (its `docs/development-environment.md`, *Client development route*):
   - add `ctnlist-web` to `APP_WEBHOOK_ALLOWED_PRIVATE_HOSTS` in its `infra/.env`, then `infra/bin/smarthostctl install` and `recreate`: its pod joins `cattomail-dev` as `catto-mail` whenever that network exists;
   - have a client whose admin can approve the connection, with the domain of ctnlist's `MAIL_FROM_ADDRESS` as a verified sending domain.
3. `bin/dev cattomail-ca` saves catto-mail's self-signed development certificate (read from its public port) to `dev/podman/cattomail-ca.pem` (gitignored). Repeat it when catto-mail's certificate is regenerated.
4. In `dev/podman/ctnlist.env` (leave `CATTOMAIL_API_BASE_URL`, `CATTOMAIL_API_KEY` and `CATTOMAIL_WEBHOOK_SECRET` empty):
   - `CATTOMAIL_API_CONNECT_HOST=catto-mail:443`: catto-mail's address is `https://localhost:8443`, a port that exists only on your machine; from ctnlist's containers the same service answers at `catto-mail`, port 443. TLS is still verified for `localhost`;
   - `CATTOMAIL_CA_FILE=/usr/local/lib/php/ctnlist/6.1.0/dev/podman/cattomail-ca.pem`;
   - `CATTOMAIL_WEBHOOK_URL=http://ctnlist-web/cattomail/webhook`: where catto-mail's pod can reach ctnlist (plain http is accepted by a development catto-mail);
   - for subscription mail, `APP_BASE_URL=https://localhost:8543/` (ctnlist's development HTTPS listener).

   Then run `bin/dev restart`.
5. Connect: **Admin → Settings → Delivery**, mail service address `https://localhost:8443`, **Connect**; sign in to catto-mail as an admin of the client (its sign-in mail is in catto-mail's Mailpit, http://localhost:8026) and approve. In another terminal, `bin/dev console ctnlist:cattomail:work --loop 30` (start it after connecting: a looping worker reads the connection when it starts).
6. Check it: **Check connection** on that page, or `bin/dev console ctnlist:cattomail:check`.

Without the Connect button the route works as before: create the API key and the webhook endpoint `http://ctnlist-web/cattomail/webhook` in catto-mail by hand and put `CATTOMAIL_API_BASE_URL=https://localhost:8443`, the key and the secret in `dev/podman/ctnlist.env`.

**Optional real end-to-end check:** `bin/dev test-cattomail [--to=address] [--muid=message] [--wait=120]`. It is never part of the normal test suite, which uses the deterministic fake catto-mail (`tests/Support/FakeCattoMail`).
- **Safeguards:** it runs only with `APP_ENV` dev or test and a local development catto-mail (`CATTOMAIL_API_CONNECT_HOST` set, or a localhost/.test host). It refuses anything else, so production can't be targeted.
- **What it does:** checks the connection, sends one proof of a message (default: the newest) to `--to` (default `MAIL_TEST_ADDRESS`), and starts a validation job for that subscriber if there is one. It then follows both until catto-mail has finished. The proof lands in catto-mail's development mail capture.
- **Campaigns:** for a campaign check, queue a message to a small list of test subscribers and process the queue as usual. Then follow the run on the Delivery page and in catto-mail's capture, and check the one-click link from the `List-Unsubscribe` header.

All development mail then ends up in catto-mail's Mailpit (catto-mail's own capture mode); nothing reaches the Internet. Production uses public HTTPS in both directions and none of the development settings.

## Operations

### Commands and cron

| Command | When | What |
|---|---|---|
| `ctnlist:cattomail:work` | every minute | Moves catto-mail work on: stored webhook events, unsent jobs, opt-outs, validation submissions, polling of jobs without a final state. Webhooks are the primary path; this is the safety net. |
| `ctnlist:maintenance` | hourly | Housekeeping (below) and a count of stuck work. `--dry-run` only counts. |
| `ctnlist:diagnose` | by hand | Configuration and health: required settings, secrets set or not (never their values), PHP extensions, database (PostgreSQL 16 or later) and suppression database, schema, obsolete settings, writable directories, worker and maintenance heartbeats, whether the worker runs with the web server's configuration, stuck work. With `--production` (automatic under `APP_ENV=prod`) also the production rules: `APP_ENV`/`APP_DEBUG`, secret strength and placeholders, a public https `APP_BASE_URL`, a real mail transport, catto-mail configured, suppression provider, `TRUSTED_PROXIES`, example addresses, the installation's front controller and CKEditor copy, compiled assets, code and `.env` permissions. ERROR lines make the exit status 1. It sends no mail and calls no external service. |
| `ctnlist:cattomail:check` | by hand | The catto-mail connection check (`--e2e` only in development; its proof always goes through the mail service, whatever `MAIL_PROOF_SMTP` says). |
| `ctnlist:templates:samples` | by hand | Adds the three sample email templates that are not there yet (by name). It never replaces a template, so it can be run again. |

The two scheduled commands are kept separate: the worker must run often and be quick, and housekeeping need not. Each runs one at a time per database (PostgreSQL advisory locks), so overlapping cron runs skip.

```cron
* * * * *  www-data  CTNLIST_INSTANCE_DIR=/var/www/example php /usr/local/lib/php/ctnlist/<version>/bin/console ctnlist:cattomail:work --no-interaction
17 * * * * www-data  CTNLIST_INSTANCE_DIR=/var/www/example php /usr/local/lib/php/ctnlist/<version>/bin/console ctnlist:maintenance --no-interaction
```

Complete templates: `deploy/cron/ctnlist.cron.example` (`/etc/cron.d`) or the systemd timers in `deploy/systemd/` (`ctnlist-worker@<installation>.timer`, `ctnlist-maintenance@<installation>.timer`). Run them as the PHP-FPM user with nothing but `CTNLIST_INSTANCE_DIR`, so they read the same `.env` as the web server: the worker records a fingerprint of its configuration (installation, environment, database, base URL, catto-mail credentials, keys; hashes only), and the Delivery page and `ctnlist:diagnose` warn when it differs from theirs. The PHP-FPM pool template keeps `clear_env = yes` and no `env[…]` overrides for the same reason. PHP sessions are removed by `ctnlist:maintenance` only (PHP's own session garbage collection is off), so `session.gc_maxlifetime` in `php.ini` does not matter.

Output and exit status are the same for both. They print one line of counts when something was done, failed or found (every run with `-v`), never row content. They exit 1 when an item failed, and 0 when there was nothing to do or another run was in progress. Their last runs, and last failures, show on **Admin → Settings → Delivery** and in `ctnlist:diagnose`.

### Operational data

| Data | Class | Policy |
|---|---|---|
| Subscribers, consent and membership history (`list_subscription_events` is append-only), roles, suppression (ctnlist banlist, delivery states), the `ALL` list | Must be kept | Never pruned. |
| Send Log, smlog and message activity, send runs, jobs and recipient outcomes, archives, the queue | Long-term history | Never pruned; they show whether ctnlist handed a message on and what became of it. |
| Webhook events | History plus temporary body | The row, type, times, outcome, error and references (job, recipient) are kept; the raw body is removed `CATTOMAIL_WEBHOOK_BODY_RETENTION_DAYS` (default 90) after processing, never before. |
| Rendered recipient content | Temporary | Cleared when its batch is accepted; for refused or cancelled jobs, `CATTOMAIL_DETAIL_RETENTION_DAYS` (default 365) after they finished (a requeue renders again). |
| Send-job create bodies, Idempotency-Keys, catto-mail ids | Retry material, then compact history | Kept: they are small and are what makes a retry, or a restored database, safe. |
| Address-validation results | History | Job counts and every subscriber's latest result are kept; results superseded by a newer result for the same subscriber are removed from jobs finished more than `CATTOMAIL_DETAIL_RETENTION_DAYS` ago (the job is marked). Results never change consent or memberships. |
| Global opt-outs | Must be kept | Never pruned (they record an explicit request). |
| Sign-in links (`auth_login_tokens`) | Temporary | Removed once used or expired and older than the sign-in rate windows (the rate limits count them). |
| Sign-in sessions (`auth_sessions`), PHP sessions (`sessions`) | Temporary | Sign-in sessions are removed once expired or signed out; PHP sessions (CSRF tokens, messages) once idle for longer than `AUTH_SESSION_TTL` or `AUTH_MAGIC_LINK_TTL`, whichever is longer. Active sessions are never touched. |
| Site Log | History, personal data (IP addresses) | Kept, as in v5, unless `APP_SITELOG_RETENTION_DAYS` is set. |
| `options` (settings overrides, status heartbeats) | Configuration | Kept. |

Maintenance works in batches of at most 1,000 rows, each its own short transaction, and at most 50 batches per task per run; the rest waits for the next run.

**Deleting subscribers.** Subscribers are not deleted: their consent events are append-only, and the Send Log, smlog and delivery rows refer to them. Removing a person's data on request would need an anonymisation design: replacing identifying fields while keeping the audit trail. That is future work, not part of maintenance.

### Stuck work and recovery

Work is **stuck** when the worker should long since have moved it on: twice `CATTOMAIL_RECONCILE_AFTER_SECONDS`, and at least ten minutes. That covers:
- send jobs not sealed, or sealed but not reconciled within that time;
- validation jobs not submitted or not reconciled;
- opt-outs or withdrawals not reported;
- webhook events not processed ten minutes after arrival.

A job that is merely slow at catto-mail (dispatched, waiting days for final bounce knowledge) is not stuck while ctnlist keeps checking it. The Delivery pages label jobs Pending, Stuck, Failed, Cancelled, Completed or Awaiting reconciliation.

Recovery actions, all POST + CSRF and recorded in the application log (administrator UUID, action, reference):
- **Retry sending** an unsealed job (`queue.process`), with its stored keys: never while its run is still staging, and never when catto-mail is not configured.
- **Check now** for a sealed job (`logs.view`).
- **Return to queue** for a refused campaign job (`queue.process`).
- **Process again** for an unprocessed or failed webhook event whose body is still kept (`queue.process`); its effects are applied at most once.
- **Retry submission** or **Check now** for a validation job (`subscribers.manage`).
- **Retry** for a pending or refused global opt-out (`subscribers.manage`).

None of them sets a state by hand: each asks catto-mail, or repeats the stored request. There is no "send again" that bypasses the once-only protections.

### Logs

The application writes to standard error from `notice` up (PHP-FPM's log in production, with `catch_workers_output` in the pool template): recovery actions, settings changes (names only), catto-mail failures, and uncaught exceptions at `critical` with their details, which the browser never sees. It also writes to the installation's `logs/` (contact, import and export files, which hold subscriber addresses: keep the directory `750` as in the layout table). ctnlist does not rotate files: use the operating system's or container's mechanism (logrotate for `logs/` and PHP-FPM's log, journald or the container runtime for standard error). Maintenance logs counts only, never rows.

### Backups and recovery

Back up, per installation:
- **the main PostgreSQL database**: all of the data above;
- **the global suppression database** (`GDB_*`) where it is hosted, if this installation hosts it (it is shared by installations);
- **the installation's `.env`**, which holds secrets that are **not in the database** and cannot be reconstructed from it:
  - `APP_SETTINGS_KEY` is required to decrypt all encrypted Settings secrets stored in the database (the SMTP server, `MAILER_DSN`, and the API key and webhook secret of a mail service connected on the Delivery page). A database backup without its key restores them unreadable: the SMTP server would have to be entered again and the mail service connected again. Keep the key with the installation's secrets, not inside database dumps. It protects nothing else: the secrets kept in `.env` are not encrypted with it;
  - `CATTOMAIL_API_KEY`, `CATTOMAIL_WEBHOOK_SECRET` and, during a rotation overlap, `CATTOMAIL_WEBHOOK_SECRET_PREVIOUS`, where the mail service is set in `.env` and not connected on the Delivery page. If lost, issue a new key and secret in catto-mail, or connect instead;
  - `APP_SECRET`: it signs one-click unsubscribe links already in mailboxes; a new one invalidates them.

  Never commit `.env` or keep backups in the repository.
- `logs/` only if the contact, import or export files matter to you; `var/` is a cache and is not backed up.

Recovery:
1. Restore the database (and the suppression database, if hosted here).
2. Restore the installation's `.env`.
3. Install the matching ctnlist code version at its shared-code path (`composer install --no-dev --optimize-autoloader`) and copy its `public_html/` (see [Deployment](#deployment) for ownership and permissions).
4. As `www-data`: clear the installation's cache (`bin/console cache:clear`, or empty `var/cache/`).
5. As `www-data`: `bin/console asset-map:compile` for the installation.
6. Restart PHP-FPM and re-enable the two cron entries (or systemd timers).
7. Run `ctnlist:diagnose --production`, then let the worker reconcile. Pending catto-mail work carries its catto-mail ids and Idempotency-Keys, so the worker polls and finishes it rather than creating replacements. Check **Admin → Settings → Delivery** (Check connection, stuck work).

On a new server, catto-mail is checked end to end with the [Release checklist](#release-checklist)'s proof, test campaign and webhook round trip (`bin/dev test-cattomail` is the development stack's check against a local catto-mail and refuses any other); the automated tests use the fake catto-mail.

## Database setup

The schema is managed by Phinx as a single migration, `20260716120000_create_phase3a_schema.php`. ctnlist is not yet in production, so schema changes are made in that migration and the development database is rebuilt; there are no upgrade migrations. The package includes destructive reset scripts for disposable development databases:

```text
database/reset-development.sql
database/reset-banlist-development.sql
```

Do not use those reset scripts for a database whose contents must be preserved.

## Development environment

`bin/dev` runs a podman-compose stack that mirrors the production layout: nginx, PHP 8.5-FPM, PostgreSQL 16 and Mailpit. Besides the development installation it serves a second, internal-only installation for the smoke tests (`http://web:8081`, database `ctnlist_smoke`, recreated by every `bin/dev test`). It needs rootless Podman and podman-compose (Debian, Ubuntu, Linux Mint: `sudo apt install podman podman-compose`); `bin/dev` calls podman-compose directly, so a docker-compose on the PATH does not matter.

```bash
bin/dev up          # build and start, composer install, migrations (including the test databases), seed admin@ctnlist.test
bin/dev help        # all commands
```

The application is at http://localhost:8180 (and https://localhost:8543 with a generated development certificate). Transactional mail, and every proof (`MAIL_PROOF_SMTP=mailpit:1025`), is captured by Mailpit at http://localhost:8125, and the three sample templates are loaded: sign in as `admin@ctnlist.test`: open the link it receives, then press Sign in on the page it opens (opening the link alone signs nobody in). The development instance runs without a banlist database (`SUPPRESSION_PROVIDER=none`) and, until catto-mail is connected ([development route](#catto-mail-development-route)), sends no campaign content: the queue, proofs and resends report that catto-mail is not configured and keep their work.

## Testing and validation

```bash
bin/dev check       # the quality gate: all of the below plus Twig/YAML/container lint and composer validate + audit, then a check that the development database is untouched
bin/dev lint        # php -l over public_html/index.php, src/ and tests/
bin/dev analyse     # PHPStan level 8
bin/dev test        # PHPUnit: unit, integration and smoke suites
```

`bin/dev check` must pass before a change is handed over; it stops at the first failing step. `tests/Integration/Http/HttpBoundaryTest` sends every route the wrong method (405), checks the webhook's size limit, production error pages, that anonymous pages without a form start no session, and the health check; `SecurityHeadersTest` the headers and the Content-Security-Policy profiles; `Smoke/SecurityBoundaryTest` extra form fields, the opt-out rate limit and the worker-configuration warning over HTTP. `tests/Integration/RouteConventionsTest` checks the whole route table: every POST route is CSRF-protected (Symfony form or `#[IsCsrfTokenValid]`; the webhook, the signed one-click unsubscribe link, logout and the sign-in link's Sign in button, whose token `MagicLinkAuthenticator` checks before using the link, are documented exemptions), every administration route declares its permission, no state-changing route accepts GET, and every GET route is requested by the route smoke test.

Smoke tests never touch the development installation or its database. They drive a second installation in the same stack, `/var/www/ctnlist-smoke` (served internally at `http://web:8081`, generated `dev/podman/smoke.env`), whose database `ctnlist_smoke` is dropped, migrated and seeded before every `bin/dev test`. Integration tests use `ctnlist_test`, each test in a rolled-back transaction. `SmokeTestCase` refuses any other database and a `SMOKE_BASE_URL` that is not the installation using it, `IntegrationTestCase` refuses any database but `ctnlist_test`, the generated `smoke.env` never carries catto-mail credentials, and `bin/dev check` compares row counts of the development database before and after the tests and fails if anything changed.

`tests/Smoke/RouteSmokeTest` requests every GET route of the running stack over HTTP, anonymously and as the development administrator, and checks status codes, login redirects, access control and PHP error output. Every new GET route must be added to it. `tests/Smoke/AuthFlowTest` covers sign-in links (opening them changes nothing, the Sign in POST and its CSRF token, parallel, used, expired and malformed links, a link to confirm a list), auth cookies, session rotation, logout and CSRF, `tests/Smoke/LayoutTest` the site layout, assets, the Content-Security-Policy and error pages, `AdminScreensTest` the administration screens, `AdminListsRolesTest` and `RoleAdministrationTest` list and role administration, `AdminMessagesTemplatesTest`, `AdminQueueTest`, `AdminSubscribersTest`, `SettingsPageTest` and `ReportsTest` the rest of the administration and the Site Log, `CattoMailSmokeTest` the catto-mail pages and webhook endpoint, `SecurityBoundaryTest` extra form fields, rate limits, the worker-configuration warning and the health check, and `ProfileTest`, `ConsentTest`, `MessageActionTest` and `ArchiveContactTest` the subscriber-facing pages. `tests/Unit` holds unit tests; `tests/Integration` boots the application against the `ctnlist_test` database (each test rolled back) and covers the services: suppression (including the banlist database, `ctnlist_banlist_test`), the send and message logs, mail, rendering, messages, the delivery queue, consent, contact and subscriber administration, catto-mail against the fake, maintenance and diagnostics, sign-in, settings and the HTTP boundary.

`bin/dev test-installer` is separate from `bin/dev check` (several minutes, and it needs the Internet for the packages): it runs `install-ctnlist` as root in a stand-in server, a systemd container with PHP-FPM, PostgreSQL and nginx (`dev/installer-test/`). It installs the last released version as a new installation, runs the installer a second time, switches to the working tree as the next version and back, refuses a release with a changed schema and then recreates the database on request, and adds a second installation for a host name nginx already serves.

`bin/dev test-podman-kit` is separate in the same way (ten minutes or more): it runs `deploy/podman` in a stand-in server with rootless Podman, PostgreSQL and nginx configured from `host-nginx.conf.example` (`dev/podman-kit-test/`). `root-prepare.sh` prepares a site user with a database and a new suppression database; `ctnlist-podman.sh` refuses a database holding another application's table, installs the working tree as the next version with Mailpit, and the test reaches the site through nginx and the pod: HTTPS and the visitor's address as PHP sees them (a forged `X-Forwarded-For` is ignored), a sign-in with the link mailed through the pod, the absolute address the mail service's Connect page is given, the timers and a restart. It then runs the kit a second time, switches to the next version and back, refuses a release with a changed schema and recreates the database on request. `tests/Unit/PodmanKitTest` holds the kit's read-only mounts, the hop it trusts, its logs without query strings and its agreement with `deploy/php-fpm`, `deploy/nginx` and `deploy/systemd`.

Real SMTP delivery and a production banlist database are not part of the development stack and must be tested on a target installation. The catto-mail end-to-end checks, `bin/dev test-cattomail` against a development catto-mail and the Release checklist's proof, test campaign and webhook round trip on the server, are still to be run once the new production server exists.

## Licence

ctnlist is released under the MIT Licence.

Copyright (c) 2009-2026 Richard Royston Catto
