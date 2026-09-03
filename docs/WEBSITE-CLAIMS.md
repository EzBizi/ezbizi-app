# What the website claims, and on whose authority

The public site says what EzBizi does. This page records **why each claim is
allowed to be there**, and — more importantly — what must *not* go back on.

It exists because the daily website check runs in a fresh session with no memory
of the last one. Without this file, the next run reads a landing page that does
not mention Training, sees Training working in the app, and helpfully adds it
back. Everything below was removed or narrowed on purpose.

Authority is the deploy session's production baseline of **2026-09-02** (release
`20260902210719`, production `main` at `c6b8bea` = `0.0.0141`), answered with row
counts from the live database and by reading the deployed code rather than from
memory. Refresh it by asking; do not infer it from the code in *this* tree, and do
not infer it from a feature branch.

**Three rows below were factually wrong when that baseline was taken, and each had
been wrong for days.** Training's tables *are* on production. Two phone lines *do*
have a ring group. And the Local Services row named a checkable test that cannot be
run, because the column it names does not exist. A row that states a fact goes
stale silently; a row that states a *test* fails loudly the first time somebody
runs it — which is why every row below now carries one, and why the tests are
written against columns rather than against counts.

---

## Do not put these on the site

| Claim | Why not |
|---|---|
| **Quotes, billing, invoicing, estimates, payments** | Does not exist. 151 production tables searched for `quote` / `invoic` / `bill` / `estimate` return nothing, and no such module is registered among 26. The only `payment` match is `vehicle_payments` — Fleet recording what it paid for a van. Removed in `6c67efb`. |
| **Scheduling a job onto a calendar** | Not built, and **test the relation, not a column.** The obvious check — "`jobs` has no scheduled-date column" — is already false: `jobs.scheduled_for` (datetime) and `jobs.assigned_role_id` exist on production, and **0 of 25 jobs have either set**. The durable test is structural: **no table carries both a job reference and a date-or-slot reference.** `service_area_slots` is `(service_area_id, weekday, start_time, end_time, max_jobs)` — a recurring weekly capacity *template* with no `job_id`, no date and no per-occurrence row, so it cannot express "this job, this day, this slot" even in principle. That stays true until the module is actually built, which is exactly when the claim should change. |
| **Training** | **Corrected 2026-09-02 — the old reason was false.** Its five tables *are* on production (`training_assignments`, `training_attempts`, `training_guides`, `training_progress`, `training_tests`); the row used to say they were not. The verdict does not change, but the reason does: **all five are empty**, so nothing has ever been assigned, attempted or completed. The checkable test is a row count in those tables, not their existence — the old test would now pass and the claim would still be false. |
| **Phone / Communication** | **Jose approved it for the site on 2026-09-03** (relayed by the deploy session, which put the 2-of-7 fraction to him explicitly). **The approval covers the capability, not any particular sentence, and the fraction has not moved.** Draft copy is below, unpublished, awaiting his sight of the actual words. Everything following is the measurement that shapes them.<br><br>**The old blocker has partly lifted; the answer is still no to the strong claim, for a smaller reason.** The row used to say no production line had a ring group and every inbound call reached voicemail. As of 2026-09-02 `phone_ring_groups` has **2 rows**, and **2 of 7** `phone_numbers` carry a non-null `ring_group_id`. So calls to those two reach a person. **Five of seven still dead-end at voicemail**, which is why "phone system" remains an overstatement rather than a promise kept — and the module was renamed **Communication** in `0.0.0141`, so any future copy names that, not "Phone". The checkable test: `phone_numbers` with a non-null `ring_group_id`, as a fraction of all of them. Do not write the claim off a lifted gate; write it off the fraction. |
| **Local Services enquiries arriving as leads** | **Contradicted, not merely unverified — and the evidence is a decoy.** Querying production returns `source = local_services` on 8 leads, which reads exactly like the confirmation this row has been waiting for. All 8 were created in the *same second* and none carries a provider id: they are `leads:seed-demo`, run on production by the deploy session on 2026-08-31. The `source` string was written by the seeder, not by Google. **True count of real Local Services enquiries: 0**, re-confirmed 2026-09-02. **The test this row used to name does not work, and saying so is the point.** It told you to look for a provider `external_id`; there is no `external_id` column on `leads` and none anywhere in `database/migrations/`, so the check silently has nothing to compare and a careful session runs it, finds nothing, and cannot tell "no real leads" from "no such column". **The first replacement written for this row was also wrong, and it is left here as the warning.** It said a genuine enquiry has a `connection_id` and a non-null `synced_at` while a seeded one has neither — reasoned from the real sync path (`LocalServicesLeads.php:216,228`) without ever opening the seeder. **`LeadsSeedDemo.php:132,143` writes both**, pointing `connection_id` at a genuine `social_connections` row it creates. The seeded rows are indistinguishable from real ones on every field that describes *how a lead arrives*, because the seeder's whole job is to look real. **The test that works is the one the seeder deliberately leaves behind:** `LeadsSeedDemo.php:45` stamps `private const PREFIX = 'DEMO-'` onto `remote_id` on every row it plants (`:128`). So — **a real enquiry's `remote_id` is Google's own id and never begins `DEMO-`:**

