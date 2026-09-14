# EzBizi — build plan & handoff

**Written:** 2026-08-05 · **Revised:** 2026-08-05 after external review
**Banner refreshed:** 2026-08-07 · **For:** anyone picking the project up

> ## Status: this is the architecture brief, and it is current. The build has passed it.
> **Phases 0–3 are built and deployed** (§18). This document is no longer a to-do list —
> it is the reference the code points at. Every `§` in a code comment means a section here.
> For what is actually done, open `EZBIZI-STATUS.md`; for what is outstanding,
> `EZBIZI-TODO.md`.
>
> The banner that stood here until 2026-08-07 said *"not ready for implementation handoff,
> do Phase 0 before writing application code"*. That was true on 5 August and false two days
> later, which is the failure mode a status line has: it is written once, at the moment it is
> least likely to stay true. Anyone who read only the top of this file would have concluded
> no code should exist.
>
> This document was reviewed externally on 2026-08-05 (`EZBIZI-PLAN-REVIEW-FOR-CLAUDE.md`,
> then `EZBIZI-REVISED-PLAN-REVIEW-FOR-CLAUDE.md`). The review found real defects — a wrong
> PHP/Laravel baseline, a settings-cascade bug that skipped Sub Companies, secrets sitting in
> the future repo directory, and documentation pointing at a decommissioned server. All four
> are fixed here and in the code, and are marked **[FIXED post-review]** below. Both review
> files predate this revision and are kept for provenance only.

Read this top to bottom before writing any code. Markers used throughout:
**[DECIDED]** = settled by the owner, don't re-litigate · **[RECOMMENDED]** = my proposal,
needs sign-off · **[OPEN]** = still to answer · **[FIXED post-review]** = corrected 2026-08-05.

---

## 1. What we're building

EzBizi is a **multi-tenant CRM/field-service platform sold to other companies**. It is a
ground-up rebuild of "A1 Tools", an existing production app (PHP + Flutter) that works but
carries years of structural debt. A1 Chimney will eventually become one customer among many.

The old app still runs at `tools.a-1chimney.com` on SiteGround. **It is not being migrated
yet — build first, migrate later.** [DECIDED]

---

## 2. The three rules that override everything

### 2.1 NOTHING CLIENT-SPECIFIC MAY BE HARDCODED [DECIDED — narrowed 2026-08-05]

> **The governing rule:** No organization-specific business behaviour or presentation should
> require a deployment. **Platform invariants and configuration schemas remain in
> version-controlled code**; permitted values and tenant overrides are data-driven.

The owner's original words were "NOTHING must be hardcoded — everything configurable inside the
app." The **intent** is right and is the reason this rebuild exists. The **literal** reading is
unsafe: you cannot make tenant isolation, permission identifiers or API contracts
runtime-editable without turning security boundaries into mutable data. Narrowing agreed
2026-08-05.

#### Six categories — never merge them

| # | Category | Owner | Where it lives | Examples |
|---|---|---|---|---|
| 1 | **Platform invariants** | engineering | **code, version-controlled** | tenant isolation, hierarchy depth, permission identifiers, domain rules, API contracts, validation schemas |
| 2 | **Deployment config** | engineering | env / secrets store | service endpoints, credentials, operational limits |
| 3 | **Release flags** | EzBizi | platform control plane | staged rollout of new features |
| 4 | **Entitlements** | **billing** | subscription record | what a plan includes — tier features, add-ons, seat caps |
| 5 | **Tenant configuration** | org admin | database, per org | branding, terminology, automations, settings |
| 6 | **User preferences** | the user | database, per user | language, dashboard layout, notification choices |

> **🔴 Do not build one generic toggle system covering 3–6.** They have different owners,
> different lifecycles and different security consequences. A release flag flipped by EzBizi, a
> paid entitlement, a permission and a tenant preference look identical in a database and are
> catastrophic to confuse — a tenant granting themselves a paid feature is a billing hole; a
> tenant flipping a release flag is a support incident.

#### What the rule still demands (categories 5–6)

- A **settings registry** — every tenant-configurable value is a row (key, allowed scopes, type,
  default, validation), never a constant.
- An **automation engine** — triggers → conditions → actions, defined in the UI.
- **i18n strings from data**, not compiled in.
- **Tab/module permissions from a matrix**, never `if (role == 'admin')`.

> A1 Tools failed this badly. Adding a CRM tab required edits in **four** places across two
> files; miss one and the tab silently vanished. That class of bug is what this rule prevents.

### 2.2 Audit everything [DECIDED — with one explicit exception]

Every action, every error, every crash — see §10.

**The exception:** the **Lead Developer account is exempt from the business audit trail**
(errors and crashes are still captured). Decided by the owner 2026-08-05. Its consequences are
stated plainly in §4.2 — this is a deliberate, documented gap, not an oversight.

### 2.3 Privacy [DECIDED — US-only launch]

**Launch is US-only.** Full GDPR readiness is deferred, but the *mechanisms* (export, purge,
data inventory, retention) are built now. **US state privacy law still applies** and needs
counsel before launch — a smaller, different task from international GDPR. See §9.

---

## 3. Hierarchy [DECIDED]

```
Organization                    "Milk United Group"        ← the paying client
├── Company                     "Serenisima"
│   ├── Franchise               Argentina                  ← where work happens
│   ├── Franchise               Mexico
│   ├── Franchise               France
│   └── Sub Company             "Serecor"                  ← a Company with a parent
│       ├── Franchise           Argentina
│       └── Franchise           Mexico
└── Company                     "Sancor"
    ├── Franchise               Argentina
    └── Franchise               Colombia
```

| Rule | Answer |
|---|---|
| Can a Sub Company contain another Sub Company? | **No** — depth is fixed at Org → Company → Sub Company → Franchise |
| Can a Franchise sit directly under an Organization? | **No** |
| Can a Company exist with no Franchise? | **No** — creating a Company requires creating a Franchise |
| Same for a Sub Company? | **Yes**, also requires a Franchise |
| Can entities be moved between parents? | **A company can be made a sub company, or a sub company made a company again** — Jose, 2026-09-14, after creating Crestline Home as a company when it should have been a sub company. Its own route (`PATCH /companies/{id}/parent`), organization-level, audited, raised as `company.updated`. Nothing else moves: not a franchise between companies, and depth stays fixed, so a company holding sub companies cannot become one. The reason the answer used to be No is unchanged and is why the act is gated where it is — every setting, assignment and roll-up below a company is expressed in terms of where it sits. |
| Is a Sub Company otherwise identical to a Company? | **Yes** — literally "a Company with a parent" |
| What is a Franchise? | A **geography** — may be per country or per state, the client decides |
| Are these names final? | **Yes** — but see §8, clients can relabel "Franchise" in their UI |

**Schema implication:** because depth is fixed and shallow, use explicit foreign keys
(`organization_id`, `company_id`, `franchise_id`) rather than an adjacency list or nested
sets. Simpler queries, and the constraint is enforceable in the database.

Model Sub Company as a `companies` row with a nullable `parent_company_id`. Do **not** create
a separate table — they behave identically.

---

## 4. Access control [DECIDED]

### Assignment vs visibility — read carefully, these differ

> **RESOLVED 2026-08-05:** a user assigned at Company level **can act on** everything beneath
> it, not merely view it. Someone on Serenisima can create a job in Argentina. Write access
> follows the same downward path as read access; only **assignment** fails to cascade.
>
> Practically: authorise every write against the **subtree** of the user's assignment, not
> against an exact scope match. Get this wrong in the restrictive direction and company-level
> managers can see work they're unable to touch.

### The assignment model [CORRECTED 2026-08-05]

> **A user may have multiple assignment rows. Each assignment targets exactly one
> Organization, Company, Sub Company **or** Franchise — enforced by an XOR constraint** so a
> row can never reference two levels at once.

- **Assignment does not cascade.** Being assigned to Serenisima does not make you a *member* of
  its franchises — you hold one assignment, not four.
- **Authority does cascade.** From each assignment, both **read and write** apply to the whole
  subtree beneath it (see the resolved note above).
- A user **can hold several assignments at once** (Argentina + Mexico but not France). This is
  a hard requirement — precisely why cPanel was rejected.

**Rules the schema must enforce** (each one is a real failure mode):

