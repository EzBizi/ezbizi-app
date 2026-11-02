# Memberships review: 2026-11-02

Owner: Membership Program (`local_9b8c5aa9-9f63-4f38-9ecc-78746c73404b`). Measured on production
(read-only tinker as www-data, plus public route probes) on 2026-11-02.

## 1. Working

- Customers › Memberships tab with Dashboard / Members / Settings (UI/UX's screens, live since 0.0.0357).
- Plans: 1 active plan for A1-Group, "Annual Membership", $99/yr.
- Members: 2 live (Jose Luis Demasi, Shoval Ohayon), 2026-10 → 2027-10, none cancelled.
- `memberships:remind-renewals` is scheduled daily at 14:00 UTC (09:00 New York). It has raised `membership.renewal_due` 2 times, both on 2026-10-05: the requested test. No real year is due until 2027-09.
- Renewal reminder rule `01a10d7b…` is active and marked core. **Today it also gained a text step** on the A-1 toll-free texting line (+1 855 955 3322), now that a line is cleared (v2: email + SMS).
- Review → customer: `customer.reviewed` has fired 196 times (162 happy, 195 verified by the personal link).
- `{{ membership_link }}` short link: `/api/m/<code>` is live; an unknown code returns 404.
- Review-page offer API: `/api/v1/web/membership` is live; an unknown key returns 404.
- Visit booking (`{{ confirm_visit_url }}`): EzBizi job first via BookJob, then the Workiz mirror, with BUSY retry. 0 uses so far, because no year is due yet.
- `failed_jobs` mentioning Membership: 0.

## 2. Broken

Nothing failing. Two gaps that make the program inert in practice:

- **No offers are going out.** The "Happy review - Membership offer" rule (`01a11cfb…`) is **inactive**, so 162 happy reviews have produced 0 offers and 0 sign-ups. It's a tenant decision (section 5), not a defect.
- **The plan has no job type** (`membership_plans.job_type` is null), so a booked visit lands in EzBizi and Workiz with no job type. Its description is still "Burger Inspection" (Jose 10-09: "Leave it"). Both fields are in Settings, so they are Jose's or Bar's to fill (section 5).

No code fixes were needed. Fix SHAs: none. The production configuration change today was the SMS step on the reminder rule (version 2).

## 3. Cohesiveness

- The customer-facing visit page (`resources/views/memberships/visit.blade.php`) copies UI/UX's join-page frame by hand. The two can drift; they should share one Blade layout (UI/UX).
- The plan editor still sends `workiz_job_type`; the API now takes `job_type` (0.0.0497+). It works through an alias. UI/UX should relabel the field "Job type" and send `job_type`, after which the alias can go.

## 4. Suggestions (ranked)

1. Switch on the happy-review offer. It's the only path that brings in members; 162 happy customers so far have never been offered.
2. Give the plan a job type, so booked visits are typed in EzBizi and Workiz.
3. A payments ledger instead of the typed `paid_minor`, once members actually pay (none yet).
4. One shared Blade layout for the customer pages (join, visit).
5. WhatsApp for the reminder once a Meta template is approved.

## 5. Needs Jose

1. Turn on "Happy review - Membership offer" now? It texts a short sign-up link ~10 minutes after a happy review, skips existing members, and sends nothing when no plan is active.
2. What job type should the $99 Annual Membership's visit be (e.g. "Chimney Inspection")? And is "Burger Inspection" the description you want customers to read?
