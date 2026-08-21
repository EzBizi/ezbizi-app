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
