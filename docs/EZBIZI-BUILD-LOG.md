# EzBizi — build log

What was built, and what went wrong. Newest at the bottom. Current state is in
`EZBIZI-STATUS.md`; this is the record of how it got there.

---

## 2026-08-05 — foundation and the first vertical slice

Organizations → Companies → Franchises → Users, then Customers → Jobs. Multi-tenancy is an
explicit process-wide `TenantContext` rather than an ORM scope, because a queue worker
handling many jobs in one long-lived process is precisely where an inherited tenant becomes a
cross-tenant write.

Staff control plane on its own host (`dev.ezbizi.com`), with `PlatformStaff` as a separate
identity from `User` — joined by a link table, so authentication is unified but identity is
not. TOTP mandatory for staff.

---

## 2026-08-06 (overnight) — workflow engine, notifications, UI

### Three decisions made without waking anyone

§5.5 says settle these before implementation. All reversible.

1. **Outbound webhooks deny by default.** A tenant-configured URL means a tenant can make our
   server issue HTTP requests — that is SSRF. The guard resolves the hostname and checks the
   *resolved* IPs, not the string: private, loopback, link-local (169.254.0.0/16, the cloud
   metadata endpoint), multicast and reserved ranges are refused. No redirects, 5s timeout,
   64 KB cap, HTTPS only. Resolving first matters because an allow-list on hostnames is
   defeated by DNS pointing a permitted name at 169.254.169.254.
2. **Schedules run in the timezone resolved at the workflow's own scope.** "9am daily" means
   9am where that franchise is. A nonexistent local time is skipped; an ambiguous one runs
   once, on the first occurrence — never twice, because a workflow that fires twice destroys
   trust faster than one that misses.
3. **Email actions go through Laravel's mailer as configured.** The action is real; the
   transport is what is missing, and the run log says so rather than dropping it silently.

### Two guarantees that were documented three times each and did not exist

- **Idempotency did not guard side effects.** The run row was written *after* the action loop,
  so a redelivery re-sent webhooks. The test passed for the wrong reason: its action set a
  field to a constant, so the second run no-opped. Fixed by claiming the row first; the new
  test uses a webhook, because a field write can no-op and hide the failure.
- **The SSRF guard was bypassed by `::ffff:169.254.169.254`.** Sixteen bytes against a
  four-byte range, so the family check refused to compare and let it through. Fixed by folding
  IPv4-mapped addresses before the comparison.

### Nothing was running the clock

The box had no `schedule:run` and no `queue:work`. The app was fine, the code was fine, and
the entire time-based half of the system had simply never executed — no error anywhere,
because nothing was broken. Systemd units now, and the workflow outbox is the canary:
undelivered events piling up is the only symptom that subsystem produces, which is why the
Health screen reads it.

Adversarial review of the engine found 21 issues; all fixed before deploy.

---

## 2026-08-06, 12:00 — I destroyed the production database

`php8.4 artisan db:wipe --env=testing --force` in `/var/www/ezbizi`. No `.env.testing`
existed, so Laravel silently fell back to `.env` and wiped `ezbizi`.

**Lost:** the `jose@ezbizi.com` Lead Developer account — password, TOTP enrolment, eight
recovery codes. Nothing else; production held zero organizations at that moment. That is luck,
not design.

**Why it went unnoticed for seven hours.** Every check afterwards was a status code — 200 for
a page, 401 for an endpoint, `active` for a unit. Not one of them touched a row. The suite kept
passing because it runs against `ezbizi_testing`, which was fine.

**Guards added.**

- `tests/TestCase.php` refuses to run unless the connected database name contains `testing`.
  RefreshDatabase migrates and truncates; pointed at a real schema it *is* a wipe, and the
  suite is the thing most likely to be pointed somewhere by accident.
- `/var/www/ezbizi/.env.testing` exists, so `--env=testing` means what it says.
- `deploy/env.testing.example` is versioned so a rebuilt box gets it too.

This is the entry that moved backups to the front of the list.

---

## 2026-08-06 — Phase 3 closed

**Subscription enforcement below the HTTP edge (§13).** Middleware is not sufficient: a
workflow that keeps firing after expiry means the product works fine without paying. The guard
is consulted from `BelongsToTenant`'s write hooks, the one place every tenant-owned write
converges whatever started it.

Two faults the tests caught rather than review:

- The guard sat after an early `return` in the `creating` hook — the branch taken whenever
  `organization_id` is stamped automatically, which is almost every create. It was live and
  enforcing nothing.
