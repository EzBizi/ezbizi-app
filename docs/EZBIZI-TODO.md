# EzBizi — open items

Owner key: **[YOU]** = only you can do it · **[CLAUDE]** = I'll do it · **[BOTH]**

Numbered from 1, and **no longer sharing a sequence with `../AWS-MIGRATION-TODO.md`**. They
are separate projects; the shared numbering only produced gaps whenever an item closed.

## Operational

| # | Item | Owner | Notes |
|---|---|---|---|
| 1 | **Outbound mail** | **[BOTH]** | Everything is wired but the provider credential. **Relaying through the websites box does not work** — AWS blocks outbound port 25 on both instances (`aspmx.l.google.com:25 BLOCKED`; 587 outbound is open). That box receives fine and has never been able to send, which is why "email sending (currently broken)" has sat in the migration TODO since the cutover plan was written. Two routes: **(a)** ask AWS to lift the port-25 restriction per instance — needs reverse DNS, not always granted; **(b)** SES, either as the app's transport or behind Postfix on the websites box. 587 outbound is open, so SES works the moment there is a key. Until then the Health screen says `missing AWS_ACCESS_KEY_ID` rather than pretending. |
| 2 | ~~**`hello@ezbizi.com`**~~ → **`contact@ezbizi.com`** | ✅ | **Done.** Mailbox created on the websites box, MX published, and all six CTAs on ezbizi.com repointed. Receiving is verified — `contact@` accepted, `nobody@` rejected. **It cannot reply until #1 is settled.** Password in `_secrets/Application-credentials.md`. |
| 3 | **`ns2` on a second machine** | **[BOTH]** | **On hold at your request.** Primary half done: ns1 offers TSIG-authenticated transfers and notifies `32.194.222.151` (`deploy/named-primary-ezbizi.conf`, key in `_secrets/`). The secondary half is not a config paste — that box runs BIND under Plesk, chrooted, from a `named.conf` Plesk regenerates, so a hand-added zone is erased on its next DNS change anywhere. Either add `ezbizi.com` to Plesk as a DNS-only domain and `plesk bin dns --set-secondary` (supported, but Plesk slaves transfer by source IP, so ns1 drops TSIG for that host), or put the secondary somewhere that is not Plesk. |

| 22 | **Signup has no email verification** | **[CLAUDE]** | **Blocked by #1, and deliberate rather than forgotten.** `POST /api/v1/auth/signup` creates the account and signs the person straight in. It cannot ask them to confirm their address first, because nothing on this platform can send mail — so a verification gate would lock out every account signup creates. Add it *after* #1 has an outbound transport, and not before. The consequence while it is absent: an address is never proved to belong to the person who typed it, so a typo produces a real organization nobody can reach, and password reset — the one flow that would recover it — also needs #1. The absence is stated in `AuthController::signup` and pinned by a test (`SignupTest::test_the_account_can_read_and_write_immediately_with_no_verification_step`), so adding the gate early fails the suite rather than a customer. |

## Decisions only you can make

| # | Question | What it blocks |
|---|---|---|
| 4 | **Are the state territories separate legal entities?** | **Deferred 2026-08-06 — "keep as is for now".** They are franchises under the single company "A-1 Chimney". Still worth answering before real jobs exist: §3 forbids moving a franchise between companies afterwards, and it decides whether #5's single organization counter stays correct. |
| 5 | ~~Which entity owns the invoice numbering sequence~~ (§5.2) | ✅ **Decided 2026-08-06: one counter for the whole ORGANIZATION.** Connecticut issues 1001, Georgia 1002, Texas 1003 — one unbroken run across the business. Revisit only if #4 turns any territory into its own filing entity, because a shared counter across separate legal entities is what tax authorities object to. |
| 6 | **Pricing metric** (§13) | Not urgent — users are metered from day one either way. |
| 9 | **What does a demo organization suppress?** | **Open.** `is_demo` is written by `ProvisionOrganization` and read in exactly one place, for display. It excludes an organization from nothing — not metering, not the health canary, not backups, not reporting. Name what it suppresses before §13's metering is built, or the first sales demo lands in the MRR. |

## Decided 2026-08-07

From the twelve-lens review. Recorded here rather than only in a conversation,
because §16's dispositions are what stop a settled question being re-litigated.