```sql
SELECT COUNT(*) FROM leads WHERE remote_id NOT LIKE 'DEMO-%';   -- real enquiries
```

**Measured on production 2026-09-03, and the dump is the proof rather than the count:** `remote_id NOT LIKE 'DEMO-%'` → **0**, out of 8 leads, **0 with a null `remote_id`** — so nothing hides in a third state between the two tests. All eight rows carry `has_connection_id = 1` **and** `has_synced_at = 1`, which is the wrong test failing visibly in the data: it would have counted all eight as real and reported the module in use. A count alone would have agreed with both tests; only the dump separates them. Note the command takes `--live`, which is how fiction reached production in the first place. **Read the seeder before trusting any test meant to exclude it** — three tests have now been written for this one row, and the two derived from the real code path both failed, because a good seeder imitates exactly the fields that path writes. This forbids the marketing claim, not the compliance page; see below. |
| **That a post reaches every connected network, or reaches a TikTok profile** | Nine networks connect; **five publish** — corrected upward 2026-09-02, see below. `Publishers::for()` returns a publisher for Facebook Pages, Threads, Instagram, Pinterest and TikTok, and `default => null` for LinkedIn, YouTube, X and Bluesky, which `refusalFor()` answers with "Publishing to X is not built yet". **TikTok posts to the creator's DRAFTS, not the profile** — TikTok requires an audit before an app may post publicly and ours has not had it, so somebody must finish the post in the TikTok app. Pinterest is **images only**; video publishing does not exist. Flagged 2026-08-31 by the deploy session as a live false statement in the in-app release notes; corrected on the site the same day. The checkable test: does `Publishers::for()` return non-null for that provider. |
| **Suggestions** | Not on production, and it will not look that way. The module is built on the unmerged branch `session/suggestions` — `git merge-base --is-ancestor fb488b9 main` fails and `main` carries no `create_suggestions_table` migration, so no deploy can have taken it. **Its own design defeats both of the usual checks.** It is deliberately absent from `ModuleRegistry` (zero hits) so that no organization can switch it off, which means a check counting registered modules never sees it; and `client/lib/main.dart:724` pins it as a destination for every user with, in its own words, "no module key, no permission and no availability flag behind it". So a check that opens the app sees it working. **Neither answers whether it is live.** The only test that does: does the `suggestions` table exist on production. **When it ships, invert this row rather than deleting it.** The pin and the registry absence do not change at deploy, so "present in the app" will still be evidence of nothing and the table will still be the only reliable check. Only the verdict flips; the reasoning is what a later session needs. |
| **That anybody has *set up* coverage and capacity** | Built and unused — a different state from unbuilt, and the two must not be flattened. The capability shipped in `0.0.0137`; `service_areas` and `service_area_slots` both hold **0 rows**, so no customer has created one. This does **not** forbid the "Coverage & capacity" card, which reads *"Set the areas each location covers and how much work a day can take"* — that is capability phrasing, and by the Inspections precedent below, built-and-unused is sellable as a capability and never as usage. **What it forbids is the tense**: "businesses set their coverage", "locations already covering", any sentence implying somebody has. If you are here because you read a 0 and reached for the card, leave the card alone. |
| **"Teams rely on it" about Inspections** | The module is live and has never been used by a customer. Not embarrassing, but not a usage claim. |
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

**Confirmed at row level afterwards, and the inference held.** The deploy desk
(`ezbizi-7b`) read `Publishers.php` out of the deployed release
`/var/www/ezbizi-releases/20260902210719` — five publishers, `FacebookPage`,
`Threads`, `InstagramProfessional`, `Pinterest`, `TikTok`. So "five networks send
today" is right and the old sentence was wrong on production.