- `WorkflowEvent` and `WorkflowRun` had to be exempted, or `delivered_at` could never be
  written and a lapsed organization's outbox would grow forever while the retry sweep picked
  up the same rows on every pass. The engine now checks once and stops the event with a stated
  reason.

Still permitted after a lapse, each with a test: password and MFA recovery, notifications,
audit writes, staff repair under platform scope, export. A business field mixed into the same
save as a password change is still refused.

**Import / export (§12, §9).** One map drives both; the client builds its screen from the API.
Partial failure with row numbers, a dry-run preview, BOM handling, re-import of an export does
not duplicate. Three things worth remembering:

- CSV formula injection is defused on export. A customer named
  `=HYPERLINK("http://evil","Click")` executes when the file opens in Excel, and that data
  arrived through an ordinary form. Numbers are left alone so `-1234` is not turned into text.
- A streamed download runs its body *after* the middleware stack unwinds, and
  `SetTenantContext` clears the tenant in a `finally` on the way out. The export captures the
  organization and re-establishes it inside the stream; without that it is an unscoped query
  writing another organization's customers into the file.
- Imports emit no per-row workflow events. Three thousand rows would otherwise fire three
  thousand "new customer" rules — welcome emails to an entire existing customer base on day
  one. §5.5's loop guards would not catch it, because every row is its own origin.

Jobs are export-only: a CSV of names cannot say *which* "J. Smith", and a wrong guess attaches
somebody's work history to a stranger.

---

## 2026-08-06 — backups that are actually backups

The previous script wrote gzipped dumps to `/var/backups/ezbizi` and stopped. Same disk, same
instance, same filesystem as the thing it protected — so the failures it survived were exactly
the ones nobody worries about.

Now: encrypted with GPG AES256, includes the bare git repo (the source of truth for the code,
previously not backed up at all) and `.env`, and **verifies its own restore** — a systemd
timer decrypts the newest dump into a scratch database each night and checks table counts,
migration counts and per-table row counts before declaring it good. An unverified backup is
worse than none, because it is indistinguishable from a working one until the day it matters.

Two bugs found by running it rather than reading it:

- `IFS='","'` looked like it joined an array and did not — IFS uses only its first character,
  so the failure report itself was not valid JSON.
- The scripts had CRLF line endings from being authored on Windows, so systemd could not
  execute one of them. `.gitattributes` pins `deploy/*.sh` to LF.

The off-site copy is still not running — see TODO #22.

Mail was wired at the same time: transport, from address, SPF and DMARC, a test-send button,
and backup alerts that use it. Everything except the provider credential, and the app now says
which credential is missing rather than logging into the void.

---

## 2026-08-06 — Phase 4 begins: dashboards

A widget registry with per-user layouts, not a screen of fixed cards. A widget
declares what it is and what it can be asked; the user's choice of which to show
is a preference (§2.1 category 6). Adding a number is a class; changing your
dashboard is a click.

Five widgets to start: jobs by status, coming up, revenue, new customers,
automation health. Two rules every one of them has to follow, both invisible when
broken:

- **Answer for the caller's reach, not the organization.** A franchise manager
  seeing organization-wide totals is a leak that looks like a feature, because
  nobody reports a number that is too big.
- **Never sum across currencies.** Amounts are minor units with a currency
  attached; adding 100 USD to 100 CAD is wrong in a way nobody notices until a
  client reconciles it.

Revenue counts nothing until somebody says which of *their* statuses means
earned. A blank figure you have to configure beats a confident wrong one that
quietly includes cancelled work.

### The adversarial review found a hole I had opened

Five reviewers over the new code, each finding verified by an independent
skeptic told to refute it. 42 raised, 16 confirmed. The worst three were mine and
all the same mistake:

**The CSV export, the JSON bundle and the per-person export all ignored reach.**
Anybody with `jobs.view` could download every franchise's jobs. The permission
gate passed and the reach gate was simply absent — and nothing upstream would
have caught it, because reach in this codebase is applied per query, by hand.
The dashboard widgets I had written an hour earlier had a shared trait whose
docblock names this exact failure mode. The exporter did not use it.

Fixed by declaring `reach_column` in ResourceMap — customers deliberately have
none, because they are shared org-wide and filtering them would break sharing —
and applying it in every export path, with a test per path.

Also confirmed and fixed:

- **A blank `number` cell discarded the generated record number.** PHP's `+`
  gives the LEFT operand precedence, so a present-but-null key beat the
  generator and the row went in with no number. Every export has a `number`
  column, so re-importing an edited export hit it on the first blank.
- **A stray comma silently truncated the row.** `array_pad` never shortens, so
  every cell past the header count was discarded and the fragment after the
  comma landed in the next field — where it validated clean.
