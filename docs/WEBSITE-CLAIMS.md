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
| **Local Services enquiries arriving as leads** | *Unverified, not false.* **This forbids the marketing claim, not the compliance page — see below.** A Google Ads connection row and a Business Profile row both exist, and 5 reviews are real — but `leads` holds 8 rows and nobody can say whether any arrived from Local Services or were typed in. A connection row proves OAuth completed; it does not prove the API returns data. Publish when someone confirms one has landed. |
| **"Teams rely on it" about Inspections or Tasks** | Both modules are live and neither has ever been used by a customer. Not embarrassing, but not a usage claim. |
| **Monitoring, Marketing** | Registered, but their dashboard summaries are explicitly placeholders. |

## Enforced at publish time

`scripts/deploy-www.sh` refuses to publish if any page about to ship matches one
of these, case-insensitively. **This block is the source of truth — the script
parses it, so edit it here and nowhere else.**

```forbidden-terms
invoic
estimat
billing
quotes &
```

**Deliberately narrow, and that is the point.** Only the billing family is
machine-enforced, because those words cannot appear innocently on this site. The
other rows above are judgements a grep would get wrong — "training" is an ordinary
English word, and a claim about Local Services is a sentence rather than a term.
A gate that cries wolf on a testimonial is a gate people start passing with a
flag.

It exists because on 2026-08-31 the shared index was found holding the exact
inverse of `6c67efb` and `57fced3` — this file deleted, and all three pages
reverted to the version telling a Google reviewer we do invoicing. The working
tree was correct throughout; only the index was wrong. The next bare `git commit`
by any of sixteen sessions would have put those claims back on `main`, and the
publish path resolves `main`, so they would have reached the public site under a
commit message about something else. Nobody would have been looking: the site had
been verified clean an hour earlier.

It was caught by one person running `git status` once more out of habit. That is
not a control, and it does not survive the session that has the habit.

## Checked, and deliberately left alone

Two things look like violations to a grep and are not. Both were re-checked on
2026-08-31; leave them, and do not "fix" them next run.

**`www/index.html` — "Technician training round".** It is a row in the *Tasks*
mock, under COMPLETE, beside "Customer survey rollout" and "Supplier price
review". It is the title of a task a business tracks, not a Training module. Tasks
is live. The Training row above forbids claiming the module exists; it does not
forbid the English word inside a task name.

**`www/google-ads-api/` — Local Services.** That page describes what the Leads
module *does with the API*, for Google's API reviewer, and the code does it:
`app/Services/Leads/LocalServicesLeads.php:155` calls
`customers/{id}/localServices:searchDetailedLeadReports`, reached from
`LeadsController:171`. "The module retrieves Local Services lead data" is a claim
about code, and it is true.

The forbidden claim is a different sentence: that enquiries **have arrived** as
leads for a customer. Code that calls an endpoint is not a lead that landed. Keep
the compliance page as it is, and keep the story off the landing page until
somebody confirms a row.

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
