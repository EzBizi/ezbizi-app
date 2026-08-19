# EzBizi — where things stand

**2026-08-19.** One page. If something contradicts another document, this one is right —
which only works if it is the newest, and on 6 August it was not: it claimed the off-site
backup was still outstanding some three hours after `EZBIZI-TODO.md` recorded it closed.
**Update this page in the same pass as the thing it describes, or delete the claim.** The four
rows between the `numbers` markers below are exempt because they are no longer written by
hand — `scripts/status-numbers.sh` regenerates them at the end of every deploy, from the test
runner, from `client/VERSION`, and from a count against the live database. They drifted three
times in one day while being maintained carefully, which is the argument for generating them
rather than for trying harder.

| | |
|---|---|
| Customer app | https://app.ezbizi.com |
| Control plane | https://dev.ezbizi.com |
| Server panel | https://srv.ezbizi.com (Webmin) |
| Stack | Laravel 13 · PHP 8.4 · MySQL 8 · Flutter web client |
<!-- numbers:start -->
| Tests | 1442 backend · 687 client. Both gated on deploy: nothing ships if either is red. |
| Client version | `v0.0.0034` — shown bottom-left in the app and on sign-in, so a stale cache is visible rather than guessed at. |
| Production data | **1 organization(s) · 4 companies · 13 franchises · 2 users · 0 customers · 0 jobs.** Counted 2026-08-19 against the live database by `scripts/status-numbers.sh`, not typed. |
| Permissions in production | **47 rows.** `ezbizi:sync-permissions` runs on every deploy, between the migration and the caches. |
<!-- numbers:end -->

## Build phases (§18 of the build plan)

| Phase | State |
|---|---|
| 0 — reconciliation & safety | Backups encrypted, **off-site**, and restore-verified nightly — the check pulls the file back down before verifying, so a copy that arrived corrupt is caught rather than assumed good. One gap left: **`ns2` on a second machine** (TODO #3, on hold). |
| 1 — decision records | Captured as docblocks in the code rather than separate pages. Deliberate: a decision written beside the code it constrains is read; one in a folder is not. |
| 2 — foundation + vertical slice | Done. Organizations → Companies → Franchises → Users, Customers → Jobs. |
| 3 — cross-cutting | **Done.** Automation engine, notifications, staff control plane, subscription enforcement, import/export. |
| 4 — expansion | **Dashboards done** — a widget registry with per-user layouts, so a new number is a class rather than a client release. A1 Tools modules next, one at a time. **Inspection is live** as release `20260819130615` (2026-08-19). Builder publishes written-once versions; Generated Reports are frozen, searchable and archivable; New Inspection is a personal draft that submits to a report. Named inspections are granted per role the way inboxes are. Spec `docs/superpowers/specs/2026-08-18-inspection-design.md`, plan `docs/superpowers/plans/2026-08-18-inspection.md`. Five tables migrated, five `inspection.*` permissions synced (47 total), health-checked as `www-data` before the symlink swap. Administrator holds the tab implicitly. Other roles see it only after a grant on Roles, and only start a named inspection they have been offered. |

## Standing hazards

Three things that are true right now and will bite if forgotten. Verified 2026-08-07 by
looking at the box, not by re-reading this list — which is the only way a hazard list stays
worth having. The numbers point at `EZBIZI-TODO.md`, which was renumbered from 1 on
2026-08-06; the references here still said #21, #22, #24 and #26 and pointed at nothing.

1. **Mail does not send.** Everything is wired except the provider credential — AWS blocks
   outbound port 25 on both instances, and SES SMTP on 587 needs credentials nobody has
   issued. **Checked 18 Aug 18:05 UTC:** the box still has `MAIL_MAILER=ses` with
   `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` present-and-empty. The intended path is
   SMTP on 587 (TODO #1), not the `ses` SDK. The app says so on Development → Health rather
   than failing silently. Password resets reach nobody; the working path is a Lead Developer
   generating a reset link from the control plane. Console steps: TODO #1 (SES), #28
   (Workspace), #29 (Azure / Outlook). Do not ship signup verification (#22) until a
   Health-screen test message has arrived.

2. **The off-site backup is off-instance, not off-account.** Encrypted, copied nightly to the
   websites box, and restore-verified by pulling the file back *down* first — so a copy that
   arrived corrupt is caught. But that box is a second Lightsail instance in the *same AWS
   account*: a billing suspension or a compromised root login takes both. Lightsail's own
   instance snapshots are the only thing outside that blast radius, and they have never been
   restore-tested. `deploy/backup.sh` states this in its own header.

3. **One nameserver.** DNS is self-hosted on the app box by deliberate choice (§15), and
   `ns2` is still not standing — the secondary would live on a Plesk-managed box that
   regenerates its chrooted `named.conf`, so a hand-added zone is erased on the next DNS
   change anywhere. Losing ns1 does not degrade the domain, it removes it: app, dev, mail
   and the marketing site all resolve through it. See TODO #3, on hold at the owner's request.

## How deployment works

Edit locally → `git push production main`. Never edit on the server; server-side edits are
for diagnosis only. The post-receive hook builds a timestamped release, runs migrations,
health-checks it and swaps a symlink. The Flutter build is uploaded separately because it is
derived and 36 MB.

Full detail: `DEPLOY.md`, beside this file.

## The documents

| File | What it is |
|---|---|
| `EZBIZI-BUILD-PLAN.md` | The architecture brief. Every `§` reference in the code points here. Still current. |
| `EZBIZI-STATUS.md` | This page. |
| `EZBIZI-TODO.md` | Open items with owners. |
| `EZBIZI-BUILD-LOG.md` | What was built when, and what went wrong. |
| `EZBIZI-DEV-ENVIRONMENT.md` | Local setup. |
| `EZBIZI-PLAN-REVIEW-FOR-CLAUDE.md`<br>`EZBIZI-REVISED-PLAN-REVIEW-FOR-CLAUDE.md` | **Superseded.** Review rounds from before the build started; their conclusions are folded into the build plan. Kept for provenance, not for reading. |
