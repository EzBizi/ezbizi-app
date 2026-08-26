# EzBizi — where things stand

**2026-08-26.** One page. If something contradicts another document, this one is right —
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
| Tests | 1931 backend · 874 client. Both gated on deploy: nothing ships if either is red. |
| Client version | `v0.0.0100` — shown bottom-left in the app and on sign-in, so a stale cache is visible rather than guessed at. |
| Production data | **2 organization(s) · 7 companies · 18 franchises · 13 users · 14 customers · 24 jobs.** Counted 2026-08-26 against the live database by `scripts/status-numbers.sh`, not typed. |
| Permissions in production | **72 rows.** `ezbizi:sync-permissions` runs on every deploy, between the migration and the caches. |
<!-- numbers:end -->

## Build phases (§18 of the build plan)

| Phase | State |
|---|---|
| 0 — reconciliation & safety | Backups encrypted, **off-site**, and restore-verified nightly — the check pulls the file back down before verifying, so a copy that arrived corrupt is caught rather than assumed good. One gap left: **`ns2` on a second machine** (TODO #3, on hold). |
| 1 — decision records | Captured as docblocks in the code rather than separate pages. Deliberate: a decision written beside the code it constrains is read; one in a folder is not. |
| 2 — foundation + vertical slice | Done. Organizations → Companies → Franchises → Users, Customers → Jobs. |
| 3 — cross-cutting | **Done.** Automation engine, notifications, staff control plane, subscription enforcement, import/export. |
| 4 — expansion | **Dashboards done** — the widget picker is replaced by module glances. One box per reachable sidebar tab, chart first, one period, cannot hide. Glances now include stock health, fleet due, customer book, jobs today/line, HR active/inactive, inspections by name. Spec `docs/superpowers/specs/2026-08-24-dashboard-module-boxes-design.md`. A1 Tools modules next, one at a time. **Inspection is live** as release `20260819130615` (2026-08-19). Builder publishes written-once versions; Generated Reports are frozen, searchable and archivable; New Inspection is a personal draft that submits to a report. Named inspections are granted per role the way inboxes are. Spec `docs/superpowers/specs/2026-08-18-inspection-design.md`, plan `docs/superpowers/plans/2026-08-18-inspection.md`. Five tables migrated, five `inspection.*` permissions synced (47 total), health-checked as `www-data` before the symlink swap. Administrator holds the tab implicitly. Other roles see it only after a grant on Roles, and only start a named inspection they have been offered. **Social Media Management** Posts layout is live. Monthly Calendar fills the pane width and height so the whole month is on screen (v0.0.0065). Catalogue rows use each network’s real brand mark (v0.0.0070). Connect for all nine networks: one `social_sign_ins` table, per-network OAuth start/callback/status (Facebook Pages, Instagram Login, Threads, LinkedIn, TikTok, Pinterest, YouTube, X). Bluesky is handle + app password. Catalogue is usable when the matching env is on the box; missing env is a sentence, not a 500. Live Connect still needs each console’s redirect URI and keys on `.env` — never in git. Compose/publish is still later. **TikTok and Pinterest are live** (2026-08-25): keys on the box, redirect URIs registered, one real TikTok connection made end-to-end. Connections can be disconnected (`DELETE /social/connections/{id}`, `marketing.edit`, view-scoped, audited); the catalogue says Connected and the panel offers Reconnect. The OAuth callback popup is EzBizi-styled and personalised — a wired EzBizi tile and the network's own mark, read from `client/assets/social`, animated, red and broken when the sign-in refuses. **Place survives a reload** (v0.0.0064): the address bar holds the current destination, so refreshing Marketing does not dump you on Dashboard. **Inventory is live** (client `v0.0.0081`). Chips Stock · Items · Orders · Locations. Items and locations sit on organization / company / franchise; quantity is (item, location). Adjust, transfer, purchase/receive, and issue (job XOR note). Costs stay hidden without `inventory.financials`. Five `inventory.*` permissions, thirteen tables. Spec `docs/superpowers/specs/2026-08-24-inventory-design.md`. Filing follows the current view (v0.0.0082): looking at A-1 Chimney cannot file on Everdoors. Buying, issuing and transferring are on screen (v0.0.0084). Sales orders are on Orders (v0.0.0091): a Customer or a counter note; stock leaves on fulfill. **Services is live** (client `v0.0.0093`). Catalogue of work the company sells: name, square photo, price, description, one label, one group. Settings chip for labels and groups. Four `services.*` permissions. Spec `docs/superpowers/specs/2026-08-25-services-design.md`. **Fleet Management is live** as release `20260821204600` (client `v0.0.0066`; this build is `v0.0.0067`). Vehicles are a company register: owned and rented periods, insurance, service, who has the keys, photos. Payments stay hidden without financials. Six `fleet_management.*` permissions, six tables. Spec `docs/superpowers/specs/2026-08-21-fleet-management-design.md`. **HR contract catalog:** eleven role templates (A-1 wording, EzBizi grammar). One template per role — hourly or salary is a Pay basis question on onboarding (`shown_when`), not four named variants. Raise is optional. Home address is core; business address is `organization.address`. `php artisan hr:publish-onboarding-catalog "{organization name}" --replace` loads them; they are not auto-installed. An in-progress onboarding can be cancelled (v0.0.0075); the row stays and the invitation is free for a new start. A fully signed agreement cannot. **Email** inbox listing fetches conversation headers together, so opening the tab is one wait rather than one per row (v0.0.0076). **Settings** that are a choice from a known list — timezone, language, paper, fiscal year — are selectors (v0.0.0077). Timezone is hours from UTC, not a city list; every selector uses the same outlined menu (v0.0.0078). Inactivity is minutes/hours/days; job statuses have readable names and rename existing jobs; the business address is street/city/state/ZIP with a lookup (v0.0.0079). **Settings** is one sidebar destination: Organization, the cascade, and Roles live as chips inside it (v0.0.0080). **Phone is live** as release `20260825125038` (2026-08-25, client `v0.0.0086`). Twilio at organization or company; numbers at org/company/franchise; Call / Records / Settings. Spec `docs/superpowers/specs/2026-08-24-phone-design.md`. **Clock in is live** as release `20260825160446` (2026-08-25, client `v0.0.0094`). Organization setting, off by default; role grant for the top-bar button; Human Resources day list in the view timezone. Spec `docs/superpowers/specs/2026-08-25-clock-in-design.md`. |

## Tasks — design and Dense Grid layout accepted

The owner accepted the Codex–Grok consensus and selected **A — Dense Grid** on 2026-08-26. The desktop/375px alternatives and decision record are in `docs/previews/tasks-layouts.html` and `docs/previews/tasks-layouts-review.md`. Tasks is not built; it is ready for explicit implementation authorization. Production-capable file paths remain gated on the Phase 1 plan's Task 10A off-instance restore proof.

## EZ Leads — `leads` and `reviews` are live

The first expansion work that depends on an outside party's approval rather than on us. Rodrigo's
A1 Leads — a local Node/SQLite system on his own PC — becomes native EzBizi modules. Read as
specification, re-expressed here; nothing is ported.

**Shipped in this release:** the `leads` module. One table, five permissions, a sidebar tab, a
dashboard glance, and the Google Ads connection behind it. The list searches a name or a phone
number in one box — digits are stored normalised so `5551234567` finds `+1 (555) 123-4567` — and
`Fetch new` pulls from the connected account on demand. Status is EzBizi's column and the sync
never writes it; the source's own status is a separate field, refreshed every run. A lead's name
and phone cannot be edited at all: they are a record of what a member of the public said, and the
one endpoint that touches a lead accepts `status` and nothing else.

**The empty state is the part that matters.** "No enquiries" and "nothing is connected" draw the
same empty list and mean opposite things, so `meta.connections` travels with every page and the
screen says different sentences for each — the same rule as Email's stale-source behaviour, and
the failure the whole module exists to avoid.

**`reviews` shipped 2026-08-26.** Reviews on a connected Business Profile, and the replies to
them. Two tables — a review is what a member of the public wrote, a reply is a public statement
the organization is about to make in its own name, and when one goes wrong the first question is
who approved it, which a column cannot answer.

**Drafting and publishing are deliberately separate**, with their own permissions
(`reviews.edit` and the fifth action, `reviews.publish`). A draft is private; publishing puts
words on Google under the business's name, in public, permanently. Those are routinely different
people — whoever writes the answer to an angry review is very often not whoever should decide it
goes out — and one grant covering both could not express that on Roles. Same split as
`inventory.financials`. The list opens on Unanswered, because an unanswered one-star sits at the
top of a listing being the first thing a stranger reads.

No automation can publish. `review.draft_reply` is offered and `review.publish_reply` is
deliberately absent: the reply most worth automating is the one to a furious customer, and that is
exactly the one a person must see first.

Everything touching the legacy v4 host lives in one class, `BusinessProfileReviews`, so the day it
is retired there is one place to start.

**Not built yet:** `business_profile` (locations, hours, posts, media), scheduled sync for both
modules (fetching is on demand), classification and transcription (`lead.classified` is declared
and marked not raised), and conversion to a customer and job (`lead.converted`, likewise).

**Three modules, not one.** `ModuleDefinition` holds one `AutomationSubject` and
`assertKeysMatchSubject` refuses a trigger key whose prefix disagrees with it, so leads, reviews
and locations cannot share a module: `leads` (`Lead`), `reviews` (`Review`), `business_profile`
(`BusinessLocation`). Three subjects, three tabs, three separately grantable units — review
management sells to businesses with no advertising at all.

**Scope is official APIs only.** The 28 files in A1 Leads that depend on `puppeteer-core` /
`playwright-core` — Workiz UI reports, LSA account discovery, Google review removal — stay on
Rodrigo's machine permanently. They need a human-authenticated browser profile no customer can
have. That is a boundary, not a backlog item.

**Gated on Google, in two independent chains.** Business Profile waits on an API quota application
that cannot be filed before **2026-10-24**, because Google requires a verified profile aged 60+
days and EzBizi's went live 2026-08-25. Google Ads waits on Basic Access review (submitted
2026-08-25) and then on `adwords` scope verification, which requires a YouTube demo of the working
module — which is why `leads` was built first. `business.manage` needs no approval; Google
classifies it non-sensitive. TODO #30–#33 carry the detail.

**So the tab ships before it can serve a customer, deliberately.** Until the developer token
leaves Test level and the scope clears verification, Connect works and every read returns nothing.
That is why `notReadyBecause()` on the Google Ads driver refuses to start a sign-in when the
developer token is missing rather than letting somebody succeed at consent and then stare at an
empty list — the failure would look exactly like a quiet week.

**Known risk, recorded now rather than discovered later:** reviews, replies, posts and media all
run on the legacy `mybusiness.googleapis.com/v4` host — 14 call sites in A1 Leads — with no v1
replacement and a years-long deprecation behind it. The review layer goes behind an adapter for
that reason and no other.

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
