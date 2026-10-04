# ctnlist restored functionality

This source tree uses the working v5.0 application as its behavioural baseline while retaining the approved v5.0.1 technical and data-model upgrades. Version 5.0.2 preserves this behaviour while moving the application to Symfony; the workflows and invariants below are the acceptance criteria for that migration.

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
- Resends, proofs and administrator copies are deliberate exceptions but remain audited.
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
- Ecwid subscription endpoint
- queue inspection and management
- message activity report
- Send Log report
- Site Log report
- roles and ACL administration

## Retained v5.0.1 upgrades

- PostgreSQL and Phinx
- Symfony Mailer (Symfony 8.1 framework from v5.0.2)
- UUIDv7 subscriber identity
- subscriber-based passwordless authentication
- database sessions
- multiple lists and per-list consent
- roles and ACL
- CSRF protection
- separate suppression database
- externally located `ban.env` support through `GDB_ENV_DIRECTORY` and `GDB_ENV_FILE`

## Review and deployment

This is a complete source tree, not a patch. The schema is created by the single Phase 3A migration; there are no upgrade migrations. Use the podman development environment (`bin/dev up`) or a disposable database for the clean migrations, and test the full application workflow, including the banlist suppression database and SMTP delivery, on the target installation before production use. Deployment is described in `README.md`.
