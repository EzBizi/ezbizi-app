# Plan: retire Airtable from EzBizi (2026-11-03)

Input: `docs/reviews/2026-11-03/airtable-retirement-inventory.md` (branch `session/airtable-inventory`): 6 bases, 46 tables, 42 read by EzBizi, 4 unmapped. Org: Triplex Group (a-1-chimney). Plan only, no code.

Rules from desk/Jose: one base at a time; EzBizi writes first; nothing switches until EzBizi's numbers match Airtable's; then disconnect. Every integration removable (EzBizi primary).

## 1. What exists today (measured in code on origin/main)

| piece | where | what it does |
|---|---|---|
| Hourly snapshot | `ezbizi:airtable-snapshot` → `AirtableSnapshot::sync` → `airtable_records` (+ `airtable_record_versions`) | copies every table a `financial_sources` row maps |
| Role mapping | `FinancialSource` (roles: pnl, repair, deposits, refunds, disputes, check_returns, hq_expenses, weekly_data, monthly_bills, lease_reviews, marketing, office_per_job, companies, payroll), scoped by `company_id`/`franchise_id` + `filter_field/filter_value`, with validity dates | tells reports which table means what, per branch |
| Readers | `SourceFigures` (repair report, repair split, refunds), `ReportSources`, `AirtableSections`/`DailyTexts`/`PeriodReport`, `SendWhatsAppPeriodReports`, `MarketingReport`, `FinancialsHome` | read via `FinancialSource::rows()` |
| Financials grid | `financial_bases/tables/fields/records/views`; `AirtableMirror` links every snapshot table as a mirror table | the EzBizi-native store already exists |
| Write-back | `AirtableWriter` (update/create/delete, conflict check vs live Airtable) used by `FinancialsController::writesToAirtable` while a table is a mirror | edits go to Airtable first today |
| Per-table switch | `financial_tables.cut_over_at` + `POST financials/tables/{table}/cut-over` (undo supported, audited `financials.cut_over[_undone]`, org view only). `FinancialSource::rows()` already reads `financial_records` instead of `airtable_records` once the matching table is cut over; `FinancialsAirtableRefreshCommand` skips cut-over tables | the cutover mechanism is built |
| Native structured tables | `financial_adjustments` (refund/dispute/check_return/repair_move), `financial_bills`, `financial_results` (marketing lead-source results), `financial_repair_weeks`, `invoices`/`invoice_lines`/`invoice_payments`, `financial_charge_sheets` | some data is already entered in EzBizi alongside Airtable (`SourceFigures::refunds` merges both, tagged `source: airtable|ezbizi`) |
| **Profit & Loss screen** | `ProfitLossController::show` → `AirtableBase::records()` **live Airtable API**, parsed by `AirtablePnl` | does NOT use the snapshot or `cut_over_at`. Must get a new reader before the P&L base can cut over |
| Client | `client/lib/screens/financials_screen.dart` (cut-over button, mirror banner), `financials/financial_sources.dart`, `profit_loss_screen.dart` (connection + table picker) | |

Consequence: retirement is mostly **finishing a path that exists** (mirror → cut over → stop snapshot), not a new data model. The plan below reuses `financial_records` as the landing store for every base, and promotes to structured tables only where EzBizi already has one or a report needs typed sums.

## 2. Target home per base

Principle: phase A lands every table in its existing Financials mirror (`financial_records`, same columns, same `source_name`), so readers keep working unchanged via `FinancialSource::rows()`. Phase B (optional, per base, after cutover is stable) moves rows into structured tables listed below. Phase A alone retires Airtable; phase B is cleanup.

