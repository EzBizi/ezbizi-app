# Human Resources — review, 2026-11-02

Measured on production (A1-Group, read-only, counts only) on 2026-11-02. Nginx
access logs for 14 rotations and Laravel logs for 7 rotations, every `api/v1/hr/*`
and `me/signature` request.

## 1. Working

- HR API traffic: 7,505 × 200, 102 × 201, 64 × 422 (form validation), 2 × 403,
  2 × 409, 1 × 401, 3 × 499. **Zero 5xx on any HR route** in the logs kept.
- No HR, onboarding, invitation, PTO or contract job in `failed_jobs` in the last 7 days.
- Onboarding: 160 on record — 93 completed, 18 in progress, 49 cancelled. None
  in progress for more than 30 days, none waiting on final approval.
- Signed documents: 81 onboarding document revisions, **every file present on disk**.
- Contracts: 14 live templates (incl. Recruiter and COO v2 with the standard clauses).
- Invitations: 8 open, 1 held until the employer signs (as designed).
- Paid time off: on, 2 days per quarter, resets on the work anniversary, carryover on,
  90-day wait. 9 used-day records, 1 request waiting for a decision.
- Pay: 56 people have a pay rate on record.
- Released in this cycle and live: permanent deletion (hr.delete), Payroll tab, PTO
  Total/Used/Left with dated days off, past resets, upcoming grants, staff requests
  with approval, draft contract PDFs, mobile share-sheet download.

## 2. Broken

Nothing broken found in code. Two things are not code faults but leave screens
showing less than they should:

- **13 active people have no start date.** Their PTO total and balance read
  "needs their start date" and they never accrue. Cause: people on the books before
  the employment record existed; the start date was never invented on purpose.
  Fix is data entry (HR › Records › Employment), not code.
- **24 active people have no contract on record**, so Payroll shows their type as
  Unknown and they are missing from a W-2-only export. Same cause: hired before
  onboarding existed in EzBizi and not among the imported A1 contracts.
- 2 open invitations have expired without being accepted or revoked.

No fix SHA needed this round; the fixes this cycle were already shipped
(c3b4b74a9 delete panel crash, b76044c7f phone download, aef88c9f5 PTO request gaps).

## 3. Cohesiveness (for UI-UX)

- Paid time off is shown in two places — Work Hours › Paid Time Off and HR › Records ›
  Time off — with different layouts for the same Total / Used / Left.
- The onboarding form's grey examples cover pay and terms boxes only; date and
  schedule questions have none.
- Payroll exports CSV; Records, Invitations and Onboarding lists have no export.
- Download icons: signed PDF on Onboarding, draft PDF on Contracts, W-4 attachment
  on Payroll — three different icon placements for "download".

## 4. Suggestions (ranked)

1. A "Missing start date" and "No contract" chip on Records (counts above) — closes the
   two data gaps in one pass for HR.
2. One PTO card component reused by Records › Time off and the PTO tab — one place to
   change, no drift.
3. Expire-and-remind for invitations: a reminder at 3 days, auto-revoke at expiry.
4. Export on every HR list (same CSV shape as Payroll) — the accountant asked for one,
   the others will follow.
5. Examples on every onboarding question type (dates, schedule), not only text boxes.

## 5. Needs Jose

- Should HR fill the 13 missing start dates and 24 missing contracts now (Eve), or
  should the import fill start dates from A1 Tools where it had them?
- Should expired invitations be revoked automatically, or stay until someone acts?
