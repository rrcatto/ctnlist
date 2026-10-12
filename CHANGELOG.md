# Changelog

The release history of ctnlist. Versions 5.0.x and 6.0.x are an incremental modernisation of the working v5.0 application; the v5 behaviour they keep, and the deliberate changes from it, are listed in [RESTORED-FUNCTIONALITY.md](docs/RESTORED-FUNCTIONALITY.md).

## Unreleased

Connecting the mail service from the Delivery page, an acceptable use policy of the site's own, and two fixes:
- **Connect:** an administrator connects the mail service on **Admin → Sending → Delivery** instead of copying an API key and a webhook secret into `.env`. Enter the service's address and press Connect; sign in at the mail service and approve; ctnlist then collects, server to server, what the service hands over once: the API address, an API key and the signing secret of a webhook endpoint for this site. It is catto-mail's connect flow (an authorisation-code flow with PKCE, catto-mail 0.2.6, OpenAPI 1.0.0-draft.10): the browser carries only a single-use code and a state, never a key or secret.
- **Stored encrypted:** the address, the key and the secret go into the Settings store (`options`), the key and the secret encrypted with `APP_SETTINGS_KEY`. They are never shown, logged or listed on the Settings page. If `APP_SETTINGS_KEY` is replaced, the page says the stored key cannot be read.
- **`.env` still wins:** `CATTOMAIL_API_BASE_URL`, `CATTOMAIL_API_KEY` and `CATTOMAIL_WEBHOOK_SECRET` in `.env` take precedence over a connection, each by itself, and Connect is refused while one of them is set. The Delivery page and `ctnlist:diagnose` say where each value comes from.
- **Connection state:** the Delivery page shows what is connected (the service, the catto-mail client, who connected it and when) and warns when the domain of the site's From address is not one of that client's sending domains, or cannot send yet (`GET /v1/sending-domains`, read when connecting and by **Check connection**).
- **Disconnect:** asks the mail service to end the connection (`DELETE /v1/connection`: the key is revoked and the webhook endpoint removed), then clears the stored values. If the service cannot be reached, nothing changes. **Forget without revoking** only clears what is stored, for a service that no longer answers. **Connect again** replaces the connection.
- **Administrators only:** connecting and disconnecting need an administrator, whatever else a role's `settings.manage` allows, because the mail service receives every campaign and every recipient's address.
- **Refusals store nothing:** a declined request, a code that is wrong, used or older than 10 minutes, a client at its key or endpoint limit, and a mail service that cannot be reached each say why. Where the approval is still valid, Finish can be pressed again.
- **Acceptable use policy:** `docs/AUP.md` is now ctnlist's own policy, for the people who subscribe and for those who write and send messages (subscribing, forwarding, the contact form and profile pictures; permission to send, what may not be sent, sending properly, limits and enforcement, data and law). It names no product: the page fills in this installation's list name, domain, organisation and contact address from Settings. Abuse reports and complaints go to `abuse@` the installation's domain (`APP_DOMAIN`), never to the administrator's or contact address, which is named only for questions about the policy: that mailbox must exist and be read.
- **Development route:** `CATTOMAIL_API_CONNECT_HOST` may name a port (`catto-mail:443`), and the new `CATTOMAIL_WEBHOOK_URL` gives a connection a webhook address catto-mail's development pod can reach; both are ignored outside `dev`/`test`. catto-mail's pod now joins the `cattomail-dev` network by itself. README "catto-mail development route" describes connecting with the button.
- **Fixed:** the message form answered with a server error when a submission lacked its template field; it now saves with no template. `bin/dev cattomail-status` failed on Podman 4.9 once the `cattomail-dev` network existed.
- **Tests:** `FakeCattoMail` covers catto-mail's connect page, `POST /v1/connect/exchange`, `DELETE /v1/connection` and `GET /v1/sending-domains` by the contract's rules. `ConnectFlowTest` proves the whole connection, every refusal, disconnecting and the precedence of `.env`; `MailServiceConnectionTest` the pages over HTTP. A real run between the two development pods passed: connect with the button, `bin/dev test-cattomail`, the signed webhooks, disconnect.

## 6.0.10 (2026-10-11)

