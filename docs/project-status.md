# ctnlist: project status

**As of 2026-10-27.** This page says where the project stands and what is waiting. `CHANGELOG.md` is the record of what each version changed; when the two disagree, the changelog and the code are right and this page needs updating.

## Where things are

- **Released:** 6.1.4, tagged `v6.1.4` on `main` (2026-10-27). The administration pages update in part with htmx 4 (the owner's request, 2026-10-27, after cattolms): every search and filter searches as you type (with new searches of messages, templates and a role's members), page turns and the buttons that change one row or card redraw only that part, and the job pages and the Delivery page refresh themselves while something is in progress. Start sending answers at once and the run is followed on a new Sending progress page. A subscriber's page has a Roles card (Assign, Administrator included, and Remove) and its own Invite button for lists, and its Clear the sending block button, which saved the profile instead because its form sat inside the profile form, works. The worker asks catto-mail for the sending domains while the From domain cannot send. No schema change. `bin/dev check` passes on it (659 tests), and each page was checked in Chromium and Firefox; the installer and the Podman kit are unchanged, so their stand-in tests were not run again.
- **Before it:** 6.1.3 (2026-10-26). Form bots had been typing other people's addresses into the sign-in and subscribe forms of the first installation, so it mailed sign-in links to strangers; both forms now refuse what looks like a bot (a hidden field, a missing or outdated browser, or a network the server in front marks) with the usual answer and send nothing. The Site Log records each request's method and answer and the refusals, Reports → Flagged activity lists them with the IP addresses behind them and the unconfirmed records bots left, and the Subscribers page shows every record by default (the search with "All lists" also failed with a 400, now fixed). The Site Log table changed, so an installation moves to 6.1.3 with `--recreate-database` and its data is loaded back from the dump. `bin/dev check` passed on it (642 tests).
- **Earlier:** 6.1.2 (2026-10-26): every period setting is written `1y1w1d1h1m1s` (the owner's convention, 2026-10-26): five `.env` settings lost their `_SECONDS`/`_DAYS` suffix, the sign-in periods are typed as periods on the Settings page, and the Site Log is kept for at least a week, never for ever. `bin/dev check` passed on it (619 tests).
- **Earlier:** 6.1.1 (2026-10-26): the Privacy page is now a full privacy policy built from `docs/PRIVACY.md` (what is collected, why, who handles it, the cookies, how long each kind is kept and the subscriber's rights under POPIA; requests go to the administrator's address), the Site Log is kept for 120 days by default (`APP_SITELOG_RETENTION_DAYS`; 0 keeps it, as before), and every page names the release in a `generator` meta tag and on the footer's last line. `bin/dev check` passed on it (601 tests).
- **Earlier still:** 6.1.0 (2026-10-24) added the rootless Podman kit (`deploy/podman/`), a second way to install for a server that already hosts other sites under a control panel: the installation runs as its own Linux user in a Podman pod behind the server's nginx, with the code, `.env` and the web root read-only to PHP. It also lets an installation run without the banlist database, production included: with `SUPPRESSION_PROVIDER=cattomail` global suppression is catto-mail's (the owner's decision, 2026-10-24), and the kit chooses it when root gives the site no `ban.env`. `bin/dev check` passed on it (598 tests), and so did `bin/dev test-podman-kit` (36 checks, a site without a banlist and one with it) and `bin/dev test-installer` (20 checks) in their stand-in servers.
- **Earlier:** 6.0.11 (2026-10-12) added `install-ctnlist`, connecting the mail service from the Delivery page (Connect, Disconnect, the connection state and the From-domain warning), proofs to Mailpit (`MAIL_PROOF_SMTP`), three sample email templates and ctnlist's own acceptable use policy. 6.0.10 (2026-10-11) added the acceptable use policy page. 6.0.9 (2026-10-11) removed the message link settings, limited the contact form's details to the signed-in subscriber, widened the message editor, required a subject, refused blank messages and made the sign-in link page plainer.
  6.0.8 (2026-10-10) had the fixes from the pre-deployment defect audit, PHP 8.5 as the minimum, the interface moved to Bootstrap 6.0.0-alpha.1, and this `docs/` directory. 6.0.7 (2026-10-09) was the release-candidate hardening called Phase 10; Phase 9 was 6.0.6.
- **Now:** Phase 11, deployment to the server. The first installation replaced the owner's 5.0.1 site on 2026-10-24 (6.1.0, now 6.1.4), with the Podman kit and without a banlist (catto-mail's suppression); it is not yet live. Its mail service was connected with the Connect button on 2026-10-27, its From domain is verified with an active DKIM key at the mail service, and the mail service's live delivery is on; the checks below are made there next, starting with a proof to the owner.
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
- **PHP:** ctnlist requires PHP 8.5 or later; the server runs 8.5.12 (2026-10-24). With the Podman kit the server's PHP does not matter: the image pins its own (8.5.12).
- **nginx access log:** opening a sign-in link does not use it up, so a logged link would stay usable until it expires. The template (`deploy/nginx/ctnlist.conf.example`) logs both its server blocks in a format without query strings; keep that format, and check that no other log on the server (a proxy in front, a default `access_log`) records the full request line.
- **Browsers:** the interface uses Bootstrap 6.0.0-alpha.1, which renders correctly only in Chrome and Edge 130, Firefox 132 and Safari 18 (macOS and iOS) or later. Subscribers on older phones will see broken subscribe, sign-in and unsubscribe pages; check the audience before go-live. It is a pre-release: expect to revisit templates when the next one arrives.
- **Preflight:** `ctnlist:diagnose --production` must report no ERROR (it checks PostgreSQL 16, the PHP extensions, secrets, the suppression database, compiled assets and permissions).
- **Real infrastructure, never yet exercised:** transactional SMTP, the banlist database (where an installation uses one), and catto-mail end to end, including its suppression of bounced and complaining addresses for an installation without a banlist. On the server that means the Release checklist's proof, test campaign and webhook round trip. `bin/dev test-cattomail` is a development check against a local catto-mail only.
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