- **`+1 415 555 0100` came back as `'+1 415 555 0100`.** The formula escape is
  correct; there was no un-escape on the way in, so every round trip added an
  apostrophe.
- **The subscription guard's cache was static.** Per-request in a per-request
  process, but a queue worker is one process for days — so an organization that
  lapsed at noon kept writing until somebody restarted it. Now a `scoped`
  binding, which Laravel resets between jobs.
- **Backup script: a failed schema query produced a zero-database backup that
  reported success.** A `for ... in $(mysql ...)` discards the substitution's
  exit status and `2>/dev/null` hid the reason.
- **Backup script: pipelines checked one hand-picked PIPESTATUS index.** A tar
  that failed behind a happy gpg was invisible.
- **restore-check accepted yesterday's dump and overwrote backup.sh's verdict**,
  so a failed backup could verify green against the previous night's file. Now
  yesterday is accepted only before 06:00, and a backup failure is carried
  forward — except an off-site failure, which still lets the local dump be
  verified and reported as `offsite:false`, because throwing away the half that
  works helps nobody.
- **The health screen's test-send button could never be enabled** — a controller
  notifies the field it drives, never an ancestor State.
- **The backups card showed a red icon beside an empty sentence** in the ordinary
  case of a written-but-not-yet-verified backup.
- **Saving panel choices wiped every panel's options**, because the dashboard
  endpoint never sent them back.

Two of my own fixes then broke the status file's JSON — first a sed pipeline
whose quoting was wrong, then a stray control byte from patching shell code
through Python string replacement. The status file is how a failure gets
reported, so it is the last place that should be clever: error tokens are now
reduced to a safe character set, which cannot produce unparseable JSON whatever
a database is called.

323 tests. Deployed as `20260806144221`.

---

## 2026-08-21 — HTML mail, as Gmail shows it

The block body was a safety property: hostile HTML became a reading-order
stack, so a newsletter of tables, sized pictures and buttons stacked as a
column of images. The owner asked for the view to be as close as possible to
Gmail, and said changing how mail is displayed was allowed.

Opened HTML is now purified (tables, classes, inline CSS; no script, no
forms, no CSS `url()`), pictures are fetched through EzBizi and inlined as
data URIs, and the web client draws that document in a sandboxed iframe
(`srcdoc`, no scripts, no same-origin). Blocks remain the fallback for
plain text and for anything that is not the web app. Raw markup is never
on the wire — it sits on `$sourceHtml` until the sanitiser has run.

Client `v0.0.0048`.

---

## 2026-08-21 — Gmail threads in the inbox

The list called `users.messages.list`, so five WhatsApp templates that Gmail
shows as one conversation were five rows. It now calls `users.threads.list`,
the row is the latest message plus a count, and opening a thread loads the
other messages in it. IMAP is still one row per message — that protocol has
no thread list.

Client `v0.0.0049`.

---

## 2026-08-21 — reading pane is a white page

The message column sat on the app grey (`#F5F8FA`), so the header and the
margins around the iframe looked like a card on a tint. Gmail's mail is a
white page. The right panel is now painted white.

Client `v0.0.0050`.

---

## 2026-08-21 — Email UI like Gmail

The three-column layout (inboxes, list, preview) is gone. The list fills
the content pane; opening a message replaces it; Back returns to the list.
Search, an Unread filter, and Gmail folders (Inbox, Starred, Sent, Drafts,
Trash, Spam) sit on the list. The reading toolbar has Back, Delete, and
mark read/unread. Delete is refused with a sentence: this connection can
read and reply, not bin mail for everyone who shares the inbox.

Client `v0.0.0051`.

---

## 2026-08-21 — bulk mail, sender icons, Trash

Tick a sender mark to select; a bar offers delete, mark read, mark unread
for the ticked rows. The list shows a coloured letter, or the site's icon
when Google's favicon service has one for that domain.

Delete bins the Gmail thread. That needs `gmail.modify` in Google Admin
domain-wide delegation (a fourth scope). Listing still uses read+send, so
a missing modify scope cannot take the inbox down — only delete is
refused, in a sentence.

Client `v0.0.0052`.

---

## 2026-08-21 — open-mail icon, padding, attachments

The list had sender icons; the open message did not. Simple HTML (a
Gmail "thanks for lunch" with no layout tables) sat flush against the
iframe edge because wrap padding was 0. Attachments were named and not
downloadable.

The reading pane now draws the same letter/logo next to From. Wrap CSS
pads 20/24/32px. Tapping a file streams it through this origin with a
sealed token — Decision 2 still holds: nothing is stored.

