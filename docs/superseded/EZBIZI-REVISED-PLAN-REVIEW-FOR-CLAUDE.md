# EzBizi Revised Build Plan — Second-Round Review for Claude

**Date:** 2026-08-05  
**Plan reviewed:** `H:\AWS\Application\EZBIZI-BUILD-PLAN.md`  
**Plan version observed:** 2026-08-05 at approximately 2:08 PM Eastern

## Request to Claude

The revised plan is substantially stronger and now works well as an architecture brief. Please make one more consistency pass before treating its decisions as signed off. For every item below, either update the plan or explicitly explain why the contradictory text should remain.

The plan is suitable for preparing Phase 0 tasks. It is not yet suitable for Phase 1 sign-off or application implementation because several original sections still contradict their revisions.

## Must fix

### 1. Company authority is simultaneously resolved and open

Company-level write access is granted at lines 103–109. Lines 119–120 then declare the same question open and assume view-only.

Delete the stale open block. Also replace:

> A user is assigned at exactly one of Organization, Company, or Franchise level.

with:

> A user may have multiple assignment rows. Each assignment targets exactly one Organization, Company, or Franchise, enforced by an XOR constraint.

### 2. The platform-staff model contradicts itself

The tenant role table still defines Developer as a tenant role at line 131, while §4.2 correctly says platform staff use a separate control plane. Remove Developer from the tenant role table.

The plan also says:

- Staff customer access requires client approval.
- The staff member remains the actor in the audit trail.
- Every action is audited.

But lines 181–207 exempt the Lead Developer from approval, impersonation records and business auditing.

If the owner retains that decision, the plan must say explicitly and consistently:

- Client approval applies only to regular Developers.
- “Audit everything” excludes the Lead Developer.
- The staff-access record cannot prove every access or change.
- Compromise or misuse of the highest-privilege account may leave no business audit trail.

The stated rationale is also weak operationally: development noise should be kept out of production by using `dev.ezbizi.com`, a demo organization and non-production data. Making production superuser activity invisible solves the wrong problem.

### 3. The no-hardcoding recommendation was marked accepted but not applied

Lines 33–46 retain the original absolute wording: every variable, permission, default and validation is runtime-configurable. Line 962 says the rule was narrowed so security invariants and API contracts remain in code, but §2.1 does not say that.

Replace §2.1 with a configuration taxonomy:

1. Platform invariants in version-controlled code
2. Deployment configuration
3. Platform-managed release controls
4. Billing-controlled entitlements
5. Tenant configuration
6. User preferences

The governing rule should be:

> No organization-specific business behavior or presentation should require a deployment. Platform invariants and configuration schemas remain version-controlled; permitted values and tenant overrides are data-driven.

### 4. The old tenancy strategy remains beside the corrected strategy

Lines 226–237 still say every row carries `organization_id` and a global ORM scope or middleware is the boundary. That contradicts the later `TenantContext`, global-table allow-list and database constraints.

Replace it with:

> Every tenant-owned row carries `organization_id`. Isolation is enforced through TenantContext, authorization policies, database constraints and organization-scoped infrastructure keys. ORM scopes are only one supporting control.

### 5. The privacy launch scope contradicts itself

Lines 56–59 still promise international clients and GDPR compliance from day one. The revised §8 and §9 say US-only launch with GDPR legal work deferred.

Keep the US-only decision and update the introduction. However, do not defer all privacy/legal review until international expansion. A US launch can still require privacy notices, service-provider contract terms, verified rights-request handling and other controls depending on applicable state laws and thresholds.

Primary reference: https://oag.ca.gov/privacy/ccpa

Have counsel determine applicability before the US launch; that is a smaller and different task from full international GDPR readiness.

### 6. The audit section was not updated

§10 still recommends ordinary asynchronous queue writes at lines 531–548. The signed-off summary promises a transactional audit outbox at line 979.

Rewrite §10 around:

- An outbox record committed with the business transaction
- Asynchronous delivery to an append-only audit destination
- Actor, tenant, scope, target, correlation and causation identifiers
- Sensitive-field redaction
- Integrity/tamper controls
- Behavior when audit delivery is unavailable
- Twelve-month Developer impersonation retention
- The explicit Lead Developer exception and its consequences, if retained
- “Platform staff,” not a Developer tenant role

### 7. The old module order contradicts the revised build sequence

§14 still says Foundation → Dashboard → Developer tab → Settings and repeats the already-corrected claim that all settings scopes expose the same options.

That contradicts §18, which correctly builds a Customer → Job vertical slice first and delays dashboard widgets until modules produce useful data.

