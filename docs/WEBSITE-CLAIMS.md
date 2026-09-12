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

**Standing authority, set by Jose on 2026-09-04: "do as you recommend, stop
asking me for approvals."** Verified, accurate copy ships — do not hold it for
his sight and do not open a FOR YOU TO DO item asking him to approve wording.
A card was held a full day for that reason before he said this.

**This makes the file below the only gate, so it carries the whole load.** The
question stopped being "has he seen the words" and is now "is every clause
checked against the deployed code or a production row". Publish what passes
that; hold what does not, and say plainly that you are holding it and why.
Keep FOR YOU TO DO for things only a person can do — a console login, a store
submission, a filing — never for permission.

---

## Do not put these on the site

| Claim | Why not |
|---|---|
| **Quotes, billing, invoicing, estimates, payments** | Does not exist. 151 production tables searched for `quote` / `invoic` / `bill` / `estimate` return nothing, and no such module is registered among 26. The only `payment` match is `vehicle_payments` — Fleet recording what it paid for a van. Removed in `6c67efb`. |
| **Scheduling a job onto a calendar** | Not built, and **test the relation, not a column.** The obvious check — "`jobs` has no scheduled-date column" — is already false: `jobs.scheduled_for` (datetime) and `jobs.assigned_role_id` exist on production, and **0 of 25 jobs have either set**. The durable test is structural: **no table carries both a job reference and a date-or-slot reference.** `service_area_slots` is `(service_area_id, weekday, start_time, end_time, max_jobs)` — a recurring weekly capacity *template* with no `job_id`, no date and no per-occurrence row, so it cannot express "this job, this day, this slot" even in principle. That stays true until the module is actually built, which is exactly when the claim should change. |
| **Training** | **PUBLISHED 2026-09-09 as a capability card — this row now governs only what may NOT be added to it, and the tense rule is the whole of it.** The card reads *"Write the guide, set the test, give it to the people who need it, and see who still owes what."* — four imperatives and no completed action, deliberately. **The row count this row has always named was finally taken**, by the deploy desk on production release `20260908222755-a18b059a9080`, answering `0037` in `0038`: `training_attempts` **0**, `training_progress` **0**, `training_assignments` **0**. Nobody has ever sat one. That is precisely the Coverage & capacity / Inspections state — built and unused, sellable as a capability and never as usage — so **no sentence may say or imply that anybody has trained**: not "teams train on it", not "your team is up to date", not a number of guides completed. Reachability was re-verified in the deployed tree rather than taken from the release note: `ModuleServiceProvider.php:2288` registers `key: 'training'`, label Training, actions `view/monitor/manage/assign`, so it is in the sidebar and the roles editor; `client/lib/core/module_screens.dart:113` maps it to a screen. **Note how far the REASON travelled while the verdict stood still** — this row said in turn: the tables are missing (false), the tables are empty, the server shipped without a sidebar entry, and now nothing but the tense. Three of those four would have been fixed by "just add the card" on the wrong day. **The original entry follows, because the correction it records is the reason the row survived to be right:** **Corrected 2026-09-02 — the old reason was false.** Its five tables *are* on production (`training_assignments`, `training_attempts`, `training_guides`, `training_progress`, `training_tests`); the row used to say they were not. The verdict does not change, but the reason does: **all five are empty**, so nothing has ever been assigned, attempted or completed. The checkable test is a row count in those tables, not their existence — the old test would now pass and the claim would still be false. **Corrected again 2026-09-08: it now has screens.** `0.0.0151` shipped the server side with no sidebar entry, and the deploy desk said in `0035` "do not describe Training as available" — then `0.0.0159` shipped *"Training is now something you can actually use — read a guide, sit its test, and see who owes what"*, and production is `0.0.0168`. **So the reason has moved twice and the verdict has not.** It is no longer unbuilt and no longer invisible; it is built-and-unused, which the Inspections precedent says is sellable as a capability. **It is still held, deliberately, because the row count is the test this row names and this run could not take one** — a scheduled run cannot reach the desk to ask, and the five tables are not readable from here. **Do not resolve this by reasoning; resolve it by asking.** The next run that reaches the desk should ask for the row count in `training_attempts` and `training_progress`, and if nothing has ever been attempted, a capability card is allowed by the same precedent that allows Coverage & capacity — with the tense rule that comes with it: never "teams train on it". |
| **Phone / Communication** | **PUBLISHED 2026-09-04 as a card reading "Your business numbers in one place — dial, texts and voicemail…" — the row now governs only what may NOT be added to it.** **Jose approved it for the site on 2026-09-03** (relayed by the deploy session, which put the fraction to him explicitly — 2 of 7 at the time, 6 of 12 on 2026-09-10). **The approval covers the capability, not any particular sentence, and the fraction has moved without changing anything the card may say.** Draft copy is below, unpublished, awaiting his sight of the actual words. Everything following is the measurement that shapes them.<br><br>**The old blocker has partly lifted; the answer is still no to the strong claim, for a smaller reason.** The row used to say no production line had a ring group and every inbound call reached voicemail. Re-measured on production 2026-09-10 by the desk in `0042`: **6 of 12** `phone_numbers` carry a non-null `ring_group_id` (it was 2 of 7 on 2026-09-02). So calls to those six reach a person. **Six of twelve still dead-end at voicemail** — the fraction roughly doubled and the SHAPE HELD, which is why the rule under it did not move, which is why "phone system" remains an overstatement rather than a promise kept — and the module was renamed **Communication** in `0.0.0141`, so any future copy names that, not "Phone". The checkable test: `phone_numbers` with a non-null `ring_group_id`, as a fraction of all of them. Do not write the claim off a lifted gate; write it off the fraction. |
| **Local Services enquiries arriving as leads** | **INVERTED 2026-09-09 — THIS IS NOW TRUE, AND THE CLAIM IS PUBLISHED. Everything after this paragraph is the history of how it was kept off the site while it was false; read it as method, not as a live prohibition.** The deploy desk ran this row's own test through `artisan tinker` on production release `20260908222755-a18b059a9080` and answered in `0038`: **`leads` with a `remote_id` not beginning `DEMO-` → 23,905, every one of them `source = local_services`**, against the 8 seeded rows this row spent three attempts learning to exclude. The test that was written to catch a decoy did its job and then reported the real thing. Published the same day in the Growth card: *"Connect a Google Ads account and its Local Services enquiries arrive as leads, each carrying the calls and messages that came with it."* The conversation half is `LeadThread` + `LeadConversation` in the deployed tree, whose docblock says Google's Local Services put it there first. **What would put this row back:** the count returning to 0, which would mean a wipe rather than a quiet failure. **And keep the tense rule even now** — the rows are Google's real enquiries into a real advertiser's account, which is what makes the *capability* claim true; it is still not a sentence about how many businesses use EzBizi, and nothing here licenses "teams rely on it". **The original entry, kept whole because its method is the most reused thing in this file:** **Contradicted, not merely unverified — and the evidence is a decoy.** Querying production returns `source = local_services` on 8 leads, which reads exactly like the confirmation this row has been waiting for. All 8 were created in the *same second* and none carries a provider id: they are `leads:seed-demo`, run on production by the deploy session on 2026-08-31. The `source` string was written by the seeder, not by Google. **True count of real Local Services enquiries: 0**, re-confirmed 2026-09-02. **The test this row used to name does not work, and saying so is the point.** It told you to look for a provider `external_id`; there is no `external_id` column on `leads` and none anywhere in `database/migrations/`, so the check silently has nothing to compare and a careful session runs it, finds nothing, and cannot tell "no real leads" from "no such column". **The first replacement written for this row was also wrong, and it is left here as the warning.** It said a genuine enquiry has a `connection_id` and a non-null `synced_at` while a seeded one has neither — reasoned from the real sync path (`LocalServicesLeads.php:216,228`) without ever opening the seeder. **`LeadsSeedDemo.php:132,143` writes both**, pointing `connection_id` at a genuine `social_connections` row it creates. The seeded rows are indistinguishable from real ones on every field that describes *how a lead arrives*, because the seeder's whole job is to look real. **The test that works is the one the seeder deliberately leaves behind:** `LeadsSeedDemo.php:45` stamps `private const PREFIX = 'DEMO-'` onto `remote_id` on every row it plants (`:128`). So — **a real enquiry's `remote_id` is Google's own id and never begins `DEMO-`:**