| base (tables) | phase A home | phase B structured home (reuse) | field mapping summary |
|---|---|---|---|
| Disputes And Refunds (Refunds 153, Disputes 32, Checks Returns 6) | mirror tables | `financial_adjustments` kind refund / dispute / check_return (exists; already merged in `SourceFigures::refunds`) | date→date, amount→cents, technician, job_number, reason; status → new nullable `status` column; franchise from the source's `franchise_id`/filter |
| Invoices System (Weekly Data 10, Companies 20, Weekly HQ Expenses 130, Ave Office expenses per job 17, Reviews and office lease cost 14, Monthly Billings 64, Marketing ANGI SWEEP 24, ace Daily 114, ace weekly 17) | mirror tables | Companies → existing `companies`/`franchises` (match by name, store the Airtable label as alias; no new table). Monthly Billings + HQ expenses + lease/reviews → `financial_bills` (lines JSON, total_cents, period). Marketing → `financial_results` (source, date, area, calls, jobs, cost/sales/profit cents). Weekly Data / office-per-job → stay in `financial_records` (derived figures, low row count) | names per `FinancialSource` field map (amount, date, company, category, total, jobs) |
| Deposit Jobs Tracker (NJ CT TX OH GA IL DMV NY NW = 1,675 rows) | mirror tables | `invoice_payments` linked to `jobs` by `job_number` where the job exists (reuse Jobs/Invoices; a deposit is a payment on a job). Rows with no matching job stay in `financial_records` | job_number, date, amount→cents, technician, company/state → franchise |
| Repair Teams (NJ CT OHIO TX IL DMV; GA, MA empty) | mirror tables | `financial_repair_weeks` (exists) + `financial_adjustments` kind repair_move (exists) | parts, tech_share, external_share, contracting, employee, helper, gas, operation, hours, job_amount, approval (FIELD_KEYS) |
| Technicians Payrolls (Example 16, Table 1 copy 0) | mirror table | HR: `pay_rates` + `time_clock_*` already model pay inputs; payroll output stays in `financial_records` until HR owns a payroll run | technician→user/employee match by name, amount, period |
| Profit And Loss (11 tables, NJ…MA, 1,113 rows) | mirror tables, read by a **new** P&L reader over `financial_records` | none for now: the sheet is a per-week ledger of categories; keep it as records. Later: compute P&L lines from the structured tables above (that is when Airtable's numbers stop being the reference) | category, week label, amount, Total / Total Balance rows (`AirtablePnl::TOTAL`, `NET`) |

### The 4 unmapped tables

| table | home | why |
|---|---|---|
| Deposit Jobs Tracker / MA (26) | add a `deposits` `FinancialSource` for the MA franchise on the existing MA table; then same path as other states | it is the same shape as the 9 mapped state tables; only the mapping was never made (MA re-homed 10-06) |
| Deposit Jobs Tracker / Everdoors (17) | `deposits` source scoped to the **Everdoors company** (`company_id`, no franchise) | Everdoors is a company, not a state; `FinancialSource` already supports company scoping |
| Invoices System / Invoices (9) | EzBizi `invoices` (+ lines, payments) — the Invoices module | EzBizi already issues invoices with statuses draft→paid; 9 rows is a one-time import, then entry moves to the Invoices module; no Financials role needed |
| Disputes And Refunds / Debt & Loans (4, last row 2026-05) | new Financials (native, non-mirror) table "Debt & Loans" in the HQ base, org-scoped; optional new role `debt_loans` only if a report must read it | 4 stale rows, no reader; a structured table is not worth a migration until someone reports on it. Org-level (HQ) because loans are not per branch |

## 3. Per-base procedure (same steps each time)

1. **Freeze map.** Confirm every table of the base has a mirror (`AirtableMirror::linkAll`) and every reader role has a `FinancialSource`. Fix unmapped ones (MA, Everdoors) here.
2. **Backfill (one-time import).** Run `AirtableMirror::refresh` per table to land the latest snapshot in `financial_records` with `source_record_id` kept. Dump `financial_records` + `airtable_records` for the base first (`/var/backups/ezbizi/`, per dump-before-rewrite rule). For phase B tables, a dry-run import command writes structured rows keyed by `source_record_id` (idempotent, re-runnable), never deleting the mirror rows.
3. **EzBizi writes first.** People edit in Financials (grid) — already possible; while mirrored, edits go to Airtable via `AirtableWriter` and come back. From the parity window on, announce to the branch staff: enter in EzBizi only. Airtable edits during the window show up as parity diffs.
4. **Parity check** (section 4) runs daily against the live snapshot for 14 consecutive days (P&L: 2 complete weeks + 1 month-end close) and must be green every day.
5. **Cut over** the base: hit cut-over on each of its tables (sets `cut_over_at`; `AirtableWriter` stops for those tables; refresh command skips them; `FinancialSource::rows()` reads EzBizi). Add a config switch per base so the whole base flips at once and a reader can assert it: `config/airtable.php` → `cutover.<base_id> => bool` (env `AIRTABLE_CUTOVER_<KEY>`), checked alongside `cut_over_at` (either flips the reader; the flag exists so the desk can flip/rollback a base without clicking N tables).
6. **Watch**: keep the snapshot running read-only for 14 more days; parity keeps running as a drift alarm (Airtable should now freeze; any new Airtable row = someone still entering there).
7. **Detach**: remove the base's `FinancialSource`s from the snapshot mapping, stop its rows in the hourly job, keep `airtable_records` for the retention period.

**Rollback** (any step before 7): undo cut-over (existing `undo`, re-runs `AirtableMirror::refresh`) or set the config flag false. Edits made in EzBizi after cutover would be overwritten by the refresh, so rollback first exports EzBizi-only rows/changes (records with `source_record_id` null or `updated_at > cut_over_at`) to CSV for re-entry in Airtable. After step 7 rollback = restore the dump.

## 4. Parity check (concrete)

New read-only artisan command `ezbizi:financials-parity {base} {--from} {--to}` (plan only), run daily by the scheduler, result written to `storage/app/parity/<base>-<date>.json` and an alert on red.

Compares **Airtable side** = `airtable_records` (fresh snapshot, `gone_at` null) vs **EzBizi side** = `financial_records` (and phase B structured tables, when present), through the same `FinancialSource` field maps the reports use:

- **Row counts** per table, per source (after `owns()` filter): must be equal; list missing/extra `source_record_id`s.
- **Per-month totals per line, within $0.01**, computed in integer cents:
  - P&L: per table × week × category, the sum of detail lines; plus the sheet's own `Total` and `Total Balance` per week; plus per calendar month (weeks split by day-count is not allowed, so month = weeks whose start date falls in the month, as `AirtablePnl::startOf`). Known self-disagreement in Airtable (one week by $1,613) is compared as-is: EzBizi must disagree identically.
  - Deposits, refunds/disputes/check returns: per franchise × month, sum(amount) and count.
  - Repair: per franchise × team × month, each of parts, tech_share, external_share, contracting, employee, helper, gas, operation, hours, job_amount.
  - Invoices System: per role × company × month, amount/total; marketing: calls, jobs, cost, sales, profit.
- **Report output parity**: render `SourceFigures::repairReport`, `refunds`, the daily/period texts and the P&L week payload twice (forced Airtable read vs forced EzBizi read) for every month since the source's `valid_from`; JSON must be byte-equal after sorting.
- Green = all three equal. Any diff > $0.00 rounded cents is red (the $0.01 tolerance covers float→cents rounding of Airtable decimals only).

## 5. Base order (lowest risk first)

1. **Disputes And Refunds** — 191 live rows, already dual-entered via `financial_adjustments`, one reader (`SourceFigures::refunds`). Proves the procedure. Debt & Loans goes native here.
2. **Technicians Payrolls** — 16 rows, one reader.
3. **Invoices System** — 9 small tables (~410 rows); Invoices (9) imported into the Invoices module; Companies matched to `companies`.
4. **Deposit Jobs Tracker** — 1,718 rows, 11 tables, daily entry by branches; add MA + Everdoors sources first.
5. **Repair Teams** — drives technician pay splits (money to people); `financial_repair_weeks` exists.
6. **Profit And Loss** — last: needs a new reader (ProfitLossController currently calls Airtable live), is the headline number, and month-end matters.

Then: disconnect the Airtable connections (6 `social_connections`, same token), remove `AirtableWriter` paths, stop `ezbizi:airtable-snapshot`, revoke the token in Airtable (Jose).

## 6. P&L reader change (required for base 6)

`ProfitLossController::show` gets a second source: read the P&L mirror table's `financial_records` (in `source_name` shape) and feed the same `AirtablePnl::weeks/week` parser. Picker in `profit_loss_screen.dart` lists Financials tables of role `pnl` instead of Airtable connections once cut over. The parser stays, so disagreement reporting stays.

## 7. Scoping, permissions, audit, automations

- **Scoping**: every row stays org-tenant (`BelongsToTenant`); branch ownership comes from `FinancialSource.franchise_id` / `company_id` (+ filter); structured tables carry `organization_id`, `company_id`, `franchise_id` as `financial_adjustments` already does. HQ tables (HQ expenses, Debt & Loans) are org-level (null franchise).
- **Permissions**: reuse existing — `financials.bases.view/edit` for grid entry, `profit_loss.view` for P&L, `jobs.financials` / Invoices permissions for imported invoices and deposits-as-payments. Cut-over stays org-view + `financials.edit`. No new permission. Branch staff who today type deposits in Airtable need `financials.bases.edit` scoped to their franchise (Jose decides who).
- **Audit**: existing `financials.*` audit prefix; add `financials.backfill` (counts, base) and `financials.parity` (green/red, diffs) entries; cut-over already audited.
- **Automations**: `financial_record.created/updated` already emit; once branches enter deposits in EzBizi those events fire for real (they fire today only via mirror refresh). Check no automation keyed on mirror refresh noise before cutover. Phase B deposits as `invoice_payments` fire Invoices events instead.

## 8. Open questions for Jose

1. Who enters what after cutover — each branch types its own deposits / repair sheets / P&L lines in EzBizi, or HQ only?
2. P&L: keep it as a typed weekly sheet in EzBizi (phase A), or should EzBizi compute P&L from jobs/payments/expenses (later, different numbers)?
3. Deposits: OK to attach them to EzBizi jobs as payments where the job number matches (phase B), or keep them as a separate Financials table?
4. Debt & Loans (4 rows, last May): keep in EzBizi or drop?