Client `v0.0.0055`.

---

## 2026-08-21 — All inboxes

Three shared inboxes meant picking one and missing the others. All inboxes
is now the first row when there is more than one, the landing view, and a
single list merged by date. Each row names the inbox the mail arrived in.
Opening it puts that address on THE INBOX YOU ARE IN, so a reply still
goes out as the inbox it came to. One mailbox that cannot be read does
not empty the rest — its sentence sits above the mail from the others.

Client `v0.0.0056`.

---

## 2026-08-21 — brand logos and the verified check

List avatars asked Google's favicon service and then drew the image
through CanvasKit, which cannot decode a cross-origin PNG with no CORS
headers — so Apple, Google and the rest stayed a coloured letter. The
image is now an HTML `<img>`, and the domain is the registrable one
(`email.apple.com` → `apple.com`). Known verified brands get Gmail's
blue check unless DMARC failed on that message.

Client `v0.0.0057`.

---

## 2026-08-21 — Workspace scope health

A Workspace that could list mail still said "connected" when Google
Admin had not granted `gmail.modify`, so Trash failed in a sentence
with no explanation on Settings. The connected card now probes each
of the four domain-wide scopes and lists them: granted or missing.
When one is missing the Google Admin steps stay on the card.

Client `v0.0.0060`.

---

## 2026-08-21 — Month squares fit the Posts pane

Monthly Calendar day cells stay square but shrink to the remaining
height of the Posts pane, so days 1–31 are on screen with no vertical
scroll. Weekly Calendar is unchanged: this week, no arrows.

Client `v0.0.0062`.

---

## 2026-08-21 — Weekly and Monthly calendars

Posts calendars are named Weekly Calendar and Monthly Calendar. The week
is this week, with no arrows. Month days are square cells, still with
previous and next.

Client `v0.0.0061`.

---

## 2026-08-21 — Social Connect for every network

Accounts can finish a sign-in for Pinterest, Facebook Pages, Instagram,
Threads, LinkedIn, TikTok, YouTube and X (same popup as Gmail). Bluesky
is a handle and an app password; the password is not stored. A network
without an app on this installation says so. Tokens never leave the
server. Compose and publish are still later.

Client `v0.0.0058`.

---

## 2026-08-21 — Social calendars, wider days and month arrows

The Posts calendars were a strip of skinny empty boxes stuck on this
week and this month. Day cells are taller, the calendar pane takes more
of the row, week days show their dates, and both calendars have previous
and next.

Client `v0.0.0059`.

---

## 2026-08-21 — Workspace Marketplace install

Connect Workspace still has the Super Admin prove they run the domain.
The four Google Admin scopes can then be granted by **Admin install** on
a Marketplace listing, instead of pasting Unique ID and scope URLs.
The listing is Public + Admin-only on Cloud project `ezbizi-506117`.
Google must review a public listing before customer domains can install.
Until `GOOGLE_MAIL_MARKETPLACE_URL` is on the box, Settings keeps the
paste-scopes fallback. Marketplace SDK is enabled.

Client `v0.0.0063`.

---

## 2026-08-21 — Place survives a reload

The signed-in shell kept the current tab only in memory, so a refresh
on Marketing always opened Dashboard. nginx already served index.html
for deep links. The address bar is now the memory: `/marketing`,
`/settings`, `/account`, and the other destinations. Back and forward
move the selection. A path this role cannot reach falls back rather
than 403ing.

Client `v0.0.0064`.

---

## 2026-08-21 — Month calendar uses the full width

Monthly Calendar day cells span all seven columns across the pane,
matching Weekly Calendar, and still fit on screen with no vertical
scroll.

Client `v0.0.0065`.

---

## 2026-08-21 — A-1 onboarding contracts adapted to the HR builder

Eleven A-1 Tools role agreements are now EzBizi catalog templates. Mapping
follows this platform: `{{name}}` became first and last name,
`{{company_name}}` became `{{organization.name}}`, hire date is a field,
paperwork date is `{{document.date}}`, home and business addresses are
fields. Hourly vs salary and raise vs no-raise are separate templates —
A-1's clause-deletion tags were not imported as fields. Forty-one
templates validate through FieldSet and MergeTags.

Publish into a tenant (not on signup):

```
php artisan hr:publish-onboarding-catalog "{organization name}"
```

`--dry-run` lists; `--replace` publishes a new version when the name
already exists. Chimney-trade wording stays opt-in.

---

## 2026-08-21 — Contract grammar grows sections, markup, addresses, checklists