| Rule | Why |
|---|---|
| **XOR constraint** per assignment row | one target level only, never two |
| **All assignments belong to the same Organization** as the user | cross-org assignment is a tenancy breach |
| **Overlapping assignments de-duplicate** | Company + one of its Franchises must not show records twice |
| **Last-Administrator protection** | an Organization must never be left with no admin — block the removal, and provide a documented recovery route |
| **Deactivated users and franchises** | assignments to a deactivated scope grant nothing, but are retained for history |
| **Invitations & email uniqueness** | one person may hold accounts in several Organizations (separate accounts — §4.3), so email cannot be globally unique; it must be unique *per organization* |

### Roles [DECIDED]

| | |
|---|---|
| Scope | **Organization-global** — defined once per Organization |
| Who creates them | **Organization Administrator only** |
| **Owner vs Administrator** | **The same thing.** There is no separate "owner" concept. |
| **Does a role vary per assignment?** | **No.** A user's role is fixed organization-wide; only their **scope** varies. |
| Built-in: **Administrator** | The organization's top role — the only built-in tenant role |

> **[CORRECTED post-review] "Developer" is NOT a tenant role.** It was previously listed here,
> which conflated two different identity domains: tenant users belong to exactly one
> Organization, while EzBizi staff work across all of them. Platform staff live in a
> **separate control plane** — see §4.2.

### Permission granularity [DECIDED — two-tier]

Permissions are **per module by default**, with an **expandable view** for finer control:

```
Jobs            [✓]          ← simple mode: on/off for the whole module
  └ expand ▾
      View     [✓]           ← granular mode, per action
      Create   [✓]
      Edit     [✓]
      Delete   [ ]
```

Store permissions at the **action** level always (`jobs.view`, `jobs.create`, `jobs.edit`,
`jobs.delete`); the collapsed module toggle is a UI convenience that sets all four at once.
Storing only module-level and adding actions later means a data migration across every role
in every organization.

Precedent from A1 Tools: it already needed `inventory_edit` and `inventory_delete` as separate
permissions, so the granular need is proven, not theoretical.

### 4.2 Platform staff — a separate control plane [REVISED post-review]

**EzBizi staff are not a tenant role.** A "Developer role" inside the organization role system
conflates two different identity domains: tenant users belong to exactly one organization,
staff work across all of them. Model staff as a **separate control plane** with its own
accounts, its own authentication, and its own audit stream.

#### Two staff tiers [DECIDED]

| Tier | Who | Access |
|---|---|---|
| **Lead Developer** | The owner's account | Everything, including managing staff accounts. For testing and development. |
| **Developer** | Dev team | Platform work: metadata, logs, settings, deployments. **Client records only via approved impersonation.** |

#### Access to client data: model (a) — client-approved [DECIDED]

Staff get **metadata, logs and settings freely**. Reaching actual customer records requires an
**impersonation session the client approves**, with a recorded reason and an expiry.

This is a legal position as much as a technical one: under GDPR you are the **processor** of
your clients' data. "Our developers can see everything, always" is not defensible when a client
asks — and enterprise buyers do ask.

Sessions must be time-limited, reasoned, scoped to one tenant, strongly authenticated, and
**the staff member remains the actor in the audit log** — never silently *becoming* a customer
user, which destroys the trail of who actually did what.

#### Lead Developer is exempt from audit logging [DECIDED 2026-08-05 — owner's call]

> **Lead Developer actions are NOT written to the audit trail.** Only **errors and crashes**
> are captured for that account.

I recommended logging it; the owner decided otherwise and that decision stands — **do not
re-raise it.**

The rationale is sound in the development context it's for: the Lead Developer account is used
constantly for testing and building. Auditing every action would flood the audit log with
development noise that isn't real business activity, making the log *less* useful for the thing
it exists to do — showing what actually happened to a client's data.

**Implement it precisely, because the distinction matters:**

| Signal | Lead Developer | Developer |
|---|---|---|
| Business audit trail | **exempt** | logged |
| **Errors & crashes** | **captured** | captured |
| Impersonation sessions | exempt | logged, client-approved, 12-month retention |

Two implementation notes so this stays coherent:
- The exemption is **per-account**, not per-role — a Developer promoted by mistake shouldn't
  silently stop being audited. Make it an explicit flag on the single Lead Developer account.
- If EzBizi ever pursues **SOC 2 or an enterprise security questionnaire**, "one account has
  unlogged access" is a question that will be asked. Not a reason to change it today — a reason
  to know the exemption exists and is deliberate, rather than discovering it under audit.

Retention: **12 months** for Developer impersonation records (§10) — longer than ordinary
developer logs, because those evidence staff access to client data.

### Accounts

One account belongs to **one Organization**. A person working for two Organizations has two
separate accounts. [DECIDED — this removes the need for workspace-switching at identity level
and keeps tenant isolation clean.]

Users are created or invited by the Organization Administrator through an onboarding flow.

---

## 5. Data model [DECIDED]

| | |
|---|---|
| Tenancy | **Single shared database.** Every **tenant-owned** row carries `organization_id`. |
| Physical separation for any client? | **Not required** |
| Where business data lives | **Franchise level** — customers, jobs, invoices, inventory, technicians |
| Cross-franchise sharing | **Per record type** — see §5.2. A single global toggle is too blunt. |
| Roll-ups | Company view = all its franchises + sub-companies combined. Organization view = everything. |
| **Primary keys** | **UUIDv7** on anything exposed via the API. Sequential integers leak business volume — a client seeing `invoice/1041` learns roughly how many exist platform-wide. v7 rather than v4 for index locality. **A UUID is not an authorization check.** |
| **Soft delete** | **Only where there is audit or legal significance** (invoices, jobs, customers). Not blanket — soft-deleting everything creates privacy exposure (§9) and clutter. |
| **Deactivate ≠ delete** | Franchises and users can be **deactivated**: retained for history, no new activity. |

> **[CORRECTED post-review] Isolation is not "a global scope."**
> An earlier draft said a global ORM scope or middleware *was* the boundary. **It is not** — it
> covers ORM reads in web requests and nothing else.
>
> **Every tenant-owned row carries `organization_id`. Isolation is enforced through
> `TenantContext`, authorization policies, database constraints and organization-scoped
> infrastructure keys. ORM scopes are one supporting control, not the boundary.**
>
> Full mechanism in **§5.1** — read it before writing a single query.

---

## 5.2 Cross-franchise sharing, per record type [DECIDED 2026-08-05 — sharing ON, with owning-franchise tags]

> ### ✅ Signed off by the owner 2026-08-05
> **Customer sharing is ON**, and **every customer carries a visible owning-franchise tag** so
> it is always clear who the relationship belongs to.

### The tag model — three parts, don't collapse them

The owner's requirement ("tags so there's no confusion") needs a precise shape, because
"which franchise does this client belong to" has two different true answers at once:

| Concept | What it is | How it's set |
|---|---|---|
| **Owning franchise** | **One** franchise. Accountable for the relationship; the customer counts toward *its* numbers | Set on creation; **reassignable** by an authorised user, **audited** |
| **Served by** | **Many** franchises — everyone who has actually done work for them | **Derived automatically** from jobs. Never hand-maintained, so it can't go stale |
| **Visibility** | Org-wide, always — see the note below | Org-wide, always |

**Why one owner *and* a derived list, rather than just one or the other:**
- Owner alone is wrong the moment a second franchise does work — the record claims a franchise
  that hasn't touched them in years.
- Derived list alone leaves nobody accountable, and makes "how many customers does Atlanta
  have?" unanswerable without double-counting.

### Rules

- **The tag is visible wherever the customer appears** — list, search results, detail header —
  not buried in a field. Its whole job is to stop someone acting on another franchise's
  customer without realising, so it has to be visible *before* they act.
- **Any franchise may create a job** for a shared customer; doing so adds them to *served by*
  automatically. It does **not** change the owner.
- **Reassigning the owner is deliberate and audited** — a customer permanently relocating is
  real, but it must be an explicit act, not a side effect of someone doing one job.
- **Roll-ups count by owning franchise**, so an organization-level customer count is the sum of
  its franchises' counts with no double-counting.
- **Editing** a shared customer is permitted from any franchise, and the audit log records who
  and from where. The tag creates social clarity; the audit trail creates accountability.

> **Not the same as the "entities never move" rule (§3).** That forbids moving a *Franchise*
> between Companies — org structure. A **customer's** owning franchise is data and *may* change.
> Don't conflate them.

One global on/off toggle can't express what these records actually are. Different types have
genuinely different natural behaviour, and getting this wrong is either a data leak or a
daily annoyance.