An acceptable use policy page:
- **Acceptable use policy:** the site has a policy page, `/acceptable-use-policy`, linked from the footer of every page beside Privacy and Contact. It shows the acceptable use policy of catto-mail, the mail service ctnlist sends its mailing lists through: who may be mailed, what may not be sent, unsubscribing, the bounce and complaint rates, and where to report abuse.
- **Built from the document:** the page is rendered from `docs/AUP.md` of the release, so the document is the only place the text lives. To publish a different policy, replace that file. The Markdown is rendered by ctnlist itself (headings, paragraphs, numbered and bulleted lists, tables, inline code, bold, emphasis and links); all of its text is escaped and no HTML passes through.

## 6.0.9 (2026-10-11)

Message links and the contact form put in order, a wider message editor, and a plainer sign-in link page:
- **Message links are no longer settings:** the Advertising page, Booking form link and Contact form link are gone from Settings (`APP_ADVERTISE_URL`, `APP_BOOKING_URL`, `APP_CONTACT_URL`; `ctnlist:diagnose` and `bin/dev` say so if `.env` still sets one). `{contact}` always opens ctnlist's own contact form for the subscriber and the message. `{booking}` and `{advertise}` are retired and render nothing; write such a link in the message or template, where `{suid}` and `{muid}` identify the recipient and the message.
- **Contact form privacy:** a subscriber's contact link no longer shows their name, address, phone and company to whoever opens it (it travels with a forwarded message): the form is filled in only for that subscriber, signed in. The acknowledgement sent to whoever submits the form holds only what they wrote.
- **Contact form mail:** the organisation gets its own mail (to the contact address and the administrator, subject "Contact form: <address>") instead of a blind copy of the acknowledgement. It names the subscriber and the message of the link, now with the message's subject, and a reply to it goes to the person who wrote. The "Booking-Form-URL" line is gone. When this mail cannot be sent, the submitter is told so and nothing is acknowledged or counted.
- **Message activity report:** shows the contact forms each subscriber sent from the message, with the time of the last one.
- **Message editor:** the form uses the page's width instead of a main column and a sidebar. First the sender (From name, From address), then the lists, priority and maximum in one row, then the subject, template and content, much as v5 arranged it. The lists are a menu of checkboxes that stays open while lists are ticked and names the ticked ones. "Maximum sends" has its v5 label again, "Maximum emails to send".
- **Subject:** a message cannot be saved without a subject, which may be up to 250 characters (it was 200). A new message starts with an empty subject and content, with placeholders, instead of "Your subject here" and "Enter your message here".
- **Blank messages cannot be sent:** a draft may still be saved without content, but queueing it (alone or in the advanced queue), sending the queue, a proof, a resend and a forward refuse a message with no text and no image, and say why.
- **Sign-in link page:** the page a sign-in link opens now says plainly "You are not signed in yet", names the button to press (Sign in, or Continue for a list confirmation) and explains why the extra step exists; the button is larger. The sign-in email says the same: opening the link alone does not sign you in.

## 6.0.8 (2026-10-10)

The fixes from a defect audit of the whole codebase before deployment, PHP 8.5 as the minimum, and the interface moved to Bootstrap 6:
- **Security:**
  - a sign-in link goes only to the subscriber's own stored address: the v5 address cleanup could turn a typed address (`alice@gmail.example.net`, `mailtoalice@gmail.com`) into someone else's, whose account the link then signed in;
  - only administrators can see or change the transactional SMTP server in Settings, since it receives every sign-in link;
  - sign-in requests over the rate limits create no subscriber; sign-in drops the CSRF token of the session it replaces;
  - an administrator's forward is always their own, and a list that is not active cannot be confirmed by a crafted request.
- **Sending:**
  - batches to catto-mail are cut by size as well as by count, so newsletters to more than a few hundred people no longer fail on catto-mail's 10 MiB request limit, and a message too large for one upload is refused before anything is staged;
  - a queue run that dies (time limit, PHP-FPM reload, reboot) no longer blocks every later run: runs are guarded by a database lock instead of the `CurrentlySending` flag, and the PHP-FPM template lets queue runs and imports raise their time limit;
  - the worker no longer takes a long queue run that is still staging for abandoned, and a recipient never joins a job the worker has already sealed;
  - a job catto-mail sealed whose handoff ctnlist never recorded is recorded when catto-mail reports it, so its recipients reach the Send Log;
  - "Maximum sends" says what it does (0 sends none, as in v5), and a run stopped by it says so.
