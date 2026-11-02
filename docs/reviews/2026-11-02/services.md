# Services — review, 2026-11-02

Measured on production, release `20261030222016-27b215107bb2`, through the live API (key 566, view = A-1 Chimney) and read-only queries on the box. No data was changed.

## 1. Working
- Catalogue list: `GET /services` → 200, 37 services for A-1 Chimney, prices, descriptions and photos all returned.
- Photos: 30/45 services org-wide have one; 0 attachment rows orphaned, 0 files missing on disk.
- Settings (taxonomy): `GET /services/groups` → 200 (Chimney, Garage Door); labels list → 200.
- Tab grants: Catalogue and Settings chips are separate grants; writes 403 without the chip, reads stay open for Jobs' line picker (ServiceTabPermissionsTest, live since 0.0.0240).
- Atomicity: `service.*` outbox events commit with the record (fix live since 09-28; ServiceEventAtomicityTest).
- Dashboard glance: figures count real groups only (live 0.0.0354; ServicesGlanceTest).
- Data integrity: 0 duplicate names within a place; 0 failed jobs and 0 logged errors mentioning Services in 30 days.

## 2. Broken
- Nothing broken found. No fix SHAs.

## 3. Cohesiveness (for UI-UX)
- Groups and labels are almost unused: 36 of A-1 Chimney's 37 services have no group and no label, and 3 of 4 groups org-wide hold nothing. The Group pie on the dashboard is therefore one big "Ungrouped" slice.
- Services' list has no search box and no group/label filter, while Inventory and Customers have search; with 37+ rows this is the one list in the app you scroll to find something.

## 4. Suggestions (ranked)
1. Link job lines to the catalogue. `job_lines.service_id` exists but 0 of 1 job lines in production use it, so the catalogue does not yet feed pricing on jobs. Payoff: consistent prices and per-service revenue in Reports.
2. Search + group filter on the Catalogue list (match Inventory). Payoff: findability at 37+ rows.
3. A one-time pass grouping the 37 A-1 services (Inspection, Sweep, Repair, Masonry, Installation…). Payoff: a useful dashboard pie and filter.
4. Audit trail: Services still writes no audit rows (registry `auditExemptBecause`, TODO #35, fleet-wide decision). Payoff: who changed a price and when.
5. Automations: the module declares 0 actions by spec decision 8. If rules should change prices or retire services, that's a decision for Jose.

## 5. Needs Jose
1. Should "Deposit", "Remaining Balance", "Scope of Work:", "Service" and "Vendor Shield Subscription" stay in the Services catalogue? They are billing lines carried over from A1 Tools, not services.
2. Photos for Brick Repair and Pellet stove cleaning: Workiz has none on any branch. Send images, or leave them blank?
3. Spec decision 8 ("No automation action writes the catalogue"): does it still stand?