| Record | Owner | Sharing behaviour |
|---|---|---|
| **Customers** | **Owning franchise** (tagged, reassignable) | **SHARED — ON [DECIDED].** Readable org-wide, editable by any franchise, with the owning-franchise tag always visible. A customer served by NJ and PA is *one person*; duplicates create two half-histories for one relationship. |
| **Jobs** | Franchise, **always** | **Never shared.** A job happens at one place, on one date, with one crew. Visible in roll-ups; never co-owned. |
| **Invoices** | **The legal selling entity** [CORRECTED post-review] | **Never shared**, and this is legal rather than preference. ⚠️ My earlier wording said "the franchise" — but a franchise is a *geography*, not necessarily a legal entity. **Before building invoicing, decide which Organization, Company or Sub Company owns the legal name, tax identity, registered address and invoice numbering sequence that appears on the document.** Franchise geography and selling entity often differ, and getting it wrong produces invoices that are not legally valid. |
| **Inventory** | Franchise | Physical stock is in one location. Optional **read-only visibility** across franchises ("does the other branch have one?"), never shared ownership. |
| **Technicians / users** | — | **Not a sharing toggle at all.** They're users: a technician working across franchises simply holds multiple assignments (§4). |

### Consequences to design for

- **Identifier uniqueness.** If customers are shared, customer numbers must be unique
  **organization-wide**, not per franchise. Decide before the first record exists — renumbering
  later breaks every reference a client has printed, exported or integrated.
- **Both directions are dangerous — corrected post-review.** I previously called turning
  sharing *on* "harmless." **It isn't: it expands personal-data visibility across scopes**, which
  is a privacy event (§9), not a convenience setting. Every franchise's customers become
  readable by staff who previously couldn't see them.
> **[REVISED 2026-08-07 — there is no toggle.]** This section was written around a
> per-organization `shares_customers` switch, and the two bullets that stood here described
> turning it on and off. The column existed, was written at provisioning, and was read by
> nothing — so the behaviour was always "shared", and the switch was a description of a
> choice the system never offered.
>
> Asked directly, the owner settled it as a property of the hierarchy rather than a setting:
> *"if i add a client to a franchise, the client belongs to the franchise, but can be seen via
> organization view, so technically it belongs to the organization itself."*
>
> That is the owning-franchise tag plus the derived "served by" list, which is what the rest of
> this section already describes — the tree makes it visible upward, and no configuration is
> involved. The column has been dropped rather than wired, because wiring it would have been
> building an option nobody asked for. If a customer ever needs franchise-private records, that
> is a new decision against a real requirement, not the restoration of this one.
- **Never auto-merge duplicates.** If sharing is enabled and two franchises already hold the
  same customer, surface them as suggested merges for a human to confirm. Automatic merging of
  customer records is unrecoverable when wrong.

---

## 5.1 Tenant isolation — the highest-severity bug class [EXPANDED post-review]

A cross-tenant leak is the one bug that ends the business. A Laravel global scope or HTTP
middleware is **not a complete boundary** — it covers ORM reads in web requests and nothing else.

**These bypass a global scope entirely:** raw SQL · route-model binding · **queue workers** ·
**scheduled commands** · exports · **websocket channels** · **automation actions** · caches ·
file paths.

Required instead:

| Control | What it does |
|---|---|
| **Mandatory `TenantContext`** | Set and required in HTTP, queues, CLI, schedules and broadcasts. Jobs carry the tenant explicitly — never inherit it from whoever happened to dispatch them. |
| **Database constraints** | Composite foreign keys (or equivalent) so a row cannot reference a parent in another organization. Belt and braces — the DB refuses even if code is wrong. |
| **Scoped keys everywhere** | Cache, queue, broadcast channel and storage path all prefixed by organization. |
| **Explicit global tables** | An allow-list of platform tables that legitimately have no `organization_id`, so "missing tenant column" is always a bug. |
| **Schema test** | Inventories every table and fails if a tenant-owned one lacks the column or constraint. |
| **Isolation tests** | Reads, writes, exports, file access, background jobs and broadcasts — not just HTTP reads. |

> **UUIDs are not authorization.** An unguessable ID is not a permission check. Use **UUIDv7**
> (time-ordered) rather than v4 — random v4 has poor MySQL index locality and causes page splits
> on every insert at scale.

---

## 5.5 Automation engine [DECIDED]

> Called the **workflow engine** until 2026-08-10, when the feature was renamed to
> *automation* all the way down — tables, classes, routes and permission keys — to
> match the word the product had always used on screen. The § numbering is
> unchanged, so every `§5.5` reference in the code still lands here.

A first-class subsystem, not a feature bolted on later. It is the main expression of the
no-hardcoding rule (§2.1).

### 🔴 Standing rule for the life of the project

> **Every new module or system added to the app must also expose its triggers and actions to
> the automation engine.** A module that can't participate in automation is not finished.

Enforce it in the module checklist and in code review. This is how the engine stays useful
instead of covering only whatever existed on day one.

### Triggers [DECIDED — all of these]

- Record **created** / **updated** / **deleted**
- **Field changed** to a specific value
- **Scheduled** / recurring (cron-like)
- **Inbound webhook**

### Actions [DECIDED — all of these]

- Send **email**
- Send **notification** (in-app / push)
- **Update a field**
- **Create a record**
- **Call an external webhook**
- **Assign to a user**

### Conditions [DECIDED — two modes]

- **Simple**: field comparisons (`status = closed`)
- **Complex**: nested AND/OR expression builder

Same two-tier idea as permissions — a simple UI over a model that's expressive underneath.

### Scope [DECIDED]

Organization level by default; **a Company or Franchise may override in its own settings** —
the same cascade as §6.

### Reliability & security contract [EXPANDED post-review]

Automation that fires twice, or not at all, or at the wrong moment, destroys trust faster than
a missing feature. Settle all of this **before** implementation:

**Delivery**
- Actions run on a **queue**, never inline — a slow external webhook must not delay a user's save.
- **Transactional outbox**: the event is written in the same DB transaction as the business
  change, then delivered asynchronously. Otherwise the record commits and the trigger vanishes.
- **At-least-once delivery** with **idempotency keys**, so a retry doesn't send the email twice.
- Retry with backoff, timeouts, and a **dead-letter queue** for permanent failures.

**Loop protection**
- Execution-depth cap *and* a **causation chain** — depth alone doesn't stop A→B→A across
  separate delayed runs.
- A total execution budget per triggering event.

**Security — outbound webhooks are the sharp edge** 🔴
- A tenant-configured webhook URL means **a tenant can make your server issue HTTP requests**.
  Without an allow-list this is **SSRF**: they point it at `169.254.169.254` and read cloud
  metadata, or at internal services. Block private/link-local ranges, cap response size and
  time, and never follow redirects blindly.
- Inbound webhooks need signature verification and replay protection.
- Webhook secrets stored encrypted, never in the settings table.

**Operability**
- Every run audited (§10): trigger, conditions evaluated, actions taken, outcome.
- Automation **versioning** with activation and rollback — an edit must not rewrite history.
- Schedules need explicit **timezone and DST** behaviour ("9am daily" in which zone?).
- Test mode, auto-disable after repeated failure, and diagnostics the owner can read.

**Build order [RECOMMENDED]:** expose triggers/actions through a **registry contract**, but
prove it with real **Customer** and **Job** events first. Building an unrestricted generic
engine before a single business module exists means designing against imagined requirements.

---

## 6. Settings cascade [DECIDED]

Settings **override downward**:

```
Organization        (baseline)
   └── Company           (overrides Organization)
         ├── Franchise        (overrides Company)
         └── Sub Company      (overrides Company)
               └── Franchise      (overrides Sub Company)
```

### 🔴 [FIXED post-review] The resolver must include Sub Companies

My original chain was `Franchise → Company → Organization`, which **skips Sub Companies
entirely** — so a franchise under Serecor would have inherited from Serenisima directly,
silently ignoring every setting Serecor defined. The correct walk is:

```
Franchise → owning Company or Sub Company → parent Company (if any) → Organization → system default
```

**Implementation [RECOMMENDED]:** store only *overrides*, sparsely. Resolve at read time by
walking that chain and taking the first hit. Never copy a parent's value into children — that
breaks inheritance the moment the parent changes.

Cache the resolved set per scope; invalidate on write.

### Every setting needs a definition, not just a value [ADDED post-review]

"All three levels have the same options" is **not quite true** — reporting currency is
Organization-only, currency and timezone are Franchise-level, language additionally allows a
per-user override. So each setting must declare its own rules:

| Field | Purpose |
|---|---|
| Key + value type | stable identifier |
| **Allowed scopes** | which levels may set it — this is what makes the above consistent |
| Default + inheritance | fallback behaviour |
| Validation schema | permitted values |
| Sensitivity | is it redacted in audit logs? |
| Edit permission | who may change it |
| Cache invalidation | what to bust on write |

**Secrets are not settings.** API keys and credentials need encrypted storage and separate
access control — never the general settings table.

---

## 7. Money [REVISED 2026-08-05 — single currency for v1]

> **Scope decision: US-only at launch. One currency (USD) everywhere.**
> **No FX conversion, no rates API, no reporting-currency setting in v1.**
> Build the schema so multi-currency can be added later **without a destructive rewrite or an
> ambiguous historical backfill.** *(Corrected post-review: it will still require additive
> migrations for rates, reporting amounts and conversion provenance — the point is that no
> existing row has to be reinterpreted or guessed at.)*

This removes a genuinely expensive subsystem — rate sourcing, caching, manual overrides,
historical reproducibility, roll-up conversion — from the first release. It was the right call.

### Store money correctly from day one anyway [RECOMMENDED — non-negotiable]

| Field | Purpose |
|---|---|
| `amount_minor` | **Integer, minor units** (cents). Never a float. |
| `currency` | ISO code, `USD` for now — **present from day one** |

**Never use floating point for money.** `0.1 + 0.2 != 0.3` in binary floating point; errors
compound invisibly across thousands of line items and surface as invoices that are a cent off.
Use integer minor units, or a true decimal type. Retrofitting this means rewriting every
financial row and every calculation that touched them.

**Keep the `currency` column even though it's always `USD`.** Adding a column later is easy;
back-filling the *correct* currency onto historical rows, once records were created under
different assumptions, is guesswork.

### When multi-currency is switched on later

Add — don't reshape:

| Field | Purpose |
|---|---|
| `fx_rate` + `rate_source` + `rate_at` | the rate **at transaction time**, and where it came from |
| `amount_reporting` + `reporting_currency` | converted, **stored** |

The reason to store the converted amount rather than convert at read time: otherwise **last
year's revenue changes every morning** as rates move. Reports stop being reproducible and
nobody trusts the numbers. Also decide then whether changing an organization's reporting
currency restates history or only applies forward — **I'd argue forward only.**

---

## 8. Internationalisation [DECIDED]

| | |
|---|---|
| **Market at launch** | **US only** [DECIDED 2026-08-05] |
| Languages at launch | **English, Spanish** |
| Future languages | Must be addable **without a code change** — build the system for it now |
| Language setting | Per **Organization**, with a per-**user** override |
| Timezone | Per **Franchise** — still needed: the US spans six zones |
| Currency | **USD only** in v1 — see §7 |
| Relabelling | Clients can rename **"Franchise"** (to Location, Branch, Region…). "Franchise" is the default. |

Because of §2.1, translation strings must be **data**, not compiled assets — an admin should
be able to add a language and fill in strings from inside the app.

---

## 9. Privacy [REVISED 2026-08-05 — US-only launch, GDPR-ready]

> **Scope: US-only at launch.** Formal GDPR compliance and legal review are **deferred**.
> **Build the mechanisms now anyway** — they are cheap to design in and brutal to retrofit.

**This is not "skip privacy."** US states have their own regimes (CCPA/CPRA in California,
and a growing list elsewhere) with broadly similar mechanics: access, deletion, and knowing
what you hold. The machinery below serves both.

### Build now

| Capability | Why it can't wait |
|---|---|
| **Data export** (JSON/CSV, per organization and per person) | Doubles as the import/export feature clients want anyway (§12) |
| **Purge personal data** — anonymise in place | Must touch every table holding personal data. Retrofitting across a mature schema is the expensive scenario |
| **Data inventory** — what personal data, in which table, why | Impossible to reconstruct later; trivial to maintain as you go |
| **Retention policies** per data category | §10 |
| **Trustworthy audit** (§10) | Any breach assessment depends on it |

### ⚠️ US launch is not "no privacy work" [CORRECTED post-review]

An earlier draft implied all privacy/legal review waits for international expansion. **It
doesn't.** Depending on which state laws apply and whether you cross their thresholds, a US
launch can still require:

- a **published privacy notice**
- **service-provider / processor contract terms** with each client
- **verified rights-request handling** (access, deletion, correction) with identity checks
- disclosure of what personal data is collected and why

**Have counsel determine applicability before launch.** That is a smaller and different task
from full international GDPR readiness — but it is not nothing, and it is cheaper to answer
before customer data exists.