- **Consent and data:**
  - unsubscribing again (a second click, a replayed one-click link) changes nothing, sends no further notifications and keeps the reason given first; a global unsubscribe says when the suppression database could not be updated;
  - bulk bounce and spam suppressions are stored as the suppression lookup searches for them, and a suppression database outage no longer interrupts a bulk domain suppression;
  - times that PostgreSQL fills in (consent events, memberships, the queue, smlog, roles) are now local time like everything else, not UTC;
  - a subscriber's priority stops at the column's maximum instead of overflowing.
- **Operations:** the application log keeps notices and warnings (recovery actions, settings changes, catto-mail failures), which were silently dropped; `ctnlist:diagnose` requires PostgreSQL 16; the nginx template's plain-HTTP block logs without query strings like the HTTPS one, so a sign-in link opened over `http://` leaves no token in the access log; README spells out the banlist migration's `GDB_ENV_DIRECTORY` and the `logs/` permissions, and describes the production catto-mail check correctly.
- **Tests:** the route smoke test no longer accepts a redirect on administration pages that should render, and the consent tables' append-only and delete protections are tested.
- **Requirements:** PHP 8.5 is now the minimum (it was 8.4.1): the development stack and the new server both run 8.5.
- **Bootstrap 6:** the interface moves from Bootstrap 5.3.8 to 6.0.0-alpha.1. Pages look much the same; on phones the site and administration menus open as side drawers. Bootstrap 6 supports only recent browsers (Chrome and Edge 130, Firefox 132, Safari 18, or later) and is a pre-release, so its class names can still change. The site keeps the light colour mode whatever the visitor's system setting.
- **Documentation:** project documentation now lives in `docs/`: instructions for a Claude project about ctnlist, a status page, and `RESTORED-FUNCTIONALITY.md`, moved there from the top of the repository.

Still to be run once the new production server exists: transactional SMTP, the banlist database and catto-mail end to end (README "Release checklist").

## 6.0.7 (2026-10-09)

Release-candidate hardening before the move to the new server:
- **Sign-in links are safe from mail scanners.** Opening a link shows a page with a Sign in button (Continue for a link to confirm a list); only that button, a POST checked against the page's CSRF token, uses the link up and signs in, so security scanners and link previews that open links no longer use them up. Used, expired and invalid links say so and offer a new one, and the sign-in email asks the recipient to open the link and choose Sign in. Confirming a list still needs its own Confirm.
- **PHP sessions** (CSRF tokens, messages) are removed only by `ctnlist:maintenance`, once idle for longer than a sign-in or a sign-in link lasts; `session.gc_maxlifetime` in `php.ini` no longer matters.
- **`ctnlist:diagnose`** reports a suppression database it cannot use (`SUPPRESSION_PROVIDER=none` in production, a missing `ban.env`) as an ERROR instead of stopping before the rest of the report, and no longer reports a missing `APP_SECRET` twice.
- **PHP extensions:** `composer.json` declares `ext-session` and `ext-filter`, which ctnlist's own code uses, so `composer install` refuses a PHP without them. `ctnlist:diagnose` requires exactly the extensions `composer install` does (now also `pdo`, `json`, `filter` and `iconv`; for `dom`, `xml` and `iconv` it names the package that needs them) and recommends `exif`; a test keeps diagnose, Composer and README in step.
- **Removed:** `MAIL_RATE_PER_MINUTE`, which paced nothing since each transactional mail has its own connection (`ctnlist:diagnose` now warns about it like the other retired settings); unused methods and configuration defaults; leftovers of the CKEditor builder.
- **Restored:** the configured telephone number in the footer, as v5 showed it.
- **Tests:** the smoke tests refuse an installation that does not use the smoke database and never use a development catto-mail; the integration tests refuse any database but `ctnlist_test`. README has a release checklist, and its installation, release and recovery steps now clear the cache before compiling assets (compiled the other way round, a release's assets came from the previous release's cache).
- **Development:** `bin/dev` runs podman-compose itself (pod `pod_ctnlist`), so a docker-compose on the PATH no longer matters. The release history moved from README into this file, CLAUDE.md was shortened (with area notes beside the code) and AGENTS.md was removed.