| # | Question | Decision |
|---|---|---|
| 10 | Minimum ceremony to create an organization | **A signup form**, doing it all at once: the user account *and* the organization *and* its first company *and* its first franchise. §3 forbids a company without a franchise, so a multi-step flow somebody can abandon halfway would produce exactly the state the rule prohibits. Today there is **no path at all** — every caller of `ProvisionOrganization` is a test, and the live organization was made from a shell. |
| 11 | Job price field: dollars or cents | **Dollars.** It is what people type, and the parser already reads dollars — only the label said cents. |
| 12 | Organization-level acts: permission alone, or permission + an organization-level assignment | **Authority never flows upward.** Owner's words: *"a franchise user should not be able to modify parent preferences, a company user should not be able to modify org preferences."* `CompanyPolicy::create` already chose this; it now applies to Settings, Branding and everything added later, from **one** shared rule rather than a home-made reach derivation per controller. |
| 13 | Are customers really shared organization-wide | **Yes, and it is inherent, not a switch.** Owner's words: *"if i add a client to a franchise, the client belongs to the franchise, but can be seen via organization view, so technically it belongs to the organization itself."* That is §5.2. `shares_customers` — a column read by nothing — is therefore deleted rather than wired: it described a choice that does not exist. |
| 14 | Cross-currency roll-ups, ever | **No — USD only.** Answered "yes" and then, asked whether anything is actually invoiced in another currency, *"not really, just USD"*. So the exchange-rate capture that a yes would have made urgent is **not being built**: it is machinery for a currency nobody invoices in. The schema already carries `currency` per franchise and per job, which keeps the option open at no cost. The one thing kept from that thread: those columns validated as any three characters, so `ZZZ` passed and was then permanent on every job — now `in:USD`, with a message naming the decision. |
| 15 | Does a record number survive a soft delete | **Yes — never reused, gaps expected.** It is a legal artefact, not a display convenience. Free to fix while the counter has never advanced. |
| 16 | What happens when a trial ends | **Read-only.** Owner's words: *"write permissions are disabled, only read is kept, so no changes whatsoever can be done."* |
| 17 | Trial length | **Sales can extend it**, so it is data, not code. Today it is `now()->addMonth()` — which is also the calendar month §13 explicitly rejects, since February trials come out shorter. |
| 18 | Audit retention | **3 months.** One policy for every customer. |
| 19 | §8 i18n · §11.4 public API · §12 theming | **Build now.** All three were *claimed* rather than built; the plan asserting them while the code lacked them was the problem, not the absence itself. |

### Deferred, deliberately

| # | Question | Why it is parked |
|---|---|---|
| 20 | **Is a scheduled job an instant or a local wall time?** | Parked with modules — *"we are not working on modules yet"*. Worth flagging that the price of parking it rises fast: `franchises.timezone` is already stored and validated at three levels and read by nothing, and "9am Tuesday" for a technician is a wall time. Stored as an instant it moves on DST and on any timezone correction. A column and a cast today; unvalidatable once ten thousand jobs exist. **Answer before the scheduling module, not after.** |
| 21 | **Does a deactivated franchise's history stay in roll-ups?** | *"Figure this later."* Only affects reporting, and no franchise has been deactivated. |
| 26 | **Can a contract field say that a date IS an expiry?** | Recorded because two docblocks point here for it. `App\Support\Contracts\FieldDefinition` carries key, label, type, filled_by, required, sensitive and retired — and nothing that marks a `date` as the one that lapses. So "which certifications expire this month" has no honest answer, and the HR dashboard's **Expiring certifications** panel is drawn badged "Not built yet" rather than guessing (`HrDashboardController::PANELS`, `client/lib/screens/hr/dashboard_tab.dart`). It is a **contract-template** decision, not a dashboard one: a seventh field property has to be matched by onboarding, the diff, the renderer and every stored version, so it is worth deciding once rather than inventing here. Parked until somebody wants the panel enough to pay for that. |
| 27 | **Is leaving a dated event?** | Recorded because `StartersAndLeavers` points here for it. §5's rule is that deactivate is not delete, and the record survives as `users.is_active = false` — a boolean with no timestamp beside it. So "who left this month" cannot be answered; only "who is no longer active". The panel reports the leaving half **without** a period and says so on screen, and `period_applies_to` names which rows the window covers. The fix, if it is wanted, is an employment-lifecycle shape (a dated leaving event, not just a column), which onboarding and Records would then have to match — the same size of decision as #26. Reading it out of `audit_events` or `automation_events` instead was rejected: both are logs on somebody else's retention policy, and a leaver would vanish from history the day one rotated. |

