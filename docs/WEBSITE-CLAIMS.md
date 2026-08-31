# What the website claims, and on whose authority

The public site says what EzBizi does. This page records **why each claim is
allowed to be there**, and — more importantly — what must *not* go back on.

It exists because the daily website check runs in a fresh session with no memory
of the last one. Without this file, the next run reads a landing page that does
not mention Training, sees Training working in the app, and helpfully adds it
back. Everything below was removed or narrowed on purpose.

Authority is the deploy session's production baseline of **2026-08-31** (release
`20260831170831`), answered with row counts from the live database and the module
registry rather than from memory. Refresh it by asking; do not infer it from the
code, and do not infer it from a feature branch.

---

## Do not put these on the site

| Claim | Why not |
|---|---|
| **Quotes, billing, invoicing, estimates, payments** | Does not exist. 151 production tables searched for `quote` / `invoic` / `bill` / `estimate` return nothing, and no such module is registered among 26. The only `payment` match is `vehicle_payments` — Fleet recording what it paid for a van. Removed in `6c67efb`. |
| **Scheduling a job onto a calendar** | Not built. The Schedule tab is a placeholder until Scheduled Jobs ships. What *is* live: service areas, zip mapping, and configured capacity per slot per weekday — so "coverage and capacity" is true and "schedule your jobs" is not. |
| **Training** | Its tables are not on production. Ships in a later deploy. |
| **Phone** | Works, but no production line has a ring group, so every inbound call reaches voicemail. "Phone system" while calls reach nobody is a promise we are not keeping. |
| **Local Services enquiries arriving as leads** | *Unverified, not false.* A Google Ads connection row and a Business Profile row both exist, and 5 reviews are real — but `leads` holds 8 rows and nobody can say whether any arrived from Local Services or were typed in. A connection row proves OAuth completed; it does not prove the API returns data. Publish when someone confirms one has landed. |
| **"Teams rely on it" about Inspections or Tasks** | Both modules are live and neither has ever been used by a customer. Not embarrassing, but not a usage claim. |
| **Monitoring, Marketing** | Registered, but their dashboard summaries are explicitly placeholders. |

## Live, and safe to sell

Customers · Field operations · Fleet · Inventory · People (users, HR onboarding,
time clock) · Advertising connections · Reviews · Growth/leads · Social media ·
Web management · Inspections · Tasks.

**Forms & reports** and **Insight** stay on the site as descriptions of a
combination rather than named modules — reports exist (`inspection_reports`,
`phone_reports`) and the dashboard has real per-module summaries. Nothing there
is false, but neither is backed by a row count; they rest on the deploy session's
judgement. Pull them the same way if that changes.

## Two things that make this easier than it looks

**Nothing is behind a feature flag.** `platform_module_flags` is empty and
availability fails open, so every registered module is reachable by every
organization. There is no "shipped but switched off" category — the only question
is whether the code does the thing, which is why the baseline is row counts.

**A claim is never in one place.** Removing "billing" meant editing the meta
description, `og:` and `twitter:` descriptions, the `SoftwareApplication` JSON-LD,
the FAQ answer in *both* the JSON-LD and the visible `<details>`, the hero, the
section intro, the card — **and the app mock**, which depicts the product and is
read as a screenshot. Grep the whole of `www/` for the word before believing it
is gone.