The real end-to-end check against catto-mail (`bin/dev test-cattomail`) is still to be run, once the new production server exists.

## 6.0.6 (2026-10-07)

Hardens the HTTP boundary and prepares production deployment:
- **Security:**
  - a strict Content-Security-Policy: scripts only from the site and by nonce, no framing; Bootstrap and Bootstrap Icons are served by the site instead of a CDN, and CKEditor's emoji list is served locally;
  - sign-in replaces the PHP session id and logout destroys it;
  - booking and contact links must be web addresses, and one-line settings refuse line breaks;
  - requests to stop email from every sender are rate-limited, and the webhook refuses oversized bodies before reading them;
  - error pages reveal nothing and render even while the database is down; anonymous pages no longer start a session.
- **Deployment:** templates for nginx, PHP-FPM, cron and systemd (`deploy/`); a documented new-installation and release procedure with permissions and request limits (README "Deployment"); `ctnlist:diagnose --production` as the go-live preflight, including whether the worker runs with the web server's configuration; `GET /health` for monitors.
- **Other changes:** every route refuses the wrong method with 405, and regression tests cover sessions, sign-in tokens, headers, links, limits and diagnostics. Tables no longer widen pages on phones.

The real end-to-end check against catto-mail (`bin/dev test-cattomail`) is still to be run, once the new production server exists.

## 6.0.5 (2026-10-07)

Adds operational maintenance and recovery:
- **Housekeeping:** `ctnlist:maintenance` (hourly, `--dry-run`) removes expired sign-in data and sessions, and raw webhook bodies and other temporary catto-mail detail after configurable retention periods. It never touches consent, delivery history or anything still needed for a retry.
- **Diagnostics and recovery:** `ctnlist:diagnose` checks an installation without printing secrets. Stuck work is formally defined and shown on the Delivery pages, with safe, logged recovery actions (retry a job, a validation or an opt-out, reprocess a webhook event). Long histories are paginated.
- **Test isolation:** smoke tests run against a separate, disposable installation and database, and `bin/dev check` proves the development database is untouched.
- **Documentation:** data classification, retention, backups and the recovery procedure (README "Operations").

## 6.0.4 (2026-10-07)

