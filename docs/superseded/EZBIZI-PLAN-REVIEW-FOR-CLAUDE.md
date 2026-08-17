# EzBizi Build Plan Review — Recommendations for Claude

**Date:** 2026-08-05  
**Document under review:** `H:\AWS\Application\EZBIZI-BUILD-PLAN.md`  
**Purpose:** Give the plan's author a concrete, evidence-backed review before implementation begins.

## Request to Claude

Please review every numbered recommendation below against the owner's settled decisions and the original plan. For each one, respond with:

1. **Disposition:** Accept / Partially accept / Reject
2. **Reasoning:** Why, including any trade-off or incorrect assumption in this review
3. **Plan change:** The exact section or wording you would change
4. **Owner decision needed:** Any question that cannot safely be resolved technically

These recommendations are input, not instructions. Challenge anything that is incorrect, but please reconcile factual contradictions before building.

## Overall assessment

The document is a strong product and architecture brief. It captures important product decisions, recognizes the danger of tenant leakage, and correctly treats settings, permissions, workflows, auditing, internationalization, import/export, and public APIs as cross-cutting concerns.

It is not yet an executable build plan. Important infrastructure facts conflict with referenced documentation; core authorization behavior remains open; the configuration rule is overly broad; GDPR and audit behavior need deeper design; and the deployment/testing approach is too risky for a multi-tenant system. The first implementation session should be replaced by a short decision and infrastructure-reconciliation phase.

## Blockers to resolve before creating the repository or installing services

### R-01 — Reconcile which server EzBizi will use and what already runs there

The plan says A1 Tools remains on SiteGround and is not being migrated yet (`EZBIZI-BUILD-PLAN.md`, lines 18–19). It later describes the application box at `100.24.188.241` as a clean slate with no application databases (`EZBIZI-BUILD-PLAN.md`, lines 448–464).

The referenced migration record says `tools.a-1chimney.com`, 1.6 GB of files, and a 459 MB database were migrated to the Application box on 2026-07-31 (`H:\AWS\AWS-MIGRATION-STATUS.md`, lines 174–195). That document also identifies an Application box at `18.204.44.252` and says it runs A1 Tools (`H:\AWS\AWS-MIGRATION-STATUS.md`, lines 388–417).

These may be different generations of infrastructure, but the plan does not explain that. Before making changes, perform a read-only live inventory and document:

- The exact host reserved for EzBizi
- Whether A1 Tools or any other production workload shares it
- Current databases, vhosts, ports, services, workers, scheduled tasks, DNS and storage
- The isolation strategy if legacy and new applications share a machine
- Which migration document is authoritative

Also correct two stale references:

- `H:\AWS\AWS-MIGRATION-STATUS.md` does not appear to contain the promised full A1 Tools module list (plan line 444).
- The landing-page source referenced at `H:\AWS\Application\` (plan lines 389–391) is not present in that directory.

### R-02 — Move credentials and private keys outside the future repository

The directory intended to hold the project currently contains:

- `Application-credentials.md`
- `Application-Key.pem`

The repository location is still open (plan line 508). A later `git init` and broad `git add` could expose both files. Move them outside the project directory before repository creation, restrict their filesystem permissions, rotate shared keys, and add automated secret scanning. A `.gitignore` is useful but is not sufficient protection for secrets kept inside a working tree.

### R-03 — Do not label the plan “requirements settled” while build-blocking decisions remain open

The header says requirements are settled (line 4), while the stack still needs sign-off, six explicit questions remain, company-level write authorization is unresolved, the billing/entitlement model is deferred, the repository is undecided, and scale is unknown.

Suggested status:

> Product direction substantially settled; architecture decisions and Phase 0 prerequisites remain open. Not ready for implementation handoff.

## Product and architecture recommendations

### R-04 — Narrow the “nothing may be hardcoded” rule

The underlying goal is good: no organization-specific workflow, presentation, or operating policy should require a deployment. The current wording makes every variable, label, permission, default, and validation runtime-editable (lines 25–38), which risks turning security invariants and API contracts into mutable data.

Suggested replacement:

> No organization-specific business behavior or presentation should require a deployment. Platform invariants and configuration schemas remain version-controlled; permitted values and tenant overrides are data-driven.

Define five separate categories:

1. **Platform invariants in code:** tenant isolation, hierarchy depth, permission identifiers, domain invariants, API contracts and validation schemas
2. **Deployment configuration:** service endpoints, credentials and operational limits
3. **Platform-managed controls:** release flags and subscription entitlements
4. **Tenant configuration:** branding, terminology, workflows and allowed settings
5. **User preferences:** language, dashboard layout and notification preferences

Do not combine feature flags, paid entitlements, permissions, settings and preferences into one generic toggle system. They have different owners and security consequences.

### R-05 — Make the tenant assignment and authorization model unambiguous

“A user is assigned at exactly one level” and “a user can hold several assignments” (lines 95–100) should be rewritten as:

> A user may have multiple assignment rows. Each assignment targets exactly one Organization, Company or Franchise, enforced by an XOR constraint.

Before schema design, resolve whether a company assignment grants write authority within descendant franchises. “Assume view-only” is not safe enough because it affects policies, endpoints, UI behavior, workflow execution and tests.

The plan also needs rules for:

- Overlapping assignments and duplicate visibility
- Same-organization enforcement on every assignment
- Last-administrator protection and administrator recovery
- Deactivated users and franchises
- Invitations, email uniqueness and login when one person has accounts in multiple organizations
- Whether parent-company users see and/or act inside Sub Companies

### R-06 — Separate platform staff identities from tenant roles

The Developer role is described as an organization-global role that crosses all organizations, while normal accounts belong to exactly one organization (lines 110–155). These are different identity domains.

Model EzBizi staff in a separate platform control plane. Support access should be time-limited, reasoned, strongly authenticated, tenant-scoped and separately audited. Prefer a support session in which the staff member remains the actor over silently becoming a customer user. Define client approval, session expiry, permitted scopes, emergency access and notification policy.

### R-07 — Strengthen tenant isolation beyond a Laravel global scope

Lines 165–176 correctly identify tenant leakage as the highest-severity bug class, but a global ORM scope or HTTP middleware is not a complete boundary. Raw queries, route-model binding, queue workers, scheduled commands, exports, WebSocket channels, workflows, caches and file paths can bypass it.

Add:

- A mandatory `TenantContext` used by HTTP, queues, CLI, schedules and WebSockets
- Composite foreign keys or equivalent database constraints preventing cross-organization relationships
- Organization-scoped cache, queue, broadcast and storage keys
- Explicit handling for platform/global tables that should not carry `organization_id`
- A schema test that inventories every tenant-owned table
- Isolation tests for reads, writes, exports, files, background jobs and broadcasts

UUIDs do not provide authorization. If UUIDs are chosen, consider UUIDv7 stored efficiently or a separate public identifier to avoid poor MySQL index locality.

### R-08 — Correct the settings cascade and allowed scopes

The hierarchy includes Sub Companies, but the resolver only walks Franchise → Company → Organization (lines 233–245). It should explicitly support:

`Franchise → owning Company/Sub Company → parent Company if present → Organization → system default`

“All three levels with the same options” (lines 435–441) also contradicts earlier rules: reporting currency is organization-level, currency and timezone are franchise-level, and language additionally permits a user override.

Each setting definition should contain at least:

- Stable key and value type
- Allowed scopes
- Default and inheritance behavior
- Validation schema
- Sensitivity classification
- Edit permission
- Audit/redaction rules
- Cache invalidation/version behavior

Secrets should not be stored as ordinary settings.

### R-09 — Define cross-franchise sharing per data type and action

“Off: each franchise sealed. On: shared sideways” (line 168) is not enough to design ownership or authorization. Clarify separately for customers, jobs, invoices, inventory and technicians:

- Which franchise owns the record
- Who may read, edit, delete and assign it
- Whether identifiers must be unique across the organization
- What happens when the toggle changes
- How duplicates are merged or preserved
- Whether jobs and financial records ever move between franchises

Also reconsider the permanent prohibition on moving entities between parents. Real organizations reorganize, and correcting a mistaken parent should not require deleting history. If ordinary users cannot move entities, define an audited administrative migration operation.

## Security, privacy and audit recommendations

### R-10 — Treat the GDPR section as a design requiring legal review, not a settled implementation

Consent is only one possible lawful basis for processing. Erasure is not unconditional where legal obligations or legal claims require retention. The 72-hour breach notification rule is risk-dependent. The official regulation should be reviewed, especially Articles 5, 6, 17, 25, 28, 30, 32 and 33:

- GDPR: https://eur-lex.europa.eu/eli/reg/2016/679/oj

Blanking name, email, phone and address does not necessarily produce irreversible anonymization. Personal data may remain in audit/crash logs, files, search indexes, caches, workflow payloads, notifications, exports, backups and external providers.

Add a privacy design covering:

- Data inventory, purpose and lawful basis
- Controller/processor responsibilities
- Data-subject request identity verification and case tracking
- Erasure, restriction, correction and legal-hold decisions
- Retention by data category and jurisdiction
- Backup expiration and restoration behavior after an erasure request
- Subprocessors and international transfers
- Data breach assessment and notification workflow
- Irreversible anonymization criteria

Have qualified privacy counsel review the result.

### R-11 — Make audit delivery durable and privacy-aware

Queueing audit writes after the request (lines 324–327) can lose the record after the business transaction commits. Use a transactional outbox written in the same database transaction, then deliver asynchronously to an append-only audit store.

Specify:

- Actor, tenant, scope, action, target and timestamp
- Request/trace/causation identifiers
- Before/after representation rules
- Redaction of credentials, tokens and unnecessary personal data
- Integrity/tamper detection
- Separate access policy for tenant, staff, security and crash logs
- Behavior when the audit subsystem is unavailable

The one- and three-month retention periods need a documented purpose and legal/security justification per category. “Log every error” must never mean recording every request body or secret.

### R-12 — Add a security and disaster-recovery baseline to Phase 0

The current plan mentions risks but defers them until “before production.” They should be prerequisites before the new application stores credentials or customer data:

- Move DNS to a resilient service
- Rotate shared SSH keys and use per-person/per-host access
- Restrict or remove public Webmin access
- Configure firewalling, intrusion prevention and patching
- Encrypt sensitive data, backups and secrets appropriately
- Establish offsite database and file backups
- Define RPO/RTO and test restoration
- Add dependency scanning, secret scanning and security updates
- Threat-model tenant boundaries, uploads, public APIs, WebSockets and webhooks
- Define monitoring for availability, queue lag, errors, disk, database and backup failures

Audit logs are not a substitute for operational logs, metrics, traces and alerts.

## Stack, delivery and testing recommendations

### R-13 — Update the greenfield PHP/Laravel baseline

PHP 8.2 reaches end of security support on 2026-12-31. It is too close to end-of-life for an August 2026 greenfield system:

- PHP supported versions: https://www.php.net/supported-versions.php

Laravel 13 is current, requires PHP 8.3+, and has security support through 2028-03-17:

- Laravel release policy: https://laravel.com/docs/13.x/releases

Recommended baseline: Laravel 13 with PHP 8.4 or 8.5 after verifying all required packages. Document exact versions and an upgrade policy.

Do not install Node on production merely “for tooling.” Build Flutter web or other frontend assets in CI and deploy artifacts. Decide whether Redis is a disposable cache, a real-time dependency, or durable queue infrastructure; those roles need different persistence, eviction and recovery policies.

### R-14 — Replace pull-and-migrate-on-main deployment with a releasable pipeline

The current “push to main → server pulls, installs, migrates” strategy (lines 337–366) lacks gates and rollback.

Use:

- A repository created only after secrets are removed
- Protected `main`, even for a solo developer
- CI for tests, linting, static analysis and dependency/secret scanning
- Reproducible release artifacts
- An isolated staging or pre-production environment
- Atomic release directories or equivalent deployment mechanism
- Backward-compatible expand/contract migrations
- Deployment locks, health checks and content-validating smoke tests
- A documented rollback path
- Verified backups before database migrations

### R-15 — Expand the risk-based test strategy

Testing only tenancy, permissions and settings (line 345) is insufficient for the plan's highest-risk behavior. Keep those tests, but add focused coverage for:

- Authentication, invitations, MFA and staff support sessions
- Workflow retries, deduplication, loops and versioning
- Subscription read-only enforcement across HTTP and background processing
- GDPR export, erasure, backup and audit behavior
- Money conversion and rounding
- Import validation, duplicate handling and partial failures
- Public API authorization, idempotency and compatibility
- Critical Customer → Job business flow
- WebSocket/channel authorization and file access

This is not a request for exhaustive tests. It is a request for automated coverage proportional to tenant, financial, legal and automation risk.

### R-16 — Phase the six client platforms and define distribution

One Flutter codebase does not make six release pipelines free. The plan needs explicit decisions for:

- Windows signing/packaging and updater behavior
- macOS signing/notarization and updater behavior
- Linux packaging and update channel
- Apple App Store and Google Play accounts/releases
- APNs/FCM credentials and supported push behavior by platform
- Web caching and service-worker rules for personal data

Prefer a release order such as web first, mobile second, desktop when a demonstrated workflow needs it. If all six truly must launch together, treat packaging, signing, store review and updater testing as first-class workstreams in the schedule.

Local caching also needs encryption where supported, TTL/version invalidation, logout wiping and a clear definition of whether cached records remain readable offline.

## Domain subsystem recommendations

### R-17 — Complete the workflow engine reliability and security contract

Execution depth alone does not prevent delayed or cross-workflow loops. Before implementation, define:

- Transactional event/outbox behavior
- At-least-once delivery semantics
- Idempotency keys and action deduplication
- Retry, timeout, backoff and dead-letter policy
- Causation chain and total execution budget
- Tenant and permission context for system actions
- Workflow version activation, rollback and historical display
- Schedule timezone and daylight-saving behavior
- Webhook authentication, signature verification and replay protection
- Outbound webhook SSRF protection, secret storage and response limits
- Test mode, disable-on-failure and operator diagnostics

Expose module triggers/actions through a stable registry contract, but initially prove that contract with concrete Customer and Job events rather than implementing an unrestricted generic engine before any business module exists.

### R-18 — Expand the money/FX model

The proposed four fields (lines 257–271) omit the reporting currency and conversion provenance. At minimum capture:

- Original amount and currency
- Reporting amount and reporting currency
- Rate, base/quote orientation and provider/source
- Rate effective time and conversion time
- Manual override actor/reason
- Rounding policy and precision

Use a deliberate decimal or minor-unit representation. Define what happens when an organization changes its reporting currency, how historical reports remain reproducible, and how refunds/adjustments reference their original transaction.

### R-19 — Clarify public API authentication and compatibility

“Token auth with scopes” may be sufficient for first-party personal access, but third-party SaaS integrations may require OAuth2, service accounts, consent and token revocation. Decide the integration use cases before selecting Sanctum-style tokens versus a broader authorization-server approach.

Add API requirements for:

- OpenAPI as the contract
- Idempotency keys for creates and external callbacks
- Deprecation and compatibility policy
- Rate limits per tenant/token and endpoint class
- Cursor or stable pagination where records change frequently
- Webhook/event schema versioning
- Correlation identifiers and consistent error codes

The Flutter client can exercise the public API, but platform staff/control-plane operations do not necessarily belong in the same public surface. Share application/domain services underneath rather than exposing every internal capability publicly.

### R-20 — Complete subscription and deactivation semantics

“One month” must be defined as a calendar month or fixed day count with a timezone. The read-only rule must apply to scheduled jobs, workflows, imports, WebSockets and API tokens, not only HTTP write middleware.

Define which writes remain allowed after expiration, such as:

- Paying or changing billing details
- Exporting data
- Managing password/MFA recovery
- Exercising privacy rights
- Contacting support
- Logging audit and security events

Keep billing-provider details behind an adapter, but settle the pricing/entitlement model before designing feature access. Subscription entitlements must remain distinct from tenant-editable feature settings.

## Recommended build sequence

Replace the current first-session sequence with:

### Phase 0 — Reconciliation and safety

1. Identify the correct host and reconcile the migration documents.
2. Move/rotate secrets and create the repository safely.
3. Secure DNS, host access and backups; run a restore test.
4. Select exact supported runtime versions and local/staging environments.
5. Establish CI, deployment and rollback skeletons.

### Phase 1 — Architecture decisions

Write short, explicit architecture decision records for:

1. Tenant context and database enforcement
2. Identity, assignments, visibility and action scope
3. Platform staff/support access
4. Settings definitions and inheritance
5. Audit/outbox and retention
6. Money/FX representation
7. Public API authentication and compatibility
8. Subscription entitlements
9. Privacy data lifecycle and backup handling

### Phase 2 — Foundation plus one vertical slice

Build authentication, organization hierarchy and one Customer → Job flow in a single franchise. Prove:

- Tenant isolation
- Roll-up visibility
- Permission enforcement
- Settings resolution
- Audit durability
- API contracts
- Queue/background tenant context

### Phase 3 — Automation and cross-cutting capabilities

Add the workflow engine using real Customer/Job triggers and actions, followed by import/export, notifications, subscription enforcement and the separate staff control plane.

### Phase 4 — Product expansion

Add dashboard widgets only when modules produce useful data. Port additional A1 Tools behavior from a verified module inventory, one explicit module plan at a time. Expand platform releases according to the chosen rollout order.

## Minimum owner decisions before Phase 1 schema work

1. Which exact server/environment is for EzBizi?
2. Can company-level assignments act in descendant franchises?
3. What does cross-franchise sharing mean for each record type?
4. Is platform support access client-approved, notification-only, or emergency-capable?
5. What should happen when an organization changes reporting currency?
6. What is the billing metric and entitlement model?
7. What is the first real business vertical slice: Customers → Jobs, or something else?
8. Must all six clients launch together, or may releases be phased?
9. Who will review GDPR roles, legal bases and retention requirements?

## What the eventual executable plan should add

Once the above is reconciled, split this architecture brief into smaller implementation plans. Each plan should identify:

- Exact files and migrations to create or modify
- Schema constraints and indexes
- Public interfaces and event contracts
- Endpoint request/response definitions
- Failure, retry and rollback behavior
- Concrete tests, commands and expected results
- Acceptance criteria and a review checkpoint
- A deployable, independently testable deliverable

Avoid a single plan covering the entire platform. Phase 0, Foundation, the first vertical slice, Workflow Engine, Staff Control Plane, Import/Export and each subsequent business module should be independently reviewable plans.

## Claude response worksheet

| ID | Disposition | Reasoning | Exact plan change | Owner decision needed |
|---|---|---|---|---|
| R-01 |  |  |  |  |
| R-02 |  |  |  |  |
| R-03 |  |  |  |  |
| R-04 |  |  |  |  |
| R-05 |  |  |  |  |
| R-06 |  |  |  |  |
| R-07 |  |  |  |  |
| R-08 |  |  |  |  |
| R-09 |  |  |  |  |
| R-10 |  |  |  |  |
| R-11 |  |  |  |  |
| R-12 |  |  |  |  |
| R-13 |  |  |  |  |
| R-14 |  |  |  |  |
| R-15 |  |  |  |  |
| R-16 |  |  |  |  |
| R-17 |  |  |  |  |
| R-18 |  |  |  |  |
| R-19 |  |  |  |  |
| R-20 |  |  |  |  |

Please conclude your response with:

1. The recommendations you accept unchanged
2. The recommendations you would modify and why
3. The recommendations you reject and why
4. A consolidated list of owner questions
5. Whether you consider the revised document ready for implementation planning
