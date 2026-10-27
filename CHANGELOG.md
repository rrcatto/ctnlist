# Changelog

The release history of ctnlist. Versions 5.0.x and 6.0.x are an incremental modernisation of the working v5.0 application; the v5 behaviour they keep, and the deliberate changes from it, are listed in [RESTORED-FUNCTIONALITY.md](docs/RESTORED-FUNCTIONALITY.md).

## 6.1.5 (2026-10-28)

The owner's guided-setup choices of 2026-10-27, ctnlist's half, and subscriber Actions with blocks kept by catto-mail (catto-mail 0.3.0, OpenAPI 1.0.0-draft.11: upgrade the mail service first, since an older one refuses blocks). One schema change, the new table `cattomail_blocks`: an installation upgrades with `--recreate-database` (its database is dumped first), and then connects to the mail service again from Set up sending, or has its data loaded back from that dump.
- **Actions** on every subscriber (the Subscribers list's rows and the subscriber page; owner decision 2026-10-27): edit details, invite to a list, unsubscribe from a list (also beside each list on the subscriber page) or from every list, block the address or every address at its domain and unblock them, clear a sending problem, send history and roles, each with its icon and title. The list marks blocked and bounced or complaining subscribers.
- **Blocks**: an administrator's block of an address or a whole domain is kept by catto-mail as an account block (`POST /v1/blocks`, `POST /v1/blocks/{id}/lift`; recorded first in the new table `cattomail_blocks` with its key, retried by the worker), so every application on the account stops too; it stops the address here at once, whatever `SUPPRESSION_PROVIDER` says, and is recorded in the banlist as well where one is kept. Bulk unsubscribe's domain, bounce and spam scopes now make blocks instead of being refused without a banlist. Schema change: `cattomail_blocks` (run `bin/dev reset-db`; an installation upgrades with `--recreate-database`).
- The privacy policy says that blocked addresses and domains are given to the email delivery service.
- **Set up sending** (Settings menu, `/delivery/setup`): four steps from nothing to the first message, one open at a time and each ticked when done: an account at the mail service (what it is, and that Connect can open one where the mail service accepts new accounts, or its operator sends an invitation), Connect, the sending domain ready (Connect now tells the mail service the domain of the site's From address, which approving adds to the account; **Open the DNS steps** leads to that domain's page at the mail service and back; the page redraws itself every 30 seconds while it waits, and **Ask the mail service now** asks at once), and a test message to yourself through the mail service (logged in the Send Log as a proof). Until all four are done, administration pages show administrators a notice that links there. Holders of `settings.manage` can follow the steps; the buttons are an administrator's.
- **The mail service's address** is a setting: `.env` gives it (`CATTOMAIL_API_BASE_URL`, which `install-ctnlist` and `ctnlist-podman.sh` now take as `--mail-service`), Settings → *Mail service* (administrators only, https only) changes it while nothing is connected, and Connect asks for it only when none is known and keeps it. A connection keeps the address it was made with, under the same name as before; Disconnect keeps the address, so connecting again needs no typing. A key or secret in `.env` still wins over a connection, and takes `.env`'s address with it.
- **Address validation** asks which members of the list to check: the confirmed members (the default: only they receive campaigns, and every address counts against the mail service's daily validation limit), the unconfirmed members, or everyone who has not unsubscribed. The job's scope names the choice.
- **Copy buttons** beside the values someone copies elsewhere: the mail service's address on the Delivery page, the Connect pages and Set up sending. Without JavaScript the value can still be selected.
- **Plainer words** on the pages this touches: the Delivery page is titled *Delivery* and says what it shows, "client" is now "account", and the From-domain warnings say what to do, with a link to the domain's DNS steps.
- **Every item of the administration bar has its icon** (each menu entry, not only the menus), all Bootstrap Icons; Bootstrap Icons is updated from 1.13.1 to 1.13.2 (`assets/lib/README.md`).
- Development: `bin/dev cattomail-status` reports a `CATTOMAIL_CA_FILE` the app container cannot read (its path is under the versioned shared-code mount, so a version change moves it), and `bin/dev cattomail-ca` prints the exact line to put in `dev/podman/ctnlist.env`.

## 6.1.4 (2026-10-27)

Administration pages that update in part (htmx), a Sending progress page, and roles on a subscriber's page. No schema change.
- **htmx 4** (4.0.0, the npm package's `dist/htmx.esm.min.js`, verified against the registry and served by the site like Bootstrap: `assets/lib/htmx`) is loaded on every page. A page that htmx updates in part draws each part as a region, and the server answers htmx's request for a region from the page's own address with that region alone (`App\Http\Htmx`, `App\Controller\RendersRegions`); every other request, Back included, gets the whole page, so every page still works without JavaScript. The Content-Security-Policy is unchanged: no template uses `hx-on`, `js:` values or trigger filters, which need `eval`, and the `data-hx-` prefix is off.
- **Search as you type:** Subscribers, Roles & ACL's Find a subscriber, the Send Log, the Site Log, a message's views, the webhook events, a send job's recipients and a validation job's results search 300 ms after the last change, without a Search button (it is still there without JavaScript), and the address bar follows. New searches: Messages by subject, Templates by name, and a role's members by email or name. Page turns redraw only the table, and Back returns to the page before.
- **Buttons that change one part of a page** redraw only that part, with their message: Remove and Clear queue on the queue; Create list (a refused list shows its errors in place) and Delete on Lists; Check connection and an opt-out's Retry on the Delivery page; Retry sending, Return to queue and Ask catto-mail now on a send job; Process again on a webhook event; Retry submission and Ask catto-mail now on a validation job; Assign on Roles & ACL and Remove on a role's members. An action comes back to the page it was pressed on, its search and page number kept.
- **Subscriber page:** the profile form is the left column, and each card on the right has its own buttons. A **Roles** card (holders of `roles.manage`) shows the roles the subscriber holds, with Assign (Administrator included) and Remove for custom roles. Inviting to a list is its own form on the List memberships card (**Invite**), instead of a field of Save subscriber.
- **Sending progress:** Start sending answers at once with the new Sending progress page (Sending menu), and the run goes on after that answer (`App\Queue\QueueRun`, `QueueRunListener`). The page shows how many messages are prepared and how many are still queued, refreshes itself every 3 seconds while the run lasts, offers Stop sending, and shows the outcome; it can be left and opened again. A run that the server ended is shown as stopped, and a request that never began as not started. Stop sending now returns to that page.
- **Pages that refresh themselves:** a send job's page and a validation job's every 15 seconds until the job is final, and the Delivery page every 30 seconds while the domain of the site's From address cannot send. The worker asks catto-mail for the sending domains on every pass while that domain cannot send, and its output says once that the domain has become ready, so the warning on the Delivery page goes by itself. These refreshes are not recorded in the Site Log, and the privacy policy says so.
- "Check now" on a send job and a validation job is now **Ask catto-mail now**, since the page itself refreshes.
- **Fixed:** on a subscriber page with a hard bounce or complaint, **Clear the sending block** sat inside the Save subscriber form. Browsers drop a form placed inside another, so the button submitted the profile instead, without its confirmation question, and the block stayed. The smoke tests now fail any page with a form inside a form.

## 6.1.3 (2026-10-26)

Bots kept off the sign-in and subscribe forms, every subscriber record on the Subscribers page, and a Flagged activity report:
- **Bots on the forms:** form bots were typing other people's addresses into the sign-in and subscribe forms, so the site emailed sign-in links to people who never asked for them, a risk to the domain's reputation. Both forms now refuse what looks like a bot (`App\Http\BotCheck`): a hidden field filled in (`website`, off-screen and out of the tab order, which people never see), a request that a server in front marks with `X-Suspect-Network: 1`, or no browser named or one long out of use (Internet Explorer, Chrome or Firefox before 80, Safari before 11, which is how these bots describe themselves). The visitor gets the usual "link sent" answer, so a bot cannot tell; nothing is sent and no record is created. README *Configuration* shows how nginx can mark networks.
- **Flagged activity** (Reports → Flagged activity, `logs.view`): the refused submissions with the address typed in, the IP addresses behind bot-like requests for the form pages (earlier requests count by their browser), and the records created in the last 30 days that never signed in or confirmed a list, which bots leave behind (shown to holders of `subscribers.view`). The menu counts the refused submissions.
- **Site Log:** each request's method and the site's answer (status) are recorded and shown, and a refused submission's reason and address; a filter shows the refused submissions only. The privacy policy says so. The table has four new columns, so this release has another schema than 6.1.2: an installation moves to it with `--recreate-database` (the installer or the Podman kit dumps the database first).
- **Subscribers page:** it shows every record by default, confirmed or not, with when it was created and last signed in. Earlier releases showed only subscribers with a confirmed membership unless "Include unsubscribed" was ticked, which hid the records the sign-in and subscribe forms create for new addresses; a "Show" choice now narrows the list to those subscribed to an active list, or to the others. The menu counts every record, and Active readers every one who has interacted with a message.
- **Fixed:** searching the Subscribers page with "All lists" answered "The request could not be completed" (a 400): the empty list field could not be read as a number. An emptied "Rows per page" box (Subscribers, Send Log, Site Log) or queue limit failed the same way; both now mean the default.

## 6.1.2 (2026-10-26)

Periods of time written one way:
- **Periods:** every setting that is a period of time is now written the owner's way, as in cattolms: years, weeks, days, hours, minutes and seconds, in that order, each optional, lower case, a whole number from 1 up (`1y1w1d1h1m1s`, `120d`, `30m`, `1m30s`). There are no months (`m` is minutes). As a length a year is 365 days; as a retention a year is a calendar year, so `3y` can include a leap day. `App\Util\Duration` reads them.
  - **Settings page:** the sign-in link lifetime (`AUTH_MAGIC_LINK_TTL`, default `30m`, at least `1m`), the session length (`AUTH_SESSION_TTL`, `1d`, at least `5m`) and the two rate windows (`AUTH_MAGIC_LINK_EMAIL_WINDOW`, `15m`; `AUTH_MAGIC_LINK_IP_WINDOW`, `1h`; at least `1m`) are typed as periods; a bare number, `0`, a month or a period below the minimum is refused at the field with how to write one. A stored override that is a number of seconds is no longer a period: enter it again.
  - **Renamed in `.env`**, the unit now being in the value: `CATTOMAIL_TIMEOUT_SECONDS` → `CATTOMAIL_TIMEOUT` (`15s`), `CATTOMAIL_RECONCILE_AFTER_SECONDS` → `CATTOMAIL_RECONCILE_AFTER` (`15m`), `CATTOMAIL_WEBHOOK_BODY_RETENTION_DAYS` → `CATTOMAIL_WEBHOOK_BODY_RETENTION` (`90d`, at least `1w`), `CATTOMAIL_DETAIL_RETENTION_DAYS` → `CATTOMAIL_DETAIL_RETENTION` (`1y`, at least `30d`), `APP_SITELOG_RETENTION_DAYS` → `APP_SITELOG_RETENTION` (`120d`). An `.env` must be changed by hand: `ctnlist:diagnose` names an old setting that is still there and any period it cannot read, whose default then applies. The `AUTH_*` periods keep their names and need values such as `30m` and `1d`.
  - **The Site Log is never kept for ever or not at all:** `0` meant "keep with no limit" in 6.1.1, and now is not a period (its default, `120d`, applies); the shortest retention is a week. The privacy policy says the period in words ("for 120 days").
  - The sign-in page and email, the Delivery page and `ctnlist:diagnose` say the periods in words ("30 minutes", "15 minutes", "1 year").

## 6.1.1 (2026-10-26)

A full privacy policy, and the release named on every page:
- **Privacy policy:** the Privacy page is now a full policy, built from `docs/PRIVACY.md` of the release like the acceptable use policy: what is collected (the address, the profile, lists and consent, what happens to each message, the engagement score, the contact form, forwards, the record of visits), why, who else handles it (the email delivery service, the mail server, the hosting provider, the people who run the lists), the two cookies, how long each kind is kept, the subscriber's rights under POPIA with the Information Regulator, and how it is protected. It names the installation from its Settings, sends requests to the administrator's address (`MAIL_ADMIN_ADDRESS`, else `APP_ADMIN_EMAIL`, the address the footer links to), and says how long the record of visits is kept from `APP_SITELOG_RETENTION_DAYS`. That mailbox must be read (README, *Release checklist*). It replaces the v5 text, which said personal information was divulged to nobody.
- **Site Log retention:** the record of visits, which holds IP addresses, is now kept for 120 days by default: `ctnlist:maintenance` removes older rows. `APP_SITELOG_RETENTION_DAYS` sets another number of days, and 0 keeps it with no limit, as v5 did and as earlier releases did by default. `.env.example` now sets 120; an existing `.env` that sets 0 keeps the Site Log until it is changed.
- **Version:** every page names the release in its head (`<meta name="generator" content="ctnlist v6.1.1">`, the 404 page too), and the footer shows it, centred, on its last line.

## 6.1.0 (2026-10-24)

Global suppression without a suppression database, and a second way to install, in a rootless Podman pod:
- **catto-mail's suppression:** `SUPPRESSION_PROVIDER=cattomail` runs an installation, production included, without the banlist database. catto-mail, which delivers every campaign, refuses suppressed recipients when a send is staged and suppresses hard-bounced and complaining addresses itself; ctnlist already marks those subscribers from catto-mail's outcomes. ctnlist still applies the v5 address rules at confirmation, queue build and before each send, and records no global suppression of its own: a subscriber's "all lists" unsubscribe (offered as "All lists on this site") unsubscribes them from this site's lists and says only that, and the administrator's bulk scopes that only record global suppressions (domains, bounces, spam complaints) are refused with the reason. An ordinary unsubscribe is still never sent to catto-mail; its global opt-out stays the subscriber's explicit request. `ctnlist:diagnose` names the provider, and `install-ctnlist` accepts an installation that uses it. `banlist` stays the default; `none` is still for development only. README *Configuration*.
- **Rootless Podman:** `deploy/podman/` runs an installation as its own Linux user in a Podman pod, for a server that already hosts other sites with nginx and a control panel such as Virtualmin. The server's nginx keeps ports 80 and 443 and the certificate and forwards the site to the pod on `127.0.0.1:<port>`; PostgreSQL and the mail server stay on the server. README *Rootless Podman* and `deploy/podman/README.md`.
  - **The pod:** nginx and PHP-FPM, and Mailpit for proofs with `--mailpit`. ctnlist's PHP image is built from the release: an exact PHP release (8.5.12), the extensions ctnlist needs and the pool of `deploy/php-fpm`. The worker and maintenance are systemd user timers that run in the pod, with the commands and schedules of `deploy/systemd`.
  - **Read-only:** the code, `.env`, `public_html` and the suppression database's settings (when the installation has that database) are read-only in every container; PHP writes `var/` and `logs/` only. Inside the pod the layout is the README's (`/usr/local/lib/php/ctnlist/<version>`, `/var/www/<installation>`), so the front controller and the console commands are unchanged.
  - **The visitor's request:** the pod's nginx believes the forwarded address only from Podman's own hop and takes only the last one, so a visitor cannot choose the address the sign-in rate limits and the Site Log see; it hands PHP HTTPS and port 443 (the mail service's Connect page is given the site's own callback address), redirects plain HTTP to HTTPS and logs without query strings. `host-nginx.conf.example` is the server side: the visitor's address only, a 300-second timeout for queue runs and imports, and an access log format without query strings that AWStats still reads.
  - **`root-prepare.sh <user>`**, once, as root: the user ids rootless containers need and linger; with `--database`, the site's role and empty database with a generated password; with `--banlist-env` or `--create-banlist`, the suppression database's settings; without them the installation uses catto-mail's suppression (`SUPPRESSION_PROVIDER=cattomail`). Passwords reach psql on its standard input.
  - **`ctnlist-podman.sh install`**, as the site's user from then on: a new installation or the switch to a release, in 13 steps each PASS, WARN or FAIL, safe to run again, like `install-ctnlist`. It refuses a database that holds tables other than the release's schema, dumps the database before a switch, refuses a release with another schema without `--recreate-database`, keeps earlier releases and switches back to one by naming it. Generated secrets and passwords reach PHP on its standard input, never on a command line. `status`, `logs`, `console`, `start`, `stop` and `restart` run the pod.
  - **Tests:** `bin/dev test-podman-kit` runs both scripts in a stand-in server (Ubuntu 24.04 with systemd, Podman 4.9, PostgreSQL 16 and nginx configured from the example): a refused database, a new installation with Mailpit and catto-mail's suppression, a second one with the suppression database, the site through nginx and the pod, a forged `X-Forwarded-For` ignored, a sign-in with the link mailed through the pod, the Connect callback address, the timers, a restart, a second run, a switch and back, and a changed schema. `tests/Unit/PodmanKitTest` holds the kit's security-relevant lines and its agreement with `deploy/`; `PhpExtensionsTest` checks the kit's extension list too.
- **Fixed (tests):** the integration tests took the catto-mail development route (`CATTOMAIL_API_CONNECT_HOST`, `CATTOMAIL_WEBHOOK_URL`) from the development `.env`, so `CattoMailCheckCommandTest` failed whenever the local catto-mail was not running; `phpunit.dist.xml` now clears both, as it sets the rest of catto-mail's configuration. `bin/dev test-installer` expected every release to add settings to `.env`; it now checks for the ones the release really adds (none in 6.1.0).

## 6.0.11 (2026-10-12)

An installer, connecting the mail service from the Delivery page, proofs to Mailpit, three sample templates, an acceptable use policy of the site's own, and two fixes:
- **Installer:** `install-ctnlist`, at the root of the release, installs ctnlist on a server or moves an installation to another release, as root, in one command: `sudo bash install-ctnlist --installation <name> --version vX.Y.Z` (without `--version`, the newest release). It needs a Debian or Ubuntu server with PHP 8.5 and PHP-FPM, Composer, Git, PostgreSQL 16 or later and nginx; it checks them and installs no packages. Each of its 14 steps says PASS, WARN or FAIL, and running it again is safe.
  - **A new installation:** the code, `.env` with generated secrets, the database and the suppression database, the schema, the cache and the assets, a PHP-FPM pool, an nginx site (with a temporary certificate until Let's Encrypt's is there) and the worker and maintenance as systemd timers.
  - **An existing installation:** the new release beside the old one, a dump of the database in `/var/backups/ctnlist/`, a question, then the switch. `.env` and the data are kept, and an earlier release can be switched back to by naming it.
  - **What it will not do:** replace an `.env`, a database, a pool or an nginx site that exists; serve a host name another nginx site already serves (its site waits beside it as `.conf.new`); or move to a release with another schema without `--recreate-database`, which empties the database after dumping it.
  - `bin/dev test-installer` runs it in a stand-in server. README, *The installer*.
- **Connect:** an administrator connects the mail service on **Admin → Settings → Delivery** instead of copying an API key and a webhook secret into `.env`. Enter the service's address and press Connect; sign in at the mail service and approve; ctnlist then collects, server to server, what the service hands over once: the API address, an API key and the signing secret of a webhook endpoint for this site. It is catto-mail's connect flow (an authorisation-code flow with PKCE, catto-mail 0.2.6, OpenAPI 1.0.0-draft.10): the browser carries only a single-use code and a state, never a key or secret.
- **Stored encrypted:** the address, the key and the secret go into the Settings store (`options`), the key and the secret encrypted with `APP_SETTINGS_KEY`. They are never shown, logged or listed on the Settings page. If `APP_SETTINGS_KEY` is replaced, the page says the stored key cannot be read.
- **`.env` still wins:** `CATTOMAIL_API_BASE_URL`, `CATTOMAIL_API_KEY` and `CATTOMAIL_WEBHOOK_SECRET` in `.env` take precedence over a connection, each by itself, and Connect is refused while one of them is set. The Delivery page and `ctnlist:diagnose` say where each value comes from.
- **Connection state:** the Delivery page shows what is connected (the service, the catto-mail client, who connected it and when) and warns when the domain of the site's From address is not one of that client's sending domains, or cannot send yet (`GET /v1/sending-domains`, read when connecting and by **Check connection**).
- **Menu:** the Delivery page is now under **Settings** in the administration menu (it was under Sending), because it is where the mail service is connected. Its address and the permissions it needs are unchanged.
- **Disconnect:** asks the mail service to end the connection (`DELETE /v1/connection`: the key is revoked and the webhook endpoint removed), then clears the stored values. If the service cannot be reached, nothing changes. **Forget without revoking** only clears what is stored, for a service that no longer answers. **Connect again** replaces the connection.
- **Administrators only:** connecting and disconnecting need an administrator, whatever else a role's `settings.manage` allows, because the mail service receives every campaign and every recipient's address.
- **Refusals store nothing:** a declined request, a code that is wrong, used or older than 10 minutes, a client at its key or endpoint limit, and a mail service that cannot be reached each say why. Where the approval is still valid, Finish can be pressed again.
- **Acceptable use policy:** `docs/AUP.md` is now ctnlist's own policy, for the people who subscribe and for those who write and send messages (subscribing, forwarding, the contact form and profile pictures; permission to send, what may not be sent, sending properly, limits and enforcement, data and law). It names no product: the page fills in this installation's list name, domain and organisation from Settings. Abuse reports and complaints go to `abuse@` the installation's domain (`APP_DOMAIN`), and that is the only address on the page: the administrator's and contact addresses are not in the policy, and the page's footer leaves the administrator's link out. That mailbox must exist and be read.
- **Development route:** `CATTOMAIL_API_CONNECT_HOST` may name a port (`catto-mail:443`), and the new `CATTOMAIL_WEBHOOK_URL` gives a connection a webhook address catto-mail's development pod can reach; both are ignored outside `dev`/`test`. catto-mail's pod now joins the `cattomail-dev` network by itself. README "catto-mail development route" describes connecting with the button.
- **Fixed:** the message form answered with a server error when a submission lacked its template field; it now saves with no template. `bin/dev cattomail-status` failed on Podman 4.9 once the `cattomail-dev` network existed.
- **Proofs to Mailpit:** the new `.env` setting `MAIL_PROOF_SMTP` (host and port of a Mailpit instance's SMTP listener, e.g. `127.0.0.1:1025`) sends proofs ("Send to me") straight to Mailpit, bypassing the mail service. Empty, the default, sends them through the mail service as before. The proof is the same merge of message and template; it is read on Mailpit's page and does not reach the address it is made out to, which the proof page and its result say. It is logged as PROOF, needs no mail service, and is refused with the reason when Mailpit cannot be reached. Proofs only: queue sends, resends and forwards always go through the mail service, and so does the proof of `bin/dev test-cattomail`. `ctnlist:diagnose` and the Delivery page say where proofs go.
- **Mailpit in production:** `deploy/mailpit/ctnlist-mailpit.service` runs Mailpit on the ctnlist server with its SMTP and its web page bound to 127.0.0.1 and the page protected by Mailpit's own basic auth; `deploy/nginx/mailpit.location.conf.example` publishes the page at `/mailpit/` of the installation. README "Proofs to Mailpit" has the steps. Development points the setting at its own Mailpit (`dev/podman/ctnlist.env.dist`).
- **Sample templates:** three templates to use and adapt, a newsletter, an announcement and a letter, each with an HTML and a text part written with ctnlist's placeholders (`resources/sample-templates/`). `bin/console ctnlist:templates:samples` adds the ones not there yet and never replaces a template; `bin/dev up` runs it in development, and it is an optional step of a new installation.
- **Tests:** `ProofRouteTest` proves both proof routes (the mail service, and Mailpit through a captured SMTP connection), the smoke suite sends a real proof to the stack's Mailpit and reads it back, and `SampleTemplatesTest` checks the samples, the command and a full render of each. `FakeCattoMail` covers catto-mail's connect page, `POST /v1/connect/exchange`, `DELETE /v1/connection` and `GET /v1/sending-domains` by the contract's rules. `ConnectFlowTest` proves the whole connection, every refusal, disconnecting and the precedence of `.env`; `MailServiceConnectionTest` the pages over HTTP. A real run between the two development pods passed: connect with the button, `bin/dev test-cattomail`, the signed webhooks, disconnect.

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