Delete §14 or replace it with a short reference to §18. Organization workflows belong in tenant administration; staff logs and organization creation belong in the separate staff control plane.

### 8. Feature flags and paid entitlements are still conflated

Line 774 proposes feature flags for pricing tiers and add-ons. These must remain separate:

- Release flags: EzBizi-controlled rollout
- Entitlements: billing-controlled product access
- Permissions: role-controlled user access
- Tenant settings: administrator-controlled behavior

Also define:

- Whether “one month” means 30 days or a calendar month, and in which timezone
- Which writes remain permitted after expiration
- Enforcement across HTTP, public API, scheduled tasks, workflows, queues, imports, tokens and WebSockets
- Whether expired organizations may export data, pay, recover MFA or exercise privacy rights

## Additional corrections

### OpenAPI source of truth

The plan calls the API “contract-first” but later says the OpenAPI specification is generated from code. Choose one authoritative model:

- Contract-first: the specification is authored/versioned first and implementation is verified against it.
- Code-first: implementation metadata generates the specification and CI detects breaking changes.

Either can work, but the current wording claims both.

### Deployment gate

“CI on push” does not protect `main` when development happens directly on `main`. A failing commit has already landed. Protected `main` should require passing checks before merge/deployment, preferably with short-lived branches and a lightweight PR even for one developer.

Deploy only the exact commit that passed CI. Isolate staging database, storage, Redis/queue namespaces, mail, push and webhook credentials so staging cannot affect customers.

### Cross-franchise sharing

The section is still marked “owner deferred,” while §16 says it is closed. Get explicit owner sign-off.

Turning customer sharing on is not harmless: it expands personal-data visibility across scopes. Require authorization, preview, confirmation and audit in both directions.

Invoice ownership should follow the legal seller entity, not automatically the Franchise geography. Before implementing invoices, define which Organization, Company or Sub Company owns the legal name, tax identity, address and numbering sequence shown on the invoice.

### Money schema wording

The currency column makes future multi-currency support easier, but multi-currency will still require additive migrations for rates, reporting amounts and conversion provenance. Replace “without a migration” with “without a destructive rewrite or ambiguous historical backfill.”

### Secrets

`Application-Key.pem` was successfully moved to `H:\AWS\_secrets`, but `Application-credentials.md` still exists in `H:\AWS\Application` while Phase 0 says secrets were moved. Move the credentials file into `_secrets` too and leave a redacted pointer in the documentation directory.

### Wildcard nginx routing

Infrastructure routing should not be driven by the no-hardcoding product rule. Unknown hosts should be default-denied. Keep explicit vhosts for application, staging and administration.

If wildcard static hosting remains, use a strict hostname capture and deny script execution, dotfiles, environment files, keys, archives and backup suffixes. Do not let arbitrary files placed under `/var/www` become public by default.

### Self-hosted secondary DNS

The owner’s self-hosted DNS decision can remain. Add TSIG-authenticated transfers, restrictive AXFR ACLs, SOA serial monitoring and an external check proving both authoritative servers answer with the current zone.

### Workiz pricing evidence

Use the official source instead of relying primarily on secondary pricing articles:

https://www.workiz.com/pricing-plans/

It supports the current base tier prices, annual discount and included member counts. It does not publicly substantiate every extra-seat and add-on number stated in the plan. Date the research and keep it informational; do not let a competitor’s current packaging prematurely dictate EzBizi’s entitlement schema.

## Improvements that should remain

The following changes are strong and should be preserved:

- Laravel 13 on PHP 8.4 instead of PHP 8.2
- Mandatory TenantContext and database-enforced tenant relationships
- UUIDv7 with explicit authorization checks
- Correct Sub Company settings inheritance
- Transactional workflow outbox, idempotency and loop protection
- Webhook SSRF and replay protection
- Risk-proportional testing
- Atomic deployments, rollback and staging
- Web → desktop → mobile release order
- Cache encryption/expiry/logout rules
- Single-currency USD v1
- Public API versus staff control-plane separation
- Phase 0 prerequisites and one-page architecture decision records
- Customer → Job vertical slice before dashboards and generic platform work
- One independently reviewable implementation plan per subsystem

## Final disposition

After the contradictions above are removed, this document will be ready to serve as the stable architecture brief for a detailed Phase 0 plan and Phase 1 ADRs.

It should continue to state that it is not itself an executable application implementation plan.

Please respond with:

1. Changes made
2. Recommendations rejected and why
3. Owner decisions that still need confirmation
4. Whether the resulting document is internally consistent
5. Whether it is ready for Phase 0 planning, Phase 1 ADR work, or application implementation