## Base UI — not module work

Everything else the audit found belongs to a module and waits for that module. These two do
not: they are the shell and the front door.

| # | Item | Owner |
|---|---|---|
| ~~7~~ | ✅ ~~**Settings**~~ — a typed value is discarded unless you press Enter; the three JSON settings render as a box that rejects what it just displayed; every control looks editable to people without `organizations.edit`, who then get a 403 on save; company-level scope is supported end to end and unreachable from the UI; the `help` text the server sends for every setting is never shown. | ✅ **Done 2026-08-06** — all five. |
| 8 | **Login** — somebody with accounts in two organizations, one with an authenticator, is silently signed into the other and can never reach the first. (The mistyped-code half is fixed: the server's message is shown.) | **[CLAUDE]** |
| 23 | **Nothing alerts.** `CheckBackupHealth` only mails, and mail does not send; the scheduler discards task output and exits 0 regardless; nothing watches disk, TLS, MySQL, nginx or whether the site answers. The Health screen requires somebody to look, and is served by the app that would be down. Needs one out-of-band channel that is not email, plus `OnFailure=` units so systemd can shout when the app cannot. | **[CLAUDE]** |
| 24 | **§18 Phase 0 item 4 was never done.** Webmin 2.653 runs as root and answers `0.0.0.0:10000` as well as through nginx; `ufw` is inactive; `fail2ban` is inactive. That box is also MySQL, the backup source, the GPG passphrase, the ACME account key and the sole nameserver. | **[BOTH]** |
| 25 | **Users, roles, invitations — none of it exists.** `User::create`, `Role::create` and `Assignment::create` appear only inside `ProvisionOrganization`. There is no way to add a second person to an organization, `permission_role` is never written, and every tenant is permanently one Administrator for whom `hasPermission` short-circuits to true. **This blocks Phase 4**: every permission a module registers is ungrantable, and it is why 32 of 33 `authorize()` calls would survive deletion with the suite green. | **[CLAUDE]** |

## Module work — deferred until each module is built

From the 2026-08-06 audit. Confirmed real, but each belongs to a module rather than to the
base UI, so they are recorded here rather than fixed piecemeal.

| Module | What is wrong |
|---|---|
| **Customers** | A row is a dead end — no detail, edit, deactivate, delete or reassign-owner control, though the API supports all five. The `Inactive` tag the list draws cannot be produced by any user action. Address fields are not on the create form at all, so no customer has an address. |
| **Jobs** | No open, edit or delete, so a status is frozen at creation. No schedule date on the create form, so `scheduled_for` — which the dashboard's "Coming up" panel reads — can never be set. No filters, though the endpoint supports three. A failed customer search reads as "no such customer". |
| **Automations** | No way to read back, edit or roll back a rule. A failed run shows no reason, though the server sends one. "Conditions did not match" is hardcoded; the per-clause trace is discarded. Deleting a condition row leaves stale text below it. An empty condition row saves and produces a rule that never matches. "is one of" cannot be expressed by the form. |

## Done

- ✅ **"Workflow" is now "automation", all the way down.** *2026-08-10.* The product said
  automation on screen while the tables, models, routes, permission keys and the plan's §5.5
  heading all said workflow. All four tables were empty in production, so this was the last
  moment it cost nothing but names. `automations`, `automation_versions`, `automation_events`,
  `automation_runs`, with every index and foreign key renamed alongside them — a renamed MySQL
  table keeps its old index names, so `workflow_runs_idempotency` would otherwise have survived
  the rename and made the idempotency guarantee ungreppable. `/api/v1/workflows/*` is now
  `/api/v1/automations/*` (nine routes, same nine), and `ezbizi:dispatch-workflow-events` is
  `ezbizi:dispatch-automation-events` — **the systemd units and the scheduler need the new
  deploy before the next sweep will run.** The permission keys moved IN PLACE rather than being
  re-seeded: `ezbizi:sync-permissions` never deletes, so a plain re-sync would have added four
  `automations.*` rows and orphaned four `workflows.*` ones, silently voiding any role holding
  them. §5.5 keeps its number, so every `§5.5` in the code still points at the right section.

- ✅ **The pre-encryption backups are gone, and the directory is closed.** *2026-08-07.*
  Seven archives written before encryption landed were still sitting in `/var/backups/ezbizi`
  as `-rw-r--r--` in a `drwxr-xr-x` directory. One of them, `app-2026-08-06.tar.gz`, contained
  `ezbizi/.env` — **APP_KEY**, the database password, the lot; another carried the BIND TSIG
  keys. Any local account could read them. The three that had no encrypted twin were encrypted
  first and every `.gpg` verified decryptable before anything was deleted, so no backup was
  lost. Directory is now `0700`, files `0600`, and `backup.sh` sets `umask 077` and
  `install -d -m 0700` so the next run cannot recreate the exposure. The off-site copy was
  never affected — that directory was already `drwx------`.
  *Found by checking the box rather than re-reading the note that said "backups: encrypted".*

- ✅ **The `/var/www/ezbizi` dual-purpose hazard is fixed, not just guarded.** *Verified
  2026-08-07.* That directory now holds only `.env`, `storage` and the built client — no
  `.git`, no `artisan`, no `vendor`. The test checkout lives at `/opt/ezbizi-test` with no
  `.env` at all, so a bare `artisan` there resolves to the framework default rather than to
  `ezbizi`. The arrangement that let `db:wipe --env=testing` reach production no longer exists.

- ✅ **Phase 3 complete** — automation engine, notifications, staff control plane, subscription
  enforcement, import/export.
- ✅ **Dashboards (Phase 4 begins)** — a widget registry with per-user layouts, so a new number
  is a class rather than a client release. Each widget scoped to the caller's reach; revenue
  reported one row per currency rather than summed.
- ✅ **Backups: encrypted, off-site, and restore-verified nightly.** GPG AES256, copied to the
  websites box, and the restore check pulls the file back *down* before verifying — so a copy
  that arrived corrupt is caught rather than assumed good. Includes the bare git repo and
  `.env`; APP_KEY lives there, and without it every TOTP secret in a restored database is
  unreadable. **§18 Phase 0 item 5 is closed.**
- ✅ **Backup health visible in the product** — Development → Health. A *missing* verdict counts
  as a failure, because a timer that has stopped writes nothing at all.
- ✅ **Test sandbox out of the webroot** (`/opt/ezbizi-test`), with no `.env` at all. A bare
  `artisan` there resolves to the framework default, not `ezbizi` — the arrangement that let
  `db:wipe --env=testing` reach production no longer exists.
- ✅ **SPF, DMARC and authenticated zone transfers** for ezbizi.com.
- ✅ **The password-reset flow has an ending.** The email links to `/reset?token=…` and the
  client threw the path away; everybody who clicked landed on sign-in with their token
  discarded.
- ✅ **Your account** — first / middle / last name, phone, gender, date of birth, profile
  picture, theme, two-factor, password, and a session list with device, platform and IP.
  Reached from the avatar menu, not the sidebar.
- ✅ **Two-factor changeable from the profile**, including for linked accounts, behind your
  password *and* a current code. Moving to a new phone no longer means going unprotected first.
- ✅ **Login: the code is its own step**, and neither screen mentions the control plane.
- ✅ **Development pinned to the bottom of the sidebar**, separated from the business tabs.
- ✅ **A network blip no longer signs you out**; a password is no longer sent in a URL; lists
  survive a failed page; the job price field stopped turning "1,250.00" into 125,000 cents.
- ✅ **`contact@ezbizi.com`** — mailbox on the websites box, MX published from the app box's
  zone, Plesk DNS deliberately left off for the domain so there is only one authority. All six
  marketing CTAs repointed from `hello@`.
- ✅ **Flutter entry points no longer cached.** `flutter_bootstrap.js` and `main.dart.js` are
  NOT hashed by Flutter, and the "hashed assets are immutable" nginx rule was matching them —
  telling browsers to hold a 30-day immutable copy of the file that names the current build.
  index.html was correctly no-cache the whole time and it made no difference. Every deploy
  since that rule landed needed a hard refresh to be seen.
- ✅ **Two-panel sign-in** on both faces, sharing one `AuthShell` so they cannot drift.
  "Keep me signed in" is real: unticked the token dies with the tab, ticked it survives the
  browser closing.
- ✅ **Territories created** — Connecticut (renamed from Main), DMV, Georgia, Illinois,
  New Jersey, Ohio, Texas, Washington, Nationwide.
