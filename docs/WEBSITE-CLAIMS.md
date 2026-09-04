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
| **Suggestions** | **INVERTED 2026-09-03 — it *is* on production, and this row said the opposite for a day.** All 17 commits are in the deployed tree (`git merge-base --is-ancestor 486668c 15c9762f` → true), merged at `e718748a`, and they were already in the *previous* production commit `678c3e83` — so it shipped in a deploy nobody announced, not in the 20:32 push. `create_suggestions_table` is in the deployed tree, so **the table test this row named has flipped to yes**, and production `client/VERSION` is `0.0.0147`. **The two design facts have not changed and still mean "present in the app" proves nothing either way**: it is still absent from `ModuleRegistry` (0 hits in the deployed tree) so a module count never sees it, and still pinned unconditionally for every user (`client/lib/main.dart:687` in the deployed tree — it was `:724` on the branch; re-read the line, do not trust this number). **Still do not put it on the site, for an entirely new reason.** The two screen layouts reached production *before* Jose reviewed them — he had delegated the choice for one night on the understanding they could change — and a defect is open against the §9 privacy inventory under-reporting the suggestion count. Neither makes the feature untrue; both make "we shipped this" a sentence we might have to retract. Revisit when the layouts are reviewed and the defect is closed. |
| **That anybody has *set up* coverage and capacity** | Built and unused — a different state from unbuilt, and the two must not be flattened. The capability shipped in `0.0.0137`; `service_areas` and `service_area_slots` both hold **0 rows**, so no customer has created one. This does **not** forbid the "Coverage & capacity" card, which reads *"Set the areas each location covers and how much work a day can take"* — that is capability phrasing, and by the Inspections precedent below, built-and-unused is sellable as a capability and never as usage. **What it forbids is the tense**: "businesses set their coverage", "locations already covering", any sentence implying somebody has. If you are here because you read a 0 and reached for the card, leave the card alone. |
| **Tasks — RETIRED, not merely unused** | **On the site until 2026-09-03, removed the same day it was checked. The reason it survived is the durable lesson: `27aef957` ("Delete every remaining record of the Tasks module", Jose's, 38 files, ~3,800 lines) swept the row out of `MODULE-OWNERS.md`, both `*TaskLink.php` entries, a `NotificationDispatcher` constant, nine superpowers files, a release note and about thirty other citations — and touched ZERO files under `www/`.** A product-wide removal sweep does not reach the marketing site, and nothing makes it obvious that it did not: every internal trace was gone, so any check that samples the repo concludes the removal was thorough. **When a module is deleted, `www/` is a separate job with a separate owner and it will not be in the sweep's diff.** `9175d19f` drops all 31 `task_*` tables and `51729347` removes it from tables it did not own, both ancestors of `d2d39304` — the SHA `/download/windows.json` names. **The decisive check is not the migration**, since a migration in the deployed tree has not necessarily run; it is that **the deployed client carries zero Tasks screens** (`git ls-tree -r d2d39304 -- client/lib/screens`, filtered for task → 0). A customer on `0.0.0148` has no Tasks screen at all. Removed from the site: the sidebar tab and its "9" pill, the whole `p-task` mock panel, the "Boards for the work that is not a job" card, and one word each in `privacy/` and `terms/`. **A retired module is a harder sweep than an unbuilt one** — it was legitimately on the site for weeks, so it reads as settled copy nobody thinks to re-check. |
| **"Teams rely on it" about Inspections** | The module is live and has never been used by a customer. Not embarrassing, but not a usage claim. |
| **Monitoring, Marketing** | Registered, but their dashboard summaries are explicitly placeholders. |

## Enforced at publish time

`scripts/deploy-www.sh` refuses to publish if any page about to ship matches one
of these, case-insensitively. **This block is the source of truth — the script
parses it, so edit it here and nowhere else.**

```forbidden-terms
invoic
estimat
bill
quotes &
```

**`bill` replaced `billing` on 2026-09-03, because `billing` was escaped.** The
homepage shipped *"**Bill** from the completed work, then see what it tells you
about the business"* as step 04 of a four-stage loop, under a section intro
reading "to the money in the bank". Published and live, and invisible twice over:
the term was `billing` and the word was `Bill`, and the sentence described a
*stage* rather than naming a module, so it read past a human sweep looking for
feature claims too. `6c67efb` was written to clear this exact family and missed
it. `bill` subsumes `billing` and matches nothing on the site today.

**The known false positive is a person named Bill** — a testimonial or quoted
customer fires this gate. That is the crying-wolf risk described below, accepted
deliberately: a name is rare and recognisable in one look, and the alternative is
a gate that the sentence above walks straight through. **If it fires on a name,
reword to a surname or initial; do not add an exception.**

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

**~~`www/index.html` — "Technician training round"~~ — GONE, and not by anyone
deciding about the word.** It was a row in the Tasks mock, defended here twice as
an innocent English use. It left the site on 2026-09-03 when the entire `p-task`
panel was deleted for an unrelated reason: Tasks had been retired from the
product. Kept as a note about method — **a false positive can be resolved by its
context disappearing rather than by the judgement about it ever being revisited.**
Do not go hunting this string; it is not there.

**The Tasks warning that used to sit here was right, and it sat here for a day.**
A previous session found the retirement, wrote the exact line numbers into this
file at 12:46 on 2026-09-03, and correctly left the page edits to whoever owns
`www/` — this document does not rewrite the site. The website session then read
this file and did not act on it, and the pages were fixed only that evening when
a *second* website session ran an independent audit and raised it. **Flagging a
live false claim in the right file is not the same as it being fixed**, and
nothing here escalates. If you write a claim-to-fix into this document, also say
it out loud to whoever owns the page, and do not assume the next reader will
notice a note among rows that are mostly settled.

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

## The Suggestions row inverted within a day, and the instruction to invert it is why that was cheap

Written 2026-09-02 as "not on production", inverted 2026-09-03 to "on
production". **It was already false when it was committed.** The branch owner
measured it unmerged, told this session so in good faith, and it merged and
deployed in between; nobody re-measured before the row was written. Two commits
(`9341426`, `671d1340`) rest on that input.

**The row survived being wrong**, because `671d1340` had added the instruction
to invert it rather than delete it. A row that had merely asserted "not on
production" would have been deleted on discovery, taking with it the two facts
that are *still true and still load-bearing*: the `ModuleRegistry` absence and
the unconditional pin, which are properties of the design and not of the deploy,
and which are exactly why "present in the app" proves nothing either way. The
verdict flipped; the reasoning did not. **That is the whole argument for writing
the inversion instruction into any row whose verdict can change.**

Three cheap lessons, all paid for once:

1. **A peer's measurement is a timestamp, not a fact** — even a careful peer's,
   even about its own branch. It was accurate when taken and stale within hours.
   Re-measure at the moment of writing, the way `StartUp.md` §4 says to resolve
   `BASE` at commit time rather than at review time. Same failure, different
   surface.
2. **Line numbers move.** This row cited `client/lib/main.dart:724` from the
   branch; the deployed tree has it at `:687`. The row now says to re-read the
   line rather than trust the number.
3. **A thing can be live and still not sellable, for reasons that are not about
   whether it works.** Suggestions is on production and does what it says. It
   stays off the site because its layouts reached production before Jose saw
   them and a privacy defect is open — "true" and "ready to claim publicly" are
   different tests, and only the first is a row count.

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

**The pipeline is built to be quiet here, deliberately and correctly.**
`scripts/deploy.sh:479` runs `deploy-www.sh` last and lets it fail *non-fatally*:

    scripts/deploy-www.sh "$GATED_SHA" || echo "⚠ the SITE did not publish — the app release above is fine..." >&2

The reasoning in its comment is sound — everything else has already shipped, and
a red exit would report a failed release that is in fact live. The consequence is
the part that matters to this file: **a green release is not evidence the site
published.** The warning goes to stderr, on the deploy session's terminal, in a
run that ends in success. Nobody watching from here would see it. That is the
mechanism behind the rule above, and it is why the check has to be a fetch of the
public URL rather than any report about the release.

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
