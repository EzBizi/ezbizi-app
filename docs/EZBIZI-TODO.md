# EzBizi — open items

Owner key: **[YOU]** = only you can do it · **[CLAUDE]** = I'll do it · **[BOTH]**

Numbered from 1, and **no longer sharing a sequence with `../AWS-MIGRATION-TODO.md`**. They
are separate projects; the shared numbering only produced gaps whenever an item closed.

## Operational

| # | Item | Owner | Notes |
|---|---|---|---|
| 1 | **Outbound mail (SES SMTP)** | ✅ | **Sending, verified 2026-08-28 by evidence rather than by a test button.** The box is on `MAIL_MAILER=smtp`, `email-smtp.us-east-1.amazonaws.com:587`, from `noreply@ezbizi.com`, with the username and password set. Google's DMARC aggregate report for **27 Aug** shows **8 messages, 100% pass** — DKIM aligned on `ezbizi.com` and SPF passing on `mail.ezbizi.com`, from SES IPs, under `p=reject`. AWS still blocks outbound 25; 587 remains the path, and the Laravel `ses` SDK is still not it. **Still in the SES sandbox** — see the production-access appeal note below. |
| 2 | ~~**`hello@ezbizi.com`**~~ → **`contact@ezbizi.com`** | ✅ | **Done.** Mailbox created on the websites box, MX published, and all six CTAs on ezbizi.com repointed. Receiving is verified — `contact@` accepted, `nobody@` rejected. **It cannot reply until #1 is settled.** Password in `H:\Development\Credentials\AWS\Application-credentials.md`. |
| 3 | **`ns2` on a second machine** | **[BOTH]** | **On hold at your request.** Primary half done: ns1 offers TSIG-authenticated transfers and notifies `32.194.222.151` (`deploy/named-primary-ezbizi.conf`, key in `H:\Development\Credentials\AWS\`). The secondary half is not a config paste — that box runs BIND under Plesk, chrooted, from a `named.conf` Plesk regenerates, so a hand-added zone is erased on its next DNS change anywhere. Either add `ezbizi.com` to Plesk as a DNS-only domain and `plesk bin dns --set-secondary` (supported, but Plesk slaves transfer by source IP, so ns1 drops TSIG for that host), or put the secondary somewhere that is not Plesk. |
| 22 | **Signup has no email verification** | **[CLAUDE]** | **Chosen 2026-08-18: a confirmation email**, still blocked by #1. The pin test exists at `SignupTest::test_the_account_can_read_and_write_immediately_with_no_verification_step`. The gate must not fire while mail does not deliver. Do not ship this until a Health-screen test message has actually arrived. |
| 28 | **Google Workspace go-live** | **[YOU]** then **[CLAUDE]** | Code is live. Needs Cloud Console (OAuth + service account) and Google Admin domain-wide delegation, then keys on the box `.env`. Console steps below. Shared inboxes only — do not pick a person's mailbox. |
| 29 | **Outlook / Azure app** | **[YOU]** then **[CLAUDE]** | Code is live (PKCE, Graph, token refresh). Needs an Entra app registration, then keys on the box `.env`. Console steps below. Shared team inboxes only. |
| 30 | **EZ Leads — GBP API quota** | **[GOOGLE]** | **Updated 2026-10-29.** Basic Access GRANTED 2026-10-28 (project 247590132639, 300 QPM). Jose reconnected all 28 profiles (20 A-1, 8 Everdoors) on EzBizi's own client 2026-10-29 and disconnected Rodrigo's interim grants. Listings work. **The legacy v4 API (mybusiness.googleapis.com) is still not released to our project** (enable → `servicemanagement.services.bind` denied); reviews, replies and media need it, so NEW reviews are paused (13,364 kept). Reply sent on case 3-3702000041562; Jose chose to wait for Google rather than reconnect the interim grant. Older history: docs/BUSINESS-PROFILE.md §9–9b. |
| 31 | **EZ Leads — Google Ads API Basic Access** | **[YOU]** then **[CLAUDE]** | **Submitted 2026-08-25**, ~5 business days to initial review. Token is currently **Test level** and cannot touch a real account. MCC `706-109-4046`. Brand verification (its hard prerequisite) is **done**, and was **re-verified 2026-08-26** after the official logo replaced the interim monogram — a logo change re-triggers verification, so do not touch branding mid-review without watching it go green again. One step may still be outstanding: the developer token must be *associated* with project `ezbizi-506117` by making one Ads API call with OAuth credentials from that project — the call may fail, it only has to be made. |
| 32 | ~~**EZ Leads — `adwords` scope verification**~~ | ✅ | **APPROVED 2026-09-18.** Google's Third Party Data Safety team approved the OAuth App Verification request for project `ezbizi-506117` (247590132639), scope `.../auth/adwords`. Filed 2026-09-16 with take 2 of the demo video after two bounces on discrepancies; the compliance sentence that went with it is *"The only write is appendLeadConversation: one reply a member of staff types and sends to a text enquiry."* **Two standing conditions, from the approval itself:** any change to the OAuth consent screen configuration requires a NEW verification request — so nobody edits branding, scopes or the support email on this project casually — and the verification cannot be inherited, so a new sensitive or restricted scope needs its own submission. This is the OAuth track only; **Standard Access (#31 / case 9-3038000041842) is a separate Google Ads track and is still open.** **Confirmed in the Console 2026-09-18**, not just in the email: Google Auth Platform → OAuth Overview reads *"Your app has been verified by Google"*, and Audience reads **Publishing status: In production**, User type **External**, with every Project Checkup row green. **The 100-user cap is now moot for this scope** — the Console states it *"does not apply if you are requesting only approved sensitive or restricted scopes"*, and the page still displays it (4 / 100 used) on a verified app, which is what made it look live. It would bite again only for a NEW unapproved sensitive scope. Token grant rate limit is 2,000 grants/day, ~3 in use. Original note follows. **Module shipped 2026-08-25, so this is now recordable.** Google requires a YouTube demo video showing the scope in use across every OAuth client on the project. `business.manage` needs none of this — Google classifies it **non-sensitive**, confirmed on the Data Access page 2026-08-25. Only `adwords` is sensitive. Record against a staging environment or test project: demoing unverified scopes on production traffic consumes the **100-user lifetime cap, which cannot be reset**. |
| 33 | ~~**EZ Leads — OAuth clients**~~ | ✅ | **Done 2026-08-26.** `EzBizi Business Profile` and `EzBizi Google Ads`, both Web application, on `ezbizi-506117`. Kept separate so a customer can connect a Business Profile without a Google Ads account. All five values are on the box `.env` and in `H:\Development\Credentials\Google OAuth - EZ Leads.md` — never in git. Both drivers report READY as `www-data`, and the redirect URIs the app derives match what is registered character for character. **Two traps cost an hour and are now in DEPLOY.md:** a release holds its own *copy* of `.env`, so editing the master only affects the next deploy; and `config:cache` must run as `ubuntu`, because `bootstrap/cache` is `ubuntu:ubuntu` and it fails silently as `www-data`. |
| 34 | ~~**HR glance over-counts new starters in the evening**~~ | ✅ | **Fixed 2026-08-31 in `9f91364`** — one line at `HrGlance.php:93` plus `tests/Feature/Dashboard/HrGlanceTest.php`, 65 lines, frozen clock. `startsOn` is a wall clock in the VIEW timezone and `created_at` is UTC, so a Carbon bound as its own local string and a UTC-5 organisation's lower bound sat five hours EARLY, pulling in starters from ~19:00 the previous evening. Seven sibling glances already converted; `JobsGlance:107` carried the rule in a comment. **Worth reading the commit message:** the first version of the test called the widget directly with an already-converted bound and would have passed against the bug — the same class as the tests this repo has shipped that passed against exactly what they claimed to catch. `StartersAndLeavers:65` was correctly left alone: `whereDate` on a DATE column has no timezone to get wrong. |
| 35 | **Six modules write no audit rows** | **[CLAUDE]** | **Enforced, not yet closed.** §2.2 is "every action" with one stated exception — the Lead Developer account, not a module. `scheduling`, `web_management`, `phone`, `fleet_management`, `inventory` and `services` can create/edit/delete and write nothing; fourteen other modules write 121 audit actions between them. Each of the six now carries `auditExemptBecause` saying plainly it is a gap rather than a decision, and `ModuleRegistryTest` fails if a seventh joins them silently — that is the half that was missing, and the only reason automations never rotted the same way. **Closing one is: add `audit->log()` / `logChange()` inside the same `DB::transaction` as the record and the outbox row (copy `JobsController`), then delete that module's `auditExemptBecause`.** A second test fails if the exemption outlives the gap, so the registry cannot keep claiming a module is exempt once it audits. Owned by each module's session, not by one person. |
| 36 | **Quoting and billing do not exist** | **[JOSE]** | **Jose 2026-08-31: not yet, but on the list.** There is no quoting, invoicing, billing, estimating or payment-taking anywhere in the product. Verified two ways on release `20260831170831`: 151 production tables searched for `quote` / `invoic` / `bill` / `estimate` return nothing, and no such module is registered among 26. The only `payment` match is `vehicle_payments` — Fleet recording what it paid for a van. **The public site claimed it until today** — the phrase had been copied into eight places plus a mock app shell showing an Estimates widget and a Finances tab, and `/google-ads-api/` told a Google compliance reviewer we manage billing, on the page arguing for API access. Removed in `6c67efb`, and `scripts/deploy-www.sh` now refuses to publish a page containing those terms. **So nothing is claiming it any more — this is a product gap, not a defect.** It is the largest single missing piece for a field-service product and every competitor has it. Reinstating the site copy is one line in `docs/WEBSITE-CLAIMS.md` once it is real. |
| 37 | ~~**App Review Notes false about permissions**~~ | ✅ | **Closed.** The notes live in `docs/apple-review-notes/ios.txt` and `macos.txt` and are pushed by `python scripts/asc-review-notes.py --push`, which reads them back after writing. Last push 2026-10-06 on 0.0.0363: permissions, account deletion, and registration (production serves `signup_open: true`). |

| 38 | **Egress filtering on the box — we now dial a hostname a CUSTOMER typed** | **[JOSE]** — an instance/security-group change, not code | Shipping with the tenant-SMTP transport (`79b5e142`). `TenantSmtpHost` resolves the host and refuses loopback, RFC1918, carrier NAT and link-local, but **we resolve and Symfony resolves again when it dials**, so a DNS record with a 1s TTL can answer publicly for the check and privately for the connection. That is DNS rebinding. **CORRECTED 2026-09-14, and the original wording of this row overstated it:** PHP CAN close this and the project already does. `ImapSocket` dials `ApprovedHost::socketTarget()` — `tcp://<the address that was actually checked>:<port>` — and passes the hostname as OpenSSL `peer_name`, so the certificate is still matched against the name. No lost verification, no egress rule needed. That closes rebinding outright on the Email module's SMTP and IMAP paths. What remains is narrower: `Mail::build()` hands the host to Symfony, which resolves it AGAIN, and Laravel's array config cannot carry an address plus a separate `peer_name` — so the gap is real only for the tenant-report path, whose fix is to send through the hardened socket instead. So this row is DEFENCE IN DEPTH for a class already handled, not the sole mitigation for a live hole. **Severity measured down, 2026-09-14:** the worst target is already hard. I checked the box myself — it is EC2 and **IMDSv2 is enforced**: an unauthenticated GET to `169.254.169.254/latest/meta-data/` returns **401**, and a token `PUT` returns **200**. A blind SMTP dial cannot do the PUT-with-token handshake, so it cannot read the instance IAM credentials. What remains reachable is internal services, not our AWS keys. SES raised this against their own feature and did not claim it closed. |

| 39 | **Call monitoring (listen / whisper / barge) needs live calls moved into conferences** | **[CLAUDE]** | **Not built, and the reason is architectural rather than missing effort.** It is the last of the RingCentral features Jose named on 2026-09-15; the other six shipped (park, pickup, longest-idle, queue position, callback request, paging), and **shared lines needed nothing built** — one number is already readable by everyone in the company, `NumbersController` publishes `on_call` / `on_call_with` as the busy lamp, and `/phone/calls/ringing` plus park/retrieve are the shared-line pickup. Twilio can only coach, whisper or barge a participant who is in a **Conference** — `callSidToCoach` is a Conference parameter and there is no equivalent for a `<Dial><Client>` leg. Measured, not assumed: every agent leg `TwimlRenderer` renders is `$dial->client(...)` (lines 258, 681, 688, 781, 1017), and the only conferences in the file are the two paging added. So monitoring means re-shaping inbound answering so the caller enters a conference and each agent is originated into it — which replaces simultaneous ring (several `<Client>` nouns on one `<Dial>`) with N outbound calls, and rewrites the exact path that sent a caller to voicemail on 2026-09-15 when the desk answered. **Listen-only is separately reachable without that rewrite** via `<Start><Stream>` media streams, but needs a WebSocket audio service EzBizi does not run. Deliberately left for a day Jose is here, per his own account of what breaking calls costs. |
| 40 | **App Store art from 2026-10-06 waiting on review slots** | **[CLAUDE]** | Uploaded to App Store Connect → Asset Library: a search-results image (submitted, Waiting for Review), a product page header (a **draft**, refused with "maximum number of submissions in review for this platform" while 0.0.0363 is in review), and 8 iPhone 6.9" + 8 iPad 13" screenshots named `iphone69-*` / `ipad13-*`. **When 0.0.0363 clears:** submit the header draft, and put the new screenshots on the next version in this order (iPhone: jobs, customers, inspection, scheduling, dashboard, hr, fleet, inventory; iPad: sign-in, then the same without hr). Sources: `Distribution/Apple/upload-2026-10-06/`, made by `scripts/store-screenshots.sh --device` and `Distribution/Apple/creative/make_creative.py`. |

| 41 | **Google listings editing (services, categories, WhatsApp link on 136 locations)** | **[JOSE]** | Built and off (`GBP_WRITE=false`) because Rodrigo's `a1leads.service` on the app box may still edit the same listings. Rodrigo has left, so stopping it is ours to do. Reminder scheduled for Jose 2026-10-30 10:00. |
| 42 | **A1 Leads final switch-off** | **[CLAUDE]** after #30 | Leads holds the cutover list (9 `A1LEADS_*` keys, a1leads.service + a1leads-refresh.timer, ~25 files). Also: export the review cards, approval history and lead corrections; rotate the keys Rodrigo held. Status page: https://claude.ai/artifact/RCB5MCq6qiqbWwfF6ffjqp |
| 43 | **PM-1 Action 2: 30-day estimate cancel** | **[JOSE]** | Rule built and OFF. Proposal: texts on day 7, 21 and 28, cancel in EzBizi + Workiz on day 30, grace starts at switch-on. Waiting on Jose's OK of days and wording. |
| 44 | **ACE intake over WhatsApp** | **[JOSE]** | Blocked since 10-20 on a pick between options A/B/C; Meta is writing them up in plain words. |
| 45 | **Thumbtack reviews** | **[THUMBTACK]** | The partner API exposes no reviews endpoint. eKomi is unblocked (credentials found in A1 Tools' env, CX building it). |
| 46 | **WhatsApp Official Business Account / groups** | **[META]** | Meta hasn't opened OBA requests; reports go 1:1 on WhatsApp to Jose, Eve and Shoval instead (live from 10-30 08:30 ET). |

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
  **"Application for Basic API Access"**. ALREADY FILED 3 Sept 2026, case
  `6-2027000041783` — these steps are for a RE-file if it is rejected on profile
  age, which the 60-day rule permits from **2026-10-24** (#30).
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

- ✅ **The inspection engine, and A1's form ported onto it** — *2026-08-29.* Nine phases.
  An inspection now belongs to the organization, a company or a franchise (`scope_type`/
  `scope_id`, Automation's pair). Fields and whole screens carry `visible_if` —
  eq/ne/in/not_in with nested and/or — and the two consequences are the ones to know:
  **a hidden field is not a missing answer** (or a masonry inspection is blocked at Submit
  by a wood-stove question nobody was shown) and **its answer is dropped at submit** (or the
  report freezes evidence of a question it never asked). Options carry a pass/fail variant
  and their own nested fields, so **failing a finding structurally demands its photograph,
  its explanation and its priced work** — the one idea worth stealing wholesale from A1's
  tool. Building codes live in a per-organization library, cited by id as a draft and frozen
  as WORDS into the version at publish, so correcting a typo never rewrites what a report
  already told a customer. Recommended work is picked from Services and becomes `job_lines`
  on submit — no estimate model, because `JobLine` already snapshots name and price for
  exactly the same reason. Six new field types, chosen by counting what A1's form actually
  uses: its type union declares `address` and the real form uses it zero times. And three
  print modes, so a report reads as findings rather than 120 undifferentiated rows, with
  "1 of 3 items did not meet standards" at the top.
  **The port is a seed, not a code path** — `inspection:import-a1` writes it through
  `FieldSet`, the Builder's own validator, so anything the engine could not express would
  fail there. It lands as a DRAFT: publishing is A1's call after reading it.
  Spec `docs/superpowers/specs/2026-08-28-inspection-engine-design.md`,
  plan `docs/superpowers/plans/2026-08-28-inspection-scope-owner.md` (phase 1).

- ✅ **An inspection belongs to a place** — *2026-08-29.* A named inspection is the
  organization's, a company's, or a franchise's, using the same `scope_type`/`scope_id`
  pair Automation and Mailbox carry. Scope decides where a definition EXISTS; the
  per-role grant still decides who may run it, and **both** must pass — `startable()`
  asks them separately so a refusal is readable. Visibility walks **up** a franchise's
  company chain and never down, so a technician sees the inspection their parent
  company owns and never a sibling franchise's. That is `InspectionScope`, and it is a
  different class from `ScopeReach` on purpose: reach answers "may this person act on
  that place", which is the right question for who may SET a scope and the wrong one
  for who may SEE the result — a technician does not reach the company above them, and
  using reach for both would hide every company-scoped inspection from exactly the
  people meant to run it, showing up as an empty picker rather than as a bug.
  Authoring still asks reach, so a franchise manager cannot scope the group's
  inspection to themselves. `scope` is on the wire with both keys always present.
  One additive migration, no permission changes. Phase 1 of
  `docs/superpowers/specs/2026-08-28-inspection-engine-design.md`.

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
