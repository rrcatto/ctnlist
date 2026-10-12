# ctnlist: project status

**As of 2026-10-12.** This page says where the project stands and what is waiting. `CHANGELOG.md` is the record of what each version changed; when the two disagree, the changelog and the code are right and this page needs updating.

## Where things are

- **Released:** 6.0.11, tagged `v6.0.11` on `main` (2026-10-12). It added `install-ctnlist`, the installer for a new installation and for moving an installation to another release; connecting the mail service from the Delivery page, now under Settings (Connect, Disconnect, the connection state and the From-domain warning), with the API key and the webhook secret stored encrypted and `.env` still winning; proofs that can go straight to a Mailpit instance (`MAIL_PROOF_SMTP`, with deploy files for Mailpit on the ctnlist server); three sample email templates (`ctnlist:templates:samples`); and ctnlist's own acceptable use policy, naming the installation and sending abuse reports to `abuse@` its domain. `bin/dev check` passes on it (585 tests, PHPStan level 8 clean). The installer was tested in a stand-in server (`bin/dev test-installer`, 20 checks) and has not yet been run on a real one. A real run between the two development pods passed before it (connect with the button, then `bin/dev test-cattomail`).
- **Before it:** 6.0.10 (2026-10-11) added the acceptable use policy page, `/acceptable-use-policy`, linked from every page's footer and built from `docs/AUP.md`. 6.0.9 (2026-10-11) removed the message link settings, limited the contact form's details to the signed-in subscriber, widened the message editor, required a subject, refused blank messages and made the sign-in link page plainer.
- **Earlier:** 6.0.8 (2026-10-10) had the fixes from the pre-deployment defect audit, PHP 8.5 as the minimum, the interface moved to Bootstrap 6.0.0-alpha.1, and this `docs/` directory. 6.0.7 (2026-10-09) was the release-candidate hardening called Phase 10; Phase 9 was 6.0.6.
- **Next:** Phase 11, deployment to the server, with `install-ctnlist`. Not started. On the server the mail service is connected with the Connect button (both sites on https), not by copying a key into `.env`.
- **Behind catto-mail's contract:** ctnlist follows OpenAPI 1.0.0-draft.10 except in three places: the `repermission.responded` webhook is recorded and ignored, a `429 quota-exceeded` answer is treated like a rate limit, and limits set below 10,000 recipients per job or 500 per batch are not known to it. `src/CattoMail/CLAUDE.md` has the detail.

## The defect audit, in brief

The whole codebase was reviewed for real defects before deployment. Everything confirmed was fixed, each fix with a regression test. The serious ones:

- a sign-in link could be mailed to an address someone typed while signing in a different subscriber's account (the v5 address cleanup rewrites some addresses);
- campaigns to more than a few hundred recipients would have failed on catto-mail's 10 MiB request limit;
- one interrupted queue run blocked every later run;
- the worker could seal a long queue run that was still going;
- "Maximum sends" said 0 meant no limit when it means none;
- `settings.manage` alone could redirect sign-in mail by changing the SMTP server;
- notices and warnings were never written to the application log;
- times filled in by PostgreSQL were in UTC, not local time.

The full list is under 6.0.8 in `CHANGELOG.md`.

## Decisions waiting for the owner

1. **Bootstrap 6 while it is a pre-release.** The owner chose it on 2026-10-10. What stays open is when to take the next pre-release: each one can rename classes, which means going through the templates again.

## To check during Phase 11

- **Memory with large lists.** Advanced-queue rotation loads every candidate subscriber at once; with a few hundred thousand subscribers it may exceed PHP's memory limit. Measure with real list sizes.
- **PHP-FPM pool:** `max_execution_time` must be set with `php_value`, not `php_admin_value`, as in `deploy/php-fpm/ctnlist.conf.example`; otherwise queue runs and imports cannot raise their time limit.
- **PHP:** ctnlist requires PHP 8.5 or later; the server runs 8.5.11.
- **nginx access log:** opening a sign-in link does not use it up, so a logged link would stay usable until it expires. The template (`deploy/nginx/ctnlist.conf.example`) logs both its server blocks in a format without query strings; keep that format, and check that no other log on the server (a proxy in front, a default `access_log`) records the full request line.
- **Browsers:** the interface uses Bootstrap 6.0.0-alpha.1, which renders correctly only in Chrome and Edge 130, Firefox 132 and Safari 18 (macOS and iOS) or later. Subscribers on older phones will see broken subscribe, sign-in and unsubscribe pages; check the audience before go-live. It is a pre-release: expect to revisit templates when the next one arrives.
- **Preflight:** `ctnlist:diagnose --production` must report no ERROR (it checks PostgreSQL 16, the PHP extensions, secrets, the suppression database, compiled assets and permissions).
- **Real infrastructure, never yet exercised:** transactional SMTP, the banlist database, and catto-mail end to end. On the server that means the Release checklist's proof, test campaign and webhook round trip. `bin/dev test-cattomail` is a development check against a local catto-mail only.
- **`logs/`** holds subscriber addresses: mode `750`.

## Known and accepted

- The diagnose "worker runs with a different configuration" warning is false when the installation path is a symlink; use real paths.
- On phones, a submenu opened inside the site or administration drawer lies over the items below it (Bootstrap 6's default; v5 expanded it in place). It is usable.
- The PHP session handler does not lock, so two simultaneous first requests in one new session can invalidate one form's CSRF token. Locking would make an administrator's other tabs wait during a long queue run.

## Clean-up, not blocking

- The administrator's `/forward/{muid}` route looks like a subscriber link.
- CKEditor still carries sample configuration and plugins without configuration from its builder.
- `sessions.ses_stamp` is a 32-bit integer (2038).
- Webhook bodies of permanently failed events are pruned with the others after the retention period.
- The cron template mails every worker and maintenance run to root.
- Consent events record no actor, IP address or source.
- Bulk subscribe, import and synchronisation run inside one web request.
- `.env` values are not held to the minimums the Settings page enforces.