Primary reference: [California AG — CCPA](https://oag.ca.gov/privacy/ccpa)

### Genuinely deferred until non-US

Lawful-basis records under GDPR · EU data residency · subprocessor register · international
transfer mechanics · the 72-hour GDPR breach-notification process.

### The schema tension — resolve it now

Soft-delete (needed for audit) and erasure (required by law) pull in opposite directions:
**soft-deleted personal data is still retained personal data.**

**Resolution [CORRECTED post-review — this contradicted §5]:** soft-delete **only on the
audit- or legally-significant records scoped in §5** (invoices, jobs, customers) — **not
blanket** — plus a distinct **"purge personal data"** operation that **anonymises in place**:
keep the invoice and its amounts (tax law requires this), blank the name, email, phone and
address, and log the purge itself.

> An earlier draft said "soft-delete by default," which directly contradicted §5's *"only where
> there is audit or legal significance… not blanket."* **§5 governs.** The distinction is
> load-bearing: it decides whether `deleted_at` goes on **every** tenant-owned table or only on
> those three. Soft-deleting everything means retaining personal data in notes, attachments and
> automation runs indefinitely — the exact exposure this section exists to prevent.

> **[CORRECTED post-review] Blanking fields is not automatically anonymisation.**
> If the remaining data can re-identify the person — a unique address, an unusual job
> combination, a linked phone in an audit log — it is still personal data legally.
> Personal data also hides in **audit logs, crash reports, uploaded files, search indexes,
> caches, automation payloads, notification history, exports and backups.** A purge that misses
> those is incomplete. **Enumerate every location that stores personal data as you build**,
> because reconstructing that list later is guesswork.

**Backups are the hard case nobody plans for:** you cannot rewrite a backup. Standard practice
is documenting that erasure applies to live systems, backups expire on a stated cycle, and any
restore re-applies outstanding erasures. Decide the cycle now so the promise is truthful.

**Before selling outside the US:** qualified privacy counsel reviews lawful basis, retention
and the processor agreement. I can build the mechanisms; I can't sign off on the legal basis.

---

## 10. Audit, errors, crash logs [REWRITTEN post-review]

Log **every action**, plus errors and client crashes — with one documented exception (below).

### Delivery: transactional outbox, not a plain queue [CORRECTED post-review]

An earlier draft said "write audit entries asynchronously (queue)". **That can lose the
record**: if the business transaction commits and the queue write then fails, the change
happened and nothing recorded it — and you only discover the gap when you need the log.

**Correct mechanism:**

1. The audit event is written to an **outbox table in the same database transaction** as the
   business change. Either both land or neither does.
2. A worker delivers asynchronously to an **append-only** audit store.
3. **If audit delivery is unavailable:** the outbox retains events and back-pressures — it must
   never silently drop. Decide explicitly whether a *sustained* audit outage blocks writes
   (safer, and what regulated buyers expect) or degrades to best-effort with alerting.

This keeps the original goal — the user's request doesn't pay the delivery cost — without the
data loss.

### What every entry records

| Field | Notes |
|---|---|
| Actor | user or **platform staff member** — never a shared "system" identity |
| Tenant + scope | organization, and company/franchise |
| Action + target | verb and the record touched |
| Timestamp | UTC |
| **Correlation & causation IDs** | ties a request to the automation runs and jobs it spawned — without this, debugging automation is guesswork |
| Before / after | for changes, with a stated rule on how much is kept |
| **Redaction** | credentials, tokens, and personal data not needed for the record. "Log everything" must never mean storing every request body |
| **Integrity** | tamper-evidence (hash chain or append-only storage). An audit log that can be edited proves nothing |

### Retention [DECIDED]

| Log type | Retention | Rationale |
|---|---|---|
| Business audit | **3 months** | owner's decision, revised 2026-08-07 (was 1 month) |
| Developer / platform logs | **3 months** | owner's decision |
| Crash logs | **1 month** | owner's decision |
| **Staff impersonation records** | **12 months** | evidences staff access to client data — must outlive ordinary logs |

Client crashes from all platforms are **sent to the API** [DECIDED].

**Enforce retention with a scheduled purge from day one.** A1 Tools didn't and the logs became
the largest thing in its database — 81 MB of crash reports, 150k alert rows, 261k sync-log rows.

### Access

Tenant audit, platform-staff audit, security events and crash reports are **separate streams
with separate access policies**. A tenant admin sees their own organization's audit; they must
never see platform-staff logs or another tenant's events.

### 🔴 The Lead Developer exception — stated plainly

Per §4.2, the **Lead Developer account is exempt from the business audit trail** (errors and
crashes still captured). The owner decided this on 2026-08-05 and it stands. Its consequences,
recorded so nobody is surprised later:

- **"Audit everything" (§2.2) excludes the Lead Developer.** The claim is not absolute.
- **Client approval applies only to regular Developers**, not to this account.
- **The staff-access record cannot prove every access or change** to a client's data.
- **If the highest-privilege account is compromised or misused, there may be no business audit
  trail of what it did.**

Implement it as a flag on **that one account**, never on a role — otherwise a Developer promoted
by mistake silently stops being audited.

---

## 11. Stack [DECIDED — signed off 2026-08-05, see §16]

### 🔴 [FIXED post-review] The PHP version was wrong — do NOT build on 8.2

My original recommendation was "Laravel on PHP 8.2, because 8.2 is already installed." That
reasoning was backwards: an incidental install drove a decade-long decision. Verified against
vendor documentation 2026-08-05:

| Laravel | PHP supported | Bug fixes until | Security until |
|---|---|---|---|
| 11 | 8.2 – 8.4 | Sep 2025 | **Mar 2026 — already EOL** |
| 12 | 8.2 – 8.5 | **13 Aug 2026** — days away | Feb 2027 |
| **13** ✅ | **8.3 – 8.5** | Q3 2027 | **Mar 2028** |

**Laravel 13 does not support PHP 8.2 at all**, and PHP 8.2 itself is out of active support.
The box currently has **8.2.33**, which must be upgraded in Phase 0.

Sources: [PHP supported versions](https://www.php.net/supported-versions.php) ·
[Laravel release policy](https://laravel.com/docs/13.x/releases)

| Layer | Choice | Why |
|---|---|---|
| **Backend** | **Laravel 13 on PHP 8.4** [FIXED post-review] | Longest support runway (security to Mar 2028). PHP 8.4 is actively supported; 8.5 is an option once packages are verified. Laravel gives auth, queues, API tokens, migrations, scheduling and websockets out of the box — exactly the structure whose absence sank A1 Tools. |
| **API** | **REST**; OpenAPI spec generated **code-first** [CORRECTED post-review — the earlier "contract-first" wording is withdrawn, see §11.4] | Public API for third parties is a requirement. REST is far easier for outside developers to consume, version and rate-limit than GraphQL. Laravel 13 also ships first-party **JSON:API resources**, worth evaluating for the public surface. |
| **Real-time** | Laravel Reverb (websockets) | First-party, no third-party service. **Channel authorisation must be tenant-scoped** (§5.1). |
| **Database** | MySQL 8.0.46 | Already installed. Matches the single-database decision. |
| **Client** | Flutter, one codebase | Six targets — but **phase the releases**, see §12. |
| **Caching** | Local cache on the client [DECIDED]; Redis server-side | Redis **not installed**. Decide its role explicitly — disposable cache, queue backend, or broadcast transport. Each implies different persistence and recovery. |
| **File storage** | **Local disk for now** [DECIDED] | Modest volumes expected; 1.3 TB free. Must stay swappable — see below. |
| **Deployment** | **Git-based, with release gates** [REVISED post-review] | See §11.2 — the original "push to main → server pulls and migrates" had no rollback path. |
| **Tests** | **Risk-proportional** [REVISED post-review] | My original three areas were too narrow. See §11.3. |
| **2FA** | **TOTP** (authenticator app) [DECIDED] | Free, no per-message cost. Configurable per organization. |

**Not installed:** Node/npm, Docker, Redis. **Do not install Node on the production box merely
for asset tooling** — build Flutter web and any frontend assets in CI and deploy the artifacts.

### File storage — swappable by design

Local disk now, but **never hardcode file paths** (§2.1). Use the framework's storage
abstraction so the backend can move to S3 or Lightsail Object Storage by changing one config
value. Store files under a **per-organization prefix** from day one — that's what makes GDPR
erasure a single delete instead of a hunt.

Two things local disk costs you, worth knowing before it bites:
- Files **do not survive a rebuild** of the instance unless separately backed up.
- Downloads are served by the app server's own CPU and bandwidth.

Neither matters at current scale. Both start mattering with large media or many clients.

### 11.2 Environments & deployment [REVISED post-review]

Development is **local**, deploying to the app box, always on `main` [DECIDED by owner].

The original plan — *push to main → server pulls, installs, migrates* — has **no rollback
path**. If a migration fails halfway, the site is down and the previous state is gone. A full
enterprise pipeline is overkill for one developer, but these are cheap and non-negotiable:

| Control | Why it earns its place |
|---|---|
| **Atomic release directories** | Deploy to a new dir, flip a symlink. Rollback = flip it back. Without this a half-finished deploy is a live broken site. |
| **Backup immediately before every migration** | Verified, not assumed. The one time you skip it is the one that fails. |
| **Expand/contract migrations** | Add columns before writing them, drop old ones after. Makes rollback possible at all. |
| **Health check + smoke test after deploy** | Confirm the app actually answers before declaring success. |
| **Deployment lock** | Prevents two deploys colliding. |
| **CI as a gate, not a notification** [CORRECTED post-review] | "CI on push" doesn't protect `main` when you commit directly to it — the failing commit has *already landed*. Use **short-lived branches and a lightweight PR even solo**, with checks required before merge. |
| **Deploy only the exact commit that passed CI** | Not "whatever `main` is now" — those diverge the moment you push twice. |
| **Protected `main`** | Stops accidental force-pushes destroying history. |

**Staging is `dev.ezbizi.com`** (§15). It must be genuinely isolated, or it isn't staging —
it's a second way to damage production:

> **Separate database · separate storage path · separate Redis/queue namespace · separate mail
> credentials (or a catch-all mailbox) · separate push and webhook credentials.**

The failure that matters: a staging test run sending real emails to real customers, or firing
webhooks at a client's live systems. Both happen when credentials are shared, and both are
discovered by the customer rather than by you.

### 11.3 Test strategy [REVISED post-review]

My original three areas were too narrow. Not exhaustive coverage — coverage **proportional to
what a silent bug costs**:

| Area | Why |
|---|---|
| **Tenant isolation** | Reads, writes, exports, files, background jobs, broadcasts. Business-ending. |
| **Permission enforcement** | Including the subtree rule (§4). |
| **Settings cascade** | Especially the Sub Company level, which I got wrong once already. |
| **Money conversion & rounding** | Financial errors compound silently and are found by clients. |
| **Subscription read-only** | Must hold across HTTP, API **and background jobs** — not just middleware. |
| **Automation retries, loops, idempotency** | Duplicate sends and infinite loops both reach customers. |
| **Auth, invitations, MFA, support sessions** | The identity boundary. |
| **Public API authz, idempotency, compatibility** | Breaking third-party integrations silently is unforgivable. |
| **GDPR export & erasure** | Legal exposure if it doesn't do what it claims. |
| **Import validation & partial failure** | First thing a new client touches. |

### 11.4 Public API [DECIDED — signed off 2026-08-05, see §16 row 9]

The public API is a **product feature**, not an afterthought — third parties will build on it.

**Auth: start with scoped API tokens, designed so OAuth2 can be added without breaking them.**

The deciding question is *who* consumes it. Two very different cases:

| Use case | Needs |
|---|---|
| **A client connecting their own systems** (accounting, their website, Zapier) | **Scoped tokens** — the client generates one in the UI and pastes it. Simple, and what almost everyone actually wants. |
| **A third-party app acting on behalf of users** ("Log in with EzBizi", an app marketplace) | **OAuth2** — needs an authorization server, consent screens, refresh tokens, revocation |

Read literally, *"other people may use our services or connect to them"* is the **first** case.
Build an authorization server for a marketplace that may never exist and you've spent weeks on
imagined requirements — the exact failure mode this rebuild exists to avoid. Tokens now; OAuth2
is additive later.

**Token design:** organization-scoped, permission scopes mirroring the role system, issued by an
Administrator in-app, revocable, rate-limited per token, with last-used tracking so abandoned
tokens are visible.

### Requirements from day one

| | |
|---|---|
| **OpenAPI spec — code-first** [CLARIFIED post-review] | An earlier draft claimed *both* "contract-first" and "generated from code". **Pick code-first:** implementation metadata generates the spec, and **CI fails the build on a breaking change**. Hand-authored specs drift within weeks for a solo developer. The discipline comes from the CI diff, not from writing YAML first. |
| **Versioned routes** (`/api/v1/`) | With a written deprecation policy |
| **Idempotency keys** on writes | A client retrying a timed-out request must not create two jobs |
| **Cursor pagination** | Offset pagination skips and duplicates records when the set changes mid-scroll |
| **Consistent error envelope** + correlation IDs | So "it broke" becomes a traceable request |
| **Rate limits** per tenant and token | |
| **Webhook event schema versioning** | Outbound payloads are a contract too |

> **The internal app should consume the same API it exposes** — that's the only reliable way to
> keep it honest. **But platform/staff control-plane operations must NOT live on the public
> surface.** Share the domain services underneath; expose two different API surfaces.

---

## 12. Platforms [DECIDED]

**Web · Windows · macOS · Linux · Android · iOS** — all six, from one Flutter codebase.

### Release order [DECIDED 2026-08-05]

> **1. Web → 2. Desktop → 3. Mobile**

One codebase is cheap; **six release pipelines are not.** Each platform carries its own
signing, packaging, store review and update mechanism — work that has nothing to do with
features. Phasing means the product is usable while that work happens rather than after it.

Web first is right: nothing to sign, nothing to review, and it updates itself.

| Platform | The work that isn't writing features |
|---|---|
| **Web** | Service-worker caching rules — **must not cache personal data** where a shared machine could recover it |
| **Windows** | Code signing certificate, installer, auto-updater |
| **macOS** | Apple Developer account, signing, **notarisation**, updater |
| **Linux** | Package format and update channel |
| **Android / iOS** | Store accounts, review cycles, **APNs + FCM credentials**, per-platform push behaviour |

- **Offline mode is OUT OF SCOPE** [DECIDED]. Removes the most expensive, error-prone part of
  the build (sync + conflict resolution). Local caching for *speed* is still wanted; offline
  *editing* is not.
- **Local cache needs its own rules:** encryption where the platform supports it, TTL and
  version invalidation, and **wiped on logout**. A cache that outlives a session on a shared
  machine is a data leak wearing a performance costume.
- **Desktop auto-updater: keep** [DECIDED]. *(Since web ships first and updates itself, steer
  office staff there; reserve desktop for those who genuinely need it.)*

### Theming [DECIDED]

- The app's visual identity follows the **EzBizi brand** established on the landing page —
  cyan `#00aeef` on white. Reference: `H:\AWS\Application\` landing page source, live at
  https://ezbizi.com
- **Themes are switchable**, and **organizations can define their own** — per §2.1, theming is
  configuration, not code. That means colors, logo and typography stored per organization, not
  compiled constants.

### Notifications [DECIDED]

**Email · in-app · push** — all three in scope at launch. Push requires Apple (APNs) and Google
(FCM) developer setup per platform. All notification behaviour must be configurable in-app
(§2.1), and is driven by the automation engine (§5.5).

### Import / export [DECIDED]

Clients can **import and export** their data. Import matters commercially — a trial that can't
load existing customers rarely converts. Export overlaps with the GDPR portability requirement
(§9), so build them as one capability.

### Demo / sandbox organization [DECIDED]

A seeded demo Organization for sales walkthroughs. Must be **clearly flagged as demo** and
resettable, so a prospect's poking around never contaminates real data.

---

## 13. Subscription lifecycle [DECIDED]

| | |
|---|---|
| Who pays | The **Organization** |
| Trial | **30 days**, and settable — see the definition below |
| On expiry | Organization **deactivates** — the admin can still log in and **view** data, but cannot enter new data |
| Signup | **Self-serve**, and creatable by EzBizi staff from the **staff control plane** (§4.2) |
| Billing provider | **[OPEN]** — deferred |
| Pricing metric | **[OPEN]** — see the Workiz reference below. **Meter users from day one regardless.** |

### "One month" — define it precisely [ADDED post-review]

Ambiguity here becomes a billing dispute. **Recommendation: 30 calendar days from activation,
evaluated in UTC**, with the exact expiry timestamp stored on the subscription rather than
recomputed. Calendar months make a February trial shorter than a March one for no reason a
customer will accept.

### Enforcement must cover every write path [EXPANDED post-review]

Middleware on HTTP endpoints is **not sufficient**. A deactivated organization must be unable
to write via:

**HTTP · the public API · scheduled tasks · automation actions · queued jobs · imports · API
tokens · WebSocket events**

An automation that keeps firing after expiry, or an import that still runs, means the product
works fine without paying. Enforce at the **service layer**, where every path converges — not
at the edge.

### Writes that must REMAIN allowed after expiry [ADDED post-review]

Blanket read-only locks customers out of the very actions that end the situation:

| Still permitted | Why |
|---|---|
| **Paying / updating billing** | A customer who cannot pay you cannot become a customer again |
| **Exporting their data** | Legally expected, and refusing looks like hostage-taking |
| **Password & MFA recovery** | Otherwise expiry locks people out permanently |
| **Exercising privacy rights** | Not contingent on an active subscription |
| **Contacting support** | |
| **Audit & security event writes** | The system must keep recording regardless |

### 🔴 Entitlements are NOT feature flags [CORRECTED post-review]

An earlier draft said "feature flags express add-ons and tier differences." **That conflates
four distinct systems** (§2.1):

| System | Controlled by | Changing it means |
|---|---|---|
| **Release flags** | EzBizi | staged rollout of new functionality |
| **Entitlements** | **billing** | what the plan includes — tiers, add-ons, seat caps |
| **Permissions** | org admin | which users may do what |
| **Tenant settings** | org admin | how the org's own behaviour is configured |

They look identical in a database and are dangerous to merge. **A tenant able to flip their own
entitlement is a billing hole; a tenant flipping a release flag is a support incident.**
Entitlements are derived from the subscription and are **read-only to the tenant**.

### Pricing reference — how Workiz does it (researched 2026-08-05)

Worth studying because Workiz is a direct competitor *and* A1 Chimney already uses it, so its
pricing is a known quantity to the owner.

> **[CORRECTED post-review — sourcing]** An earlier draft stated specific tier prices and
> add-on costs from **secondary** pricing blogs. Checking the **official** page
> (workiz.com/pricing-plans, 2026-08-05) shows those numbers are **not publicly substantiated**
> — Workiz shows "Request pricing" rather than monthly figures. The structural observations
> below come from the official page; the third-party price points are **not** reliable and have
> been removed.

**What the official page actually states (2026-08-05):**

| | |
|---|---|
| Tiers | **Standard · Pro · Ultimate** |
| Monthly prices | **not published** — "Request pricing" |
| Included users | **first 5 users** on every tier |
| Extra members | **$55/mo** (Standard) · **$65/mo** (Pro), annual billing · not listed for Ultimate |
| Annual discount | *"save $400+"* — no percentage published |
| Phone system & AI | **"sold separately"** — prices not published |

**What this implies for the build**, independent of any competitor's numbers:

1. **Meter users from day one.** Tiers are seat-capped with paid overage — the system must count
   billable users per Organization and enforce a cap. Retrofitting a seat count is painful.
2. **Entitlements** — not feature flags — express tier and add-on differences (§13, §2.1).
3. **Bill at Organization level** even though users attach at franchise level: one
   Organization-wide seat count across all companies and franchises.
4. Support **monthly and annual** terms from the start.
5. Sellable **add-ons** need to exist as a concept, since that's where the industry puts margin.

> **Treat this as dated market observation, not a template.** It describes one competitor's
> packaging on one day. Letting a rival's current pricing shape EzBizi's entitlement schema
> would bake someone else's business model into your database.

Source: [Workiz official pricing](https://www.workiz.com/pricing-plans/) — retrieved 2026-08-05.

---

## 14. Modules — see §18 for the order [SUPERSEDED post-review]

> **The build order lives in §18.** This section previously gave a different one
> (Foundation → Dashboard → Developer tab → Settings), which contradicted it. §18 wins:
> **foundation → one Customer → Job vertical slice → cross-cutting → dashboards last.**
>
> A dashboard built before any module produces data is a page of empty widgets, and you learn
> nothing from it about whether the foundation actually works.

The owner's requested surfaces still all get built — they simply arrive in §18's order, and
two of them belong in different places than originally written:

| Surface | Where it belongs |
|---|---|
| **Organization / Company / Franchise settings** | tenant administration — with **per-setting allowed scopes** (§6), *not* "all three levels expose the same options", which §6 corrected |
| **Automations** | tenant administration — an organization configures its own |
| **Platform logs, crash reports, organization creation, release flags** | the **staff control plane** (§4.2) — not a tenant-facing "Developer tab" |
| **Dashboard** | after modules produce data worth showing (§18 Phase 4) |

Further modules get ported from A1 Tools one at a time, dropping what isn't worth keeping.

> **[FIXED post-review]** An earlier draft said the A1 Tools module list was in
> `AWS-MIGRATION-STATUS.md`. **It is not** — that document has no module inventory.
> Build the list by reading `H:\A1Chimney\a1_tools` directly: `lib/features/` holds the 25
> feature modules, and `crm_sidebar.dart` holds the actual ordered tab list. Reference only —
> **never copy the code** (§15.5).

---

## 15. Infrastructure — current state (audited 2026-08-05)

**Application box — clean slate, nothing installed on it yet.**

| | |
|---|---|
| Host | `100.24.188.241` · **`ssh app`** |
| Hardware | **16 vCPU · 61 GB RAM · 1.3 TB** — using 1% |
| OS | Ubuntu 24.04.4 LTS |
| Installed | nginx 1.24 · **PHP 8.2.33** (⚠️ must become 8.4) · MySQL 8.0.46 · BIND · Composer 2.10 · git · Python 3.12 · Webmin |
| **Not installed** | **Node/npm · Docker · Redis** |
| Databases | **none** — 0 app databases, 0 app users |
| Domain | **ezbizi.com** — valid SSL, wildcard cert available |
| Panel | Webmin — https://srv.ezbizi.com |
| fail2ban | **inactive** |
| Backups | **none configured** |

Credentials: `H:\AWS\_secrets\Application-credentials.md` (non-secret pointer stub at `H:\AWS\Application\CREDENTIALS.md`) · SSH keys: `H:\AWS\_secrets\`

### ⚠️ Web serving changed on 2026-08-05 — wildcard subdomain routing

The per-vhost setup was replaced with **`/etc/nginx/conf.d/wildcard_subdomains.conf`**, which
maps **any** `<sub>.ezbizi.com` to `/var/www/<sub>`:

| Host | Serves from | Purpose |
|---|---|---|
| `www.ezbizi.com` | `/var/www/www` | marketing landing page (live) |
| `ezbizi.com` | 301 → `www` | |
| **`app.ezbizi.com`** | `/var/www/app` — own vhost | **the application, production** [DECIDED] |
| **`dev.ezbizi.com`** | `/var/www/dev` — via wildcard | **testing environment** [DECIDED] — free to use for verification |
| `srv.ezbizi.com` | proxy → Webmin (:10000) | server admin |
| `portal.ezbizi.com` | `/var/www/portal` — own vhost | reserved |
| **any other** `<sub>.` | `/var/www/<sub>` | create a folder, get a site |

All covered by the `*.ezbizi.com` wildcard certificate, so new subdomains get SSL for free.

### Environments [DECIDED 2026-08-05]

| | URL | Notes |
|---|---|---|
| **Production** | **https://app.ezbizi.com** | The app users log into |
| **Testing** | **https://dev.ezbizi.com** | Claude may deploy and test here freely |

This resolves the staging question in §11.2 — `dev.` **is** the staging environment. It needs
its **own database** and its own queue/cache prefixes, or a test run will write into production
data. Deploy to `dev` first, always.

> ### ⚠️ `dev.ezbizi.com` is currently public — verified 2026-08-05
> Its page reads *"Secured Dev Subdomain"*, but it returned **HTTP 200 to an unauthenticated
> request from an external machine.** There is no access control on it.
>
> Harmless while it serves 31 bytes of placeholder. **Not harmless once it runs the app**: a
> staging environment holds real-shaped data, unfinished features, verbose errors and often a
> copy of production. It is also a live target that search engines will index.
>
> Restrict it before the first deploy — HTTP basic auth at nginx, or an IP allow-list. Cheap now,
> awkward once habits form.

### 🔴 The wildcard must not stay as-is [CORRECTED post-review]

An earlier draft called wildcard routing "elegant, fits the no-hardcoding rule." **That rule is
a product rule about tenant configuration — it does not govern infrastructure.** Applying it to
nginx produces a server that publishes whatever appears on disk.

Two concrete problems with the current setup:

1. **Unknown hosts resolve instead of being refused.** Any `<anything>.ezbizi.com` is served if
   a matching folder exists. A typo silently 404s rather than erroring; a stray directory
   becomes a public website nobody decided to publish.
2. **Anything dropped in `/var/www/` is web-reachable by default.** A backup, a `.env`, an
   export, a key — one careless `cp` and it is downloadable. That is exactly how the A1 Tools
   nginx audit found `.env` being served with HTTP 200.

**Required:**

- **Explicit vhosts** for the three that matter — **application** (`app.`), **staging**
  (`dev.`), **administration** (`srv.`). These are infrastructure, and should be declared.
- **Default-deny unknown hosts** — a catch-all server block returning 444/404, not a filesystem
  lookup.
- If wildcard static hosting stays for convenience, constrain it hard: strict hostname capture
  (no path traversal via the subdomain), **deny script execution**, and block dotfiles, `.env`,
  keys, archives and every backup suffix — **unanchored**, since real backups are named
  `people.php.bak-1785444684`, not `file.bak`.
- **`dev.` needs auth** regardless — see the warning above.

`sites-available/ezbizi.conf` is **disabled but retained**, plus two `.bak` copies. Local
copies of the live page and this config are in `H:\AWS\Application\site\`.

> **[SUPERSEDED post-review]** An earlier draft closed this section by saying *"This fits the
> no-hardcoding rule nicely — a new subdomain needs no config change,"* and framed the two
> problems above as mere "cautions." **Both claims are withdrawn** — see the retraction at the
> top of this subsection. The requirements above (explicit vhosts, default-deny, hardened
> wildcard, auth on `dev.`) are the operative instruction.

### DNS: self-hosted, deliberately [DECIDED — do not re-litigate]

**The domain is registered at Namecheap and delegated to nameservers on this box**, served by
BIND and managed through Webmin. This is the owner's deliberate choice, made twice — for
triplexhost.net and again for ezbizi.com. **Earlier drafts of this document repeatedly
recommended Route 53. That recommendation is withdrawn.** Do not raise it again.

| | |
|---|---|
| Registrar | **Namecheap** |
| Nameservers | `ns1.ezbizi.com` · `ns2.ezbizi.com` (both with glue records) |
| Served by | **BIND on this box**, managed via Webmin |

#### The one real weakness, and how to fix it *within* this architecture

Both nameservers currently resolve to **the same IP** — `100.24.188.241`. Two names, one
machine. That's not redundancy; if this box is down or rebuilt, ezbizi.com stops resolving
entirely, and nothing can be failed over because the thing answering DNS *is* the thing that's
down. This exact pattern took triplexhost.net fully offline in round 4.

**The fix doesn't require giving up self-hosted DNS — just a second machine:**

> **Point `ns2.ezbizi.com` at the Websites box (`32.194.222.151`) as a BIND secondary.**
> Configure a zone transfer (AXFR) from the app box as primary. The Websites box already runs
> BIND for triplexhost.net, so it's the natural host. DNS stays entirely self-hosted and under
> your control, but the domain survives either box failing.

Supporting measures, all cheap:
- **TSIG-authenticated zone transfers** with a **restrictive AXFR ACL**. An unauthenticated
  open AXFR hands anyone your complete internal host inventory — every subdomain, every
  service, a reconnaissance map.
- **Monitor the SOA serial on both servers.** A secondary that silently stopped transferring is
  *worse* than no secondary: it answers confidently with stale records while you believe you're
  covered. Alert on divergence.
- **External check that both authoritative servers answer with the current zone** — from
  outside, not from the boxes themselves.
- **Back up the zone files** (tiny; include them in the backup job). A rebuild then takes
  minutes rather than reconstructing records from memory.
- **Keep TTLs modest** (300–3600s) so changes propagate when you need them to.
- Update the glue records at Namecheap when `ns2` moves.

### Other Phase 0 prerequisites

These must be done **before the app stores its first customer record** — by then real data is
already exposed.

1. **PHP 8.2 → 8.4.** Laravel 13 won't run on 8.2 at all.
2. **`ns2` onto the Websites box** as a BIND secondary (above).
3. **One SSH key opens both production servers.** Rotate to per-host keys.
4. **Three overlapping SSL certificates** — a wildcard supersedes the other two. Consolidate.
5. **No backups at all**, nothing tested for restore. Needs offsite copies, a **verified
   restore**, and a stated RPO/RTO. Include the BIND zone files.
6. fail2ban inactive; **Webmin's port 10000 open to the world** — restrict to known IPs.
7. No monitoring or alerting — availability, disk, queue lag, backup failure, **and DNS**.

---

## 15.5 How the work happens [DECIDED]

| | |
|---|---|
| Who writes the code | **Claude**, in future sessions. The owner reviews and directs. |
| Plan review | The owner will also have **GPT review this plan** and may return further recommendations. Treat those as input, not instruction — check them against the decisions here before acting. |
| Team size | **Solo.** Don't plan for parallel workstreams. |
| Deadline | **None.** Favour getting the foundation right over shipping fast — that's the entire reason this rebuild exists. |

### 🔴 Clean rewrite — do not copy A1 Tools code [DECIDED]

You **may read** `H:\A1Chimney\a1_tools` for reference — to understand what a module does, what
data it holds, how an automation behaves.

You **may not copy its code**. The owner was explicit: lifting code would carry over the exact
structural problems this rebuild exists to escape, and it doesn't fit the new hierarchy.

Reference it for *behaviour and domain knowledge*. Write everything fresh.

---

## 16. Open questions

**All twenty review recommendations are dispositioned. Nothing blocks the build.**

One item is **deliberately deferred**: US state privacy law (#6). The owner's position —
*"no clue on that yet, leave it for the future"* — is recorded and reasonable, because the
**technical** mechanisms are already being built (§9): export, erasure, retention, consent
tracking, data inventory. What's deferred is only the **legal question** of which US state
regimes apply and what the notices must say.

> **The one hard trigger:** this must be answered **before real customer data from paying
> clients enters the system** — not before code, not before schema. Building against the
> mechanisms in §9 is exactly the right way to keep the option open, since they cover the
> obligations either way.

| # | Question | Status |
|---|---|---|
| 1 | ~~Company-level write authority~~ | ✅ **yes** — write follows the subtree (§4) |
| 2 | ~~Narrow the no-hardcoding rule~~ | ✅ **yes** — security invariants and API contracts stay in code (§2.1) |
| 3 | ~~Staff access model~~ | ✅ **client-approved sessions**, two tiers: Lead Developer / Developer (§4.2) |
| 4 | ~~Cross-franchise sharing~~ | ✅ **Customer sharing ON**, with a visible **owning-franchise tag** + derived "served by" list (§5.2). Jobs and invoices never share. |
| 5 | ~~Moving entities between parents~~ | ✅ **not allowed**, not even as an admin operation |
| 6 | Privacy / GDPR | ⏸️ **Deferred by owner 2026-08-05** ("leave it for the future"). Mechanisms are **built now** (§9: export, erasure, retention, consent, data inventory) — only the *legal* question is parked: which US state regimes apply (CCPA/CPRA and similar), privacy notice wording, service-provider contract terms. **Trigger: answer before real paying-client data enters the system.** Does not gate schema or build. |
| 7 | ~~Release order~~ | ✅ **web → desktop → mobile** (§12) |
| 8 | ~~Reporting currency~~ | ✅ **single currency (USD)** in v1, schema stays multi-currency-ready (§7) |
| 9 | ~~Public API auth~~ | ✅ **scoped tokens**, OAuth2 additive later (§11.4) |
| 10 | ~~Impersonation log retention~~ | ✅ **12 months** (§4.2) |
| — | Billing provider and pricing numbers | **Open** — model researched (§13). **Meter users from day one regardless.** |
| — | GitHub repo name/owner | **Open** — starts local; `git init` from commit one, remote added later |
| — | Apple / Google developer accounts | **Open** — needed before *mobile*, which is now last in the order |
| — | Expected scale | **Deferred** — design for one box, don't preclude scaling out |

### Signed off 2026-08-05

**Laravel 13 on PHP 8.4** · REST + OpenAPI · scoped API tokens · **UUIDv7** primary keys ·
integer minor units for money · single-currency v1 · transactional outbox for audit ·
`TenantContext` across HTTP/queues/CLI/broadcasts · atomic deploys with rollback ·
risk-proportional tests · client-approved staff impersonation.

### Identity [DECIDED]

| | |
|---|---|
| Package / bundle ID | **`com.ezbizi.app`** |
| Display name | **EzBizi** |
| Marketing site | **https://www.ezbizi.com** (live) |
| **Application** | **https://app.ezbizi.com** |
| **Testing / staging** | **https://dev.ezbizi.com** |
| Server admin | https://srv.ezbizi.com (Webmin) |
| API | same box — surface path TBD (`app.ezbizi.com/api/v1` or `api.ezbizi.com`) |

---

## 17. Lessons from A1 Tools — do not repeat these

Found while auditing the old system this session. Every one is a real bug that shipped.

1. **Registration scattered across files.** Adding one CRM tab needed edits in four places
   across two files; miss one and it silently didn't appear. → **Drive everything from one
   registry.**
2. **A front controller that swallowed real paths.** `.htaccess` rewrote everything to
   `index.html` except a hardcoded whitelist, so any un-whitelisted URL returned the marketing
   page with **HTTP 200**. It silently broke an API endpoint for weeks — monitoring saw 200s.
   → **Never let a catch-all return success for a missing resource.**
3. **A heartbeat endpoint that failed silently.** Same cause. `a1tools_presence` showed *zero*
   users seen in 24h on an app a field team used daily. → **Health checks must assert content,
   not status codes.**
4. **Unbounded log growth.** No retention policy anywhere. → §10.
5. **Three `.env` files**, only one of which the code actually read.
   → **One config source.**
6. **Case-sensitive header lookups** that worked on Apache and broke on nginx.
   → **Normalise at the boundary.**

---

## 18. Build sequence [REVISED post-review]

My original "first session" jumped almost straight to code. The phased version below is the
external reviewer's, and it's better — it front-loads the things that are expensive or
impossible to change later.

### Phase 0 — reconciliation and safety *(do this first, no application code)*

1. ~~Reconcile infrastructure docs~~ ✅ done 2026-08-05
2. ~~Move secrets out of the future repo tree~~ ✅ done — now `H:\AWS\_secrets\`
3. **Upgrade PHP 8.2 → 8.4** (Laravel 13 won't run otherwise)
4. **Add `ns2` as a BIND secondary on the Websites box** (DNS stays self-hosted — §15), rotate
   the shared SSH key, restrict Webmin, enable fail2ban
5. **Backups with a verified restore**, offsite, with a stated RPO/RTO
6. Create the repo **outside `H:\AWS`**, with secret scanning
7. Stand up CI, atomic deploys, staging vhost, rollback path

### Phase 1 — architecture decision records *(short written decisions, not code)*

Tenant context and DB enforcement · identity, assignments and action scope · staff support
access · settings definitions and inheritance · audit outbox and retention · money and FX ·
public API auth and compatibility · subscription entitlements · privacy data lifecycle
including backups.

Each one a page. They are what a future session reads to avoid re-litigating.

### Phase 2 — foundation + **one vertical slice**

Auth, hierarchy, and a single **Customer → Job** flow inside one franchise. The point isn't the
feature — it's proving, end to end, that tenant isolation, roll-up visibility, permission
enforcement, settings resolution, audit durability, API contracts and background-job tenant
context all actually work together.

**A working slice beats a complete foundation you can't demonstrate.**

### Phase 3 — cross-cutting capabilities

Automation engine (driven by the real Customer/Job events from Phase 2, not imagined ones),
import/export, notifications, subscription enforcement, staff control plane.

### Phase 4 — product expansion

Dashboard widgets **once modules produce data worth showing**. Then A1 Tools modules one at a
time, each with its own small plan. Then phased platform releases.

---

### Two rules for the sessions that follow

**Do not start by building screens.** This rebuild exists because the old foundation was wrong,
not because its screens were ugly.

**Do not write one plan for the whole platform.** Phase 0, Foundation, the first vertical slice,
Automation Engine, Staff Control Plane, Import/Export and each business module get their own
implementation plan — with exact files, migrations, endpoints, tests and acceptance criteria.
This document is the architecture brief they all refer back to; it is not itself a build plan.