The A-1 import showed six gaps. They are EzBizi features now, not A-1
imports: home address on the profile; business address on the
organization; document.day/month/year from the paperwork date; body
sections `{{#key}}` that drop a clause when the answer is blank;
declared markup (#, **, lists, tables) that we turn into HTML;
checklist fields that merge as a table. The catalog is eleven
templates, one per role, with hourly/salary/raise as sections. Client
`v0.0.0067`.

---

## 2026-08-21 — Fleet Management

The coming-soon tab is a register. A vehicle is owned or rented (never
both at once), has insurance and service ledgers, can be assigned to a
person, and keeps photos on the vehicle and papers on the period, visit,
or payment they belong to. Money is a permission, not a column everyone
sees. Two overlapping deploys used to wipe `/opt/ezbizi-test/bootstrap`
mid-suite; the gate now waits on a lock.

Release `20260821204600`. Client `v0.0.0066` (this build `v0.0.0067`).

---

## 2026-08-24 — Marketing site: one canonical URL

Search Console listed HTTP, apex, `/index.html` and the old
`/privacy.html` `/terms.html` paths. nginx now 301s all of those to
`https://www.ezbizi.com/` (or `/privacy/` `/terms/`) in one hop.
`GET /` still serves the homepage — a naive `location = /index.html`
redirect looped because nginx internally rewrites `/` to that file.

---

## 2026-08-24 — Social catalogue marks and shorter names

Each network in Accounts › Catalogue (and Connect) has its brand mark
on the left. Facebook and Instagram are labelled that way on screen,
not "Facebook Page" or "Instagram professional". Keys on the wire are
unchanged.

Client `v0.0.0068`.

---

## 2026-08-28 — Tasks Phase 1

EzBizi now covers ordinary Monday board work without creating a second workspace model. A board
is filed on the active organization, company, or franchise view; private membership only narrows
that exact-view access. Dense desktop grids become grouped cards and full-screen detail on phones.

Phase 1 includes groups, one-level subtasks, eight typed custom columns, My Tasks and saved views,
updates/files/watchers/activity, governed EzBizi record links, durable in-app notifications, nine
automation triggers, six actions, and bounded CSV/XLSX transfer. File-bearing paths opened only
after the encrypted off-instance private-file restore proved exact. The JSON archive is a manifest,
not a restorable backup. Planning views and the broader platform replacement remain Phase 2/3.

Client `v0.0.0111`.

## 2026-08-31 — Tasks Phase 2

Five presentations over the one query surface Phase 1 already had: Kanban, Calendar,
Timeline/Gantt, and Workload join Table behind `GET tasks/boards/{board}/rows`, selected by a
`presentation` and `window` that travel inside the saved view's `configuration` JSON rather than
as their own endpoints. Saved-view configuration moves to `schema:2` — upgraded on read, never
rewritten in storage, so a rollback strands nothing. Windows are bounded per presentation: 400
days for a range, 50 UUID-shaped buckets for Kanban, folded into the existing conditional ETag so
a window change is a cache miss rather than stale data under a fresh tag.

Dependencies are a "blocks" edge for Timeline and nothing else — display only, no completion
refusal, no override path, no date propagation — with a cycle rejected at write time inside the
same board-row lock that stops a concurrent insert from slipping one past the check. Workload
counts tasks, never hours: no estimate field, no capacity model, no over/under-loaded threshold,
just how many tasks land on each day or week a bounded window covers. Recurrence ships both modes
— `on_schedule` walks forward from the series task's own dates on the hourly worker;
`after_completion` never creates its occurrence inline, only emits an outbox marker at completion
time, so a lock deadlock building the next instance can never abort the completion that triggered
it. Cross-board Timeline/Calendar/Workload summaries reuse My Tasks' access path verbatim rather
than re-deriving a check Phase 1 already proved against a membership-revocation race.

Timeline needed no new column — `tasks.start_on` existed from Phase 1. One real behaviour change
rode along: `PATCH /tasks/{id}` and task creation now refuse `start_on` after `due_on` with a
422, closing a gap the Phase 1 design and the client's own copy had asserted was already closed
and was not — a request that used to succeed and silently write the invalid pair now gets a
sentence instead. Server-push refresh, listed as optional in the Phase 1 design, is dropped for
real: Phase 1's polling already converges and was tested against a revocation race, and a push
channel would need its own answer to every case polling already answers, for a refresh that
already works. Decision recorded, not left to be silently revisited. The App Store freeze
(`docs/ACTIVE-CONSTRAINTS.md`) that shaped several of these choices additively while `v0.0.0112`
sat frozen against live production has since lifted.

Client `v0.0.0123`.