```sql
SELECT COUNT(*) FROM leads WHERE remote_id NOT LIKE 'DEMO-%';   -- real enquiries
```

**Measured on production 2026-09-03, and the dump is the proof rather than the count:** `remote_id NOT LIKE 'DEMO-%'` → **0**, out of 8 leads, **0 with a null `remote_id`** — so nothing hides in a third state between the two tests. All eight rows carry `has_connection_id = 1` **and** `has_synced_at = 1`, which is the wrong test failing visibly in the data: it would have counted all eight as real and reported the module in use. A count alone would have agreed with both tests; only the dump separates them. Note the command takes `--live`, which is how fiction reached production in the first place. **Read the seeder before trusting any test meant to exclude it** — three tests have now been written for this one row, and the two derived from the real code path both failed, because a good seeder imitates exactly the fields that path writes. This forbids the marketing claim, not the compliance page; see below. |
| **Thumbtack or website enquiries arriving as leads** | **HALF-INVERTED 2026-09-10. THUMBTACK IS NOW TRUE AND PUBLISHED; the website-chat half is unchanged and still conditional.** The desk answered twice in one day and the SECOND answer supersedes the first, which is the whole lesson of this edit. `0042` measured `thumbtack = 1` and said do not invert: that row was `ThumbtackTestLead` firing Thumbtack's own `POST /webhooks/test-negotiation`, with placeholder phone `1234567890`, and `remote_id NOT LIKE 'DEMO-%'` let it straight through because a real Thumbtack id has no `DEMO-` prefix. `0043` re-measured an hour later: a **real customer enquiry** had arrived (`remote_id` `575864197057708035`, phone `4142023918`, created 2026-09-10 15:00:47, ten messages), and Jose had Leads delete the specimen. **The query is now `WHERE is_test = 0`** — `leads.is_test` exists and the marker is live in `0.0.0185`, so the phone-number discriminator is history rather than a step to repeat. Production reads `local_services is_test=0 40745`, `thumbtack is_test=0 1`. Published the same day in the Growth card: *"Connect Thumbtack and its enquiries arrive the same way, carrying the messages that came with them, and you reply from the same screen."* Every clause read out of the deployed tree at `8588589e` (`/download/windows.json` = `0.0.0192`): `ThumbtackLeads::store()` files a `Lead` with `source => Lead::SOURCE_THUMBTACK`; `messages()` files `LeadConversation` rows; `send()` posts the reply and `ThumbtackLeads` is **tagged** into `LeadReplies::TAG` at `AppServiceProvider.php:70-75` beside `LocalServicesLeads` — the tag was checked, not the interface, because a class implementing `LeadReplyChannel` that nothing tags is invisible to `LeadReplies::for()`. That tagging is also what FIXED the bug this very enquiry exposed: replying on a Thumbtack thread used to emit Google's refusal sentence. **`web_chats` is still 0**, so the website-chat half keeps the conditional shape — Web management's card already uses it ("the enquiries they take arrive in Leads") and needs no edit. **What would put the Thumbtack half back:** `WHERE is_test = 0` returning 0 for `thumbtack`, which is a wipe. **And still no usage claim** — one enquiry, from Jose's own twelve connections. The card says what happens when you connect one; it does not say businesses rely on it. |
| **That a post reaches every connected network, or reaches a TikTok profile** | Nine networks connect; **five publish** — corrected upward 2026-09-02, see below. `Publishers::for()` returns a publisher for Facebook Pages, Threads, Instagram, Pinterest and TikTok, and `default => null` for LinkedIn, YouTube, X and Bluesky, which `refusalFor()` answers with "Publishing to X is not built yet". **TikTok posts to the creator's DRAFTS, not the profile** — TikTok requires an audit before an app may post publicly and ours has not had it, so somebody must finish the post in the TikTok app. Pinterest is **images only**; video publishing does not exist. Flagged 2026-08-31 by the deploy session as a live false statement in the in-app release notes; corrected on the site the same day. The checkable test: does `Publishers::for()` return non-null for that provider. |
| **Suggestions** | **INVERTED 2026-09-03 — it *is* on production, and this row said the opposite for a day.** All 17 commits are in the deployed tree (`git merge-base --is-ancestor 486668c 15c9762f` → true), merged at `e718748a`, and they were already in the *previous* production commit `678c3e83` — so it shipped in a deploy nobody announced, not in the 20:32 push. `create_suggestions_table` is in the deployed tree, so **the table test this row named has flipped to yes**, and production `client/VERSION` is `0.0.0147`. **The two design facts have not changed and still mean "present in the app" proves nothing either way**: it is still absent from `ModuleRegistry` (0 hits in the deployed tree) so a module count never sees it, and still pinned unconditionally for every user (`client/lib/main.dart:687` in the deployed tree — it was `:724` on the branch; re-read the line, do not trust this number). **Still do not put it on the site, for an entirely new reason.** The two screen layouts reached production *before* Jose reviewed them — he had delegated the choice for one night on the understanding they could change — and a defect is open against the §9 privacy inventory under-reporting the suggestion count. Neither makes the feature untrue; both make "we shipped this" a sentence we might have to retract. Revisit when the layouts are reviewed and the defect is closed. |
| **That anybody has *set up* coverage and capacity** | Built and unused — a different state from unbuilt, and the two must not be flattened. The capability shipped in `0.0.0137`; `service_areas` and `service_area_slots` both hold **0 rows**, so no customer has created one. This does **not** forbid the "Coverage & capacity" card, which reads *"Set the areas each location covers and how much work a day can take"* — that is capability phrasing, and by the Inspections precedent below, built-and-unused is sellable as a capability and never as usage. **What it forbids is the tense**: "businesses set their coverage", "locations already covering", any sentence implying somebody has. If you are here because you read a 0 and reached for the card, leave the card alone. |
| **Tasks — RETIRED, not merely unused** | **On the site until 2026-09-03, removed the same day it was checked. The reason it survived is the durable lesson: `27aef957` ("Delete every remaining record of the Tasks module", Jose's, 38 files, ~3,800 lines) swept the row out of `MODULE-OWNERS.md`, both `*TaskLink.php` entries, a `NotificationDispatcher` constant, nine superpowers files, a release note and about thirty other citations — and touched ZERO files under `www/`.** A product-wide removal sweep does not reach the marketing site, and nothing makes it obvious that it did not: every internal trace was gone, so any check that samples the repo concludes the removal was thorough. **When a module is deleted, `www/` is a separate job with a separate owner and it will not be in the sweep's diff.** `9175d19f` drops all 31 `task_*` tables and `51729347` removes it from tables it did not own, both ancestors of `d2d39304` — the SHA `/download/windows.json` names. **The decisive check is not the migration**, since a migration in the deployed tree has not necessarily run; it is that **the deployed client carries zero Tasks screens** (`git ls-tree -r d2d39304 -- client/lib/screens`, filtered for task → 0). A customer on `0.0.0148` has no Tasks screen at all. Removed from the site: the sidebar tab and its "9" pill, the whole `p-task` mock panel, the "Boards for the work that is not a job" card, and one word each in `privacy/` and `terms/`. **A retired module is a harder sweep than an unbuilt one** — it was legitimately on the site for weeks, so it reads as settled copy nobody thinks to re-check. |
| **"Teams rely on it" about Inspections** | The module is live and has never been used by a customer. Not embarrassing, but not a usage claim. |
| **That a call reaches an ANDROID handset** | **NEW 2026-09-10, and it is the understatement trap running backwards.** `0.0.0184` gave Android the device token for the calling network and later work declared the service an incoming push arrives on — both were missing, and the release notes read like a phone that rings. **The desk said it twice, unprompted, in `0042` and again in `0043`: nobody has yet observed an Android handset register or ring.** Registering with a calling network is not a handset ringing, and the release note says so in its own bullet rather than leaving the reader to infer it. The site says nothing about mobile calling today and must not start: the Communication card is about numbers, roles, dial, texts and voicemail, and none of that implies a device. **The checkable test is an observation, not a row count** — somebody has to watch an Android handset ring. Invert it when the desk says they did, not when a manifest entry appears. |
| **Monitoring, Marketing** | **Half of this row expired on 2026-09-09 and the reason has to be split.** **Marketing's placeholder is gone**: `0.0.0181` gave it a real home-screen box (published / waiting / failed, counted per account), and `GlanceRegistry::GLANCES` now lists `MarketingGlance::class` while `COMING_SOON` holds only `newsletter` and `monitoring`. So the stated reason is false for Marketing. **It changes nothing on the site, and that is the point of writing it down**: everything Marketing contains is already sold under its own cards (Social media, Newsletter), so there was never a Marketing claim resting on this row — do not add one now on the strength of a glance. A dashboard box is not a capability. **Monitoring is still off the site, for a reason that is no longer the glance.** Its `COMING_SOON` entry is deliberate, not pending, and meanwhile the module itself grew real screens: `0.0.0181` a floor plan, `0.0.0176` screen capture, live view and remote control, `0.0.0183` per-device capture-status reasons; `monitoring_floor_plans` and the capture-status column are both in the deployed tree. **The checkable test this row now names is a row count, because the Inspections precedent needs one**: `SELECT COUNT(*) FROM monitoring_floor_plans` and `SELECT COUNT(*) FROM monitored_devices`. Asked of the desk in `0041`. **And a zero would not settle it either way** — this is the one capability on the list whose copy is about watching employees' screens, so built-and-unused does not automatically make it sellable the way Coverage & capacity was. Whoever inverts this row writes the sentence and checks it against the privacy pages, not just against the count. **THE MONITORING REASON IS REPLACED, 2026-09-10, and the row still holds.** The row count it named was taken by the desk in `0042`: `monitoring_floor_plans` **2**, `monitored_devices` **49**. So built-and-unused is FALSE — somebody drew plans and forty-nine machines are checking in, and the Inspections precedent would ordinarily make that sellable. **It is held for a reason no count can settle:** the copy would be about watching employees' screens, and `0.0.0189` widened exactly that — the desktop app now reports open programs, which programs talk to the internet, and the titles of open browser tabs. **That needs a decision from Jose, not a measurement**, and it is the one item on this list where "do as you recommend" does not obviously reach, because the risk is not accuracy. **Do not invert this on the strength of 2 and 49.** Whoever writes the sentence checks it against `privacy/` as well as against the count. The old reason — "its dashboard summary is a placeholder" — is now the WRONG REASON rather than a wrong conclusion: `GlanceRegistry::COMING_SOON` still holds `monitoring` deliberately. |

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
since 2026-09-01. **iOS is live too — INVERTED 2026-09-08.** Neither fact should
ever be taken from a message; both are one command, and they are the cheapest way
to notice that a listing went live and the page did not:

```
curl -sI "https://play.google.com/store/apps/details?id=com.ezbizi.app"   # 200 = live
curl -s  "https://itunes.apple.com/lookup?bundleId=com.ezbizi.app"        # resultCount
```

**The debt this section recorded is paid, and the page paid it first.** The card
used to claim only what was checkable — built and signed, not on the store — and
this section said it owed the public a link the moment the lookup returned a
result. Measured 2026-09-08: `resultCount` is **1** — `trackId` 6805979795,
`trackName` EzBizi, `version` 0.0.0150, `releaseDate` 2026-09-04,
`currentVersionReleaseDate` 2026-09-05, `minimumOsVersion` 15.0, seller
EzBizi LLC. The `/download/` card already reads *"On the App Store"* and links
`apps.apple.com/app/id6805979795` from both the button and the
`SoftwareApplication` JSON-LD, and the **live page carries that link twice**
(`curl https://www.ezbizi.com/download/ | grep -c id6805979795` -> 2), so this
file was the stale one, not the site. Nothing to publish; only this row needed
correcting.

**And that conclusion was half right, which is the lesson. Re-measured
2026-09-08 by the daily run: the `/download/` page was correct and the
HOMEPAGE was not.** `91fef0e6` ("The download page says the iPhone app is on
the App Store, because it is") did exactly what its message says — it fixed the
download page. The homepage kept the old sentence in **two** places, published
and live for four days:

    www/index.html:38   FAQPage JSON-LD, "What can I run EzBizi on?"
    www/index.html:617  the visible <details> answering the same question

both reading *"the iPhone app is built and still to come"*, plus
`"operatingSystem":"Web, Windows, Android"` in the homepage
`SoftwareApplication` JSON-LD. Fixed 2026-09-08 to *"The Android app is on
Google Play and the iPhone app is on the App Store."* and
`"Web, Windows, Android, iOS"`.

**Three things this is worth remembering for.** (1) It is the *understatement*
failure again — the direction this file already warned nobody watches for, and
it cost every homepage visitor the store link for four days. (2) The row above
checked the page the fix had touched and concluded the site was fine; **a page
being right is not the claim being right**, and this file's own closing rule —
grep the whole of `www/` — is what would have caught it. The FAQ is the easiest
place in the site to miss, because a claim there is phrased as an answer rather
than as a feature. (3) It is a *store* fact, so the standing check catches the
store changing state but not the site failing to follow: **after either store
command flips, grep `www/` for the old sentence, not just the card.**

**Both commands stay, and the Apple one has a second failure mode now.**
`resultCount` 0 no longer means "not approved" — it would mean a pulled listing.
And **`curl -sI https://apps.apple.com/app/id6805979795` answered `429 Too Many
Requests` during this very run**, while the lookup API kept answering normally:
Apple rate-limits the storefront HTML. **A 429 is not a dead link — take the
verdict from the `itunes.apple.com/lookup` JSON, never from a `HEAD` of the
storefront.**

**Re-measured 2026-09-11 by the daily run:** Play returns `200`; the Apple
lookup returns `resultCount` **1**, `trackId` 6805979795, `version` **0.0.0190**,
`currentVersionReleaseDate` 2026-09-11. Both stores live, both pages already
correct, nothing to publish — recorded only so the next run can see the version
moving rather than re-deriving that it should.

**What would put this row back the other way:** the lookup returning
`resultCount` 0 for more than a day, which is a pulled or rejected listing rather
than propagation. Then the card's App Store button and the JSON-LD
`downloadUrl` *and* `installUrl` come down the same day — three places on one
page, plus the `og:` and `twitter:` descriptions, which both say "App Store".

## `/download/windows.json` tracks PRODUCTION, not `client/VERSION`

The daily task file says to check the manifest against `client/VERSION`. **That
check is wrong and will report a false mismatch every time a client change is
merged and not yet shipped.** On 2026-08-31 it did: manifest `0.0.0122`, repo
`0.0.0123`, nothing wrong.

The installer has to match the API it will talk to, so the manifest tracks the
deployed release. Compare it to what production runs — ask the deploy session —
or to the published `EzBizi-setup.exe`, not to the repo's next version bump.

## Communication — PUBLISHED 2026-09-04

Jose said yes on 2026-09-03; the card was held unpublished because the approval
reached this desk relayed through a peer. **On 2026-09-04 he lifted that
requirement directly — "do as you recommend, stop asking me for approvals" —
and the card landed.** It reads:

```html
<h3>Communication</h3>
<p>Your business numbers in one place — dial, texts and voicemail, with each
   role given the company's lines rather than one number at a time.</p>
```

Every clause is checkable. Three sub-tabs exist (`phone_tab.dart:36-38`:
Dial, Messages, Voicemail). Role-level access to the company's lines is what
`0.0.0141` shipped, replacing a per-line tick per role.

**What it deliberately does not say, and why the fraction outranks the
approval.** Six of twelve production lines route to a ring group; six still
dead-end at voicemail (2 of 7 when this was written; re-measured 2026-09-10). So no sentence may imply a call reaches a person:
not "your calls reach your team", not "never miss a call", not "phone
system". The approved thing is that Communication exists and does what the
card says. **An approval to describe a capability is not evidence the
capability's weakest number has changed** — and 2-of-7 is the number that
stops this sentence drifting.

**Why it was held, and what replaced the rule.** On this pipeline a commit to
`main` *is* a publish: `deploy.sh` ships `www/` with every web deploy, so
there is no staging step. A peer-relayed approval was judged enough authority
to *write* an outward-facing sentence and not enough to *publish* one Jose had
not read.

**That gate is gone, and only that gate.** Jose lifted it on 2026-09-04. What
it was protecting against — a claim reaching the public that nobody had
checked — is now carried entirely by the verification, so the verification is
the thing that must not slip. **Every clause of this card was re-checked
against the code before it shipped**, not taken from the draft:

    Dial / Messages / Voicemail   phone_tab.dart:44, :46, :47
    the module is "Communication" phone_tab.dart docstring, "Communication > Phone"
    role-level lines              role_inboxes.dart:84 — "A role with Communication
                                  now gets its company's lines by reach, not one
                                  tick at a time"

**The fraction still governs what may not be said.** 6 of 12 production lines
route to a ring group; six land in voicemail (2 of 7 when written, re-measured
2026-09-10 — doubling the numerator did not change the half). So no sentence may imply a call
reaches a person — not "never miss a call", not "your calls reach your team",
not "phone system". The published card says none of them, and a grep for all
four phrasings across `www/` returns zero. That check is worth re-running
whenever this card is edited.

## Web management — PUBLISHED 2026-09-09

A card in the module grid, between Newsletter and Inventory:

```html
<h3>Web management</h3>
<p>Your WordPress site takes its phone, address, hours and licence numbers
   from here, so one change reaches every page &mdash; and each site keeps its
   own answers. Add the chat bubble and contact form in your own brand colour,
   and the enquiries they take arrive in Leads.</p>
```

**It was a gap, not a new capability.** Web management has been in the
"Live, and safe to sell" list since this file was written and had no card;
the site named it only inside an HTML comment in the app mock. Nothing shipped
today to license it. It is here because the daily job is "do not let the site
go stale", and a module can go stale by never arriving.

Every clause read out of the deployed tree — production is `0.0.0183` at
`a5234975` (`/download/windows.json`, fetched), which is `main`'s tip, so the
working tree and the deployed tree are the same commit for once:

    phone, address, hours,    wordpress-plugin/ezbizi/readme.txt enumerates
    licence                   the shortcodes: [ezbizi_phone] [ezbizi_address]
                              [ezbizi_hours] [ezbizi_license] and four more
    one change reaches        the readme's own proposition, in as many words:
    every page                "You set your details once in EzBizi, put a
                              shortcode where the value belongs"
    each site keeps its own   readme, "Each website gets its own answers" — a
    answers                   subdomain is a separate site with its own token
    chat bubble and contact   web_widgets (one row per site, JSON config) +
    form, your brand colour   branding.brand_colour, both migrations deployed
    arrive in Leads           WebsiteEnquiries.php:177 `Lead::create` on the
                              site's company, with `source` and `remote_id`

**The last clause is the one under a do-not-list row, and it is deliberately
in that row's own allowed shape.** "Thumbtack or website enquiries arriving as
leads" permits *"connect it and enquiries arrive in Leads"* and forbids any
past tense or count, because `web_chats` was **0** when the desk measured it in
`0038`. "The enquiries they take arrive in Leads" describes what the code does
when a visitor uses the widget — the same grammar as the approved Growth card's
"Connect a Google Ads account and its Local Services enquiries arrive as leads".
**It says nothing about anybody having connected one.** If that row inverts, this
sentence needs no edit; if it ever had to be pulled, this card is a second place
to look and the app mock is a third.

**What the card deliberately does not say: that EzBizi builds or hosts the
site.** It does not. The customer's WordPress renders; EzBizi holds the facts
and answers a token. A sentence like "your website, managed from EzBizi" would
read as a site builder and be false in the direction customers act on.

## Newsletter — PUBLISHED 2026-09-08

A card in the module grid, between Social media and Inventory:

```html
<h3>Newsletter</h3>
<p>Write a mailing in blocks or straight HTML, see the month it goes out on,
   and send it through your own Mailchimp account &mdash; your list, your
   sending reputation.</p>
```

**Every clause was read out of the DEPLOYED tree, not the working one.**
Production is release `0.0.0168` at `a18b059a` (`/download/windows.json`,
fetched); the verification below was taken at `cedd6206` (`0.0.0151`, the
release that first carried it) and the files were confirmed still present at
`a18b059a`:

    blocks or straight HTML   newsletter_editor.dart:142-143 — a two-segment
                              control, ButtonSegment 'Blocks' / 'HTML'
    the month it goes out on  newsletter_dashboard.dart:42 — MonthCalendar over
                              the scheduled ones; Newsletter.scheduled_for /
                              scheduled_timezone are real columns
    your own Mailchimp        NewsletterSenders::for() returns a sender for
                              SocialProvider::Mailchimp and `default => null`
                              — the same shape as Publishers, so the same test
    your list, your           MailchimpSender sends as a campaign on the
    sending reputation        TENANT's connection. Its docblock says so in as
                              many words: "EzBizi does not send newsletters, by
                              design — a mailing goes out on the tenant's own
                              reputation, to their list, through their
                              provider."

**The test for this row is `NewsletterSenders::for()`, exactly as the social
row's is `Publishers::for()`.** If a second provider is added, the card's
"your own Mailchimp account" is the clause that goes stale, and it goes stale
by being too *narrow* — the understatement failure this file keeps catching.

**What the card deliberately does not say.** The deploy desk's `0035` caveat
is that Newsletter is reachable by administrators only until Jose grants
`newsletter.view` / `newsletter.send` to other roles. That is a permission
default, not a capability limit, and no sentence on the site describes who in
an organization can reach a module — so it changes nothing here. It is
recorded because the next reader will meet the caveat in `0035` and wonder
whether the card outran it. It did not. **No usage is claimed**: nobody has
sent a mailing, and the card is capability phrasing under the Inspections
precedent, like Coverage & capacity.

## The day two rows inverted at once, and what actually unblocked them

**2026-09-09.** The Local Services row and the Training row had between them been
rewritten five times across a week, always to correct the *reason* while the
verdict stood. Both cleared on the same day, and neither cleared because
somebody reasoned better about the code. **They cleared because a row count was
finally taken.**

The sequence is worth copying exactly:

1. The daily run wrote the two questions into `docs/agents/inbox/0037` as
   **row counts with the SQL spelled out**, not as "is Training ready?".
2. The deploy desk ran them through `artisan tinker` on the live release and
   answered in `0038` with the numbers and the release id.
3. The run published both cards the same day, each against the number.

**A question that names its own SQL is answerable by somebody who is not
thinking about the website.** Every earlier attempt on these two rows was a
judgement call passed back and forth between sessions that each had half the
evidence — this file could read the code and could not reach the database; the
desk could read the database and did not hold the claims list. Neither could
have resolved it alone, and neither was wrong.

**The one that nearly went the other way.** `0035` had said, in good faith,
"do not describe Training as available" — true when written, at `0.0.0151`,
when it had shipped server-side with no sidebar entry. `0.0.0159` gave it
screens eight releases later. **A "no" from a desk is a measurement too, and it
expires like any other**; the run that reads it a day later is reading a
timestamp. Ask again rather than inheriting it.

**And the thing the numbers did NOT license.** 23,905 real Local Services leads
prove the pipe works; three training tables at zero prove nobody has trained.
Both became *capability* claims and neither became a usage claim — the Growth
card says enquiries arrive when you connect an account, and the Training card is
four imperatives with no completed action in it. **The row count changes which
sentence is true, never which tense is allowed.**

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

## The deploy desk HAS an address — read `docs/SESSIONS.md`, never `ListAgents`

**INVERTED 2026-09-04.** This section said there was no deploy desk to find. That
was wrong, and wrong in a way that cost three sessions an evening: we were all
looking in the wrong place, and the right one was already committed.

**The route.** `CLAUDE.md:6` tells every session to read `docs/SESSIONS.md`
before messaging another one. Its Deployment row (≈:65) names a **desktop session
id** — `local_<uuid>` — addressed with `ccd_session_mgmt send_message`, *not* with
`SendMessage`.

**Why every address we tried was dead.** We were using pipe names from
`ListAgents` (`ezbizi-a1`, `ezbizi-5b`, `ezbizi-7b`, `local_a7c13d4e`). A pipe
name is derived from the folder plus two hex digits and **dies with the process**;
a desktop session id is permanent and never reassigned. Four dead addresses were
not four dead desks — they were one live desk and the wrong kind of name.

**The daily check's step 1 is therefore wrong at the source**: it opens by calling
`ListAgents` and matching a session name. That procedure cannot succeed, and
`CLAUDE.md` already says so. **Read `docs/SESSIONS.md` instead**, and fall through
when the id errors — that file's own header warns the session column can go stale
within the hour, and an unstaffed role takes a file in `docs/agents/inbox/`.

### And when the daily check runs on its schedule, the id is not the problem either

**Measured 2026-09-08, and it is structural rather than a bad day.** The catalog
is a *scheduled task*, and `ccd_session_mgmt send_message` refuses every call from
one: *"This tool is unavailable in unattended sessions (scheduled-task runs and
remote-dispatched trees)."* The correct desktop id was in hand and never got as
far as being wrong. **So the fix above — right id, right tool — does not rescue
this check on the days nobody is watching it, which is most days.**

Do not spend a run rediscovering this, and in particular **do not read the refusal
as a dead desk**: a dead id and a disabled tool produce the same silence, and they
want opposite responses. The distinction is in the error text — an id that has
gone stale errors about the *session*, this one errors about the *caller*.

**`docs/agents/inbox/` is not the fallback here; it is the only channel.** Write
the ask to the protocol in `docs/agents/README.md` — `NNNN-<from>-to-<to>-<slug>.md`
with `from: to: date: subject: needs:` — and **say in the message that a reply must
also be a file**, because the next run is another scheduled one and cannot receive
a `send_message` either. A desk that answers in its own window is answering into a
room this check will never enter. First one written that way: `0034`, 2026-09-08.

**Write that file to the protocol or it is just a file.** `docs/agents/README.md`
specifies a filename `NNNN-<from>-to-<to>-<slug>.md` and YAML frontmatter — `---`
fences, lowercase `from:` `to:` `date:` `subject:` `needs:`, where `needs:` is
`action | answer | fyi` and is the only field that changes what the receiver does.
`to:` is the routing field. A note written without them can still be read, but
only by a person who happens to look; it is not addressed to anyone. Acknowledge
by `git mv`-ing the file to `done/` — moving it *is* the acknowledgement — and
reply with a new message citing the number rather than editing theirs, because a
sent message is the record of what its receiver worked from.

**What survives the inversion, because it was never about the address:**

- **The artefact answers "did it publish", never a peer.** Fetch the URL and grep
  for the string that had to change. `deploy.sh:479` publishes the site last and
  non-fatally, so a green release is not evidence.
- **`www/` lives on `main`**, and the website session commits straight to `main`,
  so a release of `main` ships the site as a side effect with no desk in the loop —
  which is why `c0b28cd` published overnight with nobody answering. **This does not
  generalise to a branch**: a marketing session watched the same release go out and
  its own verified work stay behind. If `www/` ever moves to a branch, this route
  stops working.
- **Write the handoff where a reader already goes.** Not merely "write it down":
  that same session first put its handoff on the branch that needed merging, where
  whoever would merge it could not read it. This file works because the daily check
  is *told* to open it first.

### The inbox route works, and its answer went stale the same day

**Measured 2026-09-08 by the run after the one that wrote `0034`.** The desk
answered it as a file — `docs/agents/inbox/0035-deploy-desk-to-website-what-shipped-0141-to-0151.md`,
committed as `bc9788e1` — a full catalog of `0.0.0142` → `0.0.0151` with two
caveats and a list of claims to re-check. **So the channel is proven end to
end: a scheduled run can ask, and a desk that reads its inbox will answer where
the next scheduled run can read it.** That is the thing to keep doing.

**And by the time it was read, it was a version of production that no longer
existed.** `0035` says production is `0.0.0151` at `cedd6206`, measured 13:20
UTC. `/download/windows.json` answered `0.0.0168` built `a18b059a` the same
evening — **seventeen releases later, all dated 8 Sep 2026 in
`version_history.dart`.** Nothing was wrong with the answer; it was a
timestamp, like every peer measurement this file has been burned by.

**So the sequence for the next run is: fetch the manifest FIRST, then read the
inbox against it.** One `curl` names the deployed version and SHA with no desk
in the loop, and it is the thing that tells you whether an inbox answer is
current, a week old, or describing a release that shipped and was superseded
before you opened the file. Reading the mail first inverts that — you spend the
run believing a catalog, and the manifest is what would have told you not to.

**`version_history.dart` at the deployed SHA is the fallback catalog**, and it
is what filled the `0152`–`0168` gap on this run. It is not equal to a desk's
answer: it says what was *written down*, per version, with no "is it live for a
paying customer" judgement attached — which is exactly what `0035`'s two
caveats were. Use it to find what to verify, then verify each clause against
the code at the deployed SHA, which is what this file has always required.

**This session is already in `SESSIONS.md` and needs no registration** — the
daily catalog is listed there as *not a role* ("it finds the deploy desk here;
nobody needs to find it"), with all three of its rotating ids. Do not add a row
for it, and do not claim the Website role's row (≈:79), which is a different
session.

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

## Thumbtack — PUBLISHED 2026-09-10, and the desk corrected itself in an hour

The Growth card gained one sentence, beside the Google one it copies:

```html
<p>Track where new work comes from &mdash; down to the advert that produced the
   enquiry. Connect a Google Ads account and its Local Services enquiries arrive
   as leads, each carrying the calls and messages that came with it. Connect
   Thumbtack and its enquiries arrive the same way, carrying the messages that
   came with them, and you reply from the same screen.</p>
```

**Clause by clause, out of the deployed tree at `8588589e` (= `0.0.0192`, from
`/download/windows.json`), not out of the release note:**

    connect Thumbtack        ThumbtackOAuth.php in the deployed tree; 12
                             social_connections rows, provider thumbtack
    enquiries arrive as      ThumbtackLeads::store() -> Lead::create with
    leads                    source => Lead::SOURCE_THUMBTACK
    carrying the messages    ThumbtackLeads::messages() files LeadConversation
                             rows per negotiation message
    you reply from the       send() posts to ThumbtackNegotiations and files the
    same screen              reply; AND ThumbtackLeads is TAGGED into
                             LeadReplies::TAG at AppServiceProvider.php:70

**The tag is the clause that needed checking, not the interface.** `ThumbtackLeads
implements LeadReplyChannel` proves nothing on its own — `LeadReplies::for()`
reads the container tag, so a channel nobody tags is invisible and the screen
falls through to a generic refusal. Same shape as `IMPLEMENTED_ACTIONS` in
`ACTIVE-CONSTRAINTS.md`: declaring a thing is half of it, and the other half is
in a different file. Here the tagging is also what *fixed* the bug this very
enquiry exposed — replying on a Thumbtack thread used to emit Google's refusal
sentence, because one provider's wording was filling a gap that had no channel.

**Confirmed PUBLISHED by fetch, 2026-09-11, not by the passage of a day.**
`curl -sS https://www.ezbizi.com/ | grep -c "Connect Thumbtack and its
enquiries arrive the same way"` -> **1**, with `Last-Modified` Fri 11 Sep 2026
19:49:45 GMT. `0045` §2 had asked the desk to publish it and `0045` is still in
`inbox/` rather than `done/` — so the sentence rode a release rather than an
acknowledged request, which is exactly the route "Absence of news is not a
publish" describes and exactly why the check is a fetch. **Do not ask for it
again.**

**Two desk answers, an hour apart, opposite verdicts — and the second one is a
measurement, not a change of mind.** `0042` said "the second lead source is a
TEST, not a customer" and was right when written: the row was `ThumbtackTestLead`
firing Thumbtack's own `POST /webhooks/test-negotiation`, with placeholder phone
`1234567890`. `0043` supersedes it — a real enquiry landed at 15:00:47 and Jose
had the specimen deleted. **Read an inbox in number order and act on the last one
that speaks.** A run that stopped at `0042` would have held a true claim off the
site for a day and written down a phone-number discriminator that no longer
applies to anything.

**The query is `WHERE is_test = 0`.** `leads.is_test` shipped in `0.0.0185`
precisely because the test row was indistinguishable from a customer's without
inspecting a phone number. That is the third test written for this family of rows
and the first that depends on neither a seeder's prefix nor a placeholder.

### A claim that does not survive a disconnect, and one that does

**Recorded because it is a property of a published sentence, not of a module.**
Deleting a connection row nulls `leads.connection_id` on everything it ever filed.
For Thumbtack that is now repaired automatically — `3a6a8ae3` makes the next event
on an orphaned lead adopt it, from `0.0.0187`. **For Google Ads it is not.**
Disconnect an Ads account and its leads lose their connection, their recordings
and their replies, and Google offers no lookup to repair from. Unfixed, and a
bigger change than the Thumbtack one.

The Growth card survives this in both halves because both clauses describe what
happens *when you connect*, never what stays true afterwards. **If anybody ever
writes "your enquiries and their recordings stay with the customer", that sentence
is false for Google Ads the moment somebody disconnects** — and a disconnect is a
normal thing a customer does, not an incident.

## Reports — STILL HELD 2026-09-11, and the draft it was held with went stale in a day

`0.0.0192` shipped Reports on 2026-09-10, a card was drafted against it, and it
was held on one question: had the `saved_reports` migration RUN. **That question
is still unanswered** — `0045` is in `docs/agents/inbox/`, not `done/` — **and
the draft it was guarding is now wrong, in the direction this file keeps
catching.**

**The draft was understated within a day.** It said *"start from jobs, customers
or people"*, which is exactly what `ReportMap::all()` held at `8588589e`.
Production is now `0.0.0200` at `e1a783dc` (`/download/windows.json`, fetched)
and `ReportMap::all()` holds **ten**. Three of ten is the same understatement
failure that left the iPhone store link off the homepage for four days — and it
would have shipped the instant the desk answered a question about a table.

**A held draft ages, and nothing in the hold says so.** The hold was on the
*answer*, so the copy looked settled. Whoever gets a number from the desk
publishes what is below, not what is in `0045`.

Redrafted 2026-09-11, unpublished:

```html
<h3>Reports</h3>
<p>Build a report from your own records &mdash; start from any of ten parts of
   the business, from jobs and customers to fleet and inventory, pull in the
   columns from what they connect to, filter it and save it by name. Running a
   saved report and building one are separate permissions, so you can let
   somebody run what you built without letting them change it.</p>
```

Clause by clause, read out of the deployed tree at `e1a783dc`:

    any of ten parts     ReportMap::all() — jobs, customers, franchises (label
                         Branches), services, inventory_items, vehicles (Fleet
                         Management), training_guides (Training), leads,
                         service_areas (Scheduling), users (Human Resources)
    start from ANY       0.0.0195 fixed a relation that was traversable in one
                         direction only; before it, a report starting anywhere
                         but Jobs could reach nothing
    what they connect to the same entries carry `relations` edges; the builder
                         walks them and never composes an ON clause
    save it by name      saved_reports migration: name(120), unique on
                         (organization_id, name), definition JSON
    separate permissions ModuleServiceProvider.php:2665 key `reports`, actions
                         view / build / export, with `build` and `export`
                         behind `reports.view` (:2670-2671)
    reachable            client/lib/core/module_screens.dart:74

**"Scheduling" is a decoy, and it must not reach the card.** `0.0.0199`'s
release note says a report can start from Scheduling, and the first row of the
do-not-list forbids scheduling a job onto a calendar. They do not collide: the
resource labelled `Scheduling` is **`service_areas`** — coverage and capacity,
the built-and-unused pair that row already governs. **Re-verified at `e1a783dc`
rather than assumed**: `service_area_slots` is still `(organization_id,
service_area_id, weekday, start_time, end_time, max_jobs)` with no `job_id`, and
no migration in the deployed tree creates a table carrying both a job reference
and a slot reference. The structural test holds and the row stands. **Do not
enumerate the ten resources on the card** — printing the word "Scheduling" on a
marketing page makes the forbidden claim by itself, whatever table sits behind
it. That is why the redraft says "ten parts of the business" and names four.

**The card was verified against the deployed tree and the feature was broken.**
This is the sharpest thing learned this week and it is not really about Reports.
`0.0.0194`'s note reads: *"New report opened to a blank panel. The builder could
not be used at all in the two releases before this one."* One of those two is
`0.0.0192` — **the release every clause of the original draft was read out of,
and every clause held.** `ReportMap` was right, the migration was right, the
permissions were right, the screen was mapped, and a customer pressing New
report got a blank panel. **Reading the deployed tree proves the code says the
thing; it cannot see a dead button.** Publishing on 2026-09-10 would have
pointed the public at one, and what stopped it was a hold on an unrelated
question. That was luck, and it converts into a rule: **for a module that has
just arrived, "every clause checks out" is not enough — somebody has to have
used it.** A row count answers that for an old module; for a new one, the
bug-fix releases are the evidence, which is the next paragraph.

**The question is unchanged and the evidence around it moved.** `0.0.0194` says
*"the list of saved reports and running one were unaffected"*, `0.0.0195` adds
deleting a report, and `0.0.0197` fixes saving one from a company or a branch.
Those are faults found by somebody listing and saving reports on a real
installation, which is behavioural evidence the table exists. **It is not the
measurement**, and this file's Training row says in as many words: do not
resolve this by reasoning; resolve it by asking.

```sql
SELECT COUNT(*) FROM saved_reports;   -- 0 is a fine answer; an ERROR is not
```

A number publishes the redrafted card under the Coverage & capacity / Newsletter
precedent. An error holds it, because the table would not exist. Asked in
`0045`, re-asked with the new draft in `0046`.

**What the card deliberately does not say.** `ReportMap` publishes
`amount_minor` and `currency` on jobs and `price_minor` on services, and the
module registration says "money included". **No sentence about money goes on
this card.** Not because it is untrue, but because this site's hardest-won
removal was the billing family, `bill` is a machine-enforced forbidden term, and
a Reports card is exactly where a sentence about what the work was worth would
read as innocent and put it back. It also says nothing about who may reach it:
administrator-only until Jose grants `reports.view` is a permission default, and
the Newsletter row already settled that those change nothing here.

**And the grid will then hold two cards with "reports" in the name.** `Forms
&amp; reports` is already there — *"Capture what happened on site and turn it
into something the customer can read"* — which is Inspections output, not this
module. Neither is false. Place the Reports card away from it, or the pair reads
as one feature described twice.

## 0193 to 0200 shipped in a day and touched nothing the site says

**2026-09-11, and the eight releases are recorded because a quiet day has to be
provably quiet.** Production moved `0.0.0192` -> `0.0.0200` (`e1a783dc`). No
desk was reachable — `ccd_session_mgmt send_message` refused the correct
Deployment id with *"unavailable in unattended sessions"*, which is the error
about the CALLER and not about the session, so the desk is not dead and the
inbox is the only channel. The catalog below is `version_history.dart` at the
deployed SHA, which says what was written down and carries no "live for a paying
customer" judgement.

    0193  quiet accounts polled every 15 min; a report can say who performed a
          job and which branch it belongs to
    0194  New report opened to a blank panel — the builder was UNUSABLE in the
          two releases before this one (see the Reports section; it is the
          reason that card's hold turned out to be load-bearing)
    0195  any module can start a report; reading backwards along a relation;
          delete a report; the field list grouped like the sidebar
    0196  Not taking calls survives a reconnect; disconnected-inbox wording and
          self-recovery; accounts asked longest-unread first
    0197  sidebar drag-to-reorder saved to the account, dividers; a report can
          be saved from a company or a branch; eight distinct chart colours
    0198  a mailbox is not disconnected by a timeout; over-8 chart slices group
          into one row; a screen reader names every folded category
    0199  a report can start from TEN parts of the business, up from four;
          Reports gains sub tabs (Reports / Builder / Experimental)
    0200  Do Not Disturb survives a call you place yourself

**Nothing there makes a published claim wrong, and three re-measurements say so
rather than an inspection saying so.** `Publishers::for()` at `e1a783dc` still
returns five and `default => null` — so *"Five networks send today"* holds and
**no seventh publisher was written**. `NewsletterSenders::for()` still returns
`Mailchimp` and `default => null`, so the Newsletter card's "your own Mailchimp
account" has not gone narrow. And the four phrasings the Communication card
forbids — "never miss a call", "your calls reach your team", "phone system",
"calls reach" — return **0** across `www/`, re-run because `0196` and `0200` are
both about call availability and a call-availability release is exactly when
somebody reaches for "never miss a call".

**The audit was clean and the number is the point.** `python www/check-site.py`
-> `ok - 7 pages, 7 sitemap entries`; the forbidden-terms gate, parsed from this
file and split on LINES, -> `ok - 4 terms, none present` **with a positive
control** (`EzBizi` -> 383 matches, proving the grep was actually running — a
first attempt put `--include` after the path and every term silently returned
0, which reads identically to a pass); and all seven pages fetched from
production are byte-identical to `www/` after stripping CR. No stale deploy, no
hand edit on the box.

**What is still open, and neither is mine to close.** `saved_reports` —
unanswered, asked in `0045` and `0046`. **Monitoring** — held on a decision from
Jose about copy describing watching employees' screens, not on a measurement,
and `0.0.0189` widened it to open programs and browser tab titles. Do not
re-derive either from first principles; both are waiting on somebody else.

## 2026-09-12: nothing shipped, and the gate's own control was the finding

**Production did not move.** `/download/windows.json` answers `0.0.0200` built
`e1a783dc` — the same release yesterday's run recorded. `main` is two commits
past it (`2965475e`, `2fccdbd1`), touching only this file, `0046`, and a phone
test. So there was no catalog to ask for, and the quiet is measured rather than
assumed.

**No desk was running to ask.** `list_sessions` returned every EzBizi session
with `isRunning: false`; Deployment (`local_072bee23…`) last active 2026-09-11
20:21 UTC. **That is a third state this file has not named before** — not a dead
id, not the caller-side refusal, just nobody home. `0045` and `0046` are
therefore *unread*, not declined, and **no `0047` was written**: a third file
restating the same question adds a file, not a reader.

**The audit was clean.** `python www/check-site.py` -> `ok - 7 pages, 7 sitemap
entries`; all seven pages fetched from production are byte-identical to `www/`
after stripping CR, every one `Last-Modified` Fri 11 Sep 2026 19:49:45 GMT — no
stale deploy, no hand edit. Play returns `200`; the Apple lookup returns
`resultCount` **1**, `version` 0.0.0190. Three published claims re-measured at
`e1a783dc`: `Publishers::for()` still five and `default => null`, so *"Five
networks send today"* holds and no sixth publisher exists;
`NewsletterSenders::for()` still Mailchimp and `default => null`; the four
phrasings the Communication card forbids return 0 across `www/`.

**And the forbidden-terms gate reported `0` for all four terms while doing
nothing at all.** `--include` written *after* the path makes grep treat it as a
filename: it errors to stderr, the term returns 0, and the loop prints a clean
pass. This file already warned about exactly that. **The warning did not save
this run, and the reason is the part worth adding: the positive control was
written with the same misplacement.** `EzBizi` still answered **168**, because a
misplaced `--include` does not stop the recursive grep — so the control looked
healthy beside four silent zeros and confirmed a false pass.

**A control only discriminates if it is the same command shape as the thing it
is controlling.** Re-run with the flags before the path, the gate is
`ok - 4 terms, none present`, positive control 168, negative control
(`zzzznotpresent`) 0. Run all three through one loop; a control assembled by
hand beside the loop is testing a different command.

**Still open, both unchanged and neither mine to close.** `saved_reports` — the
count that publishes the redrafted Reports card, asked in `0045` and `0046`.
Reaching for it directly was tried and failed cleanly: the EzBizi production box
is not among this session's configured SSH servers (only A1 Tools' SiteGround),
so the read is the desk's and stays there. **Monitoring** — held on a decision
from Jose about copy describing watching employees' screens, not on a
measurement.

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