**Published in `v0.0.0142` (`aa9be21`) on 2026-09-03** and verified against the
live pages, not the repo: `/social-media/` returns "Five networks send today"
and zero matches for "Two networks send today". Resolved — do not go hunting it
again.

That confirmation arrived *after* the edit, because no deploy session was
reachable when it was made — `ezbizi-7e` answered "not the deploy desk, and I do
not know who is", and the desk itself was mid-session elsewhere. Three
independent checks stood in for the row count, they turned out to agree with it,
and they are the ones to repeat when nobody answers:

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

**`www/index.html` — "Technician training round".** It is a row in a checklist
mock, under COMPLETE, beside "Customer survey rollout" and "Supplier price
review". It is the title of a to-do a business tracks, not a Training module. The
Training row above forbids claiming the module exists; it does not forbid the
English word inside a list item.

> **That whole mock is a Tasks board, and Tasks was removed on 2026-09-02.**
> `www/index.html` still draws a Tasks tab in the product shot (the button at
> `:337`, the panel at `:447`, the heading at `:554`). The site is advertising a
> module that no longer exists. That is a claim to fix, not a false positive —
> it is listed here rather than changed because the marketing site's copy is not
> this document's to rewrite.

**`www/google-ads-api/` — Local Services.** That page describes what the Leads
module *does with the API*, for Google's API reviewer, and the code does it:
`app/Services/Leads/LocalServicesLeads.php` POSTs
`customers/{id}/googleAds:search` with `FROM local_services_lead`, reached from
`LeadsController` sync. "The module retrieves Local Services lead data" is a claim
about code, and it is true.

The forbidden claim is a different sentence: that enquiries **have arrived** as
leads for a customer. Code that calls an endpoint is not a lead that landed. Keep
the compliance page as it is, and keep the story off the landing page until
somebody confirms a row.

## Live, and safe to sell

Customers · Field operations · Fleet · Inventory · People (users, HR onboarding,
time clock) · Advertising connections · Reviews · Growth/leads · Social media ·
Web management · Inspections.

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

## Communication — approved, drafted, not published

Jose said yes on 2026-09-03. The card is written and deliberately not committed:

```html
<h3>Communication</h3>
<p>Your business numbers in one place — dial, texts and voicemail, with each
   role given the company's lines rather than one number at a time.</p>
```

Every clause is checkable. Three sub-tabs exist (`phone_tab.dart:36-38`:
Dial, Messages, Voicemail). Role-level access to the company's lines is what
`0.0.0141` shipped, replacing a per-line tick per role.

**What it deliberately does not say, and why the fraction outranks the
approval.** Two of seven production lines route to a ring group; five still
dead-end at voicemail. So no sentence may imply a call reaches a person:
not "your calls reach your team", not "never miss a call", not "phone
system". The approved thing is that Communication exists and does what the
card says. **An approval to describe a capability is not evidence the
capability's weakest number has changed** — and 2-of-7 is the number that
stops this sentence drifting.

**Why it is not committed.** On this pipeline a commit to `main` *is* a
publish: `deploy.sh` ships `www/` with every web deploy, so there is no
staging step between the two. The approval reached this session relayed
through a peer rather than from Jose directly, which is enough authority to
*write* an outward-facing sentence and not enough to *publish* one he has
not read. The deploy desk applied exactly this rule to `7349e79` earlier the
same day, declining to publish it on a peer's request; this is that rule
turned around and pointed at its author. Land it when Jose has seen the
words.

## Absence of news is not a publish

**Never record a publish you have not fetched.** This run nearly did: having
handed `7349e79` to the deploy desk, the plan was to "take silence as it having
ridden the scheduled release". The desk refused that and was right to.

Silence would equally have meant Jose declined it, or that the release failed on
the web step — which happened **three times on 2026-09-02, and on two of those
the web target shipped while the app targets did not**, so even a partly-failed
release is quiet in the direction that matters here.

It is the same defect as the two this file corrected the same day — a check
resting on an absence, which passes for the wrong reason. `external_id` was
missing so nothing matched; `jobs.scheduled_for` was expected missing and was
not; and "no bad news" is the same trick played on a person instead of a column.
**The check is one command, and it is the same one the daily audit already
runs:**

```
curl -sS https://www.ezbizi.com/<page>/ | grep -c "<the string that had to go>"
```

Fetch the page, grep for the sentence that had to disappear, and record the
result. Not the deploy desk's word, and not the passage of time.

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
