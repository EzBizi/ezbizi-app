# EzBizi — open items

Owner key: **[YOU]** = only you can do it · **[CLAUDE]** = I'll do it · **[BOTH]**

Numbered from 1, and **no longer sharing a sequence with `../AWS-MIGRATION-TODO.md`**. They
are separate projects; the shared numbering only produced gaps whenever an item closed.

## Operational

| # | Item | Owner | Notes |
|---|---|---|---|
| 1 | **Outbound mail (SES SMTP)** | **[YOU]** then **[CLAUDE]** | Code is live. AWS still blocks outbound 25; **587 is open**, so SES SMTP is the path — not the Laravel `ses` SDK, and not relaying through the websites box. Console steps below. Until credentials are on the box `.env`, Health reports the transport cannot deliver. `contact@` cannot reply until this is done. **Box check 18 Aug 18:05 UTC:** `MAIL_MAILER=ses`, `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` present-and-empty, leftover `MAIL_HOST=127.0.0.1` / `MAIL_PORT=2525` (inert under `ses`, misleading). Switch to the SMTP block below; do not fill IAM keys for the `ses` mailer. Signup verification (#22) stays out until a Health-screen test message arrives. |
| 2 | ~~**`hello@ezbizi.com`**~~ → **`contact@ezbizi.com`** | ✅ | **Done.** Mailbox created on the websites box, MX published, and all six CTAs on ezbizi.com repointed. Receiving is verified — `contact@` accepted, `nobody@` rejected. **It cannot reply until #1 is settled.** Password in `_secrets/Application-credentials.md`. |
| 3 | **`ns2` on a second machine** | **[BOTH]** | **On hold at your request.** Primary half done: ns1 offers TSIG-authenticated transfers and notifies `32.194.222.151` (`deploy/named-primary-ezbizi.conf`, key in `_secrets/`). The secondary half is not a config paste — that box runs BIND under Plesk, chrooted, from a `named.conf` Plesk regenerates, so a hand-added zone is erased on its next DNS change anywhere. Either add `ezbizi.com` to Plesk as a DNS-only domain and `plesk bin dns --set-secondary` (supported, but Plesk slaves transfer by source IP, so ns1 drops TSIG for that host), or put the secondary somewhere that is not Plesk. |
| 22 | **Signup has no email verification** | **[CLAUDE]** | **Chosen 2026-08-18: a confirmation email**, still blocked by #1. The pin test exists at `SignupTest::test_the_account_can_read_and_write_immediately_with_no_verification_step`. The gate must not fire while mail does not deliver. Do not ship this until a Health-screen test message has actually arrived. |
| 28 | **Google Workspace go-live** | **[YOU]** then **[CLAUDE]** | Code is live. Needs Cloud Console (OAuth + service account) and Google Admin domain-wide delegation, then keys on the box `.env`. Console steps below. Shared inboxes only — do not pick a person's mailbox. |
| 29 | **Outlook / Azure app** | **[YOU]** then **[CLAUDE]** | Code is live (PKCE, Graph, token refresh). Needs an Entra app registration, then keys on the box `.env`. Console steps below. Shared team inboxes only. |
| 30 | **EZ Leads — GBP API quota** | **[YOU]** | **Blocked until 2026-10-24.** Google requires the applicant to manage a verified Business Profile **active 60+ days**; EzBizi's went live 2026-08-25. Nothing shortens this. Apply on or after that date. Approval shows as quota: **0 QPM = refused, 300 QPM = granted** — it is a gate, not a rate limit. Console steps below. |
| 31 | **EZ Leads — Google Ads API Basic Access** | **[YOU]** then **[CLAUDE]** | **Submitted 2026-08-25**, ~5 business days to initial review. Token is currently **Test level** and cannot touch a real account. MCC `706-109-4046`. Brand verification (its hard prerequisite) is **done**. One step may still be outstanding: the developer token must be *associated* with project `ezbizi-506117` by making one Ads API call with OAuth credentials from that project — the call may fail, it only has to be made. |
| 32 | **EZ Leads — `adwords` scope verification** | **[BOTH]** | **Module shipped 2026-08-25, so this is now recordable.** Google requires a YouTube demo video showing the scope in use across every OAuth client on the project. `business.manage` needs none of this — Google classifies it **non-sensitive**, confirmed on the Data Access page 2026-08-25. Only `adwords` is sensitive. Record against a staging environment or test project: demoing unverified scopes on production traffic consumes the **100-user lifetime cap, which cannot be reset**. |
| 33 | **EZ Leads — OAuth clients** | **[YOU]** then **[CLAUDE]** | Two Web application clients on `ezbizi-506117`, kept separate so a customer can connect a Business Profile without a Google Ads account. Redirect URIs must match the driver's derived callback character for character. Keys go on the box `.env` as `GBP_CLIENT_ID`/`_SECRET` and `GOOGLE_ADS_CLIENT_ID`/`_SECRET` — never in git. Console steps below. |

## Mail console work (you) — recorded 2026-08-18

Nothing here is committed. Values go in the live `.env` on the app box, never in the repo.
When they exist, paste them here or say they are on the box.

### SES — platform mail (TODO #1)

Opens password resets, invitations, `contact@` replies, and unblocks #22.

- Home: https://us-east-1.console.aws.amazon.com/ses/home?region=us-east-1#/homepage
- Identities: https://us-east-1.console.aws.amazon.com/ses/home?region=us-east-1#/identities
- Leave the sandbox: https://us-east-1.console.aws.amazon.com/ses/home?region=us-east-1#/account/production-access
- SMTP credentials: https://us-east-1.console.aws.amazon.com/ses/home?region=us-east-1#/smtp

1. Create a **domain** identity for `ezbizi.com`. Publish the DKIM / verification records it gives you on ns1. Wait until Verified.
2. Request **production access**. Reason: transactional mail for EzBizi (resets, invitations) from `ezbizi.com`. Sandbox can only send to addresses you verify by hand.
3. **Create SMTP credentials.** Download the user and password once.

On the box (SMTP on 587 — not `MAIL_MAILER=ses`):

```
MAIL_MAILER=smtp
MAIL_HOST=email-smtp.us-east-1.amazonaws.com
MAIL_PORT=587
MAIL_USERNAME=<smtp username>
MAIL_PASSWORD=<smtp password>
MAIL_FROM_ADDRESS=noreply@ezbizi.com
MAIL_FROM_NAME=EzBizi
```

`MAIL_FROM_ADDRESS` must be an identity SES has verified. The receiving mailbox
is `contact@ezbizi.com`. Platform *from* in this block is `noreply@ezbizi.com` —
keep them in agreement with whatever identity you actually verify; do not leave
the box on `MAIL_MAILER=ses`. Then send a test from Development → Health.

### Google Workspace (TODO #28)

Connect the customer's domain once, then pick shared inboxes from the Directory.
Per-inbox Gmail OAuth (`GOOGLE_MAIL_CLIENT_ID` / `_SECRET`) is still required — that is how
an administrator proves they run the domain. The service account is what opens listed
mailboxes afterwards.

**Google Cloud** — https://console.cloud.google.com/  as **development@ezbizi.com**, project `ezbizi-506117`.

1. Enable **Gmail API**, **Admin SDK API**, and **Google Workspace Marketplace SDK** (Marketplace SDK is on).
2. OAuth consent — **External** if customer Workspaces will install from the Marketplace (Google review). Internal only works inside ezbizi.com. https://console.cloud.google.com/auth/overview?project=ezbizi-506117
3. Credentials → **OAuth client ID**, type **Web application**. Redirect URI, character for character: `https://app.ezbizi.com/api/v1/email/google/callback` — https://console.cloud.google.com/apis/credentials?project=ezbizi-506117
4. Same page → **service account**. Copy the email (`…@….iam.gserviceaccount.com`), the numeric **Unique ID** (what Admin asks for, not `….apps.googleusercontent.com`), and a JSON key's private key.
5. **Marketplace listing (preferred install) — parked.** Needs a proper application icon first (placeholders in `assets/marketplace/` are not it). Then App configuration **Public + Admin only**, web app `https://app.ezbizi.com`, the four scopes below. Do not save as Private — that cannot be changed. Store listing: `https://www.ezbizi.com/terms/`, `https://www.ezbizi.com/privacy/`, a 1280×800 Email screenshot. Then `GOOGLE_MAIL_MARKETPLACE_URL` on the box. Links and the rest of the checklist: local board http://localhost:8787 (track Workspace Marketplace Admin install). App config: https://console.cloud.google.com/apis/api/appsmarket-component.googleapis.com/googleapps_sdk_oauth?project=ezbizi-506117 Store listing: https://console.cloud.google.com/apis/api/appsmarket-component.googleapis.com/googleapps_sdk_publish?project=ezbizi-506117

**Google Admin** (the customer's Workspace) — https://admin.google.com/ac/owl/domainwidedelegation

Security → Access and data control → API controls → Domain-wide delegation → Add new. Paste the numeric Unique ID. Scopes, exactly these four:

```
https://www.googleapis.com/auth/gmail.readonly
https://www.googleapis.com/auth/gmail.send
https://www.googleapis.com/auth/gmail.modify
https://www.googleapis.com/auth/admin.directory.user.readonly
```

On the box:

```
GOOGLE_MAIL_CLIENT_ID=....apps.googleusercontent.com
GOOGLE_MAIL_CLIENT_SECRET=...
GOOGLE_MAIL_WORKSPACE_CLIENT_EMAIL=ezbizi@….iam.gserviceaccount.com
GOOGLE_MAIL_WORKSPACE_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n…\n-----END PRIVATE KEY-----\n"
GOOGLE_MAIL_WORKSPACE_CLIENT_ID=123456789012345678901
```

In the app: Email → Settings → Connect Workspace as a Workspace admin. Pick `info@` / `jobs@`, not a person.

### Azure / Microsoft 365 (TODO #29)

- App list: https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade
- Walkthrough: https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-register-app

1. New registration. Supported accounts: **Accounts in any organizational directory (multitenant)** — sign-in uses `login.microsoftonline.com/common`.
2. Authentication → Add a platform → **Web**. Redirect URI, character for character: `https://app.ezbizi.com/api/v1/email/microsoft/callback`
3. Certificates & secrets → New client secret. Copy the Value once.
4. API permissions → Microsoft Graph → **Delegated**: `Mail.Read`, `Mail.Send`, `offline_access`, `User.Read`. Do not add `Mail.ReadWrite`.

On the box:

```
MICROSOFT_MAIL_CLIENT_ID=<application (client) id>
MICROSOFT_MAIL_CLIENT_SECRET=<secret value>
```

`MICROSOFT_MAIL_REDIRECT_URI` only if it must differ from `APP_URL`. Then Email → Settings should offer Outlook.

## EZ Leads / Google console work — recorded 2026-08-25 (TODO #30–#33)

All of it under `development@ezbizi.com` on project **`ezbizi-506117`** (number `247590132639`,
org `ezbizi.com` / `940564858837`). Keys go on the box `.env`, never in the repo.

**Already done, so nobody redoes it:**

- EzBizi's own Business Profile — created and **verified 2026-08-25**, owned by `info@ezbizi.com`.
- GBP **Organization account** `EZBIZI LLC` under `development@`. Google requires the creating
  account to own or manage **no locations**, which is why `info@` holds the listing and
  `development@` holds everything else. One organization per company; there is no second attempt.
- Google Ads **MCC `706-109-4046`**, developer token issued (Test level).
- **Brand verification passed** — the hard prerequisite for Ads Basic Access. Consent screen is
  External and In production, branding verified and shown to users.
- All six APIs enabled: Ads, Local Services, and the four Business Profile ones. Note **enabling an
  API and having quota are separate gates** — the Business Profile four enable happily on zero
  quota and simply fail at call time.
- `business.manage` declared and saved as a **non-sensitive** scope. No approval needed.

**Left to do:**

- GBP API quota — https://support.google.com/business/contact/api_default, select
  **"Application for Basic API Access"**. On or after **2026-10-24** (#30).
- Two OAuth clients — https://console.cloud.google.com/auth/clients?project=ezbizi-506117 (#33).
- Associate the developer token with the project: one Ads API call using OAuth credentials from
  `ezbizi-506117`. Success or failure does not matter, only that the call is made (#31).
- `adwords` scope + demo video — https://console.cloud.google.com/auth/scopes?project=ezbizi-506117
  once the module exists (#32).

Design and application material lives outside the repo in `H:\Development\Leads\`:
`EZ-LEADS-DESIGN.md`, `EZ-LEADS-MODULES.md`, `EZBIZI-GOOGLE-ACCOUNT-SETUP.md`, and the design
document submitted to Google, `EzBizi-GoogleAdsAPI-DesignDocument.pdf`. That folder also holds
Rodrigo's live A1 Leads `.env` — real production credentials, deliberately never committed.

## Decisions only you can make

| # | Question | What it blocks |
|---|---|---|
| 4 | **Are the state territories separate legal entities?** | **2026-08-18 — not ours to decide.** Each client can differ. Keep the current franchise shape under one organization. Do not encode a global legal rule. Revisit only if a specific tenant needs its own filing entity, because that is when #5's shared counter would be wrong. |
| 5 | ~~Which entity owns the invoice numbering sequence~~ (§5.2) | ✅ **Decided 2026-08-06: one counter for the whole ORGANIZATION.** Connecticut issues 1001, Georgia 1002, Texas 1003 — one unbroken run across the business. Revisit only if #4 turns any territory into its own filing entity, because a shared counter across separate legal entities is what tax authorities object to. |
| 6 | **Pricing metric** (§13) | Not urgent — users are metered from day one either way. |
| 9 | **What does a demo organization suppress?** | **Parked 2026-08-18 — not yet.** `is_demo` is still display-only. |

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
| 19 | §8 i18n · §11.4 public API · §12 theming | **i18n foundation 2026-08-18:** strings as data (`ui_locales` / `ui_strings`), en+es seeded, org setting + user override, login/sidebar/Settings. Public API and extra theming still open. |

### Deferred, deliberately

| # | Question | Why it is parked |
|---|---|---|
| 20 | **Is a scheduled job an instant or a local wall time?** | **Decided 2026-08-18: wall time** in the franchise timezone. An instant moves on DST. Screens landed 2026-08-19 and write that wall clock. |
| 21 | **Does a deactivated franchise's history stay in roll-ups?** | **Decided 2026-08-18: yes.** They may re-activate. History stays. |
| 26 | **Can a contract field say that a date IS an expiry?** | **Decided 2026-08-18: yes.** Fourth boolean on `FieldDefinition` (`is_expiry`), default false. Older JSON without the key is not an expiry. |
| 27 | **Is leaving a dated event?** | **Decided 2026-08-18: yes.** `employment_events`, not `users.is_active` alone, and not `audit_events`. History begins at the migration — do not invent `left` dates from `updated_at`. |

## Base UI — not module work

Everything else the audit found belongs to a module and waits for that module. These two do
not: they are the shell and the front door.

| # | Item | Owner |
|---|---|---|
| ~~7~~ | ✅ ~~**Settings**~~ — a typed value is discarded unless you press Enter; the three JSON settings render as a box that rejects what it just displayed; every control looks editable to people without `organizations.edit`, who then get a 403 on save; company-level scope is supported end to end and unreachable from the UI; the `help` text the server sends for every setting is never shown. | ✅ **Done 2026-08-06** — all five. |
| ~~8~~ | ✅ ~~**Login**~~ — somebody with accounts in two organizations, one with an authenticator, is silently signed into the other. | ✅ **Done.** Picker + `needs_code`. `AuthApiTest::test_an_account_behind_an_authenticator_is_not_silently_skipped`. Closed 2026-08-18. |
| 23 | **Mutual box alerts — DONE 2026-08-18 19:49 UTC.** Both timers installed and running on a 5-minute cadence. App box watches `https://panel.triplexhost.net`; websites box watches `https://app.ezbizi.com/up`. `OnFailure=` confirmed on php8.4-fpm, nginx, mysql, named. **The original design was wrong:** `www.ezbizi.com` resolves to 100.24.188.241 — the app box itself — so the planned alive-file check would have been the app asking itself whether it was alive, and no `ezbizi-alive.txt` was ever needed. Peer URL is a systemd drop-in so a redeploy cannot restore the self-check, and `EZBIZI_PEER_URL` is in the box `.env` so Health names the host it watches. See `docs/DEPLOY.md`. | **[DONE]** |
| 24 | **§18 Phase 0 item 4 was never done.** Webmin 2.653 runs as root and answers `0.0.0.0:10000` as well as through nginx; `ufw` is inactive; `fail2ban` is inactive. That box is also MySQL, the backup source, the GPG passphrase, the ACME account key and the sole nameserver. | **[BOTH]** **Parked 2026-08-18 — not for now.** |
| ~~25~~ | ✅ ~~**Users, roles, invitations — none of it exists.**~~ | ✅ **Done.** Roles, HR people, invitations and onboarding exist; production has two users. Closed 2026-08-18. |

## Module work — deferred until each module is built

From the 2026-08-06 audit. Confirmed real, but each belongs to a module rather than to the
base UI, so they are recorded here rather than fixed piecemeal.

| Module | What is wrong |
|---|---|
| **Customers** | ✅ **Screens finished 2026-08-19.** A row opens a panel: edit (including address), deactivate / reactivate, delete, and reassign-owner as its own audited step. Create asks for an address. `Inactive` is produced by Deactivate. |
| **Jobs** | ✅ **Screens finished 2026-08-19.** A row opens a panel: edit status / date / notes / price / duration, delete. The list searches number, title and customer, and can filter by status. `scheduled_for` is wall time (TODO #20). A failed customer search says the request failed rather than reading as "no such customer". |
| **Automations** | Read-back, edit, rollback ("Put this one back"), failed-run reason and condition trace exist. Empty condition rows are refused on save (client and API). Stale text after deleting a condition was already fixed by keyed controllers. "is one of" splits on commas. |

- ✅ **Login no longer skips the authenticator org** — *2026-08-18.* Closed TODO #8.
- ✅ **Users, roles, invitations exist** — *2026-08-18.* Closed TODO #25. HR + Roles; production has two users.
- ✅ **Local MySQL `ezbizi_testing`** — *2026-08-18.* This machine can run `php artisan test` against MySQL 8.4. GD and Dompdf still missing.
- ✅ **Microsoft 365 as a fourth mailbox provider** — *2026-08-18.* Catalogue, PKCE sign-in, Graph reads, token refresh. Azure app registration is still console work (TODO #29).
- Workspace DWD and SES SMTP are the same kind of leftover: **TODO #28 and #1**, steps on this page under Mail console work.

## Done

- ✅ **Sidebar icons no longer vanish for a regular (cached) session** — *2026-08-21.*
  `FontManifest.json` was on the "hashed assets are immutable" nginx list even though
  Flutter does not hash it. A browser that had loaded the app kept a 30-day copy
  pointing at `MaterialIcons-Regular.otf`; that file is renamed per build, and the
  SPA fallback then served `index.html` as the font. Incognito looked fine. Exact
  no-cache for the manifests, missing `/assets/` 404s, and `index.html` (already
  no-cache) refetches the manifest before the engine starts so a tab that was
  already open unsticks on the next load.

- ✅ **Customers and Jobs screens finished** — *2026-08-19.* A customer row is no longer a dead end (detail, address, deactivate, delete, reassign-owner). A job can be opened, edited, deleted, searched and filtered. A failed customer search in the job form says the request failed.

- ✅ **Inspection module built** — *2026-08-19.* Companies design named inspections in Builder,
  technicians fill them in from New Inspection, and finished work lands in Generated Reports.
  Versions are **written once**, so publishing an edit never restyles a report already
  submitted — pinned by a test that republishes under a different preset and reopens the old
  report. Drafts are personal and one per person per inspection per customer/job, keyed by a
  `scope_key` string rather than a nullable `job_id`, because MySQL allows several NULLs in a
  unique index and two customer-only drafts would have hidden behind that. Reports are frozen
  apart from two holes stated in the model: `archived_at`, and §9 erasure. Named inspections
  are granted per role the way inboxes are, not as a permission per inspection. A bound
  `scheduled_for` freezes as rendered text in the franchise timezone, so correcting a timezone
  later cannot rewrite an old report. All four automation triggers raised.
  **Deployed 2026-08-19** (release `20260819130615`). Named-org demo
  seed is `inspection:seed-demo {organization}` and refuses a database whose name
  does not contain `testing`. Plan `docs/superpowers/plans/2026-08-18-inspection.md`.

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
