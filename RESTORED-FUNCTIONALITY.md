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
- SMTP batching, delay, retry and failover are retained.

## Audit invariants

- Every successful email handoff is written to `sendlog`.
- Every campaign-content delivery is written to `smlog`.
- A normal queue send is blocked when the `(subscriber UUID, MUID)` `smlog` record already exists.
- Resends, proofs and administrator copies are deliberate exceptions but remain audited (proofs in the Send Log only).
- Forwarded campaign copies use the campaign mail path and retain list context.

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
- In the anonymous rendering used for archives, `{booking}`, `{contact}` and `{lms-booking}` are left unreplaced (a v5 defect), so they appear literally in archive pages.

## Deliberate changes from v5

Made while porting to Symfony, where the v5 behaviour was a defect rather than a workflow:

- Contact-form values are HTML-escaped in the acknowledgement mail (v5 inserted them raw).
- The profile form's country list has a blank choice (v5 silently stored the first country, Afghanistan, when none was chosen).
- A sign-in link is claimed atomically, so two simultaneous clicks cannot both sign in.
- Forms on the Symfony pages redirect after a successful change and show the outcome as a message, so reloading does not resubmit.
- Saving the subscriber form as a subscriber ignores a posted priority: v5 added 100 to whatever priority the request carried, so a crafted request could raise one's own send priority. Subscribers still get the v5 result of 100.
- The Site Log records every request again, including Symfony pages and unknown paths (5.0.2–5.0.5 recorded only requests that reached the Fat-Free bridge; fixed in 6.0). The row is written after the response is sent, so the reverse DNS lookup no longer delays pages; its time is PHP time like the rest of the application (it was the database's UTC default), and the IP is the client address as Symfony determines it (`X-Forwarded-For` is honoured only from `TRUSTED_PROXIES`; the raw header is still recorded in its own column).
- The Ecwid store page (`/store`), the Ecwid subscription endpoint (`POST /ecwid-subscribe`) and the `{STORE}` merge tag (`APP_STORE_URL`) are removed in 6.0.1: the store is defunct. Old content that still contains `{STORE}` renders it as nothing.
- `/subscribe` no longer shows a sign-in form. Signed-in subscribers see every list with their membership and a subscribe or unsubscribe action (v5 asked them to sign in again). Visitors get a subscribe form (email address and list) that emails them a link to confirm; as before, nothing is subscribed until they confirm.
- Proofs go to any valid address (by default `MAIL_TEST_ADDRESS`, editable on the proof page) without needing a subscriber record. v5 required the test address to be a subscriber and marked the proof as that subscriber's delivery in `smlog`, which could make the queue skip them for the real campaign. A proof is now rendered with test merge values (first name "Test", last name "Recipient", links that belong to no subscriber, no tracking pixel) and logged in the Send Log only.
- Links in a proof copy that would act for a subscriber (unsubscribe, confirm, forward, preferences, reactions, booking and contact forms) lead to a page explaining that a proof has no subscriber; proofs have no tracking pixel.
- A sign-in link requested to confirm a list (the subscribe form, a confirmation link) is sent as "Confirm your subscription to …" instead of a sign-in email; it is the same secure link.
- Role administration is complete: custom roles can be renamed, described, deleted and removed from subscribers, each role lists its members, and subscribers are found by search instead of a picker limited to the first 500. Non-administrators cannot hand out permissions they do not hold.
- Opening another person's subscriber form, or one that does not exist, gives the 403 or 404 page instead of a 200 page reading "Access denied." or "The subscriber does not exist.".

## Review and deployment

This is a complete source tree, not a patch. The schema is created by the single Phase 3A migration; there are no upgrade migrations. Use the podman development environment (`bin/dev up`) or a disposable database for the clean migrations, and test the full application workflow, including the banlist suppression database and SMTP delivery, on the target installation before production use. Deployment is described in `README.md`.
