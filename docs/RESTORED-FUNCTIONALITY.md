# ctnlist restored functionality

This source tree uses the working v5.0 application as its behavioural baseline while retaining the approved v5.0.1 technical and data-model upgrades. Versions 5.0.2 to 6.0 preserved this behaviour while moving the application to Symfony (complete in 6.0); the workflows and invariants below were the acceptance criteria for that migration and remain the baseline for further changes.

## Administrator authority

- Messages and templates can always be saved as drafts.
- No list is required when saving a message.
- `ALL` is optional and is never inserted automatically.
- Message values are stored as supplied rather than rewritten into a system-selected workflow.

## Message workflow

Restored or retained:

- create and edit messages
- optional assignment to one or more lists
- message list pagination and statistics
- Queue, SendQ and Proof controls
- message activity links
- ordinary queue construction
- advanced queue rotation across a total audience volume
- immutable first-send archives
- proof, resend, forwarding and archive forwarding
- message-linked confirmation, subscription and unsubscription tracking
- proof confirmation by GET followed by CSRF-protected POST delivery

## Audience and delivery

- Selected lists form one combined audience.
- Subscribers appearing in several selected lists are queued once.
- Only confirmed, non-unsubscribed members of active selected lists are eligible.
- Global email and domain suppression is enforced during confirmation, queue construction and immediately before delivery.
- A global unsubscribe disables every local list membership for that subscriber.
- Engagement and priority ordering from v5 is retained.
- Queue rows store the actual list context used for the recipient.
- Delivery is done by the catto-mail smarthost (replacing v5's SMTP batching, delay, retry and failover; see below).

## Audit invariants

- Every successful email handoff is written to `sendlog` (for campaign content: when catto-mail has sealed its send job).
- Every campaign-content delivery to a subscriber is written to `smlog`.
- A normal queue send is blocked when the `(subscriber UUID, MUID)` `smlog` record already exists.
- Resends, proofs and administrator copies are deliberate exceptions but remain audited (proofs in the Send Log only).
- Forwarded campaign copies use the campaign delivery path (catto-mail) and retain list context.

## Subscriber actions

Transactional notification email and Send Log recording are restored for:

- subscription invitation
- subscription confirmation
- unsubscription
- subscriber profile update
- authenticated profile update
- forwarding action
- passwordless login link
- contextual contact/order acknowledgement

## Administration restored

- subscriber search, list filtering and pagination
- active-reader view
- bulk subscribe/unsubscribe
- import and export
- subscriber synchronisation
- v5 email typo/domain correction and address-change handling
- queue inspection and management
- message activity report
- Send Log report
- Site Log report
- roles and ACL administration

## Retained v5.0.1 upgrades

- PostgreSQL and Phinx
- Symfony Mailer (Symfony 8.1 framework from v5.0.2)
- UUIDv7 subscriber identity
- subscriber-based passwordless authentication (Symfony Security from v5.0.3)
- database sessions
- multiple lists and per-list consent
- roles and ACL
- CSRF protection
- separate suppression database
- externally located `ban.env` support through `GDB_ENV_DIRECTORY` and `GDB_ENV_FILE`

## Known v5 behaviours

These are part of the v5 baseline and are preserved deliberately; change them only by decision.

- The v5 address cleanup rules (now `App\Subscriber\EmailNormaliser`) make addresses in non-commercial domains unusable on purpose, including `.org`, `.gov`, `.gov.za`, `.gov.uk`, `.ac.za` and `.ac.uk`, as well as role accounts such as `postmaster@` or `newsletter@`. An unusable address counts as globally suppressed, so such a subscriber loses every list membership when a message is queued.

## Deliberate changes from v5

Made while porting to Symfony, where the v5 behaviour was a defect rather than a workflow:

- Contact-form values are HTML-escaped in the acknowledgement mail (v5 inserted them raw).
- The profile form's country list has a blank choice (v5 silently stored the first country, Afghanistan, when none was chosen).
- A sign-in link is claimed atomically, so two simultaneous sign-ins with it cannot both succeed.
- Opening a sign-in link shows a page with a Sign in button (Continue for a link to confirm a list), and only that button signs in and uses the link up. In v5 opening the link signed in, so a mail scanner or link preview that opened it first used it up.
- Forms on the Symfony pages redirect after a successful change and show the outcome as a message, so reloading does not resubmit.
- Saving the subscriber form as a subscriber ignores a posted priority: v5 added 100 to whatever priority the request carried, so a crafted request could raise one's own send priority. Subscribers still get the v5 result of 100.
- The Site Log records every request again, including Symfony pages and unknown paths (5.0.2–5.0.5 recorded only requests that reached the Fat-Free bridge; fixed in 6.0). The row is written after the response is sent, so the reverse DNS lookup no longer delays pages; its time is PHP time like the rest of the application (it was the database's UTC default), and the IP is the client address as Symfony determines it (`X-Forwarded-For` is honoured only from `TRUSTED_PROXIES`; the raw header is still recorded in its own column).
- The Ecwid store page (`/store`), the Ecwid subscription endpoint (`POST /ecwid-subscribe`) and the `{STORE}` merge tag (`APP_STORE_URL`) are removed in 6.0.1: the store is defunct. Old content that still contains `{STORE}` renders it as nothing.
- `/subscribe` no longer shows a sign-in form. Signed-in subscribers see every list with their membership and a subscribe or unsubscribe action (v5 asked them to sign in again). Visitors get a subscribe form (email address and list) that emails them a link to confirm; as before, nothing is subscribed until they confirm.
- Proofs go to any valid address (by default `MAIL_TEST_ADDRESS`, editable on the proof page) without needing a subscriber record. v5 required the test address to be a subscriber and marked the proof as that subscriber's delivery in `smlog`, which could make the queue skip them for the real campaign. A proof is now rendered with test merge values (first name "Test", last name "Recipient", links that belong to no subscriber, no tracking pixel) and logged in the Send Log only.
- Links in a proof copy that would act for a subscriber (unsubscribe, confirm, forward, preferences, reactions, booking and contact forms) lead to a page explaining that a proof has no subscriber; proofs have no tracking pixel.
- A sign-in link requested to confirm a list (the subscribe form, a confirmation link) is sent as "Confirm your subscription to …" instead of a sign-in email; it is the same secure link.
- A sign-in link is sent to the subscriber's own stored address, never to the address as typed: the v5 address cleanup (e.g. `alice@gmail.example.net` → `alice@gmail.com`) could otherwise send a link for one subscriber's account to another mailbox. Sign-in requests over the rate limits create no subscriber.
- Unsubscribing from a list one is already unsubscribed from (a second click, a repeated one-click link) changes nothing and sends no further notification, and a later global unsubscribe keeps the reason given first.
- Only administrators can see or change the transactional SMTP server in Settings: it receives every sign-in link.
- Role administration is complete: custom roles can be renamed, described, deleted and removed from subscribers, each role lists its members, and subscribers are found by search instead of a picker limited to the first 500. Non-administrators cannot hand out permissions they do not hold.
- Opening another person's subscriber form, or one that does not exist, gives the 403 or 404 page instead of a 200 page reading "Access denied." or "The subscriber does not exist.".
- Campaign content (queue sends, proofs, resends, forwards) is delivered by the catto-mail smarthost through its API, rendered per recipient by ctnlist. ctnlist's own SMTP campaign transport is gone: the failover servers (`MAIL_SMTP_SERVERS_JSON`), `MAIL_BATCH_SIZE`, `MAIL_BATCH_DELAY` and the per-minute pace (`MAIL_RATE_PER_MINUTE`) no longer exist, and `MAILER_DSN` carries transactional mail only. catto-mail sets the envelope (VERP return path); the v5 `Sender`, `List-Owner`, `List-Post`, `List-Subscribe`, `List-Archive` and `X-ctnlist-*` headers are not sent (catto-mail accepts no arbitrary headers), and `List-Id` is `<list name> <shortcode.APP_DOMAIN>` per list.
- `List-Unsubscribe` is a signed one-click link (RFC 8058) that unsubscribes from that list without signing in; the `{unsubscribe}` link in the message body still leads to the signed-in unsubscribe page.
- Hard bounces and complaints reported by catto-mail take a subscriber out of campaign selection (a complaint also unsubscribes them from every list). `MAIL_BOUNCE_LIMIT`, which nothing implemented, is removed.
- "Sent" counts and Send Log rows for campaign content mean "accepted by catto-mail" (the send job was sealed); delivery outcomes are on the Delivery page.
- `MAIL_UNSUBSCRIBE_ADDRESS` is removed: v5 used it for a mailto `List-Unsubscribe`; catto-mail now writes `List-Unsubscribe`/`List-Unsubscribe-Post` from the signed one-click URL. `MAIL_BOUNCE_ADDRESS` remains the return path of transactional mail only.
- Asking for "no email from any sender" needs a ticked confirmation box (checked on the server, not only a browser dialog), and the subscriber is told whether catto-mail has the request yet, will get it shortly, or refused it.
- Placeholders never appear literally. In archives, `{booking}` and `{contact}` become their plain labels (BOOKING FORM, CONTACT FORM) and `{baseurl}`/`{listshortcode}` are filled; v5 meant to replace them but assigned the result to unused variables. `{lms-booking}` was only ever "replaced" in that dead code and has no configuration; it is retired and, like `{STORE}`, renders as nothing. `{advertise}`, `{facebook}`, `{twitter}` and `{booking}` render nothing when their URL is not configured (v5 produced a link with an empty address). `{usertrack}` is dropped from the plain-text part (the pixel exists only in HTML). Subscriber names are HTML-escaped in the HTML part.
- Message HTML shown on the public archive page is sanitised (no script, event handlers, `javascript:` links, frames or forms); the stored archive and the mail itself are unchanged. Archive pages, and archive forwarding, return 404 while `APP_ARCHIVE_ENABLED` is off (v5 only hid the menu link).
- Logging out is a POST with a CSRF token (the account menu's "Log out" is a form); `GET /logout` answers 405. Export (`/export`) and synchronisation (`/sync`) show a form and act only on POST with a CSRF token; v5 exported and synchronised on a GET link (`/export/{offset}/{limit}`, `/sync`).
- The administrator's subscriber form is at `/subscribers/{token}[/{muid}]` (was `/subscribe/{token}`, which read as the public subscribe page); the profile-update notification links to the subscriber's own profile.
- The sign-in page and email state the configured link lifetime (`AUTH_MAGIC_LINK_TTL`, e.g. "2 hours") instead of a fixed "30 minutes".
- The profile's "Photo URL" text field (`s_photo`, stored but never shown in v5) is replaced by an uploaded profile picture: chosen, positioned, zoomed and rotated on Edit profile, stored as a 256-pixel PNG in the database (`subscriber_images`), and shown beside the subscriber's name in the site menu and on their profile (a placeholder when there is none, and for signed-out visitors).
- Contact messages and forwards/resends are rate-limited per hour (`CONTACT_RATE_LIMIT`, `FORWARD_RATE_LIMIT`, Settings); over the limit the page answers 429.
- The booking and contact form links (`APP_BOOKING_URL`, `APP_CONTACT_URL`) must start with `https://`, `http://` or `{BaseURL}`; anything else (e.g. `javascript:`) is refused on the Settings page and never rendered as a link in mail. v5 used any configured text.
- Bootstrap and Bootstrap Icons are served by the site itself, not a CDN, and every page has a strict Content-Security-Policy (no framing by any site, scripts only from the site).
- The interface is Bootstrap 6 (a pre-release, 6.0.0-alpha.1). On phones the site and administration menus open as side drawers. It supports only recent browsers (Chrome and Edge 130, Firefox 132, Safari 18 on macOS and iOS, or later); on older ones pages do not render correctly, the public subscribe, sign-in and unsubscribe pages included. The site keeps the light colour mode whatever the visitor's system setting.

## Review and deployment

This is a complete source tree, not a patch. The schema is created by the single Phase 3A migration; there are no upgrade migrations. Use the podman development environment (`bin/dev up`) or a disposable database for the clean migrations, and test the full application workflow on the target installation before production use: `ctnlist:diagnose --production` must pass, and the banlist suppression database, transactional SMTP delivery and catto-mail delivery end to end (the README Release checklist's proof, test campaign and webhook round trip; `bin/dev test-cattomail` is the development check against a local catto-mail) must be verified there. Deployment is described in the repository's `README.md` ("Deployment", with templates in `deploy/`).
