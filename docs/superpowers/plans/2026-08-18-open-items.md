# EzBizi open-items implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Do not start this plan until Mia grants go-ahead.** No deploy, no SSH, no `.env` edits on the box, no commits unless she asks.
>
> Reviewed 2026-08-18 (`docs/superpowers/plans/2026-08-18-open-items-REVIEW.md`). Fixes 2–5 applied below. Fix 1 rejected — see that section.

**Goal:** Close the decisions from 2026-08-18 and ship the work she approved, in an order that does not lock people out of signup or invent APIs.

**Architecture:** Platform mail and box-to-box liveness are operational. Workspace mail code is already on `main` and only needs go-live. Product work copies existing registries (MailboxProvider, FieldDefinition, Widget) rather than adding parallel ones. i18n is a data catalogue (§8), not compiled Flutter ARB files.

**Tech Stack:** Laravel 13 · PHP 8.4 · MySQL 8 · Flutter web · systemd timers · Amazon SES SMTP on 587 · Microsoft Graph for Outlook · Google Workspace DWD (already coded)

**Spec:** `docs/EZBIZI-TODO.md`, `docs/EZBIZI-STATUS.md`, `docs/EZBIZI-BUILD-PLAN.md` (§2.1, §3, §4, §5.5, §8, §18), `docs/MAIL-SETUP.md`, `StartUp.md`, `docs/DEPLOY.md`

## Global Constraints

- Front door is `StartUp.md`. If documents disagree, `docs/EZBIZI-STATUS.md` wins.
- TDD: red test first; delete the production line once and watch it fail; restore.
- R8: user-facing sentences name what happened and what to do. No class names, `::`, or Exception in product copy.
- Registries: a new mail provider is a `MailboxProvider` case plus `MailboxReaders::forProvider`. A forgotten `match` arm is a shipping bug.
- Mail is live, never stored. Shared team inboxes only. Personal mail is out of scope. Google Groups out of v1.
- Never copy A1 Tools code. Never commit secrets. Never edit on the server as shipping. Never point tests at a database whose name lacks `testing` (`tests/TestCase.php`).
- Do not SSH or `git push production` unless Mia says so in that turn.
- Colours from `client/lib/core/theme.dart`. No modals. Finish the whole task.
- MySQL is the gate. sqlite `:memory:` is local approximation only (StartUp.md §8). Never `assertSame` on JSON key order from the DB.
- `Http::fake()` once per test, by full path.
- Windows OpenSSL: `OPENSSL_CONF` must point at PHP's `openssl.cnf` (DEV-ENVIRONMENT). Dummy PEM when a test does not sign.

---

## Review 2026-08-18 — what changed

Checked against `main` @ `80acccc`, same commit the review named.

| Review item | Disposition |
|---|---|
| **Fix 1** — signup pin test missing | **Rejected.** `HEAD:tests/Feature/Api/SignupTest.php:322` is `test_the_account_can_read_and_write_immediately_with_no_verification_step`. TODO #22's guard rail is in place. Do not write a second pin. Phase 8 still *replaces* that test when the gate ships. |
| **Fix 2** — `/up` exists and does not ping MySQL | **Taken.** `bootstrap/app.php` already registers `health: '/up'`. Phase 5 registers a cheap `DiagnosingHealth` DB ping so a stopped database is not HTTP 200, and the watch-script comment states what `/up` still does not cover (backups, mail, TLS). |
| **Fix 3** — `scheduled_for` call sites | **Taken.** Phase 7 lists every writer/reader, including automations, plus a test that an automation write matches the API wall time. |
| **Fix 4** — MySQL numbered last, ordered fourth | **Taken.** Local MySQL is now Phase 1. Later phases assume that gate. |
| **Fix 5** — no leaving backfill | **Taken.** Leaving history **begins at the migration**. The widget says so. Do not invent `left` dates from `users.updated_at`. |
| Phase 10 “seventh property” | Wording only. `is_expiry` is a **fourth boolean** (ninth constructor argument). |
| Phase 8 token refresh | Name `tests/Feature/Api/GoogleMailTokenRefreshTest.php` as the shape to copy. |
| Phase 12 i18n size | Split into server slice then client slice. |