Makes the catto-mail integration operable in production:
- **Integration status page** (Admin → Sending → Delivery): a "Check connection" button that names the failure (host, connection, TLS, key, permission); the last API success and error; a worker heartbeat; counts of pending and stuck work; send-run pages; and a webhook-event diagnostic page.
- **Hardening:** queue staging can no longer stage one queue row twice when queue runs overlap; job states only move forward, even when updates race; bounce and complaint effects commit with their once-only claim; one open global opt-out per subscriber; a single-instance worker with meaningful exit codes.
- **Other changes:** clearer administrator errors (with catto-mail's field errors), an explicit confirmation for the global opt-out, and `ctnlist:cattomail:check` plus the optional real end-to-end check `bin/dev test-cattomail`. `bin/dev up` re-attaches the development catto-mail network automatically. The unused `MAIL_UNSUBSCRIBE_ADDRESS` is removed.

## 6.0.3 (2026-10-06)

Hands campaign delivery to the catto-mail smarthost and hardens the application:
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

## 6.0.2 (2026-10-06)

Arranges the Settings page as tabs, one per section, each with its own form and Save button; fields are laid out on a grid, the SMTP tab shows the main server and each failover server as its own block, and the URL (e.g. `/settings#smtp`) opens and returns to a tab.

## 6.0.1 (2026-10-06)

Removes the defunct Ecwid store (the Store page, its links, `POST /ecwid-subscribe` and `APP_STORE_URL`; a leftover `{STORE}` placeholder renders as nothing) and rebuilds the frontend on plain Bootstrap 5.3: a sticky header with an administration bar that shows only the tools each user's permissions allow, a simple footer, Bootstrap Icons and the system font. Unify, Font Awesome, jQuery and the Google Fonts request are gone; the application's CSS and JavaScript are served by Symfony AssetMapper, and CKEditor loads only on the editors. Every administration screen follows one pattern (page header with the main actions, filter panels, responsive tables, status badges, empty states, confirmation before deleting, clearing or suppressing). `/subscribe` is now a subscribe form for visitors and a list of the subscriber's lists when signed in, with list descriptions. Custom roles can be renamed, deleted and removed from subscribers, each role lists its members, subscribers are found by search, and nobody but an administrator can hand out permissions they do not hold. Proofs go to any address without a subscriber record, and their subscriber links explain that a proof has no subscriber. Sign-in links sent to confirm a list say so. A new Settings page lets administrators override selected `.env` settings, with SMTP secrets encrypted by `APP_SETTINGS_KEY` (README "Settings page").

## 6.0 (2026-10-05)

Completes the move to Symfony (phases 8–10 of the [Symfony migration](#symfony-migration)): the administration pages (messages and templates, queueing, sending and proofs, subscribers, bulk operations, import, export, synchronisation and the Ecwid endpoint) and the Send Log, Site Log and message activity reports move onto Symfony. All mail now goes through the Symfony mailers, the Fat-Free Framework, its bridge and the legacy code are removed, the Site Log again records every request, and the code passes PHPStan level 6. Import logs and export files are written to `APP_LOG_DIR` (default: the installation's `logs/`).

## 5.0.5 (2026-10-05)

Moves every subscriber-facing page onto Symfony and the new services (phase 7): sign-in link requests, the profile pages, the confirm/unsubscribe links and `/subscribe`, forwarding, likes and dislikes, resends, the open-tracking pixel, the archives and the contact form. Sign-in links, consent notifications, forwards, resends and contact acknowledgements are now sent through the Symfony mailers. Administration (subscribers, bulk operations, import/export, messages, templates, the queue) and the reports still run on Fat-Free and the legacy mailer.

## 5.0.4 (2026-10-05)

Ports list, role and ACL administration to Symfony and adds the Symfony campaign core (phases 5–6): global suppression, the Send Log and message log, mail transports with failover, the transactional and campaign mailers, template rendering, archives, messages and the delivery queue. These services are covered by integration tests against a separate test database; the subscriber-facing and administrator routes move onto them in the next phases, so live campaign delivery still uses the Fat-Free code.

## 5.0.3 (2026-10-04)

Moves sign-in, logout, permission checks and CSRF to Symfony Security, renders every page in a single Twig layout, and ports the home, privacy and store pages (phases 3–4).

## 5.0.2 (2026-10-04)

Starts the move from the Fat-Free Framework to Symfony 8.1, keeping the behaviour of 5.0.1-restored (phases 0–2): a route smoke suite and PHPStan baseline, the `public_html/`, `src/`, `templates/` and `config/` layout, and the Symfony kernel as the front controller with a Fat-Free bridge. Also adds the podman development environment (nginx, PHP 8.5-FPM, PostgreSQL 16, Mailpit) and drops legacy compatibility (the `ALL` shortcode, 3–6 character list shortcodes, a single schema migration).

## 5.0.1-restored (2026-10-03)

The working v5.0 application's behaviour restored on top of the approved v5.0.1 technical and data-model upgrades; see [RESTORED-FUNCTIONALITY.md](docs/RESTORED-FUNCTIONALITY.md).

## Symfony migration

The move from Fat-Free to Symfony was made in phases; each left a working application with lint, PHPStan and the smoke suite passing.

| Phase | Version |
|---|---|
| 0. Route smoke suite and PHPStan baseline | 5.0.2 |
| 1. Directory layout (`public_html/`, `src/`, `templates/`, `config/`) | 5.0.2 |
| 2. Symfony kernel as front controller, Fat-Free bridge, container-built legacy services | 5.0.2 |
| 3. Authentication and security (Symfony Security, magic-link authenticator, ACL voter, CSRF) | 5.0.3 |
| 4. Twig layout and simple pages | 5.0.3 |
| 5. Lists, roles and ACL administration | 5.0.4 |
| 6. Campaign core services on DBAL repositories | 5.0.4 |
| 7. Subscriber-facing routes | 5.0.5 |
| 8. Administrator campaign routes | 6.0 |
| 9. Reports | 6.0 |
| 10. Remove Fat-Free; PHPStan level 6 | 6.0 |
