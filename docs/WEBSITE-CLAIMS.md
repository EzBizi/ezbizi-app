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
| **Local Services enquiries arriving as leads** | **Contradicted, not merely unverified — and the evidence is a decoy.** Querying production returns `source = local_services` on 8 leads, which reads exactly like the confirmation this row has been waiting for. All 8 were created in the *same second* and none carries a provider id: they are `leads:seed-demo`, run on production by the deploy session on 2026-08-31. The `source` string was written by the seeder, not by Google. **True count of real Local Services enquiries: 0.** The checkable test for next time — a genuine enquiry carries a provider `external_id` and does not share a timestamp with seven siblings. This forbids the marketing claim, not the compliance page; see below. |
| **That a post reaches every connected network, or reaches a TikTok profile** | Nine networks connect; **five publish** — corrected upward 2026-09-02, see below. `Publishers::for()` returns a publisher for Facebook Pages, Threads, Instagram, Pinterest and TikTok, and `default => null` for LinkedIn, YouTube, X and Bluesky, which `refusalFor()` answers with "Publishing to X is not built yet". **TikTok posts to the creator's DRAFTS, not the profile** — TikTok requires an audit before an app may post publicly and ours has not had it, so somebody must finish the post in the TikTok app. Pinterest is **images only**; video publishing does not exist. Flagged 2026-08-31 by the deploy session as a live false statement in the in-app release notes; corrected on the site the same day. The checkable test: does `Publishers::for()` return non-null for that provider. |
| **Suggestions** | Not on production, and it will not look that way. The module is built on the unmerged branch `session/suggestions` — `git merge-base --is-ancestor fb488b9 main` fails and `main` carries no `create_suggestions_table` migration, so no deploy can have taken it. **Its own design defeats both of the usual checks.** It is deliberately absent from `ModuleRegistry` (zero hits) so that no organization can switch it off, which means a check counting registered modules never sees it; and `client/lib/main.dart:724` pins it as a destination for every user with, in its own words, "no module key, no permission and no availability flag behind it". So a check that opens the app sees it working. **Neither answers whether it is live.** The only test that does: does the `suggestions` table exist on production. |
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

**An empty or missing `forbidden-terms` block is a FAILURE, not a pass.** The
script treats it that way deliberately: the failure this gate exists to stop is a
reverted index restoring the billing pages, and that same revert would delete this
file. A gate that reads its rules from a file the attack deletes has to fail
closed.

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

## The publishing count moved on 2026-09-02, and the site was the last to hear

**Corrected upward, which is the direction nobody watches for.** From
2026-09-01 the site said "Two networks send today" and listed Facebook Pages,
Instagram and Threads under *"Connect today; sending is not built."* That had
been false for a day. Facebook and Threads publishing landed in `859f1f1` and
Instagram in `095d49d`, both on 2026-09-01, and both shipped to customers in
release **`0.0.0130`** — production runs `0.0.0141`.

Recorded because the discipline in this file is aimed at overstating, and this
was the opposite failure: a compliance page telling a Meta reviewer that we do
not post to Facebook Pages while the deployed code posts to Facebook Pages. **A
stale understatement is still a wrong published claim**, and on the pages a
reviewer reads it is not the harmless direction.

The tell was not a message. **No deploy session was reachable that evening** —
`ezbizi-7e` answered "not the deploy desk, and I do not know who is", and no
other peer answered at all. Three independent checks stood in for a row count,
and they are the ones to repeat:

1. `Publishers::for()` in the repo — the test this file already names.
2. `client/lib/core/version_history.dart` — *"Social: Facebook, Threads and
   Instagram can post"*, filed under a **released** version number.
3. `/download/windows.json` → `0.0.0141`, built `c6b8bea`;
   `git merge-base --is-ancestor 859f1f1 c6b8bea` and the same for `095d49d`
   both pass. The manifest tracks the **deployed** release (see below), so
   that is production evidence rather than repo evidence.

Per-network caveats now on the page, all read off `cannotSend()`: **Instagram
refuses text-only** ("Instagram does not post words on their own"), **Pinterest
is images only**, **TikTok posts to drafts**. Facebook and Threads return
`null` — no restriction. If a network is added, its row needs its `cannotSend()`
sentence, not just its name.

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

## Store listings — check the store, not a session

**Android is live on Google Play** (`com.ezbizi.app`), linked from `/download/`
since 2026-09-01. **iOS is not published.** Neither fact should ever be taken from
a message; both are one command, and they are the cheapest way to notice that a
listing went live and the page did not:

```
curl -sI "https://play.google.com/store/apps/details?id=com.ezbizi.app"   # 200 = live
curl -s  "https://itunes.apple.com/lookup?bundleId=com.ezbizi.app"        # resultCount
```

The iOS card claims only what is checkable — built and signed, not on the store —
because its actual review status has been contested between two repo files since
August. **It owes the public a link the moment that lookup returns a result**, and
nothing else about it should change before then.

## `/download/windows.json` tracks PRODUCTION, not `client/VERSION`

The daily task file says to check the manifest against `client/VERSION`. **That
check is wrong and will report a false mismatch every time a client change is
merged and not yet shipped.** On 2026-08-31 it did: manifest `0.0.0122`, repo
`0.0.0123`, nothing wrong.

The installer has to match the API it will talk to, so the manifest tracks the
deployed release. Compare it to what production runs — ask the deploy session —
or to the published `EzBizi-setup.exe`, not to the repo's next version bump.

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
