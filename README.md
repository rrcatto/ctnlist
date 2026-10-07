# ctnlist 6.0.5

ctnlist is a web-based mailing-list application created by Richard Royston Catto in 2009. Versions 5.0.x and 6.0.x are an incremental modernisation of the working v5.0 application, not a replacement of its established workflows.

Versions 5.0.2 to 6.0 keep the behaviour of 5.0.1-restored and move the application from the Fat-Free Framework to Symfony 8.1. Version 6.0 completes the migration: every route is a Symfony controller and Fat-Free has been removed (see [Migration status](#migration-status)).

Version 5.0.3 moves sign-in, logout, permission checks and CSRF to Symfony Security, renders every page in a single Twig layout, and ports the home, privacy and store pages.

Version 5.0.4 ports list, role and ACL administration to Symfony and adds the Symfony campaign core: global suppression, the Send Log and message log, mail transports with failover, the transactional and campaign mailers, template rendering, archives, messages and the delivery queue. These services are covered by integration tests against a separate test database; the subscriber-facing and administrator routes move onto them in the next phases, so live campaign delivery still uses the Fat-Free code.

Version 5.0.5 moves every subscriber-facing page onto Symfony and the new services: sign-in link requests, the profile pages, the confirm/unsubscribe links and `/subscribe`, forwarding, likes and dislikes, resends, the open-tracking pixel, the archives and the contact form. Sign-in links, consent notifications, forwards, resends and contact acknowledgements are now sent through the Symfony mailers. Administration (subscribers, bulk operations, import/export, messages, templates, the queue) and the reports still run on Fat-Free and the legacy mailer.

Version 6.0 moves the administration pages (messages and templates, queueing, sending and proofs, subscribers, bulk operations, import, export, synchronisation and the Ecwid endpoint) and the Send Log, Site Log and message activity reports onto Symfony. All mail now goes through the Symfony mailers, the Fat-Free Framework, its bridge and the legacy code are removed, the Site Log again records every request, and the code passes PHPStan level 6. Import logs and export files are written to `APP_LOG_DIR` (default: the installation's `logs/`).

Version 6.0.1 removes the defunct Ecwid store (the Store page, its links, `POST /ecwid-subscribe` and `APP_STORE_URL`; a leftover `{STORE}` placeholder renders as nothing) and rebuilds the frontend on plain Bootstrap 5.3: a sticky header with an administration bar that shows only the tools each user's permissions allow, a simple footer, Bootstrap Icons and the system font. Unify, Font Awesome, jQuery and the Google Fonts request are gone; the application's CSS and JavaScript are served by Symfony AssetMapper, and CKEditor loads only on the editors. Every administration screen follows one pattern (page header with the main actions, filter panels, responsive tables, status badges, empty states, confirmation before deleting, clearing or suppressing). `/subscribe` is now a subscribe form for visitors and a list of the subscriber's lists when signed in, with list descriptions. Custom roles can be renamed, deleted and removed from subscribers, each role lists its members, subscribers are found by search, and nobody but an administrator can hand out permissions they do not hold. Proofs go to any address without a subscriber record, and their subscriber links explain that a proof has no subscriber. Sign-in links sent to confirm a list say so. A new Settings page lets administrators override selected `.env` settings, with SMTP secrets encrypted by `APP_SETTINGS_KEY` (see [Settings page](#settings-page)).

Version 6.0.2 arranges the Settings page as tabs, one per section, each with its own form and Save button; fields are laid out on a grid, the SMTP tab shows the main server and each failover server as its own block, and the URL (e.g. `/settings#smtp`) opens and returns to a tab.

Version 6.0.3 hands campaign delivery to the catto-mail smarthost and hardens the application:
- **catto-mail:** queue sends, proofs, resends and forwards go through catto-mail's API, with durable send runs, idempotent retries, signed webhooks, a reconciliation worker, hard-bounce and complaint handling, address validation, an optional global opt-out and signed one-click unsubscribe links. ctnlist's own SMTP campaign path is removed and `MAILER_DSN` carries transactional mail only.
- **Forms:** every input form is a Symfony Form with Validator constraints that keep entered values and show errors at their fields.
- **Security:**
  - PHPStan level 8;
  - GET routes never change state (export, sync and logout are POST with CSRF);
  - route-wide CSRF, permission and smoke-coverage checks;
  - security headers;
  - rate limits on the contact form and forwards;
  - sanitised archive HTML;
  - placeholders never print literally;
  - one quality gate, `bin/dev check`.
- **Profile pictures:** a subscriber can choose, crop and save one, and it shows beside their name in the site menu.

Version 6.0.5 adds operational maintenance and recovery:
- **Housekeeping:** `ctnlist:maintenance` (hourly, `--dry-run`) removes expired sign-in data and sessions, and raw webhook bodies and other temporary catto-mail detail after configurable retention periods. It never touches consent, delivery history or anything still needed for a retry.
- **Diagnostics and recovery:** `ctnlist:diagnose` checks an installation without printing secrets. Stuck work is formally defined and shown on the Delivery pages, with safe, logged recovery actions (retry a job, a validation or an opt-out, reprocess a webhook event). Long histories are paginated.
- **Test isolation:** smoke tests run against a separate, disposable installation and database, and `bin/dev check` proves the development database is untouched.
- **Documentation:** data classification, retention, backups and the recovery procedure (see [Operations](#operations)).

Version 6.0.4 makes the catto-mail integration operable in production:
- **Integration status page** (Admin → Sending → Delivery): a "Check connection" button that names the failure (host, connection, TLS, key, permission); the last API success and error; a worker heartbeat; counts of pending and stuck work; send-run pages; and a webhook-event diagnostic page.
- **Hardening:** queue staging can no longer stage one queue row twice when queue runs overlap; job states only move forward, even when updates race; bounce and complaint effects commit with their once-only claim; one open global opt-out per subscriber; a single-instance worker with meaningful exit codes.
- **Other changes:** clearer administrator errors (with catto-mail's field errors), an explicit confirmation for the global opt-out, and `ctnlist:cattomail:check` plus the optional real end-to-end check `bin/dev test-cattomail`. `bin/dev up` re-attaches the development catto-mail network automatically. The unused `MAIL_UNSUBSCRIBE_ADDRESS` is removed.

## Platform and upgrades

- PHP 8.4.1+ (developed on 8.5), Composer
- Symfony 8.1 (including Symfony Security, Twig and Mailer) with Doctrine DBAL 4
- PostgreSQL with versioned Phinx migrations
- Symfony Mailer for transactional mail only (`src/Mail/`: sign-in links, invitations, notifications, contact acknowledgements, throttled); campaign content (queue sends, proofs, resends, forwards) is delivered by the catto-mail smarthost through its HTTPS API (`src/CattoMail/`)
- Symfony Forms and Validator for every input form; symfony/html-sanitizer (archive pages) and symfony/rate-limiter (contact form, forwards)
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
- delivery through the catto-mail smarthost (see [catto-mail integration](#catto-mail-integration)): ctnlist renders every recipient's message, catto-mail sends it
- archive creation when a campaign is first queued
- queue-time subscriber state updates

### Once-only delivery and `smlog`

The ordinary campaign workflow sends a message once to a subscriber. The unique `(subscriber UUID, message MUID)` record in `smlog` is used as the delivery guard.

All campaign-content paths—including queue delivery, forwarding, proof copies and deliberate resends—are delivered by catto-mail and update `sendlog` when catto-mail has accepted them (the send job is sealed); deliveries to subscribers also update `smlog`. A proof can go to any valid address (by default `MAIL_TEST_ADDRESS`), which needs no subscriber record: it is logged as PROOF in `sendlog` only and does not affect the queue, consent, memberships or the once-only guard.

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
src/            application code, namespace App\ (Controller/, Repository/ and domain folders: Campaign/, Queue/, Mail/, CattoMail/, Log/, Subscriber/, Security/, Suppression/; Form/ and Validator/; Command/ for console commands)
templates/      Twig templates: base.html.twig and layout/ (navbar, admin bar, flash messages, footer) form the site layout
tests/          PHPUnit: Unit/, Integration/ (application against the ctnlist_test database), Smoke/ (HTTP against the running stack), Support/ (FakeCattoMail)
dev/podman/     development container files
```

## Deployment

The repository is a shared code tree installed once per version at `/usr/local/lib/php/ctnlist/6.0.5/` (the path is set in `public_html/index.php`). Run `composer install --no-dev` there. PHP needs the `gd` extension with JPEG and WebP support (profile pictures; `composer install` checks for it) and should have `exif` (phone photos are turned upright). The picture editor uploads only the cropped square (well under 1 MB); without JavaScript the original file is posted, so allow uploads of up to 8 MB (nginx `client_max_body_size`, PHP `upload_max_filesize`/`post_max_size`) or such uploads are refused.

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

Transactional mail (sign-in links, invitations, notifications, contact acknowledgements) goes through the SMTP server in `MAILER_DSN`, throttled to `MAIL_RATE_PER_MINUTE`. Campaign content goes through catto-mail (`CATTOMAIL_*`, below). Optional legacy synchronisation targets use `SYNC_DATABASES_JSON`.

Sign-in links expire after `AUTH_MAGIC_LINK_TTL` seconds and are rate-limited per address (`AUTH_MAGIC_LINK_MAX_PER_EMAIL` within `AUTH_MAGIC_LINK_EMAIL_WINDOW`) and per client IP (`AUTH_MAGIC_LINK_MAX_PER_IP` within `AUTH_MAGIC_LINK_IP_WINDOW`); a signed-in session lasts `AUTH_SESSION_TTL` seconds. The sign-in page and email state the link lifetime in words from the same setting. Contact messages are limited per hour per client IP and per address (`CONTACT_RATE_LIMIT`, default 5), forwards and resends per subscriber (`FORWARD_RATE_LIMIT`, default 10; holders of `messages.manage` are exempt); over a limit the page answers 429. Contact-form submissions are appended to `CONTACT_LOG_FILE` (default: the installation's `logs/contact.log`). Bulk-subscribe and import logs (`emails_added.txt`) and the export files (`export-subscribers.txt`, `export-remove.txt`) are written to `APP_LOG_DIR` (default: the installation's `logs/`).

### Settings page

Administrators, and any role given the `settings.manage` permission, can change a selected set of settings at **Admin → Settings** without editing `.env`: site identity, contact details and message links, the mail sender and proof address, the transactional SMTP server and its pace, the contact form, the subscription messages, the archive and the sign-in limits.

- Each setting resolves as **database override → `.env` → built-in default**, and the page shows which one is in effect (Database, .env or Default). Overrides are stored in the `options` table; nothing is written to `.env`. Database overrides are optional: an installation without any runs exactly from its `.env`.
- Saving a value equal to the inherited one, or "Reset override" / "Reset section", removes the override so `.env` (or the default) applies again. Changes apply from the next page or email; a send that is already running keeps the SMTP settings it started with.
- SMTP passwords are encrypted in the database with AES-256-GCM using the dedicated `APP_SETTINGS_KEY` from `.env` (32 random bytes, Base64; generate with `openssl rand -base64 32`). The key is needed only once a secret is saved there; without it the SMTP section explains what to add. Passwords are never sent to the browser (leave the field empty to keep the current one) and never logged.
- **Do not change `APP_SETTINGS_KEY` while encrypted settings exist**: they become unreadable, and mail that needs them fails (the Settings page reports it) until they are entered again or reset. A future key rotation must decrypt with the old key and re-encrypt with the new key before the new key is deployed.
- Stay in `.env` only: `APP_SECRET`, `APP_SETTINGS_KEY`, `APP_ENV`/`APP_DEBUG`, `APP_INSTANCE_ID`, the catto-mail settings (`CATTOMAIL_*`), the database and suppression-database settings, `TRUSTED_PROXIES`, `APP_BASE_URL`/`APP_DOMAIN`, `APP_TIMEZONE`, file paths (`CONTACT_LOG_FILE`, `APP_LOG_DIR`), the auth cookie name, `APP_ADMIN_EMAIL` and the synchronisation targets.

Deployment is otherwise unchanged (including `asset-map:compile` per installation).

### Security notes

- Secrets are generated, never typed: `APP_SECRET` with `openssl rand -hex 32`, `APP_INSTANCE_ID` with `openssl rand -hex 16`, `APP_SETTINGS_KEY` with `openssl rand -base64 32` (`bin/dev ensure-env` does this for the development stack and never replaces an existing key).
- Every page carries `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, a `Permissions-Policy`, HSTS over HTTPS, and a Content-Security-Policy that stops framing by other sites, `<base>`/plugin injection and form posts to other sites. A full script/style policy (nonces for the import map, the CDN and CKEditor) is a later change. Static files are served by nginx, which can add the same headers there.
- The auth cookie is HttpOnly, SameSite=Lax and Secure whenever `APP_BASE_URL` is https.
- Bootstrap and Bootstrap Icons stay on jsDelivr with subresource integrity: vendoring them with AssetMapper would not bring the icon fonts along. CKEditor 5 is a prebuilt bundle in `public_html/vendor/ckeditor5/`, loaded only on the message and template editors.
- Message HTML shown on the site (the public archive) is sanitised (`config/packages/html_sanitizer.yaml`); mail is sent as composed.
- Logs never contain secrets, setting values or tokens; the Site Log omits query strings and masks one-click unsubscribe signatures. Configure the web server's access log not to record query strings for `/auth/verify`, which carries the sign-in token.

### Message placeholders

Placeholders are case-insensitive. For a subscriber: `{firstname}`, `{lastname}`, `{emailsleft}`, `{subscription}` (the subscription message, itself filled), `{unsubscribe}`, `{confirm}`, `{forward}`, `{preferences}`, `{like}`, `{dislike}`, `{booking}` (`APP_BOOKING_URL`, may contain `{BaseURL}`, `{suid}`, `{muid}`), `{contact}` (`APP_CONTACT_URL`), `{usertrack}` (open-tracking pixel, HTML only), `{suid}`, `{muid}`, `{baseurl}`, `{listshortcode}`; for everyone: `{subscribe}`, `{archive}`, `{listname}`, `{domain}`, `{organisation}`, `{advertise}` (`APP_ADVERTISE_URL`), `{facebook}` (`APP_FACEBOOK_URL`), `{twitter}` (`APP_X_URL`). In archives subscriber links become plain labels (UNSUBSCRIBE, FORWARD, UPDATE, OPT IN, YES, NO, BOOKING FORM, CONTACT FORM), names and identifiers are empty. Site links whose URL is not configured render nothing. `{STORE}` and `{lms-booking}` are retired and render nothing. Proofs fill names with test values and point subscriber links at a page explaining that a proof has no subscriber.

## catto-mail integration

ctnlist is the first client of the [catto-mail](https://github.com/rrcatto/catto-mail) smarthost. The two applications stay separate: ctnlist talks to catto-mail only through its HTTPS `/v1` API (`App\CattoMail\CattoMailClient`, the only class that does) and receives its signed webhooks. ctnlist never touches catto-mail's database. ctnlist keeps subscribers, lists, consent and ordinary unsubscribes, campaigns, templates, audience selection and the per-recipient rendering. catto-mail does address validation, SMTP delivery, DSNs, complaints, global transport suppression, tracking and metering.

**Configuration** (`.env` only, never Settings, never committed; a changed key applies from the next request):

| Variable | Purpose |
|---|---|
| `CATTOMAIL_API_BASE_URL` | catto-mail's HTTPS origin (`/v1` is added). |
| `CATTOMAIL_API_KEY` | This installation's API key, sent as `Authorization: Bearer`; never rendered or logged (errors and log lines are redacted). |
| `CATTOMAIL_WEBHOOK_SECRET` | The signing secret catto-mail showed when the webhook endpoint `<APP_BASE_URL>cattomail/webhook` was registered. |
| `CATTOMAIL_WEBHOOK_SECRET_PREVIOUS` | Only during a secret rotation: the previous secret, for catto-mail's overlap window. After catto-mail rotates, put the new secret in `CATTOMAIL_WEBHOOK_SECRET` and the old one here; remove it when the overlap has passed. |
| `CATTOMAIL_GLOBAL_OPTOUT_ENABLED` | `true` only when catto-mail's operator granted this client the global-opt-out capability. |
| `CATTOMAIL_CA_FILE`, `CATTOMAIL_API_CONNECT_HOST` | Development only: trust catto-mail's self-signed certificate; reach its API host through another name (ignored outside `dev`/`test`). |
| `CATTOMAIL_RECONCILE_AFTER_SECONDS` | Poll jobs that have had no final webhook after this long (default 900; 300 in development). |
| `CATTOMAIL_TRACK_OPENS`, `CATTOMAIL_TRACK_CLICKS` | catto-mail open/click tracking per send job (default off). |

**Sending.** Every campaign delivery (queue sends, proofs, resends, forwards) is rendered by ctnlist for its recipient and handed to catto-mail. catto-mail does no mail merge.
- A sending run (one queue run, proof, resend or forward) gets send jobs, one per message and list, of at most 10,000 recipients each: a bigger run gets several jobs, numbered `<run uuid>/<n>` (their `external_reference`).
- Recipients are uploaded in batches of at most 500, then the job is submitted.
- Each recipient carries its final subject, HTML and text, `external_recipient_reference` (the ctnlist delivery's UUID) and, for subscription mail, a per-recipient one-click `unsubscribe_url`. That URL is `https://…/unsubscribe-link/…`, signed, without tracking, and unsubscribes from that list only.
- Proofs, and resends of a draft without lists, are transactional jobs.
- A message's From domain must be a sending domain registered in catto-mail, and subscription mail needs an https `APP_BASE_URL`.
- **Admin → Sending → Delivery (catto-mail)** shows each message's send jobs, every recipient's message state, and a recipient's event history read live from catto-mail. `remote_accepted` is acceptance by the receiving server, not inbox delivery; `open_recorded` is not proof of reading.
- A queue row is removed only in the same transaction that stores its staged delivery, and only once: a concurrent queue run that finds the row already taken rolls back. The database also allows only one active campaign delivery of a message to a subscriber.

**Integration status** (**Admin → Sending → Delivery**, permission `logs.view`). The page shows:
- the configuration (the API key and secrets are never shown, only whether they are set);
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

**What "sent" means.** A recipient's Send Log row and smlog sent mark are written when catto-mail seals its job (`POST /send-jobs/{id}/submit` answers 202). That is ctnlist handing the message to its mail transport. It does not mean the receiving server accepted it (`remote_accepted`) or that it reached an inbox. The Delivery pages show those outcomes.

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

**Worker.** Webhooks are the primary path; the worker is the safety net. Run `ctnlist:cattomail:work` from cron every minute, per installation:

```bash
* * * * * CTNLIST_INSTANCE_DIR=/var/www/example /usr/local/lib/php/ctnlist/<version>/bin/console ctnlist:cattomail:work
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

**Headers.** catto-mail accepts no arbitrary MIME headers:
- It sets the envelope (VERP return path), `List-Id` (from the job's `list_id`) and `List-Unsubscribe` with `List-Unsubscribe-Post` (from each recipient's signed `unsubscribe_url`).
- v5's `Sender`, `List-Owner`, `List-Post`, `List-Subscribe`, `List-Archive` and `X-ctnlist-*` headers are not sent.
- None of them is needed for delivery or one-click unsubscribe. `List-Archive`/`List-Subscribe` would need a catto-mail contract change (see below).

**What is stored.**
- Rendered recipient content is kept only until its batch is accepted, then cleared.
- Recipient rows keep the address, subject, states and references.
- Webhook events are kept in full (`cattomail_webhook_events.cwe_payload`, which can contain recipient addresses and remote diagnostics) for diagnosis and de-duplication.
- Address-validation results are kept per job.

`ctnlist:maintenance` removes webhook bodies after `CATTOMAIL_WEBHOOK_BODY_RETENTION_DAYS` and other temporary detail after `CATTOMAIL_DETAIL_RETENTION_DAYS` (see [Operations](#operations)).

**Possible catto-mail contract enhancements** (not needed for correct operation; not worked around):
- an authenticated health or identity endpoint, instead of reading a job that cannot exist;
- a way to cancel an empty `collecting` send job;
- structured `List-Archive`/`List-Subscribe` values per job.

### catto-mail development route

The development stacks are separate (catto-mail's pod is on an internal network, its API published on `127.0.0.1:8443`; its webhook worker only delivers to public addresses, or to private hosts listed in its `APP_WEBHOOK_ALLOWED_PRIVATE_HOSTS`). The route is a dedicated internal Podman network, `cattomail-dev`:

1. `bin/dev cattomail-link` creates `cattomail-dev` and attaches ctnlist's web container (alias `ctnlist-web`) and app container. `bin/dev up` re-attaches them by itself whenever the network exists; without it, `up` works as usual and catto-mail stays optional. `bin/dev cattomail-status` lists what the route has and lacks (it never prints secret values).
2. `bin/dev cattomail-ca` saves catto-mail's self-signed development certificate (read from its public port) to `dev/podman/cattomail-ca.pem` (gitignored).
3. catto-mail's side, done in the catto-mail repository:
   - attach its pod to `cattomail-dev` with an alias (e.g. `catto-mail`);
   - add `ctnlist-web` to `APP_WEBHOOK_ALLOWED_PRIVATE_HOSTS`;
   - provision a client with its console: an active client, the development sending domain, an API key, optionally the global-opt-out capability, and a webhook endpoint `http://ctnlist-web/cattomail/webhook` for the six event types (plain http is accepted in catto-mail development).
4. In `dev/podman/ctnlist.env`:
   - `CATTOMAIL_API_BASE_URL=https://localhost`;
   - `CATTOMAIL_API_CONNECT_HOST=catto-mail` (TLS is still verified for `localhost`);
   - `CATTOMAIL_CA_FILE=/usr/local/lib/php/ctnlist/6.0.5/dev/podman/cattomail-ca.pem`;
   - the API key and webhook secret;
   - for subscription mail, `APP_BASE_URL=https://localhost:8543/` (ctnlist's development HTTPS listener).

   Then run `bin/dev restart` and, in another terminal, `bin/dev console ctnlist:cattomail:work --loop 30`.
5. Check it: **Admin → Sending → Delivery → Check connection**, or `bin/dev console ctnlist:cattomail:check`.

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
| `ctnlist:diagnose` | by hand | Configuration and health: required settings, secrets set or not (never their values), obsolete settings still present, writable directories, database, worker and maintenance heartbeats, stuck work. |
| `ctnlist:cattomail:check` | by hand | The catto-mail connection check (`--e2e` only in development). |

The two scheduled commands are kept separate: the worker must run often and be quick, and housekeeping need not. Each runs one at a time per database (PostgreSQL advisory locks), so overlapping cron runs skip.

```cron
* * * * *  CTNLIST_INSTANCE_DIR=/var/www/example /usr/local/lib/php/ctnlist/<version>/bin/console ctnlist:cattomail:work
17 * * * * CTNLIST_INSTANCE_DIR=/var/www/example /usr/local/lib/php/ctnlist/<version>/bin/console ctnlist:maintenance
```

Output and exit status are the same for both. They print one line of counts when something was done, failed or found (every run with `-v`), never row content. They exit 1 when an item failed, and 0 when there was nothing to do or another run was in progress. Their last runs, and last failures, show on **Admin → Sending → Delivery** and in `ctnlist:diagnose`.

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
| Sign-in sessions (`auth_sessions`), PHP sessions (`sessions`) | Temporary | Removed once expired or signed out, or past `session.gc_maxlifetime`. Active sessions are never touched. |
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

The application writes to standard error (PHP-FPM's log in production) and the installation's `logs/` (contact, import and export files). ctnlist does not rotate files: use the operating system's or container's mechanism (logrotate for `logs/` and PHP-FPM's log, journald or the container runtime for standard error). Maintenance logs counts only, never rows.

### Backups and recovery

Back up, per installation:
- **the main PostgreSQL database**: all of the data above;
- **the global suppression database** (`GDB_*`) where it is hosted, if this installation hosts it (it is shared by installations);
- **the installation's `.env`**, which holds secrets that are **not in the database** and cannot be reconstructed from it:
  - `APP_SETTINGS_KEY` decrypts the secret settings stored in the database (SMTP). A database backup without its key restores those settings unreadable; they would have to be entered again. Keep the key with the installation's secrets, not inside database dumps;
  - `CATTOMAIL_API_KEY`, `CATTOMAIL_WEBHOOK_SECRET` and, during a rotation overlap, `CATTOMAIL_WEBHOOK_SECRET_PREVIOUS`. If lost, issue a new key and secret in catto-mail;
  - `APP_SECRET`: it signs one-click unsubscribe links already in mailboxes; a new one invalidates them.

  Never commit `.env` or keep backups in the repository.
- `logs/` only if the contact, import or export files matter to you; `var/` is a cache and is not backed up.

Recovery:
1. Restore the database (and the suppression database, if hosted here).
2. Restore the installation's `.env`.
3. Install the matching ctnlist code version at its shared-code path (`composer install --no-dev`) and copy `public_html/`.
4. `bin/console asset-map:compile` for the installation.
5. Clear the installation's cache (`bin/console cache:clear`, or empty `var/cache/`).
6. Restart PHP-FPM and re-enable the two cron entries.
7. Let the worker reconcile. Pending catto-mail work carries its catto-mail ids and Idempotency-Keys, so the worker polls and finishes it rather than creating replacements. Check **Admin → Sending → Delivery** (Check connection, stuck work) and `ctnlist:diagnose`.

The real end-to-end check against catto-mail (`bin/dev test-cattomail`) is a release step for when the new environment exists; the automated tests use the fake catto-mail.

## Database setup

The schema is managed by Phinx as a single migration, `20260716120000_create_phase3a_schema.php`. ctnlist is not yet in production, so schema changes are made in that migration and the development database is rebuilt; there are no upgrade migrations. The package includes destructive reset scripts for disposable development databases:

```text
database/reset-development.sql
database/reset-banlist-development.sql
```

Do not use those reset scripts for a database whose contents must be preserved.

## Development environment

`bin/dev` runs a podman-compose stack that mirrors the production layout: nginx, PHP 8.5-FPM, PostgreSQL 16 and Mailpit. Besides the development installation it serves a second, internal-only installation for the smoke tests (`http://web:8081`, database `ctnlist_smoke`, recreated by every `bin/dev test`).

```bash
bin/dev up          # build and start, composer install, migrations (including the test databases), seed admin@ctnlist.test
bin/dev help        # all commands
```

The application is at http://localhost:8180 (and https://localhost:8543 with a generated development certificate). Transactional mail is captured by Mailpit at http://localhost:8125: sign in as `admin@ctnlist.test` and follow the magic link it receives. The development instance runs without a banlist database (`SUPPRESSION_PROVIDER=none`) and, until catto-mail is connected ([development route](#catto-mail-development-route)), sends no campaign content: the queue, proofs and resends report that catto-mail is not configured and keep their work.

## Testing and validation

```bash
bin/dev check       # the quality gate: all of the below plus Twig/YAML/container lint and composer validate + audit
bin/dev lint        # php -l over public_html/index.php, src/ and tests/
bin/dev analyse     # PHPStan level 8
bin/dev test        # PHPUnit: unit, integration and smoke suites
```

`bin/dev check` must pass before a change is handed over; it stops at the first failing step. `tests/Integration/RouteConventionsTest` checks the whole route table: every POST route is CSRF-protected (Symfony form or `#[IsCsrfTokenValid]`; the webhook, the signed one-click unsubscribe link and logout are documented exemptions), every administration route declares its permission, no state-changing route accepts GET, and every GET route is requested by the route smoke test.

Smoke tests never touch the development installation or its database. They drive a second installation in the same stack, `/var/www/ctnlist-smoke` (served internally at `http://web:8081`, generated `dev/podman/smoke.env`), whose database `ctnlist_smoke` is dropped, migrated and seeded before every `bin/dev test`. Integration tests use `ctnlist_test`, each test in a rolled-back transaction. `SmokeTestCase` refuses any other database, and `bin/dev check` compares row counts of the development database before and after the tests and fails if anything changed.

`tests/Smoke/RouteSmokeTest` requests every GET route of the running stack over HTTP, anonymously and as the development administrator, and checks status codes, login redirects, access control and PHP error output. Every new GET route must be added to it. `tests/Smoke/AuthFlowTest` covers sign-in links, auth cookies, logout and CSRF, `tests/Smoke/LayoutTest` the site layout and error pages, `tests/Smoke/AdminListsRolesTest` list and role administration, `AdminMessagesTemplatesTest`, `AdminQueueTest`, `AdminSubscribersTest` and `ReportsTest` the rest of the administration and the Site Log, and `ProfileTest`, `ConsentTest`, `MessageActionTest` and `ArchiveContactTest` the subscriber-facing pages. `tests/Unit` holds unit tests; `tests/Integration` boots the application against the `ctnlist_test` database (each test rolled back) and covers the services: suppression (including the banlist database, `ctnlist_banlist_test`), the send and message logs, mail, rendering, messages, the delivery queue, consent, contact and subscriber administration.

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