---

## What this plan is not

| Item | Disposition |
|---|---|
| Webmin / ufw / fail2ban (TODO #24) | **Parked.** Mia: not for now. Leave the TODO open. |
| Customers screens | Parked. |
| Jobs **screens** (open/edit/delete/filters) | Parked. Schema for wall-clock `scheduled_for` still lands (Phase 7) so Jobs later does not store the wrong thing. |
| Second nameserver (TODO #3) | Still on hold. |
| Demo-org suppression (TODO #9) | Not yet. |
| Territories as legal entities (TODO #4) | Client-dependent. Keep current franchise shape. Do not encode a global legal rule. |
| Pricing metric (TODO #6) | Undecided. |
| Off-account backup | Mia: already tested, leave it. |
| Public API (§11.4) and extra theming (§12) | Not in this plan. Question 18 was i18n. |
| Automations rebuild | **Mostly already built** (read-back, edit, “Put this one back”, failed-run reason, condition trace). Phase 10 is an audit of leftovers, not a rewrite. |
| Extra signup-pin task | Not added. The pin already exists (review Fix 1). |

---

## Answers already given (record in TODO when executing)

- Signup confirmation email: **yes**, after SES actually sends.
- Workspace go-live: **yes** (code exists; console + deploy).
- Scheduled job time: **wall time** in the franchise timezone (not a UTC instant).
- Contract date can be an expiry: **yes**.
- Leaving is a **dated event**, not only `is_active = false`. History starts when the table ships.
- Deactivated franchise history **stays in roll-ups**.
- Close stale TODO #8 (login 2FA skip) and #25 (users/roles/invitations).
- Outlook as a fourth mail provider: **yes**.
- Local MySQL `ezbizi_testing`: **yes**.
- i18n foundation: **yes** (English + Spanish, strings as data, §8).
- Boxes alert on each other: **yes** — see Phase 5.

### Q17 — did Claude already set up local MySQL?

**No.** `docs/EZBIZI-DEV-ENVIRONMENT.md` still lists it **open**. Claude fixed OpenSSL on 2026-08-17. GD and Dompdf are also still missing. `phpunit.xml` already forces `DB_CONNECTION=mysql` and `DB_DATABASE=ezbizi_testing`. The database itself was never created on this Windows machine. StartUp.md §8 still says to approximate with sqlite in memory.

---

## Recommended order

```
1  Local MySQL ezbizi_testing
2  Record decisions in TODO/STATUS
3  SES (Mia, Desktop guide)              ──┐
4  Workspace go-live (deploy + Google)      │ independent of SES
5  Mutual box alerts                        │
6  Platform mail live                    <──┘ after SES test arrives
7  Job wall-clock scheduled_for
8  Signup confirmation email                after 6; replaces existing pin test
9  Outlook mail provider
10 Automations leftover audit
11 Contract field is_expiry
12 Dated leaving events                     history begins at migration
13 i18n — server slice, then client slice
```

Workspace (4) does not need SES. Signup confirmation (8) **must not** ship before a Health-screen test email has been received.

---

## Phase 0: Documentation discovery (allowed APIs)

Copy these; do not invent parallel ones.

| Need | Copy from |
|---|---|
| Mail transport health | `app/Support/Mail/MailStatus.php` — `ses` wants `AWS_ACCESS_KEY_ID`; `smtp` wants `MAIL_USERNAME`; From must not be `@example.com` |
| Platform mailer | `config/mail.php` — prefer SMTP so SES / Workspace / Postmark are credential changes |
| Gmail OAuth shape | `GoogleMailAuthController`, `GoogleOAuthSignIns`, `MailboxProvider`, `MailboxReaders::forProvider` |
| Gmail token refresh | `tests/Feature/Api/GoogleMailTokenRefreshTest.php` |
| Workspace go-live | `docs/MAIL-SETUP.md` DWD section; `.env.example` `GOOGLE_MAIL_WORKSPACE_*` |
| Health | `HealthController`, `tests/Feature/OperationalHealthTest.php` |
| Framework liveness | `bootstrap/app.php` `health: '/up'` — extend via `DiagnosingHealth`, do not add a second public health route |
| Backup remote | `deploy/backup.sh` — websites box `ubuntu@32.194.222.151` |
| Automations rollback | `AutomationsController::activateVersion`, client `_restore` / “Put this one back” |
| Contract fields | `app/Support/Contracts/FieldDefinition.php` — fourth boolean (`is_expiry`), same defaults-as-safe pattern |
| Leavers widget | `app/Support/Dashboard/Widgets/Hr/StartersAndLeavers.php` |
| Signup pin | `SignupTest::test_the_account_can_read_and_write_immediately_with_no_verification_step` at line 322 |
| User already has | `email_verified_at` on `User` — unused by signup today |
| Job time | `App\Models\Job` `$casts['scheduled_for' => 'datetime']` — this is the instant trap |
| Franchise tz | `franchises.timezone` already stored, read by almost nothing |
| Test DB guard | `tests/TestCase.php` `REQUIRED_MARKER = 'testing'` |
| Deploy | `docs/DEPLOY.md` — `git push production main` only |
| i18n spec | build plan §8: US launch, English+Spanish, strings **from data**, org default + user override, franchise timezone, relabel “Franchise” |

**Anti-patterns**

- Do not add a Microsoft callback onto `email/google/callback`. GoogleMailAuthController already says a second provider needs a second callback; `oauth/start` / `oauth/status` stay provider-neutral.
- Do not store mail bodies. Do not import personal inboxes. Do not add Google Groups.
- Do not read leavers out of `audit_events` or `automation_events` (StartersAndLeavers docblock).
- Do not backfill `left` from `users.updated_at` — that column moves on any save.
- Do not compile i18n into Flutter `.arb` as the source of truth.
- Do not `MAIL_MAILER=log` in production. Do not put SES passwords in the repo.
- Do not create `ezbizi` (production name) as the local test database.
- Do not treat Laravel `/up` as “backups, mail and TLS are fine.” That is the staff Health screen.

---

## Phase 1: Local MySQL `ezbizi_testing`

This machine only. Not production. First so later phases can use the real gate.

- [ ] Install MySQL 8 (or start it if it is already installed but has no service).
- [ ] `CREATE DATABASE ezbizi_testing;` — name **must** contain `testing`.
- [ ] Local `.env` / user with rights only on that schema. Password stays out of git (`H:\AWS\_secrets\` or Windows user env).
- [ ] Run `php artisan test` **without** sqlite overrides. That is the first honest local gate.
- [ ] Update `docs/EZBIZI-DEV-ENVIRONMENT.md`: MySQL row from **open** to **fixed**, with the date. Do not claim GD/Dompdf unless those are also installed (optional extras, not required to close Q17).

**Anti-pattern:** pointing phpunit at `ezbizi`. `TestCase` will refuse a name without `testing`; do not weaken that guard.

**Verification:** `php artisan test` talks to MySQL `ezbizi_testing` and finishes. sqlite override is no longer the default local command.

---

## Phase 2: Record decisions

**Files:** `docs/EZBIZI-TODO.md`, `docs/EZBIZI-STATUS.md` (STATUS only for hazards that changed; do not hand-edit the generated numbers block).

- [ ] Close TODO #8 and #25 as done, with the date and the tests that prove them (`AuthApiTest::test_an_account_behind_an_authenticator_is_not_silently_skipped`; HR invitations + Roles screens).
- [ ] TODO #20: decide wall time; point at Phase 7.
- [ ] TODO #21: deactivated franchise history stays in roll-ups.
- [ ] TODO #26 / #27: unpark as work, not questions.
- [ ] TODO #22: still blocked by outbound mail; confirmation email is the chosen shape; the pin test **exists** at `SignupTest.php:322` — do not “add” it.
- [ ] TODO #23: reword to mutual watchdog (Phase 5).
- [ ] TODO #24: remains open, parked at owner request.
- [ ] Automations module row: rewrite to “audit leftovers” not “none of it exists”.
- [ ] Add Outlook + local MySQL + i18n foundation as open work items if they are not numbered.

**Verification:** every disposition above is a sentence in TODO. No product code.

---

## Phase 3: SES (Mia, not an agent)

**Deliverable already written:** `C:\Users\jose\Desktop\ezbizi-ses-setup.md`

Agent work after she says the test email arrived: Phase 6.

- [ ] Mia follows the Desktop file: verify domain, leave sandbox, create SMTP credentials.
- [ ] Agent does **not** invent a second mailer class. `MAIL_MAILER=smtp` + SES host on 587 is the intended path (`config/mail.php`).

---

## Phase 4: Workspace go-live

**Code is already on `main`** (`MailboxProvider::GmailWorkspace`, `GoogleWorkspaceAuthController`, migration `2026_08_17_120000_create_google_workspace_tables.php`, Flutter directory picker, `tests/Feature/Api/GoogleWorkspaceTest.php`).

This phase is **release + console**, not new features.

- [ ] Confirm whether production has that commit (`git log production/main` vs local). If not, after go-ahead: `git push production main` (Mia or agent **only if she asks to push**).
- [ ] Google Cloud: service account + Gmail API + Admin SDK; numeric Unique ID. Steps in `docs/MAIL-SETUP.md`.
- [ ] Google Admin (the **customer** domain): Security → Domain-wide delegation, scopes exactly:

```
https://www.googleapis.com/auth/gmail.readonly
https://www.googleapis.com/auth/gmail.send
https://www.googleapis.com/auth/admin.directory.user.readonly
```

- [ ] On the app box `.env` (SSH only if Mia asks):

```
GOOGLE_MAIL_WORKSPACE_CLIENT_EMAIL=...
GOOGLE_MAIL_WORKSPACE_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n"
GOOGLE_MAIL_WORKSPACE_CLIENT_ID=(numeric Unique ID)
```

OAuth client `GOOGLE_MAIL_CLIENT_ID` / `_SECRET` must already exist (admin identity). Two keys, two jobs.

- [ ] Smoke: Email → Settings → Connect Workspace → pick a **shared** address, not a person.

**Verification:** a Workspace shared inbox lists in Email. No mail stored in MySQL.

**Anti-pattern:** do not dump Directory into mailboxes automatically.

---

## Phase 5: Mutual box alerts

**Question 3:** yes. The websites box (`32.194.222.151`, already the backup remote in `deploy/backup.sh`) and the app box can watch each other. Neither Health screen nor EzBizi's own mail is the shout path — the **other** box notices.

### `/up` is already registered

`bootstrap/app.php` sets `health: '/up'`. Do not add a second public health URL.

Laravel's default `/up` returns 200 once the framework boots. **Nothing in this repo listens on `DiagnosingHealth`.** With MySQL stopped, `/up` is still 200. A peer watchdog built on that probe proves nginx and PHP-FPM, not that EzBizi can read its database.

### Design

1. **App readiness:** keep `GET /up`. Register a listener on `Illuminate\Foundation\Events\DiagnosingHealth` that runs a cheap `select 1` against the default connection. If that throws, `/up` is not 200. That is the probe the websites box curls.
2. Document in `docs/DEPLOY.md` and in the watch script header: `/up` means nginx + PHP-FPM + MySQL answered. It does **not** mean backups, mail, or TLS are healthy — those stay on the staff Health screen.
3. **Websites liveness:** a tiny static file or nginx stub on `https://www.ezbizi.com`. Prefer a dedicated path like `/ezbizi-alive.txt` that is not a full page render.
4. **App box timer** (systemd, every 5 minutes): curl the websites alive URL. On failure, write `storage/app/peer-status.json` (`ok` / `failed` / `at`) and run `OnFailure=` (existing backup alert address **only as a fallback**; primary shout is the peer).
5. **Websites box cron/systemd:** curl `https://app.ezbizi.com/up`. On failure, write a file under the backup user's home (that box can already receive `contact@`; if **its** postfix can send on 587 to Google, that is an out-of-band path that does not depend on Laravel). If it cannot send, the failure file is still visible on the next backup pull and on a one-line SSH check.
6. **Health screen addition:** `peer` object (`host`, `ok`, `checked_at`, `problem`) so a human who *does* look still sees it. A missing verdict is a failure, same as backups.
7. **OnFailure=** for `php8.4-fpm`, `nginx`, `mysql`, `named` on the app box: a oneshot that curls the websites box with a “unit died” query or writes the peer file. If PHP is down, Laravel cannot mail; the websites box's missed `/up` is the shout.

### Files (expected)

- Modify: bootstrap / a service provider — `DiagnosingHealth` → `select 1`
- Create: `deploy/watch-peer-websites.sh` (runs on app box)
- Create: `deploy/watch-peer-app.sh` (runs on websites box)
- Create: matching systemd unit + timer examples under `deploy/`
- Modify: `HealthController::index` — add `peer`
- Modify: `docs/DEPLOY.md` — `/up` is readiness (nginx, PHP, MySQL), not the Health screen
- Test: `tests/Feature/OperationalHealthTest.php` — missing peer file counts as failure; fresh `ok` does not
- Test: `/up` fails (or the DiagnosingHealth listener throws) when the default connection cannot `select 1` — fake the connection in-process; do not stop production MySQL

**Verification:** prove with a fake stale `peer-status.json` in tests and a dry-run curl documented in DEPLOY.md. Stop nginx on a **non-production** rehearsal only if Mia provides a window.

**Anti-pattern:** do not make `/api/v1/health` public. Do not have the dying app be the only thing that can report it is dead. Do not treat 200 on `/up` as “the product is healthy” without the DB ping.

---

## Phase 6: Platform mail live

Blocked on Phase 3 (test message in Mia's inbox).

- [ ] Put SMTP values in production `.env` (SSH only with permission). `MAIL_FROM_ADDRESS=contact@ezbizi.com`.
- [ ] Health → test email.
- [ ] Confirm `MailStatus::delivers()` is true on that screen.

No application code unless Health still names the wrong missing key after `MAIL_MAILER=smtp` (then fix `MailStatus` with a test).

---

## Phase 7: Job `scheduled_for` is wall time

**Decision:** store the technician's clock, in the franchise timezone. An instant moves on DST.

### Design

- Stop treating `scheduled_for` as a UTC instant via Eloquent `datetime` cast.
- Store a naive local datetime (franchise wall clock) **and** interpret it with `franchises.timezone`.
- “Coming up” compares in that zone, not in `config/app.php` UTC.
- Automations that **write** this field must write the same wall time the API returns. Changing the cast without this path is a silent semantics change.

Copy timezone validation from wherever franchises already validate `timezone` (three levels per TODO #20).

**Files (expected)**

- Modify: `app/Models/Job.php` cast
- Modify: `app/Http/Controllers/Api/JobsController.php` — validate, present, `job.scheduled` payload (`toIso8601String()` is instant-shaped today)
- Modify: `app/Support/Automations/ActionRunner.php` — automations write `scheduled_for`
- Modify: `app/Http/Controllers/Api/AutomationsController.php` — catalogue / writable fields
- Modify: `app/Support/Data/ResourceMap.php` — filters and conditions
- Modify: `app/Support/Dashboard/Widgets/UpcomingJobs.php` — `where('scheduled_for', '>=', now())` is UTC-instant comparison
- Modify: `client/lib/screens/jobs_screen.dart`
- Modify: `client/lib/screens/dashboard_screen.dart` (Coming up formatting)
- Modify: `client/lib/core/automation_catalogue.dart`
- Test: a job at 2026-03-08 09:00 in `America/Chicago` (DST spring-forward) still displays and sorts as 09:00 Chicago, not 08:00 or 10:00
- Test: an automation that sets `scheduled_for` produces the same wall time `GET /jobs/{id}` returns

**Do not** build the Jobs edit screen (parked). Only the field meaning and any API that already accepts `scheduled_for`.

---

## Phase 8: Signup confirmation email

Blocked on Phase 6.

`User` already has `email_verified_at`. Signup today issues a session and allows writes. That is pinned by `SignupTest::test_the_account_can_read_and_write_immediately_with_no_verification_step` (`tests/Feature/Api/SignupTest.php:322` on `80acccc`). **Replace that test; do not add a second pin beforehand.**

### Design (recommended)

Create the org/user/company/franchise in one request (§3, §10). Do **not** split the form. Send a confirmation link. Writes stay refused until `email_verified_at` is set (same idea as the current test, inverted). `/me` may still read. Password reset remains the recovery path.

Replacement tests:

- unverified signup cannot `POST /api/v1/customers`
- token from the mailed link sets `email_verified_at` and then the write succeeds
- Mail fake (`MAIL_MAILER=array`) asserts a message was queued/sent

R8 copy: “Check your email to confirm this address.” No mention of SES.

**Anti-pattern:** adding the gate while `MailStatus::delivers()` is false. That locks every signup.

---

## Phase 9: Outlook (Microsoft) as fourth mailbox provider

Fourth provider beside `gmail_oauth`, `gmail_workspace`, `imap`. **Per-inbox sign-in**, like Gmail OAuth — not another domain-wide grant.

### Design (copy Gmail, do not share its callback)

1. `MailboxProvider::MicrosoftOauth = 'microsoft_oauth'`
   - `label`: `Microsoft 365`
   - `connectsBy`: `sign_in`
   - `storesAPassword`: false
   - `caution`: shared team inboxes only; do not connect a person's own mailbox
2. `MailboxReaders::forProvider` **must** grow an arm. Incomplete `match` is a bug.
3. `CannotReadMailbox` provider sentences: add this case.
4. New callback route, e.g. `GET /api/v1/email/microsoft/callback` — public, HTML, state row + PKCE. Do not authenticate the callback (same reasons as GoogleMailAuthController).
5. `oauth/start` and `oauth/status` stay generic; a resolver chooses Google vs Microsoft from `mailbox.provider`.
6. Graph delegated scopes: `Mail.Read`, `Mail.Send`, `offline_access`, `openid`, `email`. No mail wipe/delete scopes.
7. Access tokens expire; `offline_access` means a refresh token will arrive. Copy `tests/Feature/Api/GoogleMailTokenRefreshTest.php` for refresh, expiry, and a revoked grant. Do not leave refresh as an implied follow-on.
8. Live Graph reads; nothing stored except tokens/metadata.
9. Azure app registration is **Mia's console work**. Document in `docs/MAIL-SETUP.md` with:
   - https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-register-app
   - Redirect URI must match character for character: `https://app.ezbizi.com/api/v1/email/microsoft/callback`
10. Env: `MICROSOFT_MAIL_CLIENT_ID`, `MICROSOFT_MAIL_CLIENT_SECRET`, optional `MICROSOFT_MAIL_REDIRECT_URI`.
11. Flutter: catalogue already prints `connects_by`; settings should not call Google `oauth/start` for this provider (same as Workspace test in `email_settings_test.dart`).

**TDD first files**

- `tests/Feature/Api/MicrosoftMailTest.php` — catalogue publishes the provider; start refuses when client id missing (sentence, not exception); callback without state refuses; connecting does not persist message bodies.
- A refresh test modelled on `GoogleMailTokenRefreshTest`.
- `Http::fake()` full Graph URLs only.

**Anti-pattern:** IMAP to Outlook.office365.com as a fake “Microsoft provider”. That stores a password. This provider is OAuth.

Until Azure ids exist, the Email module **refuses to start** a sign-in and says so (`notReadyBecause()` pattern).

---

## Phase 10: Automations leftovers

Do **not** rebuild. The 2026-08-06 TODO is stale.

Already present: GET detail, edit tab, version history, “Put this one back”, run error text, condition trace.

- [ ] Walk `automations_screen.dart` / `automation_builder.dart` against the TODO row: empty condition row still saveable? stale text after deleting a condition? “is one of” still broken?
- [ ] Fix only what still reproduces, each with a Flutter or PHPUnit test that failed first.
- [ ] Update the TODO module row to match reality.

---

## Phase 11: Contract field `is_expiry`

**Fourth boolean** on `FieldDefinition` (constructor already has `key, label, type, options, filledBy, required, sensitive, retired`). Default **false** (safe direction, same as the other three booleans). Do not check a “seventh property” count.

Must be threaded through: `from()`, `toArray()`, `comparableAttributes()`, FieldSet validation, onboarding, contract diff, renderer, stored `contract_versions.fields` JSON.

Then `HrDashboardController` “Expiring certifications” can count date fields with `is_expiry` true whose value falls in the window. Until templates mark one, the panel stays honest (“none marked as expiry”), not “Not built yet” while guessing.

**Tests:** a date field without the flag is not an expiry; with the flag it is; older JSON without the key is false.

---

## Phase 12: Leaving as a dated event

Do **not** use `audit_events` or `automation_events`.

### Design (recommended)

Table `employment_events`: `user_id`, `kind` (`started` | `left`), `occurred_on` (date), `created_at`, tenant id. Deactivate writes `left` and sets `users.is_active = false`. Reactivate writes `started` and sets `is_active = true`. History is kept.

**Backfill:** do **not** invent `left` rows from `users.updated_at`. Anyone already `is_active = false` on the day this ships has no leaving date. The widget says leaving history starts on the migration date (R8: a sentence on the panel, not a class name). `started` may still be seeded from invitation accepted / `users.created_at` for people who are active — that timestamp is a real join date, not an approximation.

`StartersAndLeavers`: both halves honour the period; `period_applies_to` becomes `['Joined', 'Left']`. Inactive people with no `left` event still appear on the undated “no longer active” count so the panel does not disagree with Records.

Onboarding / Records: creating a person still uses invitation accepted / `users.created_at` as the first `started` event so the dashboard and Records cannot disagree.

**Tests:** leave in March, rehire in April, “left this month” in March still lists them; current inactive list does not. A fixture user who was inactive before the migration has no `left` row and is counted as inactive, not as “left this month.”

---

## Phase 13: i18n foundation (§8)

Largest phase. Greenfield: no `lang/`, no `client/lib/l10n`, no `intl` in `client/pubspec.yaml`. Split so the server can land without the client.

### 13a — server

- Strings are **rows** (or JSON documents) keyed by stable ids, not compiled into the Flutter client as the source of truth.
- Organization default language; per-user override (build plan §8).
- Seed `en` and `es` for the shell keys only.
- Admin can add a language later **without a deploy** (fill-in UI can be a later slice).
- Franchise timezone stays franchise-level. Relabel “Franchise” already exists as `franchise_label` — do not invent a second relabel system.

**Tests:** org `es` + user override `en` returns English; unknown key falls back; adding a locale row does not require a migration of PHP enums.

### 13b — client

- Load catalogue from API at session start; missing key falls back to English, never to a class name.
- Login / sidebar / Settings only. Email, HR, Automations translations follow later.

**Show designs before a new translations admin tab** (StartUp.md §6). Mockups first if that tab is in scope; otherwise seed-only v1 with no new tab.

**Out of v1:** translating Email, HR, Automations in full.

---

## Final verification

- [ ] `cd client && flutter analyze && flutter test` for any client change.
- [ ] Backend: MySQL `ezbizi_testing` from Phase 1. Do not claim the deploy gate is green from sqlite.
- [ ] `MailboxReaders::forProvider` has an arm for every `MailboxProvider` case (grep both).
- [ ] No secrets in git (`git diff` / scan without piping to `head`).
- [ ] STATUS numbers block untouched by hand.
- [ ] TODO matches shipped behaviour.
- [ ] No SSH/deploy unless Mia asked in that session.

---

## Execution handoff (after Mia go-ahead)

Plan saved to `docs/superpowers/plans/2026-08-18-open-items.md`. Review beside it: `docs/superpowers/plans/2026-08-18-open-items-REVIEW.md`.

Two execution options:

1. **Subagent-Driven** — fresh subagent per phase, review between phases.
2. **Inline** — this session, executing-plans, checkpoints after each phase.

Workspace go-live and SES `.env` still need Mia on Google Admin / AWS and an explicit deploy/SSH grant.

Suggested first work after grant: **Phase 1 (local MySQL)** so every later test is honest, then **Phase 2 (docs)**, with Mia on SES and Google DWD in parallel.
