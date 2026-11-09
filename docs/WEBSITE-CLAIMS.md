# What the website claims, and on whose authority

> **2026-11-02: www.ezbizi.com is WordPress now** (Jose: "remake it on wordpress then, the entire site"). `www/` edits NO LONGER GO LIVE. Pages live on the websites box, /var/www/vhosts/ezbizi.com/httpdocs (theme ezbizi-site). Body = one HTML block in post_content; <head> = post meta `_ezb_head`. Edit with wp-cli as ezbizi_ftp, `--user=ezbizi_admin` (else kses strips scripts). Imported from main f4cb9327e. The claims register below still holds; the daily check must diff the live page against WordPress, not `www/`. Owner of the WP plumbing: Web Module (local_89561020).


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
| **Quotes, billing, invoicing, estimates, payments** | **2026-10-21: INVOICES NOW EXIST ON MAIN — "invoicing is not built" is false from 0.0.0472; the verdict holds.** `5e98257a` (Financials › Invoices: charge sheets, branded PDFs, approve, send through the business's own mail server, payments and refunds *recorded*, overdue sweep; tables `invoices`, `invoice_lines`, `invoice_payments`) was NOT in production `044b1ceb6` (0.0.0471) at the 10-21 check. Held: `invoic` and `bill` are gate terms, no row count yet, and EzBizi records payments rather than taking them (no payment processor in `config/services.php`). Inverting means editing `forbidden-terms` deliberately with a non-test `invoices` count in hand, and checking `/privacy/` for invoice recipients' data first. **2026-09-29: ESTIMATES NOW EXIST — the reason below is false for them; the verdict holds.** `0.0.0313`-`0.0.0315` (production `0.0.0318`, `a9577f42`) made estimates records (`EST-`), with a Jobs › Estimates tab gated on the Estimates permissions *and* Jobs Financials; `0.0.0313` also shows each job's money state (Unpaid / Paid …) read live from Workiz. Held because it shipped today, no row count was taken, and `estimat` is a publish-gate term — inverting means editing the `forbidden-terms` block deliberately, with a count of `estimates` rows in hand. Billing / invoicing / payments are still not built in EzBizi: the money chips are Workiz's figures. Original reason: Does not exist. 151 production tables searched for `quote` / `invoic` / `bill` / `estimate` return nothing, and no such module is registered among 26. The only `payment` match is `vehicle_payments` — Fleet recording what it paid for a van. Removed in `6c67efb`. |
| **Scheduling a job onto a calendar** | Not built, and **test the relation, not a column.** The obvious check — "`jobs` has no scheduled-date column" — is already false: `jobs.scheduled_for` (datetime) and `jobs.assigned_role_id` exist on production, and **0 of 25 jobs have either set**. The durable test is structural: **no table carries both a job reference and a date-or-slot reference.** `service_area_slots` is `(service_area_id, weekday, start_time, end_time, max_jobs)` — a recurring weekly capacity *template* with no `job_id`, no date and no per-occurrence row, so it cannot express "this job, this day, this slot" even in principle. That stays true until the module is actually built, which is exactly when the claim should change. **2026-09-25: the replacement test (below, 09-23) FLIPPED** — `SchedulingAvailabilityController` now answers `basis: 'remaining'`, counted by `App\Support\Scheduling\Openings::taken()`. **Held anyway, for one checkable reason:** the bookings it counts are `jobs.scheduled_for`, which arrive from Workiz, and the Schedule board writes moves back *to Workiz* (`0.0.0277`). Whether a company WITHOUT Workiz can put a job on a date inside EzBizi was not established by this run. Ask the Scheduling session: *can a tenant with no Workiz connection set `scheduled_for` on a job from any screen, and how many `jobs` with `scheduled_for` set have no `workiz_jobs` row?* A yes with a nonzero count inverts this row. **ANSWERED 2026-09-28 by the desk (`0066`): yes, and 0.** `JobsController` validates `scheduled_for` on create/update with no Workiz check and `job_detail_panel.dart` draws the field — but **0 of 8,231** production jobs with `scheduled_for` set lack a `workiz_jobs` row. The row's own test says a yes with a *nonzero* count inverts it, so it **stays held**. Re-ask for the count; do not reason past it. |
| **WhatsApp messaging** | **2026-10-28: THE CODE NOW EXISTS, AND NO TENANT HAS USED IT — the verdict holds one more step.** Embedded Signup landed in `4d0a0a845` (0.0.0445) and coexistence (a WhatsApp Business app number that stays on the phone) in `698fe7e04` (0.0.0502, live). Production `whatsapp_numbers`: 3 rows, all hand-mapped EzBizi/A1 numbers, `social_connection_id` null on all 3; WhatsApp `social_connections`: 0. Signing up another business's WABA also needs Meta's app-level access, which no row proves. **Invert on the first `whatsapp_numbers` row with a non-null `social_connection_id` in a customer org** — then check `/privacy/` for the coexistence contacts/history sync (`smb_app_data`; today no webhook handler stores `history` or `smb_app_state_sync`, so nothing new is kept). **REASON CORRECTED 2026-09-24; the verdict stands.** An unpublished Meta app does NOT block it: `whatsapp_messages` has held real inbound and outbound rows since 2026-09-15, on EzBizi's own numbers mapped by hand to A1-Group in `whatsapp_numbers`. It stays off the marketing pages because **no tenant can attach its own number** — there is no Embedded Signup in `app/`, `routes/` or `client/lib/` (0 hits). Invert when a tenant can connect a number itself. **The privacy page is a different question and now discloses it** (see 2026-09-24 below). The original reason, kept as history: **In the code on main and NOT reachable — Meta has not published the app.** Relayed by the deploy desk 2026-09-16 and recorded here immediately, because this is the exact shape that reaches the site by accident: the commits are on main, so anyone deriving "what shipped" from the log — which is what the daily check would do if the desk were unreachable — reads a finished feature. A merged commit is not a reachable feature and Meta publishing is not ours to predict. The checkable test is Meta's app status, not the presence of the code. |
| **Training** | **PUBLISHED 2026-09-09 as a capability card — this row now governs only what may NOT be added to it, and the tense rule is the whole of it.** The card reads *"Write the guide, set the test, give it to the people who need it, and see who still owes what."* — four imperatives and no completed action, deliberately. **The row count this row has always named was finally taken**, by the deploy desk on production release `20260908222755-a18b059a9080`, answering `0037` in `0038`: `training_attempts` **0**, `training_progress` **0**, `training_assignments` **0**. Nobody has ever sat one. That is precisely the Coverage & capacity / Inspections state — built and unused, sellable as a capability and never as usage — so **no sentence may say or imply that anybody has trained**: not "teams train on it", not "your team is up to date", not a number of guides completed. Reachability was re-verified in the deployed tree rather than taken from the release note: `ModuleServiceProvider.php:2288` registers `key: 'training'`, label Training, actions `view/monitor/manage/assign`, so it is in the sidebar and the roles editor; `client/lib/core/module_screens.dart:113` maps it to a screen. **Note how far the REASON travelled while the verdict stood still** — this row said in turn: the tables are missing (false), the tables are empty, the server shipped without a sidebar entry, and now nothing but the tense. Three of those four would have been fixed by "just add the card" on the wrong day. **The original entry follows, because the correction it records is the reason the row survived to be right:** **Corrected 2026-09-02 — the old reason was false.** Its five tables *are* on production (`training_assignments`, `training_attempts`, `training_guides`, `training_progress`, `training_tests`); the row used to say they were not. The verdict does not change, but the reason does: **all five are empty**, so nothing has ever been assigned, attempted or completed. The checkable test is a row count in those tables, not their existence — the old test would now pass and the claim would still be false. **Corrected again 2026-09-08: it now has screens.** `0.0.0151` shipped the server side with no sidebar entry, and the deploy desk said in `0035` "do not describe Training as available" — then `0.0.0159` shipped *"Training is now something you can actually use — read a guide, sit its test, and see who owes what"*, and production is `0.0.0168`. **So the reason has moved twice and the verdict has not.** It is no longer unbuilt and no longer invisible; it is built-and-unused, which the Inspections precedent says is sellable as a capability. **It is still held, deliberately, because the row count is the test this row names and this run could not take one** — a scheduled run cannot reach the desk to ask, and the five tables are not readable from here. **Do not resolve this by reasoning; resolve it by asking.** The next run that reaches the desk should ask for the row count in `training_attempts` and `training_progress`, and if nothing has ever been attempted, a capability card is allowed by the same precedent that allows Coverage & capacity — with the tense rule that comes with it: never "teams train on it". |
| **Phone / Communication** | **PUBLISHED 2026-09-04 as a card reading "Your business numbers in one place — dial, texts and voicemail…" — the row now governs only what may NOT be added to it.** **Jose approved it for the site on 2026-09-03** (relayed by the deploy session, which put the fraction to him explicitly — 2 of 7 at the time, 6 of 12 on 2026-09-10). **The approval covers the capability, not any particular sentence, and the fraction has moved without changing anything the card may say.** Draft copy is below, unpublished, awaiting his sight of the actual words. Everything following is the measurement that shapes them.<br><br>**The old blocker has partly lifted; the answer is still no to the strong claim, for a smaller reason.** The row used to say no production line had a ring group and every inbound call reached voicemail. Re-measured on production 2026-09-10 by the desk in `0042`: **6 of 12** `phone_numbers` carry a non-null `ring_group_id` (it was 2 of 7 on 2026-09-02). So calls to those six reach a person. **Six of twelve still dead-end at voicemail** — the fraction roughly doubled and the SHAPE HELD, which is why the rule under it did not move, which is why "phone system" remains an overstatement rather than a promise kept — and the module was renamed **Communication** in `0.0.0141`, so any future copy names that, not "Phone". The checkable test: `phone_numbers` with a non-null `ring_group_id`, as a fraction of all of them. Do not write the claim off a lifted gate; write it off the fraction.<br><br>**THAT TEST IS INCOMPLETE, and the Phone module said so unprompted on 2026-09-15 — it undercounts, which is the understatement direction this file keeps being caught by.** A null `ring_group_id` is not a dead line: it falls back to the connection's `default_ring_group_id`. Measured by the Phone module (session `ezbizi-32`, its own module, on production): **13 numbers; 6 of 13 carry the column; 11 of 13 RESOLVE to a group** once the connection default is applied. So the column moved 6/12 -> 6/13 by one number being added and none gaining a group of its own, while the number that describes what a caller experiences is **11 of 13**. Its words: *"6 of 13 lines route to a team" would be a false statement about the product.* **The test is now the RESOLVED fraction, and the column beside it** — quote both or say which one you are quoting. **The verdict does not move: two of thirteen still dead-end at voicemail**, so every forbidden phrasing stays forbidden — not "never miss a call", not "your calls reach your team", not "phone system". **And note the shape of the error rather than the number:** the named test read one column and the product reads a column *and its fallback*, so the file's own test was measuring a narrower thing than the claim it governs. A test derived from a schema and not from the code path that consumes it will do this again. |
| **Call monitoring — listen, whisper or barge on a live call** | **NEW 2026-09-16, and it is the only RingCentral feature Jose named that was deliberately NOT built.** Told to this check unasked by the Phone module (`0051`), which also gave the reason it cannot arrive by accident: Twilio can only coach a participant who is in a Conference, and every leg the dial plan renders is a `<Dial><Client>`. So it is not a setting somebody switches on later, and a release note will never announce it. Row 39 of `docs/EZBIZI-TODO.md`. The Communication card must never grow a sentence about supervising, coaching or listening to a call. **Everything else Phone shipped this fortnight — paging/intercom, call park, pickup, longest-idle routing, queue announcements, callbacks, the call-flow chart — the site is simply SILENT about, which is capability unsold rather than a claim to correct; the module explicitly is not asking for copy.** |
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
| **Monitoring — PUBLISHED 2026-09-15. What stays forbidden is that ANYBODY IS TOLD.** | **Jose lifted the hold, verbatim: "5 - publish, why not"** (relayed by the deploy desk, 2026-09-15). The hold was never about accuracy — 2 floor plans and 49 devices had already made built-and-unused false — it was about copy describing watching employees' screens, and that was his to decide. **Shipped: a Monitoring card, AND the `/privacy/` section that had to precede it.** `privacy/` disclosed monitoring NOWHERE before this — zero hits for monitor, screen, capture, remote control, open programs, browser tab or employee — so the card would have sold the most intrusive thing in the product while the notice was silent about it. The disclosure is **derived from the modules' own `PersonalDataEntry` declarations** (`MonitoredDevice`, `MonitoringScreenshot`, `MonitoringActivity`), which is the inventory the app already publishes to whoever asks what is held about them — not from my reading of the columns. **THE STANDING RULE, and it is the opposite of what a relay would have had me write:** no sentence may say or imply that a person is informed. `SettingRegistry` defines `monitoring.control_indicator` as `default: false`, help text "Off by default, and fully silent when off", plus "Watching a live view stays silent — only taking control shows this". The desk relayed that the notice now appears in-app rather than only in the tray — **true** (`version_history.dart:52`) — and framed it as "that sentence is now true in a way it was not when you drafted it", which invited a reassurance that is **false on a default install and false for live view even when switched on**. The improvement was to WHERE the notice appears, not to WHETHER it appears. The checkable test: `monitoring.control_indicator`'s `default:` in `SettingRegistry`. **Also corrected in the same pass:** privacy claimed "EzBizi collects no location data of any kind", a universal negative that went false as the surface grew — the agent reports "its wifi network and local address" and `ip_address` is a stored column. Narrowed that day to "no satellite or phone-mast position, and no location permission requested on any device" — **which Field tracking then made false as well, and it was rewritten again on 2026-09-25** (see the Field row in *Load-bearing sentences*). A narrowed universal is still a universal; this one lasted ten days. | 
| **Marketing** | **Its placeholder reason expired 2026-09-09 and it changes nothing on the site.** `0.0.0181` gave Marketing a real home-screen box and `GlanceRegistry::GLANCES` lists `MarketingGlance::class`. Everything Marketing contains is already sold under its own cards (Social media, Newsletter), so no Marketing claim ever rested on this row — **do not add one now on the strength of a glance. A dashboard box is not a capability.** |
| **Messenger or Instagram enquiries arriving as leads** | **NEW 2026-09-22, and it is the WhatsApp shape for the third time.** `a486cbbd` (shipped the same day, in production `0.0.0242` at `049e13a1`) gives `Lead` `SOURCE_FACEBOOK` and `SOURCE_INSTAGRAM`, a `MetaMessagesWebhookController`, a reply channel and a tab-grant migration. The code is complete and it is deployed. **It is still Meta's app**, and the WhatsApp row above records that Meta has not published it — so the same gate that stops WhatsApp stops an inbound Messenger or Instagram message arriving at all, and no commit will announce Meta changing its mind. The commit message argues about echo suppression and the 24-hour reply window and says nothing about app review, which is exactly how a finished feature reads when the gate is somebody else's. **Two checkable tests, and either one alone is not enough:** Meta's app status, and `SELECT COUNT(*) FROM leads WHERE source IN ('facebook','instagram') AND is_test = 0` — the count is the one that proves a real person reached a real business. Until then the Growth card names Local Services and Thumbtack and must not grow a third and fourth. |
| **The five rendered WordPress widgets** | **NEW 2026-09-18, and the test is the DIRECTORY, not our estate.** Plugin 1.12.0 adds `[ezbizi_social_links]` `[ezbizi_google_map]` `[ezbizi_post_title]` `[ezbizi_hours_table]` `[ezbizi_city_list]` (each an Elementor widget too, `preset="name"` their only argument), and they are genuinely rendering: 110/110 installs on our own hosting are at 1.12.0, pushed by an operator with a shell (`scripts/plugin-rollout.sh`). **That is not a customer being able to get it.** `curl -s "https://api.wordpress.org/plugins/info/1.0/ezbizi.json"` -> `"version": "1.10.0"` (2026-09-17), so a business that installs the EzBizi plugin the only way a stranger can install it gets a plugin with none of these shortcodes in it. An operator pushing code to sites we happen to host is not a release. **The row inverts when that one command returns 1.12.0 or later** — and note it is the same shape as the WhatsApp row above: finished code, on main, not reachable by the customer. |
<!-- superseded row retained below for its history -->
| **Monitoring, Marketing (superseded 2026-09-15)** | **Half of this row expired on 2026-09-09 and the reason has to be split.** **Marketing's placeholder is gone**: `0.0.0181` gave it a real home-screen box (published / waiting / failed, counted per account), and `GlanceRegistry::GLANCES` now lists `MarketingGlance::class` while `COMING_SOON` holds only `newsletter` and `monitoring`. So the stated reason is false for Marketing. **It changes nothing on the site, and that is the point of writing it down**: everything Marketing contains is already sold under its own cards (Social media, Newsletter), so there was never a Marketing claim resting on this row — do not add one now on the strength of a glance. A dashboard box is not a capability. **Monitoring is still off the site, for a reason that is no longer the glance.** Its `COMING_SOON` entry is deliberate, not pending, and meanwhile the module itself grew real screens: `0.0.0181` a floor plan, `0.0.0176` screen capture, live view and remote control, `0.0.0183` per-device capture-status reasons; `monitoring_floor_plans` and the capture-status column are both in the deployed tree. **The checkable test this row now names is a row count, because the Inspections precedent needs one**: `SELECT COUNT(*) FROM monitoring_floor_plans` and `SELECT COUNT(*) FROM monitored_devices`. Asked of the desk in `0041`. **And a zero would not settle it either way** — this is the one capability on the list whose copy is about watching employees' screens, so built-and-unused does not automatically make it sellable the way Coverage & capacity was. Whoever inverts this row writes the sentence and checks it against the privacy pages, not just against the count. **THE MONITORING REASON IS REPLACED, 2026-09-10, and the row still holds.** The row count it named was taken by the desk in `0042`: `monitoring_floor_plans` **2**, `monitored_devices` **49**. So built-and-unused is FALSE — somebody drew plans and forty-nine machines are checking in, and the Inspections precedent would ordinarily make that sellable. **It is held for a reason no count can settle:** the copy would be about watching employees' screens, and `0.0.0189` widened exactly that — the desktop app now reports open programs, which programs talk to the internet, and the titles of open browser tabs. **That needs a decision from Jose, not a measurement**, and it is the one item on this list where "do as you recommend" does not obviously reach, because the risk is not accuracy. **Do not invert this on the strength of 2 and 49.** Whoever writes the sentence checks it against `privacy/` as well as against the count. The old reason — "its dashboard summary is a placeholder" — is now the WRONG REASON rather than a wrong conclusion: `GlanceRegistry::COMING_SOON` still holds `monitoring` deliberately. |

## Load-bearing sentences — re-check these, they go false silently

**The `forbidden-terms` grep below catches a claim we REMOVED coming back. This
table is the opposite failure and there is no grep for it: a claim that is on the
site, was true when written, and goes false because a feature landed somewhere
else.** It has happened twice in a week — "no location data of any kind" (overtaken
by the monitoring agent) and "mail your business writes does not go out over ours"
(false two days after publishing, when three automation actions started sending).
Both were caught by somebody happening to look.

**Each row names the test, not the answer.** A row stating the current fact decays
the day it changes; a row naming where to look does not. Walk this table when the
deploy desk reports anything shipping in these areas, and after any release that
touches apps, monitoring, social, Google or mail.

| The sentence | Page | What makes it true, and where to look |
|---|---|---|
| "Your phone asks you before the app gets any of these" — notifications, microphone (iPhone), location only if Field tracking is on; "We ask for nothing else: no camera, no photo library, no contacts, no calendar" | `/privacy/` | `AndroidManifest.xml`, `Info.plist`, `pubspec.yaml`. **Location is now EXPECTED in all three** once Field ships (`ACCESS_FINE_LOCATION`, `FOREGROUND_SERVICE_LOCATION`, `NSLocationAlways*`, `geolocator`) — so the break is now any permission that is NOT in the sentence: camera, photos, contacts, calendar. It went false twice before this (notifications, microphone). **And the manifest is not the test — it went false a THIRD time with the manifest clean.** Android has asked for the microphone since `52941393` (09-02) and the Phone group (`READ_PHONE_STATE`, `READ_PHONE_NUMBERS`, `CALL_PHONE`) since `19403de6` (09-14), all at RUNTIME from `voice_device_mobile.dart` through `twilio_voice`, whose own manifest merges the declarations at build — so `AndroidManifest.xml` in this repo never shows them. The page said microphone "On iPhone" only until 2026-09-25. Test: `grep -rn 'request[A-Za-z]*Permission\|requestMicAccess\|Permission.*request' client/lib` as well as the three files. |
| Field tracking: "Employees are tracked only if their employer turns it on for their role. An organization's administrators are included automatically" · window default "while you are clocked in" · "three months" · "your own location trail is not sent to Google" | `/privacy/` | **REVERSED 2026-09-28 on Jose's ruling: "admins should be tracked as well, i never said they shouldn't" / "Always, like any tracked role."** The admin exclusion (`! role?->isAdministrator()` in `FieldTracking::isTracked`) is being removed; the page was changed FIRST, over-disclosing until it ships — the safe direction. **Test the consequence, not just the clause:** `isTracked` is then `is_active && hasPermission('field.tracked')`, admins hold every key, and `ModuleAvailability::isAvailable` returns true when no flag exists — so **every active administrator in every organization is tracked, with nobody switching anything on**. That is why the page says "included automatically" rather than "like any tracked role": the latter implies somebody chose it. Guard: `FieldTest::test_the_administrator_is_tracked_like_any_tracked_role` (renamed from `…never_tracked_by_implication`). **If an org-level switch is ever added in front of tracking, "included automatically" goes false** — it becomes "once your employer turns on Field". Retention: `RetentionPolicy::window(3, …)` on all four field tables — **months, not days**. Google: **`FieldMaps` sends job addresses/locations AND the technician's current position — "only" was false, corrected 2026-09-30.** The position goes twice: as today's route starting point (`optimise($origin…)`, live) and for dispatcher ETAs (`matrix(array $origins, …)`, Field worktree, next release). **The trail is never sent**, and that is what the page leans on. Test: every call site passing a PERSON's position into `FieldMaps` — a third makes the page's two-case sentence incomplete. Device description on `field_presences` (`battery_pct`, `charging`, `platform`, `os_version`, `device_model`, `app_version`, migration `2026_09_30_100000`): latest only, no history — a history table would falsify "only the latest description is kept". Separately, since 2026-09-29 (Jose: "yes update the privacy page"), Leads sends the street address an enquirer wrote, to geocode it (`AddressCheck`); the Who-we-rely-on bullet says so and says nothing else from the conversation is sent - keep it true: only `LeadDetails::address` + ZIP may reach Google. SOS sits behind `permission:field.tracked`; with the reversal, admins sending an SOS is consistent with everything else rather than an exception. |
| "whether anybody is told … the answer is no unless they change it" | `/privacy/` | `SettingRegistry` → `monitoring.control_indicator` → `default:`. If that ever becomes `true`, this sentence is wrong in the reassuring direction. |
| "We do not aggregate or anonymise Google user data … not combine it across customers" | `/privacy/` | No cross-organization read in `app/Services/Leads/`, `app/Support/Leads/`, `app/Services/Ads/`. **Filed with Google against `ezbizi-506117`, so a reviewer can re-read it.** Any benchmark, "average in your industry" or analytics rollup is a COMPLIANCE change and the policy moves first. |
| "cost, clicks, impressions, interactions and conversions, and the value of those conversions" ("such as") | `/privacy/` | `app/Services/Ads/CampaignPerformance.php` — `REQUIRED_METRICS` + `OPTIONAL_METRICS`. **Went short on 2026-09-24** (`5b27593b` added six optional metrics) and was caught 09-28; now "such as", so a further widening cannot make it an incomplete list. A new *kind* of data (not a metric) still needs a clause. |
| "— if your organization turns it on — a copy of a notification" | `/privacy/` | `SettingRegistry` → `notifications.email_enabled` → `default:`. **Flipped `true` → `false` on 2026-09-28** (Jose's ruling, deployed by `0.0.0304`), which made "unless your organization turns it off" false from that deploy until this fix. If the default goes back to `true`, the word goes back to "unless". |
| "typically fewer than five a month" · STOP · HELP | `/privacy/`, `/terms/` | `client/lib/widgets/sms_codes_card.dart`. **These three strings must stay word-for-word identical in all three places** — a reviewer compares them, and "almost agree" is what turns a skim into a close read. **RUN 2026-09-22, AND THE ROW FAILED ITS OWN TEST ON ITS FIRST RUN.** STOP and HELP do match, word for word, in all three. The frequency string does not: the site says *"typically fewer than five a month"* and `sms_codes_card.dart:328` says *"typically fewer than 5 messages per month"*. Both are true and they are not the same words, which is the state this row was written to forbid. **It was NOT corrected on the site, deliberately**, and the reason is the only interesting part: the toll-free campaign is in carrier review, the consent language cited in a campaign filing is part of that filing, and nobody here knows which of the two wordings was filed. Editing a published consent sentence mid-review to match an app is a change to evidence somebody is currently reading. **The next person to answer this owns the campaign, not this page** — asked as `0059`. Align to whichever was filed; do not align to whichever is prettier. **ANSWERED 2026-10-08 by Phone (`0101`): neither wording was filed** (the toll-free verification has no frequency sentence and is TWILIO_REJECTED anyway). **Aligned 2026-10-10:** `/privacy/` and `/terms/` now read *"typically fewer than 5 messages per month"*, the app's words. All three now match. |
| "Either stops the texts; neither needs a password" | `/terms/` | `SmsCodesController::disable()` takes only `$request->user()`. Load-bearing: add a confirmation step and this page is false. |
| "Facebook Pages, Instagram and Threads send today" · TikTok "rebuilt to TikTok's Direct Post rules … not yet proven" (was "drafts, not the profile" until 2026-10-12: `TIKTOK_DIRECT_POST` is `true` on production since `4f8ee29b`, and its only row is a refusal) · Pinterest "posting waits on Pinterest" · LinkedIn/YouTube/X/Bluesky "newly built and not yet proven" | `/`, `/social-media/` | **`Publishers::for()` is NECESSARY, NOT SUFFICIENT — it passed for Pinterest while Pinterest refused every Pin.** Since 2026-10-09 all nine return a publisher. Also run, on the box: `social_post_targets` JOIN `social_connections` grouped by `provider, status`, and read `failure` on the newest rows. **Pull** a network on a network refusal in `failure`; **add** one only on a `sent` row from a real tenant connection that did not go through a sandbox. Pinterest inverts when `services.pinterest.sandbox_token` is unset AND a `sent` row follows. Was two, corrected upward to five on 2026-09-02, down to four on 2026-10-09. |
| "It is not on the App Store yet" | `/download/` | `curl -s "https://itunes.apple.com/lookup?bundleId=com.ezbizi.app"` → `resultCount`. The card owes a link the moment it answers. |
| "A Google Business Profile can be connected to read and answer reviews" — **REMOVED 2026-09-22** | `/` ×3 | `app/Services/Reviews/BusinessProfileReviews.php` — its own docblock: *"The Business Profile APIs ship at 0 QPM and stay there until Google approves an access application. Every call here will fail until that lands."* The replacement sentence is true while that stays so. **What would let it back:** Google granting Business Profile quota, which `08c2b82e` puts no earlier than **2026-10-24** — the application was REFUSED on the 60-day verified-listing rule and the profile verified 2026-08-25. Re-read the docblock, not this row. |
| "There are two writes to Google" (reply to a text enquiry; pause or resume a campaign, by a person or at on/off times a person set — "No rule or schedule does it" REMOVED 2026-11-05 for `ad_schedules`) · "does not create or remove campaigns, or change anything … other than pausing or resuming it" · "calls and bookings are never replied to through Google" | `/google-ads-api/` | Every Google Ads/LSA `->post(` in `app/`: `grep -rn "googleAds\|localServices:\|:mutate" app --include=*.php`. Today: `LocalServicesLeads` `appendLeadConversation` and `CampaignPerformance::setStatus` `campaigns:mutate` (`updateMask: status`). **Went false on 2026-09-24** (`5b27593b` added pause/enable) and was caught 09-30: the page said "one write" and "does not … pause campaigns" for six days, on the page Google's API reviewer reads. A third endpoint, a mask wider than `status`, or an automation action/command calling `setStatus` falsifies it. |
| "an automated first reply: a message the customer wrote themselves" · "EzBizi supplies no wording of its own" | `/google-ads-api/` | **NEW 2026-11-03, true today by usage only.** `lead.book_by_chat` (`3c56384c6`, live in 0.0.0525) is an automation step that answers a Local Services text with EzBizi's own template lines (`BookingBot::next`: address, service, an open window, "we are holding") through `replyToLead(..., automatic: true)`, on every customer message, after Grok reads the thread. **Test:** active automations whose active version's `actions` contain `book_by_chat` (production 2026-11-03: **0**, 0 runs). The first such rule makes both sentences false on a page filed with Google — the page is the Google API module's and Jose's call, not this check's; flag it, do not rewrite it. `/privacy/` "A draft is never sent on its own" survives (the bot sends templates, not drafts) but its Leads purpose list does not name reading the thread for an address, service and a yes. |
| "watch a machine live and take control of it, and … browse the files on it and download them — reading only … each folder opened and file downloaded recorded" · "watching a screen or browsing its files shows no notice" | `/privacy/` | `app/Http/Controllers/Api/Monitoring/FilesController.php` (ops `list`/`download` only; audit `monitoring.files.listed` / `.downloaded` per path; grant `monitoring.files.view`) and `client/lib/core/file_access_service.dart` (no notice drawn). Added 2026-10-02 after `0.0.0348` shipped it. An upload/rename/delete op, or audit moving to once-per-session, falsifies it; a notice on the machine falsifies the second clause in the reassuring direction. |
| "An organization can also have EzBizi send mail on its behalf" | `/privacy/` | The automation actions that send. Corrected in `0.0.0240` after two days wrong — the previous sentence promised the opposite. |
| "WhatsApp … kept for 24 months" · "Facebook or Instagram message is kept with the enquiry … deleted when that enquiry is" | `/privacy/` | `WhatsAppMessage::retention()` (`window(months: 24)`) and `LeadConversation::retention()` (`withParent`). Two different rules; never merge them into one period. |
| "Who we rely on … **these are all of them**" (AWS, SES, Twilio, Apple and Google, Mailchimp, the accounts you connect) | `/privacy/` | `config/services.php`'s top-level keys, against that list. **An exhaustive list is the only kind of sentence that a new integration falsifies by existing**, and nothing announces it — the integration ships in its own module and never reads this page. Mailchimp and the two push services were missing when this row was written (2026-09-23) and had been for weeks. `bedrock` is configured and **off** (`LeadInsights::enabled()` false, no keys) — the day it gains keys, a model is reading lead text and this list needs a line. `google_speech` (2026-09-28, `CallTranscripts`) is the same: configured and **off** until `GOOGLE_SPEECH_KEY` is set - the day it is, Local Services call audio goes to Google Cloud Speech-to-Text and this list needs a line **before** the key goes in. **2026-09-29: transcription runs on our own box instead** (Whisper in /opt/whisper, `ezbizi-transcribe.timer`; Jose: "i don't want to rely on any physical computers"), so call audio reaches no new processor and this list needs no line - unless somebody sets `GOOGLE_SPEECH_KEY` AND removes /opt/whisper, which is the only way the Google path runs. `google_mail` / `microsoft_mail` are a customer's own mailbox, covered by "the accounts you connect". **2026-10-01: `config/services.php` is NOT the whole test — it missed Photon (komoot), keyless and hardcoded, which had received typed addresses since 2026-08-24.** Also run `grep -rhoE "https://[a-z0-9.-]+\.[a-z]{2,}" app --include=*.php | sort -u` and account for every host that is not Google/Meta/Twilio/AWS/Apple/Mailchimp or an account the customer connects (Workiz, Airtable, the social networks). |

| "transcribed on EzBizi's own server … the recording is not sent to any other company to be transcribed" · "transcripts behind call reports are kept for twelve months" · Phone numbers "transcribed … by Twilio … off unless they do" | `/privacy/` | **The own-server claim is true because of the BOX, not the code.** `CallTranscripts::enabled()` is `local() || services.google_speech.key`; `TranscribeLeadCalls` uses Whisper only when `local()` (= `/opt/whisper/venv/bin/python` executable) and otherwise sends audio to **Google Speech-to-Text** (`speech.googleapis.com`). Measured 2026-10-05 on production: `local: true`, Google key unset. **So either setting `GOOGLE_SPEECH_KEY`, or the Whisper venv disappearing from the box, silently makes this sentence false for Leads** — it fails over to a third party with no error. `TranscribeReportCalls::fromWhisper` refuses instead (`['failed', null]`), so reports cannot fail over. Test: `php artisan tinker` → `app(CallTranscripts::class)->local()` true and `config('services.google_speech.key')` empty. Retention: `CallReportTranscript` `RetentionPolicy::window(12, …)`. Phone: `phone_numbers.transcribe` / voicemail `transcribe` `default(false)`, Twilio via `TwimlRenderer::startTranscription`. |

| QR scans: "The scan record holds no internet address, no device details and no location" · "a random key that is changed every day and thrown away after two" · "deleted after thirteen months" | `/privacy/` | `create_qr_code_scans_table` — four columns only (`organization_id`, `qr_code_id`, `scanned_at`, `visitor`); **a fifth column breaks the first sentence**. `QrCodeScan::visitorFor` — `sha256(salt|ip|ua)`, salt `Cache::remember('qr-scan-salt:'.$day, now()->addDays(2), random_bytes(32))`; a persisted or fixed salt makes "nobody can work out which phone" false. `RetentionPolicy::window(13, 'scanned_at')`. **Scoped to the SCAN RECORD on purpose**: nginx access logs on app.ezbizi.com record every request's IP including `/q/…`, so "we do not store IP addresses" would be false — never widen it. |
| Cloudflare: "Its script loads with the page, so Cloudflare sees the browser and IP address of anyone who opens a page with one of these forms, not only of those who send it" · "nothing typed into the form is sent to it" | `/privacy/` Who we rely on | `wordpress-plugin/ezbizi/enquiries.php` + `widgets.php` + `elementor-forms.php` print `challenges.cloudflare.com/turnstile/v0/api.js` with the box at render time, not on submit — so the peer's "a visitor submitting" wording understated it; never narrow back. `app/Support/Web/Turnstile.php::passes` and `ezbizi_turnstile_passes` send siteverify only `secret` + `response` (no `remoteip`, no form fields); **adding a form field or `remoteip` to that POST breaks the second sentence**. Only domains in `config('ezbizi.turnstile')` get the box. |
| YouTube: "Read-only access ... is used once, when you connect, to read the channel's id, title and handle" · "Upload access ... is used only to publish a video you choose ... nothing is uploaded otherwise" · "We do not read your videos, comments, subscribers or analytics" · disconnect "deletes those stored tokens and channel details at once" · audit note "for three months" | `/privacy/` Connected social accounts → YouTube (YouTube API Services Developer Policy, asked by Social 2026-10-09) | `YouTubeOAuth::SCOPES` (upload + readonly) and `identify()` — the ONLY YouTube API call (`channels?part=snippet&mine=true`). **A YouTube publisher landed later the same day** (`8b232f86`, in production `0.0.0404`); the upload sentence was already worded as a limit ("only to publish a video you choose"), so it stays true; **any new YouTube read (videos, comments, analytics) breaks the "we do not read" sentence**. `SocialConnectionsController::letGo` → `$row->delete()` (no revoke at Google — hence "doing both"). `credentials` cast `encrypted:array`. `social.disconnected` falls in ExpireAuditEvents business = 3 months. |
| Leads → xAI: "conversation … including Google Local Services messages and calls … and the contact's name … sent to xAI (Grok)" · "A draft is never sent on its own" · "Data retention is switched off on our xAI account" · Google section "We share it with one service provider … xAI … Nobody else receives it" · /google-ads-api/ "shared with one service provider" | `/privacy/` Calls and messages + Google user data + Who we rely on; `/google-ads-api/` | `LeadInsights::thread()` (every `LeadConversation`, text or transcript, + `contact_name`) → `GrokCli::ask` in `read()`/`suggestReply()`; switch `config('ezbizi.leads_ai_grok')` (LEADS_AI_GROK). Never sends: `suggestReply` returns text only. **Jose 2026-10-09, relayed verbatim by Leads (local_29c0a73a): "yes change it"** — option (b), Google-sourced leads included, told it changes what Ads API access was approved against. Retention: Jose, "Data retention is turned off" — an account setting nobody here can read, so it is written as ours, not as xAI's promise. **Any new recipient of Google user data breaks "Nobody else receives it"** — Bedrock (`bedrock()` path in the same class) included: if AWS Bedrock keys are ever set, Anthropic-on-AWS reads leads too and this row must change. |
| Call scoring → xAI: "divide each call into the phone menu, the staff member and the customer" · "to assess how staff handled the call, for coaching" · in Google section and /google-ads-api/ too | `/privacy/`, `/google-ads-api/` | Speaker split: `ezbizi:label-call-transcripts` → `TranscriptSpeakers::label` over `LeadConversation.transcript` (Local Services calls included). Scoring: Reports › Performance › People, requested by Grok AI (local_47f99139) 2026-10-12, Jose approved and "chose to tell staff" — NOT promised on the page for every organization. Written before the scoring code landed; **if scoring ever reads anything beyond transcripts (recordings, staff names to xAI), re-check**. |
| Coaching summaries → xAI: "scores … checklist items they missed and the short notes … not the transcripts, and not the staff member's name" · "kept for twelve months" | `/privacy/` Calls and messages | `AiPersonReviews::prompt()` (07dcdfbf): score, missed `checks`, `strength`, `improve` per call; name used only as the grouping key, never in the prompt. `CallReportPersonReview::retention()` = window(12). The notes are Grok's own earlier output from transcripts, so not claimed customer-free beyond the prompt's "never name customers". **Putting `answered_by` or a transcript into the prompt breaks it.** |
| Review drafts → xAI: "presses Draft with AI on a Google review, the review's text and the reviewer's name are sent to xAI (Grok)" · "Nothing is posted until a person …" | `/privacy/` Calls and messages + Who we rely on | Now ALSO EzBizi itself: `ReviewsController::suggestReply` (session/grok 6166d501) sends business name, `reviewer_name`, `rating`, `comment` — derived, and the page names all four. The A1 Leads path is still peer-sourced: in this repo `A1LeadsReviewOpsController::draft` → `A1LeadsReviewOpsClient::draft` POSTs to A1 Leads `/api/v1/ezbizi/reviews/{id}/draft`; the Grok call is on A1 Leads' side, outside this tree. Posting stays in `A1LeadsGbpReplies`. Public reviews read by A1 Leads, not our Google OAuth — so the Google user data section is untouched. **Superseded 2026-10-29: EzBizi does draft and can publish by itself.** `ReviewReplyWriter::write` (GrokCli, same four fields) is called by Draft with AI, `ReviewsController::draftBatch` (up to 20, 0.0.0506) and `ActionRunner::reviewReplyWords` when a `review.draft_reply` / `review.publish_reply` step has `ai: true` (`5e886cf2b`, 10-20). `publishReviewReply` posts with no person where `BusinessLocation.auto_reply` is on (default off), review < `AUTO_REPLY_MAX_AGE_DAYS` (30), no other reply/draft — the page now says exactly that. **Posting goes through `BusinessProfileReviews::publish` (v4, 0 QPM until Google approves case 3-3702000041562)** — so today it fails, and the day quota lands it works with no edit. **That same day, review text read through OUR Business Profile OAuth reaches xAI**, and the `/privacy/` Google user data section (scoped to Google Ads, "Nobody else receives it") names no Business Profile purpose — re-check it before or with the quota. A wider `auto_reply` default, a longer age window, or posting over a person's draft breaks the paragraph. |
| Weekly summary → xAI: "Each Monday … jobs, sales and deposits for each branch, the same totals for each technician by name, the profit and loss, and how many calls there were for each reason" · "automation rules decide who receives it" | `/privacy/` Calls and messages + Who we rely on | `AiWeeklySummary::figures()` (session/grok 6166d501) = `PeriodReport::html()` per active branch, tags stripped (ad sources, field performance with deposits, technicians by name, Airtable P&L) + `call_report_labels` counts; raises `reports.ai_summary_ready` for Automations (email / WhatsApp group) — NOT "emailed to the owner" as relayed. **No LSA in PeriodReport today** (its docblock: "LSA by location … not here yet"); the day LSA lands there, Google user data reaches xAI by a second purpose and the Google section must name it. |
| FB campaign categories → xAI: "names of the campaigns in an organization's connected Facebook ad accounts, and the names of its own service categories" · "Nothing about a customer is sent" | `/privacy/` Calls and messages + Who we rely on | `SuggestMetaCategories` (session/grok 68cefaee): prompt = category list + `MetaAdCampaign.name` only. **HELD, not written: ad spend / cost-per-job per source (Google Ads, LSA, Facebook) to Grok** — Google Ads figures are Google user data; the Google section allows xAI only "for that same purpose" (enquiries + coaching). **Jose 2026-10-12, relayed verbatim by Grok AI: "Facebook only for now (Recommended)" — no Google Ads/LSA figures to xAI; Google section and /google-ads-api/ unchanged.** Any Google spend to xAI later still needs a new verbatim yes for a new purpose, and /privacy/ Google section + /google-ads-api/ "No aggregation" paragraph changed before it ships. |
| AI Assistance → xAI: question + "the Library answers that person is allowed to read" + "up to five matching branches (name, phone, address and service area)" · "their phone number is not" sent | `/privacy/` Calls and messages + Who we rely on | `AssistantBrain::aiAnswer` (session/grok f11d1190): prompt = org name + readable `AssistantEntry` rows + `locations()->take(5)` + `$query`; `WhatsAppStaffBot` calls it with the message text only. **Adding the sender's number, name or history to that prompt breaks "their phone number is not"**. |
| WhatsApp figures → xAI: "jobs by source, sales, each technician's sales and deposits by name, and how many enquiries arrived from each source other than Google Local Services" · "No customer's name or contact details" | `/privacy/` Calls and messages + Who we rely on | `ReportAsk::answer` (d883e59b): question + branch names, then `PeriodReport::figures()` (created/closed by source, techs) + `ReportAsk::leads()` (counts by `source`). `leads()` excludes `Lead::SOURCE_LOCAL_SERVICES` since 76c2bb74 (on main, with a test) — LSA counts are Google user data and Jose ruled "Facebook only for now" (2026-10-12). **Removing that where-clause breaks the page.** Workiz `job_source` values named "Google LSA" are A1's own CRM records, not Google API data, so "jobs by source" may carry that label truthfully. |
| Device permissions: "only when you tap Download on a how-to video, permission to add that video to your photos — on an iPhone, and on Android 9 or older" · "only when you tap to take a photo for a job, the camera — your iPhone asks first; on Android the phone's own camera app takes the picture" · "never reads your photos" · "no contacts, no calendar" | `/privacy/` Where EzBizi runs | `client/ios/Runner/Info.plist`: `NSPhotoLibraryAddUsageDescription`, and since 199538a9 (0.0.0463) `NSCameraUsageDescription` + `NSPhotoLibraryUsageDescription` — the second is declared because image_picker requires it, never requested: `files_io.dart` `takePhoto` calls `ImagePicker().pickImage(source: ImageSource.camera)` only. **If any `ImageSource.gallery` / `pickMultiImage` appears, "never reads your photos" is false.** `AndroidManifest.xml`: `WRITE_EXTERNAL_STORAGE maxSdkVersion=29`, no CAMERA (the camera intent needs none), no READ_MEDIA_*/READ_CONTACTS/READ_CALENDAR. **Adding CAMERA to the Android manifest makes "on Android the camera app takes it" incomplete — Android would then prompt. Any new Usage key or uses-permission breaks this paragraph.** "no camera" removed 2026-10-20. |
| AppAsk (WhatsApp staff line AND, since 5eb754c8 / 0.0.0432, the in-app AI tab via `AssistantController` when the Library has no answer) → xAI: "any record the person may see, including customers, jobs, calls, staff records, details of monitored computers, the contents of connected mailboxes, and Google Ads, Local Services and Business Profile data" · "Only text … never audio, images or files" · "read only" | `/privacy/` Calls and messages + Google user data + Who we rely on; `/google-ads-api/` | **Jose 2026-10-13: "Everything, as asked"** (relayed desk → Grok AI; supersedes "Facebook only for now" FOR THIS PATH ONLY — ReportAsk still excludes LSA). At 1a0802dd (950f1f32) BLOCKED is only auth/staff/mcp/me/api-keys/me/sessions; JSON-only gate (`Content-Type` contains json) stays; GETs run as the asker with a 2-minute token. History: 050d6753 refused, 57c80ace/35cf8644 blocked Google/mail/monitoring, 950f1f32 dropped those blocks on Jose's word. **Removing the JSON-only gate breaks "never audio, images or files"; a non-GET breaks "read only".** Gmail content to xAI also sits under Google's Workspace API user-data policy — that is a review risk, not a page falsehood. |
| WhatsApp actions: "a small, fixed set of things — such as answering a review, moving a job, changing a lead's status or adding a scheduling note" · "repeats exactly what it is about to do and waits for a YES" · "does not ask for the app's second sign-in step" | `/privacy/` Keeping it safe (6d82a948, another session, 2026-10-16) | `AppAct::COMMANDS` (allowlist: review reply + publish, board reschedule, `PATCH leads/{lead}` `['status']` only, scheduling location note) and `AppAct::NEVER`; gate tick `ai_assistance.command`. Ships in 0.0.0446 — the page was written ahead of the release. **A new key in `COMMANDS` that is not one of those four kinds, or the allowlist becoming a denylist, breaks "small, fixed set"; a 2FA step added makes the bold sentence over-disclose (safe).** |
| Call recordings by number: message → xAI "only to read the number and the dates" · recordings "pass through Meta" · "not sent to xAI" | `/privacy/` Calls and messages + Meta line | `CallRecordingsRequest` (6727b341) sends the staff text to Grok for phone/from/to; `SendCallRecordings` reads `RingCentralCallLog` only (no LeadConversation, so no LSA recordings) and uploads audio to the WhatsApp Cloud `/media` endpoint. **If it ever reads Lead recordings, Google LSA audio reaches Meta — re-check.** |
| Automations card: triggers "a lead arrives, a call is missed, a job changes status, stock runs low" · steps "send a text, open a follow-up job, assign it, email a saved report or tell the administrators" · "conditions" · "on a schedule" | `/` grid (Jose 2026-10-09: "review … and improve it") | `ModuleServiceProvider` keys `lead.received`, `phone.call.missed`, `job.status_changed`, `item.low`; actions `phone.sms.send` (to "a number you give" — so NOT "text the customer"), `job.create` (follow-up for the same customer), `job.assign_to_user`, `report.send`, `organization.notify_administrators`; `ConditionEvaluator`; trigger `automation.schedule_reached`. The old "no fix in the window" hold is superseded by Jose's ask; **removing any of those keys breaks the card**. |
| AI & the API card: "Make an API key and an AI like Claude, or your own script, works in EzBizi as you — through an MCP server that reaches the same API the app uses, with your permissions and nothing more" · "read only, read and edit, or also delete" · "Every change made with a key is logged with each field as it was before" · "any key can be revoked" · FAQ "Administrators decide which roles may make keys" · Growth "An AI can read the conversation and draft a reply, which a person reads and sends" | `/` (Jose 2026-10-13: "update the site … AI Connections - API - MCP") | `McpController` (`list_endpoints` + `call_api` through the kernel with the caller's token; `BLOCKED` = auth, staff, mcp, me/api-keys, me/sessions — "same API" stays true while that list holds only credentials/platform); `routes/api.php` `POST mcp`; levels `viewer/editor/full` in `api_keys_card.dart`; `ApiKeysController::activity` (`before` from the audit trail) and `destroy`; the API keys permission row is administrators-only until ticked. **Measured on production 2026-10-13**: this session's own MCP key answered `GET me` 200. Growth AI line: `LeadsController` `can_suggest` = `canReply && LeadInsights::enabled()` — read `true` on a production lead the same day; `suggestReply` returns text only. **Breaks if**: `LEADS_AI_GROK` goes off (pull the Growth sentence), anything sends a draft without a person, the MCP endpoint gains OAuth-only access, or key activity stops recording `before`. Not claimed: OAuth / claude.ai or ChatGPT connectors (docblock: none), call scoring (off), Monday summary (inactive), review drafts (A1-Group-only path). |
| DO NOT: "Keeps working offline — everything syncs once there's signal again" | `/` Office, truck or phone | Removed 2026-10-09. `client/pubspec.yaml` has no local database (no sqflite/drift/hive/isar) and `api_client.dart` has no outbox; only `field_tracker.dart` buffers GPS points offline. Replaced with "Installs without IT", which the FAQ already carried. Re-add only with an offline write queue in the client. |

| Call labelling: "Where call labelling is switched on, the text of a transcribed call … never the recording … sent to Anthropic (Claude) and to xAI (Grok)" · "one word" · "EzBizi keeps only that word, for twelve months" · sub-processor "Anthropic and xAI" | `/privacy/` | `app/Support/Reports/Tagging/` — every `CallTagger` implementation, and what its `tag()` sends (today: `CallLabels::instruction` + the transcript text, nothing else). **A third tagger falsifies "these are all of them" by existing.** `CallReportLabel::retention()` (`window(12)`); `create_call_report_labels` stores `label` only — **a column holding the model's reasoning or the transcript breaks "only that word"**. Switch: `config/experimental_reports.php` `tagging.enabled` (`REPORT_CALL_TAGGING`, default `false`). Written 2026-10-06 BEFORE the switch, on Reports' `0094`; the sentence is conditional so it is true either way. |
| "When xAI is not available … sent instead to Anthropic (Claude), for the same purpose" · "Anthropic receives the same information, or less: it is never sent a manual to read" · Report files on WhatsApp: message + branch names to xAI "only to read which report, branch and dates"; file "passes through Meta"; "The report itself is not sent to xAI" | `/privacy/` Calls and messages + Who we rely on | `GrokCli::provider()` (`eb2e455d2`, live 0.0.0498): `AI_PROVIDER` auto = Grok CLI if configured, else `ClaudeProvider`. Every xAI sentence on the page rides on this one switch. `ClaudeProvider::askMany` sends images only (no PDF) — if it starts sending files, drop "it is never sent a manual". `ReportFileRequest::read()` (`f903e323d`) and `SendRequestedReport`. |

**Adding a row costs nothing and omitting one costs two days.** If you write a
sentence on the site that would be false if somebody changed code you do not own,
it belongs here with the file that decides it.

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

**`www/google-ads-api/` — Local Services.** **2026-09-29: the sentence "nothing composes or sends a reply automatically" was replaced** (Jose: "yes update the page") - he unblocked automated Google replies that day ("flip the block now, there's no need to tell google about it"). The page now names the automated first reply the customer writes; a person's reply is audited (`lead.replied`), an automated one is `is_automatic` on the conversation row and in `automation_runs`. That page describes what the Leads
module *does with the API*, for Google's API reviewer, and the code does it:
`app/Services/Leads/LocalServicesLeads.php` POSTs
`customers/{id}/googleAds:search` with `FROM local_services_lead`, reached from
`LeadsController` sync. "The module retrieves Local Services lead data" is a claim
about code, and it is true.

The forbidden claim is a different sentence: that enquiries **have arrived** as
leads for a customer. Code that calls an endpoint is not a lead that landed. Keep
the compliance page as it is, and keep the story off the landing page until
somebody confirms a row.

## SES production access changed nothing on this site (checked 2026-09-14)

The deploy desk broadcast that SES left the sandbox and flagged the marketing
site as "the one that matters most", because a contact form that is accepted and
silently never delivered is the worst shape of that bug. **Checked, and the site
was never in that population. No change was made and none is needed.**

- **There is no form anywhere in `www/`.** The only submission-shaped thing is
  `download/index.html`'s `fetch('/download/windows.json')`, which reads a version
  and posts nothing. Contact is `mailto:` only — the visitor's own mail client.
- **The visitor enquiry forms described under "Enquiries from your websites" are
  not ours and do not email.** They live on a customer's own site and post to
  `POST v1/web/chat`; `WebsiteEnquiries` creates a `Lead` and emits `lead.received`
  — the same trigger Google and Thumbtack raise. **Zero mail send paths across
  `app/Support/Web/` and `app/Http/Controllers/Api/Web/`** (`Mail::`,
  `Notification::`, `->notify(`, `toMail`), against a pattern that matches 17
  files elsewhere in `app/` — so the absence is measured, not assumed. The page
  says the enquiry "arrives as an enquiry the organization can read and reply to",
  which claims no email and stays true.
- **The Newsletter card names Mailchimp, not us** — "your list, your sending
  reputation". It never depended on SES.
- The only SES mention on the site is the subprocessor line, true before and after.

**That line was owed, and it was paid on 2026-09-14 — the test this paragraph named
passed.** Email verification is no longer in flight: `RequireVerifiedEmail` is aliased
`verified` at `bootstrap/app.php:27` **and applied to the main API group** at
`routes/api.php:582` in the deployed release `f3c5f45d` (`0.0.0211`), with
`verify` / `verify/resend` at `:227-228`. **Both halves were checked, not just the
alias** — an alias registered and never put on a group is a gate without its
affordance, and it reads identically to a shipped one in a grep.

`/privacy/` gained an **Email we send you** section the same day, after *Text messages
we send you*, and the `Last updated` stamp moved to 14 September 2026. Every clause read
out of the deployed tree at `f3c5f45d`:

    confirmation link to the    EmailVerificationLink + App\Services\Auth\
    address you sign in with    EmailVerifications; the `verified` middleware above
    a password reset            PasswordResetLink
    the invitation that         InvitationLink
    brings you into an org
    a copy of a notification    NotificationNotice ("One in-app notification, sent
    unless your org turns       again as an email"), gated on
    it off                      `notifications.email_enabled`, whose SettingRegistry
                                default is `true` — hence "unless", not "where"
    and they are all of them    app/Notifications + app/Mail hold SIX classes at
                                f3c5f45d. The other two — InspectionReportMail,
                                SavedReportMail — cannot leave over EzBizi's
                                transport: MailDispatcher::smtpConfigFor throws
                                CannotSendMail unless the identity's transport is
                                MailTransport::Smtp.

**One clause was written, measured, and then WEAKENED before it shipped, and that is the
part worth keeping.** The first draft said business mail "goes through the mail provider
your business connects". `MailTransport::Smtp` exists (added 2026-09-14) and
`MailDispatcher` branches on it — **and there is no route to reach it**: `git show
f3c5f45d:routes/api.php` has zero sending-identity endpoints, because `0.0.0211` removed
the Sending Address screen from Settings. A tenant cannot connect a mail server today.
The shipped sentence therefore states only the negative that is true — *mail your
business writes to its own customers does not go out over ours* — plus Mailchimp, which
the Newsletter card already sells. **A capability with an enum, a dispatcher branch and
no affordance is not a capability, and a privacy page is the worst place to discover
that.**

**Left for somebody else, and it is not a website problem:** that same refusal at
`MailDispatcher.php:215` tells the customer to *"Open Settings, Sending Address"* — a
screen `0.0.0211` deleted. Latent today (nothing can reach the second branch while the
first always throws), real tomorrow. Written to the Email module as `0049`.

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

## Reports — PUBLISHED 2026-09-14, and the hold aged the draft twice

**The desk answered in `0048`: `Schema::hasTable('saved_reports')` YES, `COUNT(*)` 0,
on deployed release `0.0.0201` at `d8d64550`, with 0 pending migrations. A number, not
an error — which is exactly what the hold below was guarding against.** Published the
same day under the Coverage & capacity / Newsletter precedent: built and unused is
sellable as a capability and never as usage. The card sits at the END of the module
grid, away from `Forms &amp; reports` at `www/index.html:497`, so the pair does not read
as one feature described twice.

**And the number in the draft was wrong again by the time it shipped — for the THIRD
time on this one card.** Three → ten → **eleven**: `monitored_devices` (label
`Monitoring`) joined on `3c41584d` in `0.0.0202`, with no migration, because a device
scopes through its owner. **Re-measured by this run at the deployed SHA `f3c5f45d`
(`0.0.0211`) rather than taken from the desk's `0.0.0201`** — the desk's own number was
a release old when it was written, which is the pattern this file has been burned by
every time. `ReportMap::all()` holds **eleven** keys: jobs, customers, franchises
(Branches), services, inventory_items, vehicles (Fleet Management), training_guides
(Training), leads, service_areas (Scheduling), monitored_devices (Monitoring), users
(Human Resources). **A held draft ages, and a COUNT on a card is the clause that ages
fastest.** Whoever edits this card re-counts at the deployed SHA before touching the
sentence.

**`Monitoring` is now a SECOND decoy on this card, beside `Scheduling`, and the rule
that already covered one covers both.** Do not enumerate the eleven resources: printing
"Scheduling" makes the forbidden calendar claim whatever table sits behind it, and
printing "Monitoring" publishes a module this file holds on a decision from Jose about
copy describing watching employees' screens. The shipped card names four — jobs,
customers, fleet, inventory — and says "eleven parts of the business" for the rest.
Checked after publishing: `Monitoring` → **0** across `www/`; `Scheduling` → **1**,
which is an HTML comment in the app mock at `www/index.html:418` and not a visible
claim.

**What would put this row back:** `saved_reports` erroring rather than counting, which
is a dropped table. A zero does not — the card claims capability, not usage, and says
nothing about money (`ReportMap` publishes `amount_minor`, `currency` and `price_minor`;
`bill` is a machine-enforced forbidden term and a Reports card is exactly where a
sentence about what the work was worth would read as innocent).

**The history below is what the hold was, and why it turned out to be load-bearing.**

### The original hold, 2026-09-11

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
<p>Build a report from your own records &mdash; start from any of eleven parts of
   the business, from jobs and customers to fleet and inventory, pull in the
   columns from what they connect to, filter it and save it by name. Running a
   saved report and building one are separate permissions, so you can let
   somebody run what you built without letting them change it.</p>
```

Clause by clause, read out of the deployed tree at `e1a783dc`:

    any of eleven parts  ReportMap::all() — jobs, customers, franchises (label
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

## 2026-09-13: a second quiet day, and the two tests this file names had no paths

**Production has not moved in two days.** `/download/windows.json` answers
`0.0.0200` built `e1a783dc` — the release recorded on 2026-09-11 and again on
2026-09-12. `main` is three commits past it (`2965475e`, `2fccdbd1`,
`a4af173f`) and the whole diff is this file, `0046`, and one phone test:
`git diff --name-only e1a783dc..HEAD` → three paths, **zero under `www/`**,
**zero under `database/migrations/`**. So no do-not-list row can have moved,
and that is measured from the diff rather than inferred from the quiet.

**Still nobody home.** `list_sessions` returns every EzBizi session with
`isRunning: false`; Deployment (`local_072bee23…`) last active 2026-09-11
20:21 UTC — the same timestamp yesterday's run read. `0045` and `0046` are
unread, not declined. **No `0047`**, for the reason yesterday gave: a third
file restating the same question adds a file, not a reader.

**The audit is clean.** `python www/check-site.py` → `ok - 7 pages, 7 sitemap
entries`. All seven pages fetched from production are byte-identical to `www/`
after stripping CR, every one `Last-Modified` Fri 11 Sep 2026 19:49:45 GMT —
no stale deploy, no hand edit. Play returns `200`; the Apple lookup returns
`resultCount` **1**, `version` 0.0.0190. The forbidden-terms gate, parsed from
this file and split on LINES, flags before the path, all six strings through
one loop → `ok - 4 terms, none present`, positive control `EzBizi` **256**,
negative control `zzzznotpresent` **0**. The four phrasings the Communication
card forbids return **0** across `www/`.

**The two `for()` tests this file has named a dozen times do not live where you
would guess, and the wrong guess fails in the shape of a real finding.**
`git show e1a783dc:app/Services/Social/Publishers.php` answers *"path does not
exist"* — which is exactly what a deleted publisher registry would answer, on
the one claim this file keeps catching in the understated direction. The
deployed paths are:

    Publishers::for()          app/Support/Social/Publishing/Publishers.php
    NewsletterSenders::for()   app/Support/Newsletter/NewsletterSenders.php

Read at `e1a783dc`: five publishers — `FacebookPage`, `Threads`,
`InstagramProfessional`, `Pinterest`, `TikTok(toDrafts: …)` — and
`default => null`, so *"Five networks send today"* holds and **no sixth or
seventh publisher was written**. `NewsletterSenders::for()` is still
`Mailchimp` and `default => null`, so the Newsletter card's "your own Mailchimp
account" has not gone narrow. **Write the path down beside the test**: a
`git show` of a wrong path and a `git show` of a deleted file are the same
sentence, and one of them is a publish-blocking finding.

**Still open, both unchanged and neither mine to close.** `saved_reports` — the
count that publishes the redrafted Reports card, asked in `0045` and `0046`.
**Monitoring** — held on a decision from Jose about copy describing watching
employees' screens, not on a measurement.


## 2026-09-14: eleven releases, two cards published, and the desk read its inbox

**Production moved `0.0.0200` → `0.0.0211`** (`e1a783dc` → `f3c5f45d`,
`/download/windows.json`, fetched). `git diff --name-only e1a783dc f3c5f45d` is 212
paths and **zero under `www/`** — so nothing edited the site in the repo, and the two
changes below are this run's.

**Nobody was running, and the inbox answered anyway.** `list_sessions` returns every
EzBizi session `isRunning: false`; Deployment (`local_072bee23…`) last active 21:13 UTC.
But `docs/agents/inbox/0048-deploy-desk-to-website-saved-reports-count.md` was waiting —
the desk found `0045` and `0046` *by accident*, four days late, while running a landmine
check, and says so plainly: **"a message saying 'I cannot reach you' only works if it
reaches you."** It has added the directory to what it checks when it picks up the desk.
**The channel is proven again and its latency is now measured at four days.** Do not
read silence on an inbox file as a refusal; `0034` took a day and `0046` took four.

**Published today, both against a measurement and neither against a release note:**

1. **Reports** — the desk's `saved_reports` count (table YES, `COUNT(*)` 0). See the
   Reports section; the resource count had moved ten → eleven and was re-measured at
   `f3c5f45d` rather than taken from the desk's `0.0.0201`.
2. **`/privacy/` — Email we send you** — email verification reached production, which is
   the debt the SES section above recorded. See that section; one clause was measured,
   found to have no affordance, and weakened before it shipped.

**The audit is clean.** `python www/check-site.py` → `ok - 7 pages, 7 sitemap entries`.
All seven pages fetched from production were byte-identical to `www/` after stripping CR
**before** this run's edits, every one `Last-Modified` Mon 14 Sep 2026 20:43:27 GMT — so
the site republished today and matches the tree; no stale deploy, no hand edit. The
forbidden-terms gate, parsed from this file and split on LINES, flags before the path,
all six strings through one loop → `ok - 4 terms, none present`, positive control
`EzBizi` **250**, negative control `zzzznotpresent` **0** — re-run after both edits. The
four phrasings the Communication card forbids return **0** across `www/`.

**Three published claims re-measured at `f3c5f45d`, at the paths this file learned the
hard way on 2026-09-13:** `app/Support/Social/Publishing/Publishers.php` still returns
five — FacebookPage, Threads, InstagramProfessional, Pinterest, TikTok — and
`default => null`, so *"Five networks send today"* holds and **no sixth or seventh
publisher was written**. `app/Support/Newsletter/NewsletterSenders.php` is still
`Mailchimp` and `default => null`. Stores: Play `200`; Apple lookup `resultCount` **1**,
`version` 0.0.0190, `currentVersionReleaseDate` 2026-09-11.

**Six migrations landed in the eleven releases and none touches a do-not-list row.**
`email_verifications`, `verify_every_account_that_predates_the_gate`,
`add_call_queue_to_phone_ring_groups`, `add_security_alerted_at_to_monitored_devices`,
`add_remote_customer_id_to_leads`, `add_default_voicemail_transcribe_to_phone_connections`.
**No table carrying both a job reference and a date-or-slot reference**, so the
structural scheduling test holds.

**Two things the release notes moved that the site must NOT follow, both in the
understated direction this file keeps catching — and both correctly left alone:**

- **Android calling.** `0.0.0203` registered the calling account, `0.0.0204` says *"An
  incoming call now rings on Android"* and in the same bullet *"Placing a call and its
  audio have not been confirmed yet"*. That row's test is **an observation, not a release
  note** — somebody has to watch a handset ring, and a note asserting it is the app
  session's claim about its own work. The site says nothing about mobile calling and
  still must not.
- **Ring groups.** `0.0.0209` added a caller queue and `0.0.0210` least-recently-busy
  ordering; `0.0.0210` also says **thirteen** numbers, up from twelve. The fraction with
  a `ring_group_id` was not re-measurable from here and the four forbidden phrasings
  return 0, so nothing moved. **A queue is not a person answering** — if anything, a
  queue is what a caller waits in *because* nobody has.

**Still open, and neither mine to close.** **Monitoring** — held on a decision from Jose
about copy describing watching employees' screens, not on a measurement, and `0.0.0201`,
`0.0.0202`, `0.0.0207` and `0.0.0208` all widened it further (antivirus/firewall state,
an in-app screen-control notice, a Monitoring report resource). **Training** — still the
tense rule only; nothing this week touched it.

## 2026-09-15 (evening): nine releases, nothing to publish, and the site's own test was the understated one

**Production moved `0.0.0211` → `0.0.0220`** (`f3c5f45d` → `ef9f420b`,
`/download/windows.json`, fetched). `main`'s tip **is** the deployed SHA for
once, so the working tree and the deployed tree are the same commit.
`git diff --name-only f3c5f45d ef9f420b` is 159 paths, **two of them under
`www/`** — `index.html` and `privacy/index.html`, both this morning's runs.

**All four of today's website commits are LIVE, confirmed by fetch rather than
by the passage of the afternoon.** All seven pages fetched from production are
byte-identical to `www/` after stripping CR, every one `Last-Modified`
**Tue, 15 Sep 2026 21:12:58 GMT** — so the site republished after the last of
them (`78969405`, 14:00 EDT = 18:00 GMT) and matches the tree. No stale deploy,
no hand edit on the box.

**The audit is clean.** `python www/check-site.py` → `ok - 7 pages, 7 sitemap
entries`. The forbidden-terms gate, parsed from this file and split on LINES,
flags before the path, all six strings through one loop → `ok - 4 terms, none
present`, positive control `EzBizi` **265**, negative control `zzzznotpresent`
**0**. The four phrasings the Communication card forbids return **0** across
`www/`.

**Three published claims re-measured at `ef9f420b`:**
`app/Support/Social/Publishing/Publishers.php` still returns five —
FacebookPage, Threads, InstagramProfessional, Pinterest, TikTok — and
`default => null`, so *"Five networks send today"* holds and **no sixth or
seventh publisher was written**. `app/Support/Newsletter/NewsletterSenders.php`
is still `Mailchimp` and `default => null`. **`ReportMap::all()` still holds
ELEVEN keys** — re-counted rather than carried, because that card's own section
says a count is the clause that ages fastest, and three Google Ads releases in
this window are exactly what would have moved it. It did not: Google Ads is a
**tab inside Reports**, not a report resource. Stores: Play `200`; Apple lookup
`resultCount` **1**, `trackId` 6805979795, **`version` 0.0.0200** (was 0.0.0190
on 2026-09-14), `currentVersionReleaseDate` 2026-09-15 — both live, both pages
already correct, nothing to publish.

**Five migrations landed and none touches a do-not-list row.**
`add_park_to_phone_calls`, `add_last_attempted_at_to_sending_identities`,
`create_whatsapp_messages_table`, `create_whatsapp_numbers_table`,
`create_phone_callbacks_table`. The structural scheduling test was re-run over
every migration in the deployed tree: **no table carries both a job reference
and a slot reference.** `phone_callbacks` has timestamps and a `ring_group_id`
and **no job reference at all**, so it is not a near miss.

### The finding is in this file, not on the site: the ring-group test undercounts

The Phone module answered unasked and corrected the test this file has named
since 2026-09-02. **A null `ring_group_id` falls back to the connection's
`default_ring_group_id`**, so the column says 6 of 13 and the product does 11 of
13. The row is corrected above. **The verdict did not move** — two of thirteen
still dead-end at voicemail, every forbidden phrasing stays forbidden, the card
needs no edit — and that is why this is a finding about the instrument rather
than about the claim. **A test derived from a schema, and not from the code path
that consumes the schema, measures a narrower thing than the claim it governs.**
Three of this file's hardest rows (`external_id`, `jobs.scheduled_for`, the
seeder's `connection_id`) are the same mistake in different clothes.

### A published card was false for days and this check could not have seen it

**`0.0.0219`: *"The chat and contact form on your websites can reach EzBizi
again. The browser was refusing to send the message before it ever left the
page, so everything a visitor typed came back as our own did-not-send
notice."*** And `0.0.0217`: they *"were off on every site"*, with no way to
place them at all on Elementor.

The Web management card, published 2026-09-09, says *"Add the chat bubble and
contact form in your own brand colour, and the enquiries they take arrive in
Leads."* **For some window ending today that sentence described a dead
control**, and every clause of it still read correctly out of the deployed tree,
because the code that files a `Lead` was never the broken part — the browser
refused the request before it left the page.

This is the Reports lesson landing on a card that had already shipped:
**reading the deployed tree proves the code says the thing; it cannot see a dead
button.** There is nothing to fix — `0217` and `0219` fixed it and production is
`0220` — and no edit is owed. It is recorded because the daily check has no
instrument for this, and pretending otherwise is worse than naming the gap. The
only thing that catches it is a release note admitting it, or somebody using the
feature.

### Three capabilities are now reachable and unsold — deliberately, for one more day

`78969405` said these would be "written up when they are reachable and
measured, not when they are merged". Two now are, and the third is measured:

- **A business can connect its own MAIL SERVER** (`0.0.0215`). Measured at
  `ef9f420b`, both halves, because an enum and a dispatcher branch with no route
  is the trap this file recorded on 2026-09-14: `routes/api.php:1828-1830`
  carries `GET`/`PUT`/`DELETE mail/sending-server`, and
  `client/lib/widgets/sending_server_panel.dart` is the screen. **So the
  affordance is back and `docs/agents/inbox/0049` is CLOSED by measurement** —
  `MailDispatcher.php:389` now says *"Open Settings, Sending Server"*, naming a
  screen that exists. Its controller docblock states Jose's authority verbatim
  ("yes build it for god sake", 2026-09-14) and that it is deliberately **not**
  the sending-identity screen he had deleted: our SES is not in the path at all.
  **No privacy edit is owed** — `/privacy/`'s *"Mail your business writes to its
  own customers does not go out over ours"* is not weakened by a second route
  that is also not ours, it is reinforced; and *"a mailing goes through your own
  Mailchimp account"* is a sentence about newsletters, where
  `NewsletterSenders::for()` is still Mailchimp alone.
- **Web Management gained Pages and Posts** (`0.0.0215`), read live from the
  site rather than cached.
- **A Google Ads tab inside Reports** (`0.0.0215`–`0.0.0219`): spend, clicks,
  impressions and conversions per account and per campaign.

**None is published, and the reason is one sentence: all three arrived today.**
The Reports section of this file records a release where every clause checked
out in the deployed tree and the button was dead, and the section above records
the same thing happening to a card that had already shipped. **For a capability
that is hours old, "every clause checks out" is the weakest evidence this file
accepts.** Nothing is lost by waiting for a release that does not fix them.

**Still open, and neither mine to close.** **Training** — the tense rule only.
**WhatsApp** — in the code, unreachable, waiting on Meta publishing the app, and
the test is Meta's app status and not the commit log.

### What the desk was asked and what actually answered

No deploy desk was reachable. `list_sessions` returns every EzBizi session
`isRunning: false`; Deployment (`local_072bee23…`) last active 21:40 UTC.
`ListAgents` showed three live pipe sessions, and the longest-running of them
(`ezbizi-32`, 10h) turned out to be **the Phone module**, which answered the two
questions that were its own and declined the other three. **That is the fourth
state this file has now seen on this channel** — not a dead id, not the
caller-side refusal, not nobody home, but *the wrong desk answering correctly
about its own module*. It was worth more than a catalog would have been: the
ring-group correction above is entirely its finding, and a deploy desk reading a
row count off the column would have handed over the undercount in good faith.

## 2026-09-16: twelve releases, nothing published, and yesterday's day-old evidence expired instead of maturing

**Production moved `0.0.0220` → `0.0.0232`** (`ef9f420b` → `547b74b0`,
`/download/windows.json`, fetched). `main`'s tip **was** the deployed SHA when
these measurements were taken, so every code claim below was read off the tree
production is running — and it stopped being true mid-run, which is the point:
`b3eda207` landed while this section was being written. `git log
ef9f420b..547b74b0` is **170 commits and NOT ONE under `www/`**.

**The audit is clean and the site is current.** All seven pages fetched from
production are byte-identical to `www/` after stripping CR, `Last-Modified`
**Wed, 16 Sep 2026 21:58:44 GMT** — so the site republished today off the same
tree it already matched. No stale deploy, no hand edit on the box.
`python www/check-site.py` → `ok - 7 pages, 7 sitemap entries`.
`./scripts/index-landmines.sh` → `0 stale, 0 live`.
The forbidden-terms gate, parsed from this file and **split on LINES**, all four
strings through one loop → `ok - 4 terms, none present`.

**State the control's METHOD or it is not a control.** Yesterday's run recorded
positive control `EzBizi` **265**; `www/` has not changed by a byte since, and
today the same idea gives **178** (`grep -ro "EzBizi" www/ | wc -l`, occurrences,
case-sensitive) or **393** (python, `.count('ezbizi')` lowercased, which also
counts every `ezbizi.com` and `com.ezbizi.app`). Three numbers, one unchanged
site. **Nothing moved and it would have read as movement** — so the number is
worthless without the command that produced it, and the next run must compare
methods before it compares counts.

**Four published claims re-measured at `547b74b0`:** `Publishers.php` still
returns five — FacebookPage, Threads, InstagramProfessional, Pinterest, TikTok —
and `default => null`, so *"Five networks send today"* holds and **no sixth
publisher was written**. `NewsletterSenders.php` is still `Mailchimp` and
`default => null`. **`ReportMap::all()` still holds ELEVEN keys**, re-counted
rather than carried. Stores: Play `200`; Apple lookup `resultCount` **1**,
`trackId` 6805979795, **`version` 0.0.0200**, `currentVersionReleaseDate`
2026-09-15 — **the App Store version did not move across twelve releases**, which
is what an unbumped store listing looks like and is not a site problem: both
download cards say the app is there, and it is.

**Three migrations, none of them a scheduling table.** `create_device_tokens_table`,
`add_went_quiet_to_web_sites`, `add_is_automatic_to_lead_conversations`. The
structural test was re-run over every migration in the deployed tree: **no table
carries both a job reference and a date-or-slot reference.** `device_tokens` is
`(organization_id, user_id, platform, token, last_registered_at)` — a handset
register, not a diary.

### The three held capabilities: two moved again today, so the wait did not mature them

`ef9f420b`'s run held Sending Server, Web Management Pages/Posts and the Google
Ads tab in Reports for one day, on the rule that "every clause checks out" is the
weakest evidence a capability can have when it is hours old. A day passed and
**two of the three were shipped again**: Pages and posts re-shipped in `0.0.0224`
*and* `0.0.0229`, with templates in `0225` and a chat auto-reply and four SEO
fields alongside them; the Google Ads reports screen changed in `0223` (it now
says whether an over-quota refusal is that account's or EzBizi's). A capability
that ships twice more in the waiting period has not been sitting still being
verified — **the wait bought nothing for those two and they are held again.**

**Only the Sending Server went a full day untouched**, and it is deliberately
still unpublished: it is a Settings screen, not a module, and the two cards that
could carry it would both be made worse. Newsletter's *"through your own
Mailchimp account"* is a sentence about newsletters, where `NewsletterSenders`
is still Mailchimp alone, and a mail-server clause bolted to it would read as if
mailings used it.

### The gap worth naming: there is no Automations card, and eight of today's twelve releases were Automations

Not a wrong claim — an absent one, which is the failure mode this file is worse
at seeing. The homepage grid sells eighteen capabilities and automation is not
among them, while `routes/api.php:1666-1679` carries the catalogue, trigger
fields, runs, test and run-now routes, and `app/Support/Automations/` holds the
engine, the condition evaluator and the schedule.

**Today is the wrong day to publish it, and the release notes are the reason
rather than caution.** `0.0.0232` closed an hourly email cap that *"never fired
in production"* and a counter whose not-counting *"was reading as permission to
send"*; `0225` rewrote why a rule silently did not run. A card written today
would describe a machine that was demonstrably mis-firing this morning.

**The test for whichever run publishes it** — both halves, because either alone
is the trap this file keeps recording: a production row count of `automations`
(capability phrasing does not need usage, but "nobody has ever made one" is worth
knowing before writing), **and** a release window with no automation *fix* in it.
Reachability is already measured above and is not the open question.

### The channel: the same wrong desk as yesterday, and it answered better than a catalog again

No deploy desk. `ListAgents` showed two peers — `ezbizi-32` (1d) and an observer
50 seconds old. One question went to `ezbizi-32` asking whether it is the desk,
saying in the message that a reply must be a file because a scheduled run cannot
receive `send_message`. **It was the Phone module for the second night running,
it said so first, it named the desk's id out of `docs/SESSIONS.md` rather than
guessing, and it answered the one question that was its own** — `0051`, committed
as `b3eda207`. **Asking the wrong session and saying how to reply produced a
file; asking the right one by a pipe name would have produced silence.**

What it measured on production tonight: `phone_ring_groups` **3**, `phone_numbers`
**13**, `phone_callbacks` **0**; the resolved fraction **11 of 13**, dead-ending
**2**, identical to 2026-09-15, and no migration touched a phone table in the
range. **So no Communication claim moved.** It also volunteered the shape that
makes a usage sentence impossible: 29 inbound calls in the last two days,
**2 answered by a person**, 27 rang to voicemail at 59-60s. Live customer
traffic, and not a fraction the site may be given.

**Nothing else in this section rests on an answer**: production being `main`'s
tip made the deployed tree readable here, which is the one condition under which
this check does not need the desk for code claims. It still needs it for row
counts, which is exactly what the Automations test above is waiting on.

## 2026-09-17: seven releases, and a PRIVACY sentence had been false for a day

**Production moved `0.0.0232` → `0.0.0239`** (`547b74b0` → `2832a607`,
`/download/windows.json`, fetched). `main`'s tip **is** the deployed SHA, so
every code claim below was read off the tree production is running.
`git log 547b74b0..2832a607` is **259 commits and NOT ONE under `www/`**.

**The audit was clean before the edit below.** All seven pages fetched from
production byte-identical to `www/` after stripping CR, `Last-Modified`
**Thu, 17 Sep 2026 22:12:22 GMT**. `python www/check-site.py` →
`ok - 7 pages, 7 sitemap entries`. `./scripts/index-landmines.sh` →
`0 stale, 0 live`. Forbidden terms, parsed from this file and **split on
LINES** → `ok - 4 terms, none present`. Control, **method stated**: python
`.count('ezbizi')` lowercased over `www/**/*.html` = **393**, identical to
yesterday's number by the same method over an unchanged tree; negative control
`zzzznotpresent` = **0**.

### The finding: `/privacy/` said EzBizi never carries a business's mail, and it does

**Published 2026-09-14, false since 2026-09-16, corrected today.** The *Email we
send you* section ended:

> These are EzBizi's own messages about your account, **and they are all of
> them. Mail your business writes to its own customers does not go out over
> ours** — a mailing goes through your own Mailchimp account…

**Both bolded clauses are false at `2832a607`.** Three automation actions send a
tenant's mail over EzBizi's own SES identity, and the code says so in its own
words — `ActionRunner.php:610-635`, on Jose reversing his 2026-09-14 ruling:

    report.send                ActionRunner:382 -> SendSavedReport:183
    email.send                 ActionRunner:383 -> ActionRunner:1352
    inspection_report.email    ActionRunner:391 -> ActionRunner:1354

all three reaching `MailDispatcher::platformForTenant()` (`:178`), which is
EzBizi's platform identity gated only by an hourly cap. **`asTenant()` is a
`@throws CannotSendMail always` stub** (`:136`) — retired for putting a
*tenant's* address on our transport — and the live method puts **EzBizi's**
address on it instead. Jose's words, quoted in `SendSavedReport`'s docblock:
*"Email ( the Ezbizi SES one, not the company one )"*, 2026-09-16.

**The sentence was true when it was written and the ruling behind it was
reversed two days later.** `SendSavedReport:60` records the first ruling
verbatim — *"EZBIZI EMAIL IS ONLY FOR EZBIZI ACTIONS"* — which is the sentence
the privacy page was derived from. **A privacy claim derived from a ruling ages
with the ruling, and nothing on the site is watching the ruling.** The daily
check found this only because a release note said *"Emailing a report to a
customer is back"* and the word *customer* did not fit the page.

**What shipped:**

> These are EzBizi's own messages about your account. **An organization can also
> have EzBizi send mail on its behalf**: an automation rule can email a saved
> report, an inspection report, or a message the organization writes — to the
> recipients that rule names, or to an address held on the record it fired on,
> which may be a customer's. Those leave from EzBizi's own address rather than
> the organization's, and the body says which business the work is about. A
> newsletter is not one of these: a mailing goes through your own Mailchimp
> account, on your list and your sending reputation.

Every clause read out of the deployed tree: recipients-vs-record is the real
split (`SendSavedReport:167` loops a recipient list; `ActionRunner:1322` reads a
`to_field` **off the record the rule fired on**, so a customer's address on a
job is exactly how it is reached — one phrasing covering both would have been
wrong about one of them). "EzBizi's own address" is `platformForTenant`'s
`$this->platform->identity()`. "Says which business" is
`companyName: (string) $scope->name`. The Mailchimp clause survives untouched
because `NewsletterSenders::for()` is still `Mailchimp` and `default => null`.

**`Last updated` moved to 17 September 2026** — and it read **16** September
before this edit, set by `7ef75f6f` on the **15th**, a stamp dated into the
future that nothing would ever have corrected.

**Deliberately NOT written: "or through your own mail server if you connect
one."** The screen exists (`mail/sending-server`, published in `0.0.0215`) and
`MailDispatcher::throughTenantProvider()` exists — and **nothing in `app/` calls
it.** Measured: zero callers outside the dispatcher itself and one test. It is
the 2026-09-14 trap in its third outfit — an enum, a dispatcher branch, a route
AND a screen, and still no code path that sends through it. **A privacy page is
the worst place to describe a route no message takes.**

**This is the first published claim this check has had to pull rather than
narrow, and grep found it in exactly one place** — `www/privacy/index.html:81`,
with the homepage's Newsletter card (`index.html:533`) the only other Mailchimp
sentence and still true. The "a claim is never in one place" rule held anyway
because it was *checked*, not assumed.

### Four published claims re-measured at `2832a607`

`Publishers.php` still returns five — FacebookPage, Threads,
InstagramProfessional, Pinterest, TikTok — and `default => null`, so *"Five
networks send today"* holds and **no sixth publisher was written**.
`NewsletterSenders.php` is still `Mailchimp` and `default => null`.
**`ReportMap::all()` still holds ELEVEN keys**, re-counted rather than carried:
jobs, customers, franchises, services, inventory_items, vehicles,
training_guides, leads, service_areas, monitored_devices, users. Stores: Play
`200`; Apple lookup `resultCount` **1**, `trackId` 6805979795, **`version`
0.0.0222** (was 0.0.0200 for two days), `currentVersionReleaseDate`
2026-09-17 — both live, both pages already correct.

### Sixteen migrations and not one new table

Every migration in the window is the per-tab permission sweep plus three column
changes (`pto_allowance_and_waiting_carry_a_unit`,
`add_answer_with_to_phone_numbers`, the Work Hours copy pair). **Zero
`Schema::create` across all sixteen**, so the structural scheduling test cannot
have moved: no table carries both a job reference and a date-or-slot reference,
and `service_area_slots` is still `(service_area_id, weekday, start_time,
end_time, max_jobs)` with no `job_id`.

### The Automations card is held again, and its own test is what held it

Yesterday's run named the test: a production row count of `automations`, **and a
release window with no automation *fix* in it.** The second half fails outright.
`0.0.0233` shipped three automation fixes — a variable in an email or WhatsApp
message *"was always blank"*, a rule could not name the caller in a text, and a
typed quotation mark *"arrived as a string of symbols"*. **A card written today
would again describe a machine that was demonstrably mis-firing this morning**,
which is the same sentence yesterday wrote, for a different release. The test
did its job; nothing about it needs changing.

### New this window, deliberately unpublished

- **Paid time off** (`0.0.0236`) — allowance, waiting period, carry-over, and
  everybody sees their own balance. Hours old, and *"Off until you set it up"*
  by its own release note, so built-and-unused **and** unswitched. Candidate for
  the People card on a later run, under the Coverage & capacity precedent and
  its tense rule.
- **Per-tab role permissions** (`0.0.0238`) — the whole of today's sweep. Not a
  capability the site sells; Roles and permissions are not on the homepage grid
  at all.
- **Inspections Flow view**, **searchable dropdowns everywhere**, **iPhone
  notifications** (`0.0.0237`) — refinements under published cards, or mobile,
  which the site is silent about on purpose.
- **Chat-bubble hours per site** (`0.0.0239`) — a refinement to the Web
  management card, which needs no edit.

### The channel: no desk asked, and this run did not need one for the finding

Production being `main`'s tip made the deployed tree readable here, which is the
one condition under which the code claims need no desk. **The correction above
was derived entirely from that tree** — the release note pointed, the code
answered. Row counts still need the desk: Training's `training_attempts`, the
Local Services and Thumbtack `is_test = 0` counts, the resolved ring-group
fraction, and the `automations` count the card above is waiting on.

## 2026-09-18: one release, and the capability that shipped is one a customer cannot install

**Production moved `0.0.0239` → `0.0.0240`** (`2832a607` → `7ceefd9a`,
`/download/windows.json`, fetched). Twenty commits, **one** release tag, and
`git log 2832a607..HEAD -- www/` is a single commit — yesterday's own privacy
correction, which **is deployed**: `36dbbc52` is an ancestor of `7ceefd9a`, and
production `/privacy/` is byte-identical to `www/privacy/index.html`. `0052`
asked for that publish as the urgent kind; it went out.

`HEAD` (`5d0ea1e1`) is two commits past the deployed SHA and **both are
`docs/` and `scripts/` only** (`git diff --stat 7ceefd9a HEAD` → 4 files, no
`app/`, no `client/`). So the deployed tree is readable here, and every code
claim below was read off it rather than asked for.

**The audit was clean and no page needed an edit.** All seven pages fetched
from production byte-identical to `www/` after stripping CR, `Last-Modified`
**Fri, 18 Sep 2026 19:10:26 GMT**. `python www/check-site.py` →
`ok - 7 pages, 7 sitemap entries`. `./scripts/index-landmines.sh` →
`0 stale, 0 live`. Forbidden terms, parsed from this file and **split on
LINES** → `ok - 4 terms, none present`. Control, method stated: python
`.count('ezbizi')` lowercased over `www/**/*.html` = **395**, up 2 from
yesterday's 393 by the same method, which is the privacy edit and nothing else;
negative control `zzzznotpresent` = **0**.

### `/download/windows.json` is right, and the reason it was wrong this morning is worth keeping

Manifest `0.0.0240`, `built` `7ceefd9a`, repo `client/VERSION` `0.0.0240`. No
mismatch, and nothing to report — but for one afternoon **`0.0.0239` named two
different trees and the newer one was live.** The web parity work reached
production through `deploy.sh` and `build-app.sh`, neither of which touches
`client/VERSION`, so production served `1e53de9c` under the number `2832a607`
had. Jose found it the obvious way: his app still read `0.0.0239 · 2832a607`.
**A deploy is not a release, and this page's own rule — compare the manifest to
what production *runs*, never to the repo — is what would have caught it**:
comparing manifest to `client/VERSION` agreed with itself all afternoon while
the served bundle was three days newer.

### The finding: a capability shipped, is rendering on 110 sites, and is still not sellable

Plugin **1.12.0** adds five rendered widgets — `social_links`, `google_map`,
`post_title`, `hours_table`, `city_list` — and they are not a promise:
110/110 installs on our own hosting are at 1.12.0, sampled hosts return 200
with markup and no leaked shortcodes, and `google_map` degrading to a Maps link
without a latitude is documented behaviour rather than a failure.

**And a customer cannot get any of it.** One command, and it is the row's test:

```
curl -s "https://api.wordpress.org/plugins/info/1.0/ezbizi.json"   # -> "version":"1.10.0"
```

The directory serves **1.10.0**. The estate is at 1.12.0 because an operator
with a shell and passwordless sudo pushed it there, one tarball per install.
**Somebody installing the EzBizi plugin the only way a stranger can install it
gets a plugin with none of these shortcodes in it.** Filed as a row in the
do-not table above.

This is the WhatsApp shape in a second outfit — finished code on `main`,
reachable by nobody who is not us — and the trap is sharper here, because the
usual refutation *"but is it live?"* has an honest yes attached to it. It is
live on sites **we** host. The site sells to people we do not.

**The chat switch and the Cities pane are refinements under the published Web
management card and need no edit.** The switch defaults on and works by
omitting the chat block rather than sending a flag, so it bites on the 1.10.0
and 1.11.0 plugins already out there; the card's "Add the chat bubble and
contact form in your own brand colour" is true before and after. Cities is an
editor pane, and the card never described the editor.

### Re-measured at `7ceefd9a`, not carried

`Publishers::for()` still returns **five** — FacebookPage, Threads,
InstagramProfessional, Pinterest, TikTok — with `default => null`, so *"Five
networks send today"* holds and **no sixth publisher was written**.
`NewsletterSenders::for()` is still `Mailchimp` and `default => null`.
`ReportMap::all()` still holds **eleven** keys, re-counted from the method body:
jobs, customers, franchises, services, inventory_items, vehicles,
training_guides, leads, service_areas, monitored_devices, users. Stores: Play
`200`; Apple lookup `resultCount` **1**, `trackId` 6805979795, `version`
**0.0.0222**, `currentVersionReleaseDate` 2026-09-17 — unchanged since
yesterday and both pages already correct. (`0.0.0240`'s iOS build is
*delivered* to App Store Connect, which is not accepted and not a store
version; the page says nothing that depends on it.)

**`git diff --name-only 2832a607 7ceefd9a -- database/migrations/` is EMPTY** —
zero migrations, so the structural scheduling test cannot have moved: still no
table carrying both a job reference and a date-or-slot reference.

### The channel: the tool is gone on a schedule, not the desk

`ccd_session_mgmt send_message` to the Deployment id
(`local_072bee23-…`, the durable address `docs/SESSIONS.md` names) returned
**"This tool is unavailable in unattended sessions (scheduled-task runs and
remote-dispatched trees)."** That is a property of *this* run, not of the desk:
the same id reached it by hand on other days. **So a scheduled run of this check
can never ask for a row count**, and the daily task file's step 1 cannot be
completed on a schedule at all — worth knowing before somebody reads "no desk
answered" as "no desk was running". Five peers were up in `ListAgents`.

Nothing above needed one: production being two docs-commits behind `main` made
the deployed tree readable here. **Still owed, and all of them row counts:**
`automations` (the Automations card's own test), `training_attempts`, the Local
Services and Thumbtack `is_test = 0` counts, and the resolved ring-group
fraction. Ask by hand, not from a scheduled run.

### New this window, deliberately unpublished

- **The five rendered widgets** — the row above.
- **Chat on/off per site**, **Cities pane**, **selection by key rather than
  list position** — refinements under the Web management card.
- **A guard against naming a field after a plugin-owned shortcode**
  (`WidgetPresets::PLUGIN_OWNED_SHORTCODES`) — internal, and nothing the site
  sells.

## 2026-09-22: four days of drift, and the register's second run found a live false claim

**Production is `0.0.0242` at `049e13a1`** (`/download/windows.json`, fetched),
which is **`main`'s tip** — `git rev-parse HEAD` is the same SHA. So the deployed
tree is this tree, every code claim below was read off it rather than asked for,
and the only thing a desk could have added is a row count.

**Forty-five commits since the `7ceefd9a` baseline of 2026-09-18**, and there is
no section here for 09-19, 09-20 or 09-21 — so this run is a four-day catch-up
rather than a daily diff. One commit touches `www/`: `72c99ce0`, this morning,
which added the ten-row register above and corrected `/privacy/`'s
device-permission sentence. **It published** — all seven pages fetch
byte-identical to `www/` after stripping CR, `Last-Modified`
**Tue, 22 Sep 2026 20:07:40 GMT**.

Gate output, before the edit below and again after it:

    python www/check-site.py        ok - 7 pages, 7 sitemap entries
    forbidden-terms (LINE-split)    ok - 4 terms, none present
    ./scripts/index-landmines.sh    0 stale, 0 live
    control .count('ezbizi')        397 (was 395 on 09-18, +2 = the privacy edit)
    negative control                0
    every JSON-LD block             parses

### The finding: the homepage said a customer can read and answer Google reviews, and no customer can

**Three places on `/`, published and live.** The FAQPage JSON-LD (`:38`), the
*"Advertising & reviews"* capability card (`:512`-`:513`) and the visible
`<details>` answering the same question (`:638`) all said a Google Business
Profile **can be connected to read and answer reviews**.

Four independent sources say it cannot, and they agree:

1. `BusinessProfileReviews`' own docblock — *"The Business Profile APIs ship at
   0 QPM and stay there until Google approves an access application. Every call
   here will fail until that lands."*
2. Its runtime refusal at `:313` — *"Google has not granted this installation
   any Business Profile quota yet, so reviews cannot be read."* Note
   **`this installation`**: it is not per-tenant, so it blocks every customer at
   once.
3. `docs/EZBIZI-STATUS.md:122` — quota *"cannot even be applied for before
   2026-10-24"*.
4. **`08c2b82e`, committed today**: the application was **REFUSED** on the
   60-day verified-listing rule. The profile verified 2026-08-25, so 2026-10-24
   is the earliest re-file.

**Connecting works and that half was true** — TODO #33 closed on 2026-08-26 with
both OAuth clients live and the driver reporting READY, and `business.manage` is
non-sensitive so it needs no approval. It is the purpose clause that was false,
and a purpose clause is the whole reason somebody connects.

Softened the same day, all three places plus the card's own heading:

    h3    Advertising & reviews  ->  Advertising & business profiles
    card  "...and answer reviews from the same place as the work."
          -> "Ads brings its Local Services enquiries in as leads; reading and
              answering Business Profile reviews is waiting on Google to grant
              us API access."
    x2    "A Google Business Profile can be connected to read and answer
           reviews, and a Google Ads account to bring Local Services
           enquiries..."
          -> "A Google Ads account can be connected to bring Local Services
              enquiries in beside the customers and jobs they become. A Google
              Business Profile can be connected too, though reading and
              answering its reviews is waiting on Google to grant Business
              Profile API access."

**Naming the state rather than going silent, on this file's own `/download/`
precedent** — the iOS card claimed only what was checkable ("built and signed,
not on the store") and owed a link the day the lookup answered. A reader told
*why* a thing is not there can tell it from a thing that does not exist.

**Three things worth keeping about how this one hid.** (1) It is the fourth
member of the family this file keeps meeting — finished code, deployed,
unreachable because a third party has not said yes — after WhatsApp, the
WordPress widgets and now Messenger/Instagram. (2) **It is the first one caught
by the register rather than by somebody happening to look**, one day after the
register was written; the row that found it did not exist yesterday. (3) It was
never on the do-not list, because Reviews sits on the **"Live, and safe to
sell"** list — and it belongs there, since the module, the drafting split and
the publish permission are all real. **A module can be live and its data path
blocked, and the safe-to-sell list has no column for that.** The sentence, not
the module, was the thing that was wrong.

### The Reviews panel in the app mock was left alone, and here is the test that would change it

The mock still carries a Reviews tab showing *RATING 4.8*, *THIS MONTH 17 new
reviews*, *AWAITING REPLY 2*. **Deliberately not deleted**, and this is the
weaker half of today's call: the Reviews screen genuinely exists with those
fields, drafting and publishing are real and separately permissioned, and a mock
is an illustration of a screen rather than a sentence about what arrives in it.
The Tasks precedent does **not** reach it — that panel went because the module
was retired and a customer on the shipped client had no such screen at all.

**If you disagree, the test is the same one as the sentence**: Business Profile
quota. If 2026-10-24 comes and goes without it, the mock has been showing
seventeen reviews a month for a module no customer can put one review into, and
that is a harder sentence to defend every week it stays.

### `/download/windows.json`, stores and the plugin directory

Manifest `0.0.0242`, `built` `049e13a1`, repo `client/VERSION` `0.0.0242` — all
three agree, and the manifest agreeing with `client/VERSION` is a coincidence of
today rather than the test. Play returns `200`. The Apple lookup returns
`resultCount` **1**, `trackId` 6805979795, version **0.0.0222**,
`currentVersionReleaseDate` 2026-09-17 — unchanged since 09-18, both pages
already correct. `api.wordpress.org` still serves the plugin at **1.10.0**, so
the five-widgets row stands exactly as written.

### Re-measured at `049e13a1`, not carried

`Publishers::for()` returns **five** — FacebookPage, Threads,
InstagramProfessional, Pinterest, TikTok, `default => null`. No sixth publisher,
so *"Five networks send today"* holds. `NewsletterSenders::for()` is still
Mailchimp alone. `ReportMap::all()` still holds **eleven** keys, re-counted from
the method body. `monitoring.control_indicator` is still `default: false`, so
`/privacy/`'s "the answer is no unless they change it" holds.
`AndroidManifest.xml` declares `INTERNET` and `POST_NOTIFICATIONS`;
`Info.plist` declares `NSMicrophoneUsageDescription` and nothing else; `pubspec`
pulls no camera, location, contacts, calendar or photo package — which is
exactly what this morning's corrected sentence says, so that row passes on its
second run. `metrics.*` in `CampaignPerformance` is cost_micros, clicks,
impressions, conversions, all_conversions — unchanged since the sentence was
written beside it.

**Four migrations since `7ceefd9a`, and not one creates a table.** Three alter
`phone_extensions` or grant permissions; the floor-plan one moves a tab. So the
structural scheduling test cannot have moved: **still no table carrying both a
job reference and a date-or-slot reference.**

### `0058` is closed by measurement, and `b203303a` is now in main

SES held the `/privacy/` clause *"and the body says which business the work is
about"* open because `SavedReportMail` named the business nowhere — two of three
senders named it and the page spoke for all three (`44b190e1`). They asked
explicitly that the page **not** be narrowed while Reports fixed the code.
Measured today: `git merge-base --is-ancestor b203303a HEAD` -> **yes**, and
`resources/views/emails/saved-report.blade.php:20-22` prints *"Sent by
{{ $business }} through EzBizi..."*. Production is that SHA. **The clause is
true of all three senders now**; the hold is spent. Read the template, not the
commit subject — that is how SES caught it in the first place.

### A third lead source is live and the site is incomplete rather than wrong

Relayed by grok (a Cursor session, not the deploy desk) in `0053`, measured on
production 2026-09-18 ~15:05 UTC through `artisan tinker`, **not re-measured
here and four days old**: `leads WHERE is_test = 0` -> local_services 41,831,
thumbtack 2, **website_chat 16**. Also `automations` 10, `training_attempts` 0,
`training_progress` 0 — so the Training tense rule still holds on a relayed
zero.

**Nothing to publish, and the reason is that the card already says it.** Web
management reads *"Add the chat bubble and contact form in your own brand
colour, and the enquiries they take arrive in Leads"* — written in the
conditional shape precisely so it would be true before and after, and sixteen
real enquiries make it true rather than make it need an edit. The Growth card
names Local Services and Thumbtack and does not claim to be a list of every
source. **A capability sentence that survives its own inversion needs no work on
the day it inverts**, which is the whole argument for writing them that way.

### The channel: no desk asked, and nothing above needed one

`ccd_session_mgmt send_message` is unavailable to a scheduled run — recorded on
09-18 and unchanged — and production being `main`'s tip made every code claim
readable here. **Still owed, and all of them row counts a scheduled run cannot
take:** the `facebook`/`instagram` lead count with `is_test = 0`, the resolved
ring-group fraction, and fresh figures for the three grok gave on 09-18. Ask by
hand.

### New this window, deliberately unpublished

- **Messenger and Instagram as lead sources** — the do-not row above.
- **Phone extensions that point at a team or a menu**, paging, call park — the
  Communication card is silent on extensions and the module is not asking for
  copy.
- **The Integrations screen rebuilt as a card per provider** — a presentation
  change behind a login, and the site sells no screen layout.
- **Monitoring's General ownership chart and the floor plan's own map tab** —
  refinements under the published Monitoring card.

## 2026-09-23: 133 commits, and the sub-processor list was an exhaustive claim that had stopped being one

**Production is `0.0.0252` at `92dd881e`** (`/download/windows.json`, fetched),
which is **`main`'s tip** — `git rev-parse HEAD` is the same SHA. So the
deployed tree is this tree and every code claim below was read off it. No desk
was asked: `ccd_session_mgmt send_message` refused this run as "unattended", the
same as 09-18 and 09-22. Row counts remain the only thing owed.

**133 commits since the `049e13a1` baseline of 2026-09-22.** One touches `www/`
— `1ae78d95`, yesterday's Reviews softening — and **it published**: all seven
pages fetched byte-identical to `www/` after stripping CR, before this run's own
edit.

Gate output, after the edit below:

    python www/check-site.py        ok - 7 pages, 7 sitemap entries
    forbidden-terms (LINE-split)    ok - 4 terms, none present
    control .count('ezbizi')        399 (was 397 on 09-22, +2 = this edit)
    negative control                0
    every JSON-LD block             parses

### The finding: "these are all of them" named four parties and there were seven

`/privacy/` → **Who we rely on** said *"We keep this list short on purpose, and
these are all of them"* over Amazon Web Services, Amazon SES, Twilio, and *"the
social networks you connect"*. **Three recipients of personal data were not on
it**, and one of them carries a customer's own contacts out of EzBizi:

1. **Mailchimp.** `MailchimpSender`'s own docblock, unprompted: *"The push is
   the moment customer addresses leave EzBizi for a third party."* A `customers`
   audience is pushed into the tenant's Mailchimp list and then the campaign
   sends. The Newsletter card was published **2026-09-08**; the sentence
   describing who receives that data never followed it.
2. **Apple (APNs) and Google (FCM).** `ApnsSender::deliver()` POSTs
   `alert => ['title' => ..., 'body' => ...]`, and `PushSender` does the FCM v1
   equivalent. A notification body names the thing it is about, so the content
   reaches Apple or Google, not merely a device token.
3. **Google Ads**, which the page describes at length in its own section above
   and then omits from the list that claims to be complete — the same shape as
   the iOS card and the homepage, where the page that was fixed was not the
   claim that was wrong.

Published the same day, three bullets:

    + Apple and Google — a notification sent to a phone carries its title and
      text through Apple's or Google's delivery service, because that is the
      only way to wake an app that is not open.
    + Mailchimp — only where an organization connects its own Mailchimp account
      to send a newsletter. If the audience is one kept in EzBizi, those email
      addresses are added to that organization's own Mailchimp list before the
      campaign goes out.
    ~ "The social networks you connect, and only the ones you connect"
      -> "The accounts you connect, and only the ones you connect — the social
          networks described above, and any business account EzBizi reads from
          at your instruction, such as Google Ads."

`Last updated` and `dateModified` moved to 2026-09-23 with it.

**The last bullet is the durable half, and the reason it was widened rather than
extended.** Workiz and Airtable both became connectable this window; a list
enumerating *social networks* is falsified by every integration that is not one,
silently, by a module that has no reason to read this page. A bullet phrased
around what the customer connects survives the next one. The new row in the
load-bearing table names `config/services.php` as where to look, because that
file is the one thing a new integration cannot avoid touching.

**The site was wrong by omission over time rather than by a sentence going
false, and that is the first of its kind here.** No commit made it wrong. It was
wrong the day Newsletter shipped and every day since, and nothing in a daily
diff would ever surface it — a diff shows what changed, and this was a claim
that did not change while the world around it did. The only thing that finds one
of these is reading a sentence against the code that would have to be true,
which is what the load-bearing table is for and why this one now has a row.

### The scheduling test tripped, and the claim did not move

**`workiz_jobs` (created today, `2026_09_23_140000`) carries `job_id`,
`scheduled_for` and `scheduled_end` in one table.** That is *literally* the
structural test the do-not row names — *"no table carries both a job reference
and a date-or-slot reference"* — and it now returns the wrong answer.

**Do not invert the row.** The substance is unchanged, and the code says so in
its own words: `SchedulingAvailabilityController`'s docblock — *"Nothing yet
burns a slot: `Job` has `scheduled_for` and no link to a service area, so there
is no booked count to subtract."* Its response still carries
`basis: 'configured'`, and the docblock states what flips it: *"The day
Scheduled Jobs exists this returns `remaining`."*

**So the test is replaced, by the one the code already maintains for its own
reasons:**

> **`SchedulingAvailabilityController::BASIS_CONFIGURED` is what the
> availability endpoint answers with.** The day it can answer `remaining`,
> something is counting bookings against capacity, and that is the day the claim
> changes.

That is better than the old test on every axis: a module owner maintains it
against their own product, it cannot be tripped by a mirror of somebody else's
calendar, and it is one grep. `workiz_jobs` is a **landing table for another
product's schedule** — its own docblock calls it that — and a read-only copy of
what Workiz already decided is not EzBizi putting a job on a calendar.

**The lesson is about the shape of a structural test, not about Workiz.** A test
phrased as "no table anywhere has these two columns" is a claim about the whole
schema, and a schema grows for reasons that have nothing to do with the claim. A
test phrased against a named constant in the module that owns the behaviour
grows with the behaviour.

### Workiz: connectable by any company, and deliberately unpublished

`WorkizConnectController@store` is registered at `social/workiz` and
`reports/experimental/workiz`, and Jose asked for it in as many words
(quoted in `integrations_screen.dart:336`): *"make it as well so any company can
make workiz connections via api as well, same set it up on integrations"*. So it
is not ours-only.

**It stays off the site**, and the gate is the screen's own: the form is offered
only to somebody holding **both** `reports.build` and
`reports.experimental.view` — `_maySeeExperimental`. A capability reachable only
through a report labelled Experimental is not one to sell, and the site names no
integration that is not already sold under a card.

`workiz` also became a lead source (`5e930f87`, plus
`2026_09_23_170000_grant_existing_roles_the_workiz_lead_source`). **Nothing to
publish, for the reason recorded on 09-22 about `website_chat`:** the Growth
card names Local Services and Thumbtack and does not claim to be a list of every
source, so a fourth source makes the site incomplete rather than wrong.

### Re-measured at `92dd881e`, not carried

`Publishers::for()` returns **five** — FacebookPage, Threads,
InstagramProfessional, Pinterest, TikTok, `default => null`. *"Five networks
send today"* holds. `NewsletterSenders::for()` is **Mailchimp alone**.
`ReportMap::all()` holds **eleven** top-level keys (jobs, customers, franchises,
services, inventory_items, vehicles, training_guides, leads, service_areas,
monitored_devices, users). `monitoring.control_indicator` is still
`default: false`. `AndroidManifest.xml` declares `INTERNET` and
`POST_NOTIFICATIONS`; `Info.plist` declares `NSMicrophoneUsageDescription` and
nothing else; `pubspec.yaml` pulls no camera, location, contacts, calendar or
photo package — the two-permission sentence holds on its third run.
`BusinessProfileReviews` still carries the 0 QPM docblock and the runtime
refusal at `:313`, so **yesterday's softened Reviews sentence is still the true
one**; nothing about the 2026-10-24 re-file date has moved.

**`a1leads_gbp_screen.dart` is not a counter-example, and it is worth saying
why.** A screen shipped this window that shows real Google reviews with replies
— which reads exactly like the capability the homepage says we do not have. It
is a **read-only window onto Rodrigo's A1 Leads**, admitted to five GET routes
against a service running `MIGRATION_READ_ONLY=1`, with Reply and Match
deliberately absent because *"A1 Leads is still the only writer to that Business
Profile"*. It is A-1's own data through A-1's own credential, not a customer
capability, and the file says the module is deleted the day EzBizi holds the
credential itself.

### `/download/windows.json`, stores and the plugin directory

Manifest `0.0.0252`, `built` `92dd881e`, which is production and `main`'s tip.
Play returns `200`. The Apple lookup returns `resultCount` **1**, `trackId`
6805979795, version **0.0.0222**, `currentVersionReleaseDate` 2026-09-17 —
**unchanged since 09-18, now thirty client releases behind Windows**, and that
is a fact about the store rather than about this site: both pages are correct
either way. `api.wordpress.org` still serves the plugin at **1.10.0**, so the
five-widgets row stands.

### New this window, deliberately unpublished

- **Workiz as an integration and as a lead source** — the section above.
- **The Workiz job/client/team mirror**, its five-minute projection and the
  history importer — a mirror of another product is not a capability of ours.
- **Scheduling's service-area horizon, the derived zip map and the hours grid** —
  configuration surfaces under the unpublished Scheduling module.
- **Phone extensions, the extension table view and caller-typed extensions** —
  the Communication card is still silent on extensions, unchanged from 09-22.
- **Monitoring's floor-plan walls surviving a zoom, and desk assignment** —
  refinements under the published Monitoring card.

### Still owed, and all of them row counts a scheduled run cannot take

The `workiz`, `website_chat`, `facebook` and `instagram` lead counts with
`is_test = 0`; the resolved ring-group fraction; `training_attempts` /
`training_progress` / `training_assignments`. The 09-18 figures relayed by grok
are now five days old and were not re-measured here. Ask by hand.

## 2026-09-24: `/privacy/` said Meta only publishes posts, and it carries customers' messages

**Production is `0.0.0267` at `4f270c35`** (`/download/windows.json`), one commit
behind `main` (`67897e04`, Workiz). All seven pages, `robots.txt` and
`sitemap.xml` were byte-identical to `www/` before this edit, so `41ee08b5`
published. No desk asked: the scheduled run cannot `send_message`, as before.

**Raised by mail `0063` from the Meta API session, verified here before acting.**
The only Meta mention on `/privacy/` was the social-posting sentence, and
"these are all of them" did not name Meta. Against the deployed tree:
`WhatsAppWebhookController` at `v1/social/whatsapp/webhook`, `WhatsAppMessage`
declaring `counterparty` CONTACT and `body` CONTENT with a 24-month window, and
`MetaMessagesWebhookController` + `LeadConversation` (`withParent`) for
Messenger/Instagram. The exhaustive list was falsified again, by an integration
that never reads this page — the row added on 09-23 predicted exactly this.

Published: a **WhatsApp, Facebook and Instagram messages** paragraph under
*Calls and messages* (conditional on connecting for the Meta DMs, two separate
retention rules), and a **Meta Platforms** bullet in *Who we rely on*.
`Last updated` / `dateModified` → 2026-09-24; sitemap `lastmod` for `/privacy/`
moved from a stale 2026-09-03.

Gate output after the edit: `check-site.py` ok - 7 pages, 7 sitemap entries;
forbidden-terms (LINE-split) ok - 4 terms, none present.

Stores: Play `200`; Apple `resultCount` 1, version 0.0.0222 (unchanged since
09-17); WordPress directory still **1.10.0**, so the five-widgets row stands.

Still owed, unchanged: the lead counts by source with `is_test = 0`, the
resolved ring-group fraction, the Training row counts.

## 2026-09-25: `/privacy/` said the microphone was iPhone-only and Google got job locations only

**Production is `0.0.0283` at `ef7e7b0f`** (`/download/windows.json`), which is
`main`'s tip. All seven pages, `robots.txt` and `sitemap.xml` were byte-identical
to `www/` before this edit, so today's two Field privacy commits published. No desk
asked: a scheduled run cannot `send_message`, as before.

**Two load-bearing sentences were false, both found by reading the code the
sentence depends on rather than a diff.**

1. *"On iPhone, the microphone … We ask for nothing else"*. Android requests the
   microphone and the Phone permission group at runtime (`voice_device_mobile.dart`
   `setup()`, reached only when the server issues a voice token). The row's own
   test read the repo manifest, which cannot see a plugin's runtime request —
   so it passed three runs in a row while false. Now: *"The microphone … and on
   Android also the phone permission, which Android requires before an app can
   place or take a call — both only if your organization uses the phone features."*
2. *"sends Google Maps Platform the addresses and locations of the jobs"*.
   `FieldController::plan()` also sends a tracked person's current position as the
   route origin. Now names that one position; *"Your location trail is not sent to
   Google"* stays, and is still true.

Plus a **Google Maps Platform** bullet in *Who we rely on* — `google_maps` is a
`config/services.php` key the exhaustive list did not name, the 09-23 row's
prediction coming true a third time. Sitemap `lastmod` for `/privacy/` → 2026-09-25
(it lagged today's earlier edit).

Gate: `check-site.py` ok - 7 pages, 7 sitemap entries; forbidden-terms (LINE-split)
ok - 4 terms, none present.

**The scheduling do-not row's test flipped and the row is held** — see the row.

Stores: Play `200`; Apple `resultCount` 1, version 0.0.0222 (unchanged since
09-17); WordPress directory **1.10.0** while our estate runs 1.20.5, so the
five-widgets row stands. Unchanged at `ef7e7b0f`: `Publishers::for()` five,
`NewsletterSenders::for()` Mailchimp only, `monitoring.control_indicator`
`default: false`, `BusinessProfileReviews` still 0 QPM.

**Deliberately unpublished this window:** Customer Experience (Reviews and
Business Profile moved out of Marketing — the A-1 GBP screens are A1 Leads' data,
not a customer capability); Android placing calls (outbound is not the do-not
row's "a call reaches an Android handset", and the site says nothing about mobile
calling); the incoming-call card; the typed-question AI answer; the plugin's
widget options (not in the directory).

Still owed, unchanged: lead counts by source with `is_test = 0`, the resolved
ring-group fraction, the Training row counts — and now the Scheduling question in
its row.

## 2026-09-26: nothing shipped, and yesterday's privacy fix is still not live

**Production is still `0.0.0283` at `ef7e7b0f`** (`/download/windows.json`); no
commit has landed on `main` since `fcf9e96a` (inbox `0065`), so nothing new
could make a claim false. No desk asked: a scheduled run cannot `send_message`.

**`/privacy/` and `sitemap.xml` are live at `0509dfe5`, byte for byte** — a
stale deploy, not a hand edit (it matches a historical commit exactly). So
`6c4b354a`'s two corrections (Android microphone + phone permission; one current
position sent to Google as a route start) and the Google Maps Platform bullet
have been unpublished ~24h, and `0065` has no reply. The other six pages and
`robots.txt` match `www/`. Not re-filed: `0065` already says what to publish and
how to verify; a second file would only be noise.

Gate: `check-site.py` ok - 7 pages, 7 sitemap entries; forbidden-terms
(LINE-split) ok - 4 terms, none present; `index-landmines.sh` 0 stale, 0 live.

Stores: Play `200`; Apple `resultCount` 1, version 0.0.0222; WordPress
directory 1.10.0, so the five-widgets row stands.

Still owed, unchanged: lead counts by source with `is_test = 0`, the resolved
ring-group fraction, the Training row counts, the Scheduling question.

## 2026-09-27: nothing shipped, and the privacy fix is ~48h unpublished

**Production is still `0.0.0283` at `ef7e7b0f`** (`/download/windows.json`); no
commit on `main` since `9a8e0048`, so nothing new could make a claim false. No
desk asked: `send_message` is unavailable to a scheduled run.

**`/privacy/` still matches `0509dfe5` and `sitemap.xml` matches `1cf017c8`
(same bytes as `0509dfe5`'s)**, both exactly: a stale deploy, not a hand edit.
Live `/privacy/` `Last-Modified` is 2026-09-25 19:36 GMT. `6c4b354a`'s
corrections are unpublished for a second day and inbox `0065` has no reply. Not
re-filed. The other six pages and `robots.txt` match `www/`.

Gate: `check-site.py` ok - 7 pages, 7 sitemap entries; forbidden-terms
(LINE-split) ok - 4 terms, none present; `index-landmines.sh` 0 stale, 0 live.

Stores: Play `200`; Apple `resultCount` 1, version now **0.0.0277** (was
0.0.0222 yesterday).

**Published 2026-09-28** at `3996218c` by the desk (`0066`): `6c4b354a`'s privacy
corrections and the Field-admin sentence. Verified here, not from the mail: all
seven pages, `robots.txt` and `sitemap.xml` byte-identical to `www/` before this
run's edit.

## 2026-09-28: 21 releases, and two `/privacy/` sentences had gone false

**Production is `0.0.0304` at `20f2d3ff`** (`/download/windows.json`), `main`'s
tip less the queue commit. 177 commits since `ef7e7b0f`; release notes
`0.0.0284`-`0.0.0304` walked against the load-bearing table.

1. *"— unless your organization turns it off — a copy of a notification"*.
   `notifications.email_enabled` is now `default: false` (Jose, 2026-09-28).
   Now *"if your organization turns it on"*.
2. *"campaign performance figures: cost, clicks, impressions and conversions"*.
   `CampaignPerformance` has also requested six optional metrics since
   `5b27593b` (09-24). Now *"such as cost, clicks, impressions, interactions and
   conversions, and the value of those conversions"*.

Sitemap `lastmod` for `/privacy/` → 2026-09-28 (it lagged today's desk publish).

**Checked, unchanged at `20f2d3ff`:** `FieldTracking::isTracked` has no admin
exclusion (matches the published page); `monitoring.control_indicator`
`default: false`; `app/Mail` + `app/Notifications` hold eight classes, the new
`WorkHoursReportMail` covered by "an automation rule can email a saved report";
`google_speech` is configured (whether `GOOGLE_SPEECH_KEY` is set on production was not measured); no camera/photos/contacts/calendar
permission in the three files.

**Deliberately unpublished:** Messenger/Instagram DMs into Leads (do-not row,
count not taken); Instagram comment replies; website contact form into Leads
(plugin 1.21.0, directory still 1.10.0 — the Web management card already says
it in conditional form); SEO Helper; Reports > Google Ads revenue per dollar.

Gate: `check-site.py` ok - 7 pages, 7 sitemap entries; forbidden-terms
(LINE-split) ok - 4 terms, none present. Stores: Play `200`; Apple
`resultCount` 1, version 0.0.0277; WordPress directory 1.10.0.

Still owed: lead counts by source with `is_test = 0` (now incl. facebook /
instagram), the resolved ring-group fraction, the Training row counts, and the
Scheduling count above.

## 2026-09-29: 14 releases, nothing on the site went false

**Production is `0.0.0318` at `a9577f42`** (`/download/windows.json`). Release
notes `0.0.0305`-`0.0.0318` walked against the load-bearing table. No desk
asked: `send_message` is unavailable to a scheduled run.

**Today's three edits are already live** (`7cf26ffd` Leads address to Google,
`3114e1aa` Whisper, `d348a86d` automated first reply): all seven pages,
`robots.txt` and `sitemap.xml` byte-identical to `www/`.

**The finding is in this file, not on the site: Estimates shipped** — see the
Quotes/estimates row. The site says nothing about estimates, so nothing is wrong;
it stays unsold on purpose.

**Checked, unchanged at `a9577f42`:** the Overbooking warning email is raised as
an automation trigger, so "an automation rule can email … a message the
organization writes" covers it. New `WelcomeSetPassword` (A1 Tools accounts;
sends nothing without `--send`) redeems through the reset page — the account-mail
paragraph lists kinds, not "all of them", so it stays true; add "or a first
password" if it is ever sent widely. `config/services.php` gained only
`whisper` (on our box, no processor). No camera/photos/contacts/calendar
permission in the three files or `client/lib`. HR pay rates and emergency
contacts (`0.0.0309`) and the WordPress widget form options (plugin, directory
still 1.10.0) are not described anywhere on the site.

Gate: `check-site.py` ok - 7 pages, 7 sitemap entries; forbidden-terms
(LINE-split) ok - 4 terms, none present; `index-landmines.sh` 0 stale, 0 live.
Stores: Play `200`; Apple `resultCount` 1, version 0.0.0283; WordPress
directory 1.10.0.

Still owed: lead counts by source with `is_test = 0` (incl. facebook /
instagram), the resolved ring-group fraction, the Training row counts, the
Scheduling count, and now an `estimates` row count.

## 2026-09-30: 15 releases, and the Google Ads API page said we never pause campaigns

**Production is `0.0.0333` at `de57c819`** (`/download/windows.json`) = `main`'s
tip. Release notes `0.0.0319`-`0.0.0333` walked against the load-bearing table.
No desk asked: `send_message` is unavailable to a scheduled run.

**The finding: `/google-ads-api/` was false since 2026-09-24.** It said "There is
one write to Google" (the reply) and "EzBizi does not create, modify or pause
campaigns". `5b27593b` (09-24) added `POST ads/campaigns/status` →
`CampaignPerformance::setStatus` → `campaigns:mutate`, and today `68cfa5e7`
put Pause/Resume in Leads › Manage too. Its usage table also listed a
"Local Services API — lead status … Write" row that no code does (the prose on
the same page says status is never written back) and omitted the real reply
write. Fixed: two writes named, a `campaigns:mutate` status-only row and an
`appendLeadConversation` row replace the phantom one; the not-list now reads
"does not create or remove campaigns, or change anything about a campaign other
than pausing or resuming it"; Last updated / `dateModified` / sitemap
`lastmod` → 2026-09-30. Every clause read from the code: `ads_performance.manage`
gate, `updateMask: status`, audit `ads_performance.campaign_status_changed`
with user and campaign, and no automation or command calls `setStatus`
(`AdsCampaignWatch` only reads). Jose's commit says the pause was "declared to
google as well on reports"; the page is now consistent with that.

**Checked, unchanged:** `FieldMaps::drive()` (new, `5600d4e5`) is the "how long
you are from a job" case the privacy page already names; `matrix()` is now live
for the same case. `monitoring.control_indicator` and
`notifications.email_enabled` both `default: false`. `config/services.php`,
`app/Mail`, `app/Notifications` unchanged. `battery_plus` / `webview_flutter`
add no permission; the manifest gained only an https VIEW intent query.
Campaign spend by channel is still campaign figures ("such as" holds).

**Deliberately unpublished:** Leads dashboard, reply templates with
`{{availability}}`, morning digest trigger, transcript search, campaign pause in
Manage (on the API page only, not sold); plugin 1.23.x robot check (directory
still 1.10.0); estimates (row above).

Gate: `check-site.py` ok - 7 pages, 7 sitemap entries; forbidden-terms
(LINE-split) ok - 4 terms, none present. Before the edit all seven pages,
`robots.txt` and `sitemap.xml` were byte-identical to production (incl. this
morning's `69932f83` privacy change). Stores: Play `200`; Apple `resultCount` 1,
version 0.0.0283; WordPress directory 1.10.0.

Still owed: lead counts by source with `is_test = 0` (incl. facebook /
instagram), the resolved ring-group fraction, the Training row counts, the
Scheduling count, an `estimates` row count.

## 2026-10-01: 14 releases, and the sub-processor list had missed Photon since August

**Production is `0.0.0347` at `0b4297f4`** (`/download/windows.json`). Release
notes `0.0.0334`-`0.0.0347` walked against the load-bearing table. No desk
asked: `send_message` is unavailable to a scheduled run. `0074` confirms the
09-30 Google Ads API page published; verified independently below.

**The finding: `/privacy/`'s "these are all of them" omitted Photon (komoot,
OpenStreetMap).** `AddressLookupController` (since `39add55f`, 2026-08-24),
`CountyLookupController` and `SchedulingLocationsController::nearest` (`6b7c282f`,
0.0.0347) send the typed address text from our server to
`photon.komoot.io` — now including a new hire's home address (onboarding address
search, 0.0.0341) and a customer address/ZIP for the nearest branch. No key, so
`config/services.php` never showed it. Added a Photon bullet (what is sent: the
typed text only; `q`, `limit`, `lang`, our User-Agent); Last updated /
`dateModified` / sitemap `lastmod` → 2026-10-01 (sitemap had lagged at 09-29).
The load-bearing row now names a host grep as well.

**Checked, unchanged at `0b4297f4`:** no new permission (three files, `client/lib`
request calls; `printing` adds none); 0.0.0344's photo pick is the iPhone
*browser*. W-4/W-9 and saved signatures fall under "onboarding documents and
signed agreements". Onboarding's new-hire link email and the new automation email
look: the email paragraph lists kinds, not "all of them". AI Assistance's Ask
calls nothing external (`AssistantController` has no `Http::`).
`notifications.email_enabled` and `monitoring.control_indicator` untouched.

**Deliberately unpublished:** Scheduling Locations tab, live-chat visitor
photos/typing, Leads Completed state, Inventory history, HR dashboard, Training
"Continue" (Training row's tense rule unchanged), estimates (row above).

Gate: `check-site.py` ok - 7 pages, 7 sitemap entries; forbidden-terms
(LINE-split) ok - 4 terms, none present. Before the edit all seven pages,
`robots.txt` and `sitemap.xml` were byte-identical to production. Stores: Play
`200`; Apple `resultCount` 1, version 0.0.0318; WordPress directory 1.10.0.

Still owed: lead counts by source with `is_test = 0` (incl. facebook /
instagram), the resolved ring-group fraction, the Training row counts, the
Scheduling count, an `estimates` row count.

## 2026-10-02: 2 releases, and `/privacy/` had not caught up with remote file access

**Production is `0.0.0349` at `f2b575e1`** (`/download/windows.json`). `0088`
(desk) confirms yesterday's Photon edit published in `0.0.0348`'s web leg;
verified independently: all seven pages, `robots.txt` and `sitemap.xml` were
byte-identical to `www/` before today's edit. No desk asked: `send_message` is
unavailable to a scheduled run.

**The finding: Monitoring's read-only Files tab (`0.0.0348`) was not on the
"set out here in full" monitoring section.** A role holding
`monitoring.files.view` can list folders and download files (≤5 MB) on a
monitored computer; no path sandbox beyond Windows ACLs; every list/download
audited with its path; the agent draws no notice. Added one clause to the
live-view sentence, and "or browsing its files" to the no-notice sentence. The
Lead Developer security exemption also blocks it, but the page's per-person
exemption sentence was NOT extended to files - that exemption is a different
mechanism and was not checked. New load-bearing row.

**Also: Scheduling's Availability search now sends a typed street address to
Google** (`0f175402`, `AddressCheck` → `maps.googleapis.com/.../geocode`), so
the Google Maps bullet's "in Leads … Nothing else from the conversation is
sent" was incomplete. Added "And in Scheduling: a street address somebody
types to see which branch covers it, to find its ZIP code." Last updated /
`dateModified` / sitemap `lastmod` → 2026-10-02.

**Checked, unchanged at `f2b575e1`:** no new outbound host in the window's
`app`/`client/lib` diff; `config/services.php`, `app/Mail`,
`app/Notifications`, manifests and `pubspec.yaml` untouched; no new runtime
permission request. Onboarding's signed-contract PDF is "onboarding documents
and signed agreements". Roles' "Seen on leads", Automations copies/chips,
tiles-over-tables layouts: nothing the site says.

**Deliberately unpublished:** Monitoring Files on the landing card (privacy
only, like live view's detail), estimates (row above).

Gate: `check-site.py` ok - 7 pages, 7 sitemap entries; forbidden-terms
(LINE-split) ok - 4 terms, none present. Stores: Play `200`; Apple
`resultCount` 1, version 0.0.0318; WordPress directory 1.10.0.

Still owed: lead counts by source with `is_test = 0` (incl. facebook /
instagram), the resolved ring-group fraction, the Training row counts, the
Scheduling count, an `estimates` row count.

## 2026-10-03: nothing shipped, and yesterday's privacy edit is unpublished

**Production is still `0.0.0349` at `f2b575e1`** (`/download/windows.json`); the
only commits since are this file's and `d3165650`. No desk asked:
`send_message` is unavailable to a scheduled run.

**`/privacy/` and `sitemap.xml` differ from production, and it is a stale
deploy, not a hand edit:** live `/privacy/` matches `9dc00d74` exactly
(`Last-Modified` 2026-10-02 19:04 UTC, before `d3165650` at 23:06 UTC). No
release has run since, so `0089` (still in the inbox, unanswered) rides the
next one. The other six pages and `robots.txt` are byte-identical.

Gate: `check-site.py` ok - 7 pages, 7 sitemap entries; forbidden-terms
(LINE-split) ok - 4 terms, none present; `index-landmines.sh` 0 stale, 0 live.
Stores: Play `200`; Apple `resultCount` 1, version 0.0.0318; WordPress
directory 1.10.0.

Still owed: lead counts by source with `is_test = 0` (incl. facebook /
instagram), the resolved ring-group fraction, the Training row counts, the
Scheduling count, an `estimates` row count.

## 2026-10-04: nothing shipped, and the privacy edit is ~48h unpublished

**Production is still `0.0.0349` at `f2b575e1`** (`/download/windows.json`); no
commits since 10-03's entry. No desk asked: `send_message` is unavailable to a
scheduled run. `0089` is still in the inbox, unanswered.

**`/privacy/` and `sitemap.xml` still differ, still a stale deploy:** live
`/privacy/` matches `9dc00d74` exactly (`Last-Modified` 2026-10-02 19:04 UTC).
No release since, so the 10-02 edit (Monitoring Files, Scheduling geocode) rides
the next one. The other six pages and `robots.txt` are byte-identical.

Gate: `check-site.py` ok - 7 pages, 7 sitemap entries; forbidden-terms
(LINE-split) ok - 4 terms, none present; `index-landmines.sh` 0 stale, 0 live.
Stores: Play `200`; Apple `resultCount` 1, version **0.0.0347** (was 0.0.0318);
WordPress directory 1.10.0.

Still owed: lead counts by source with `is_test = 0` (incl. facebook /
instagram), the resolved ring-group fraction, the Training row counts, the
Scheduling count, an `estimates` row count.

## 2026-10-05: twelve releases, nothing on the site went false, and the privacy backlog is live

**Production is `0.0.0361` at `5d455440`** (`/download/windows.json`; main is
at `0.0.0363`). No desk asked: `send_message` is unavailable to a scheduled
run. `0090` (desk) confirms the 10-02 edit published in `0.0.0350`; today's
transcription disclosure (`fd3d93ad`, written by another session off `0093`)
is live too — `/privacy/` `Last-Modified` 2026-10-05 22:21 UTC, "Last updated 5
October 2026". All seven pages, `robots.txt` and `sitemap.xml` are
byte-identical to `www/`.

**Walked `f2b575e1..5d455440` against the load-bearing table; nothing moved:**
no new outbound host (only TikTok, a connected account); no manifest,
`Info.plist`, `pubspec.yaml` or runtime-permission change; `config/services.php`
gained only `workiz.retire_deleted`; `SettingRegistry` gained
`leads.office_hours`, `leads.photos_email`, `hr.onboarding_approval` — the two
defaults the site leans on are untouched; `app/Mail`/`app/Notifications`: only
retries on `PasswordResetLink`. RingCentral and Workiz v2 are "the accounts you
connect". Monitoring's drive list is still list/download only.

**Deliberately unpublished:** Memberships (join page, visit booking, plan
prices — shipped, no row count; no payment taken), Marketing QR Codes, call
report transcripts on any landing card (privacy only), estimates (row above).

Gate: `check-site.py` ok - 7 pages, 7 sitemap entries; forbidden-terms
(LINE-split) ok - 4 terms, none present; `index-landmines.sh` 0 stale, 0 live.
Stores: Play `200`; Apple `resultCount` 1, version 0.0.0347; WordPress
directory 1.10.0.

Still owed: lead counts by source with `is_test = 0` (incl. facebook /
instagram), the resolved ring-group fraction, the Training row counts, the
Scheduling count, an `estimates` row count.

## 2026-10-06: sixteen releases, and the privacy page now names Anthropic and xAI before their switch

**Production is `0.0.0377` at `d6f7b986`** (`/download/windows.json`). Before
this run's edit all seven pages, `robots.txt` and `sitemap.xml` were
byte-identical to `www/`; `67b7bbc3` (QR scans, another session) is live —
`/privacy/` `Last-Modified` 2026-10-06 21:21 UTC.

**The finding was in the inbox, addressed to this role:**
`docs/agents/inbox/0094-reports-to-website-privacy-call-transcripts-to-anthropic-and-xai.md`.
`0.0.0373` (`b5dcedfa`) built Performance-report call labelling with Claude
and Grok; it sends the transcript TEXT to `api.anthropic.com` (Anthropic SDK)
and `api.x.ai`, stores one label (`call_report_labels`, 12 months), and is
off until `REPORT_CALL_TAGGING`. Checked in the code, not taken from the note:
both taggers send only the instruction and `"Transcript:

".$transcript`;
the transcripts are RingCentral calls (`RingCentralCallLog`), not Google user
data. **Written conditionally** ("where call labelling is switched on") so it
is true now and after the switch; added a paragraph under Calls and messages
and an "Anthropic and xAI" sub-processor entry. **Not written:** "neither
company keeps the transcript" — the note says the providers are "not asked to
keep anything", which is not the same as not keeping it.

**Walked `5d455440..d6f7b986` against the load-bearing table; nothing else
moved:** `config/services.php` unchanged; no manifest / `Info.plist` /
`pubspec.yaml` change; `SettingRegistry` gained only
`ai_assistance.ask_new_contacts` (a WhatsApp menu, no LLM call); Monitoring
Files still `list`/`download` only (`0.0.0367` lets an empty path mean the
drives). Website-form CAPTCHA (`723fc8f6`) is EzBizi's own proof-of-work, no
third party. QR `deactivated_at` is on `qr_codes`, not `qr_code_scans`.

**Deliberately unpublished:** Workiz invoices / payments / estimates mirrored
in `0.0.0368` (Workiz's records, not EzBizi billing — the do-not row holds),
in-app I-9 and direct deposit, QR stats, Memberships.

Gate: `check-site.py` ok - 7 pages, 7 sitemap entries; forbidden-terms
(LINE-split) ok - 4 terms, none present. Stores: Play `200`; Apple
`resultCount` 1, version 0.0.0347.

## 2026-10-07: ten releases, nothing on the site went false

**Production is `0.0.0387` at `71578003`** (`/download/windows.json`), which is
`main`. All seven pages, `robots.txt` and `sitemap.xml` are byte-identical to
`www/`. No new inbox item addressed to this role; no desk asked
(`send_message` is unavailable to a scheduled run).

**Walked `d6f7b986..71578003` (65 commits) against the load-bearing table;
nothing moved:** `config/services.php`, manifest, `Info.plist`,
`pubspec.yaml`, `SettingRegistry`, `Publishers`, `app/Services/Ads`,
`Reports/Tagging`, Monitoring `FilesController` and the SMS strings are
untouched; no new outbound host in `app/`; no new runtime permission request.
The one migration is `alerts` (Communication ▸ Alerts: sender, message,
timestamp). The "live camera view" in `0.0.0381` is the existing
user-entered-URL camera viewer (since `775dd8af`, 2026-09-01), not a new
processor.

**Deliberately unpublished:** Communication alerts, RingCentral missed-call
notifications, API key activity log, Dashboard Templates, camera live view,
plus everything held on 10-06.

Gate: `check-site.py` ok - 7 pages, 7 sitemap entries; forbidden-terms
(LINE-split) ok - 4 terms, none present; `index-landmines.sh` 0 stale, 0 live.
Stores: Play `200`; Apple `resultCount` 1, version **0.0.0363** (was 0.0.0347);
WordPress directory 1.10.0.

Still owed: lead counts by source with `is_test = 0` (incl. facebook /
instagram), the resolved ring-group fraction, the Training row counts, the
Scheduling count, an `estimates` row count.

## 2026-10-08: ten releases, nothing on the site went false

**Production is `0.0.0397` at `17f64cde`** (`/download/windows.json`). All
seven pages, `robots.txt` and `sitemap.xml` are byte-identical to `www/`;
`1c089b43` (Cloudflare Turnstile under Who we rely on, another session) is
live. No desk asked (`send_message` is unavailable to a scheduled run); the
one inbox item, `0103`, is UI/UX's tab-level note to module owners and touches
nothing in `www/`.

**Walked `71578003..17f64cde` (88 commits) against the load-bearing table;
nothing moved:** `config/services.php`, manifest, `Info.plist`,
`pubspec.yaml`, `Publishers`, `app/Services/Ads`, `Reports/Tagging`,
Monitoring `FilesController` and the SMS strings untouched; no new outbound
host or runtime permission request. `SettingRegistry` changed only the paper
size default (a4 → letter); `monitoring.control_indicator` and
`notifications.email_enabled` are still `false`. `InvitationLink` gained a
line pointing at `/download/` — still the invitation the privacy page names.

**Deliberately unpublished:** website booking widget (`web_bookings` — a
visitor asks for an area/day/window, a dispatcher approves; it is not a job,
so the Scheduling row holds), short review links (`review_codes`), plus
everything held on 10-07.

Gate: `check-site.py` ok - 7 pages, 7 sitemap entries; forbidden-terms
(LINE-split) ok - 4 terms, none present; `index-landmines.sh` 0 stale, 0 live.
Stores: Play `200`; Apple `resultCount` 1, version 0.0.0363; WordPress
directory 1.10.0.

Still owed: lead counts by source with `is_test = 0` (incl. facebook /
instagram), the resolved ring-group fraction, the Training row counts, the
Scheduling count, an `estimates` row count.

## 2026-10-09: seven releases, and "Pinterest sends now" was false on production

**Production is `0.0.0404` at `25dbe9b3`** (`/download/windows.json`). Before
this run's edit all seven pages, `robots.txt` and `sitemap.xml` were
byte-identical to `www/`; today's three `www/` commits by other sessions
(`e850c807` YouTube, `ef8d1480` offline/Automations, `41cc00bd` xAI) are live.
No desk asked (`send_message` is unavailable to a scheduled run); row counts
were taken read-only on the box instead.

**The finding: Pinterest refuses EzBizi's Pins, and the site said it sends.**
`975d1d59` says it outright — Trial-access apps may not create Pins on
`api.pinterest.com`. Production `social_post_targets` for `pinterest`: one
`failed` 2026-10-09 15:36 with Pinterest's own refusal (*"Apps with Trial access
may not create Pins in production"*), one `sent` 17:37 — **to the sandbox**:
`services.pinterest.sandbox_token` is SET on production, so every tenant's
Pinterest post now goes to `api-sandbox.pinterest.com` with the app owner's
token and never reaches the tenant's Pinterest. Pinterest moved from "Sends
now" to "Posting waits on Pinterest" on `/social-media/`, and out of the
"send today" list on `/`. Counts "five of the nine" → "four".

**Also corrected, the understatement direction:** `b78751c1` + `8b232f86`
gave LinkedIn, YouTube, X and Bluesky publishers (in `0.0.0404`), so "sending
is not built. The tick box is greyed" and "EzBizi refuses the post" were false.
**Not promoted to "sends"**: 0 `social_post_targets` rows for any of the four.
Worded "newly built and not yet proven on a customer's account". Promote each
on its first real `sent` row. Only `facebook_page` has ever had a `sent` row
(1); Instagram, Threads and TikTok have none either — left as they are, because
nothing shows a network refusing them, and the rule above pulls on evidence of
refusal, not on absence of sends.

**Walked `17f64cde..25dbe9b3` (98 commits) against the rest of the table:**
`GrokTagger` gained a Grok CLI path — still xAI, still instruction + transcript
only, so the call-labelling sentence holds. `FilesController` refactor only
(still `list`/`download`). `pubspec.yaml` added `desktop_drop` (no permission).
`config/services.php`: Pinterest sandbox + `grok_cli` (xAI, already listed).
New hosts in `app/`: LinkedIn, X, Bluesky, YouTube, Pinterest sandbox — all
networks a customer connects.

**Deliberately unpublished:** Report Builder, HR signature fix, plus everything
held on 10-08. Flagged to Marketing as `0107`: the sandbox token routes every
tenant's Pinterest post to the app owner's sandbox.

Gate: `check-site.py` ok - 7 pages, 7 sitemap entries; forbidden-terms
(LINE-split) ok - 4 terms, none present. Stores: Play `200`; Apple
`resultCount` 1, version 0.0.0363; WordPress directory 1.10.0.

Still owed: lead counts by source with `is_test = 0` (incl. facebook /
instagram), the resolved ring-group fraction, the Training row counts, the
Scheduling count, an `estimates` row count.

## 2026-10-10: nothing shipped, and the Pinterest correction is still unpublished

**`main` has not moved since `c30238b2` (2026-10-09 19:07); production is
`0.0.0404` at `25dbe9b3`.** Five pages, `robots.txt` and `sitemap.xml` were
byte-identical to `www/`; `/` matches `ef8d1480` and `/social-media/` matches
`7349e798` exactly — a stale deploy, not a hand edit. **So `710b8887` is not
live and the site still says Pinterest sends.** Re-measured on production:
`services.pinterest.sandbox_token` still set; Pinterest `failed` 2, `sent` 1
(sandbox); `facebook_page` `sent` 1; nothing for LinkedIn/YouTube/X/Bluesky.
The correction is still right. `0108` (publish ask) is still in the inbox.

**Changed: SMS frequency aligned to the app**, on Phone's `0101` — see the
SMS row in *Load-bearing sentences*. `/privacy/` and `/terms/` "Last updated"
and `dateModified` moved to 2026-10-10, and their `sitemap.xml` `lastmod`
(which had lagged the page edits since 10-02 / 09-03) moved with them.

Gate: `check-site.py` ok - 7 pages, 7 sitemap entries; forbidden-terms
(LINE-split) ok - 4 terms, none present. Stores: Play `200`; Apple
`resultCount` 1, version 0.0.0363; WordPress directory 1.10.0.

Still owed: lead counts by source with `is_test = 0` (incl. facebook /
instagram), the resolved ring-group fraction, the Training row counts, the
Scheduling count, an `estimates` row count.

## 2026-10-12: nothing shipped, and the Pinterest correction is ~3 days unpublished

**`main` has not moved since `5a64288f` (2026-10-10 19:05); production is
`0.0.0404` at `25dbe9b3`.** Live `/` matches `ef8d1480`, `/privacy/`
`41cc00bd`, `/terms/` `d8d79d49`, `/social-media/` `7349e798`, `sitemap.xml`
`d3165650` — each exactly, so a stale deploy (live `Last-Modified` 2026-10-09
20:19 UTC), not a hand edit. `710b8887` and `5a64288f` are not live: the site
still says Pinterest sends. `/download/`, `/google-ads-api/`,
`/delete-account/`, `robots.txt` are byte-identical to `www/`.

**The deploy desk was reachable this run** — `send_message` to
`local_072bee23…` delivered — and was asked to publish and for row counts
(Pinterest sandbox token, `sent` rows by provider, the do-not-list). **It answered:** it published
`5a64288f` (covers `710b8887`; re-verified here — all nine live files now
byte-identical to `www/`), nothing shipped since `0.0.0404`, Pinterest
sandbox token still set, and `social_post_targets` by provider:
`facebook_page` 1 sent, `pinterest` 1 sent (sandbox) + 2 failed, **`tiktok`
1 failed**, nothing else. The desk closed `0108` itself (`0087bec6`).

**The finding: TikTok had never sent, and the site said it sends to drafts.**
Its one row (2026-08-28, after the drafts publisher of 08-27) reads *"TikTok
refused it: Please review our integration guidelines…"*. And the mechanism
the page described is gone: `4f8ee29b` (10-05, deployed) rebuilt it for
Direct Post, and production `config('services.tiktok.direct_post')` is
**`true`** — so "to the account's drafts", "ours has not had [the audit]" and
"the account row says this before you press send" were all unverifiable or
false. Moved TikTok into the "not yet proven" group by the rule above (pull on
a refusal; add on a real `sent` row): `/` "Facebook Pages, Instagram and
Threads send today"; `/social-media/` "three of the nine", "For the other
six", TikTok row rewritten; page date and `sitemap.xml` `lastmod` 2026-10-12.
**Whether TikTok's audit actually passed was not established** — setting
`TIKTOK_DIRECT_POST` is not evidence of it. Promote TikTok on its first real
`sent` row.

Gate: `check-site.py` ok - 7 pages, 7 sitemap entries; forbidden-terms
(LINE-split) ok - 4 terms, none present; `index-landmines.sh` 0 stale, 0 live.
Stores: Play `200`; Apple `resultCount` 1, version 0.0.0363; WordPress
directory 1.10.0.

Still owed: lead counts by source with `is_test = 0` (incl. facebook /
instagram), the resolved ring-group fraction, the Training row counts, the
Scheduling count, an `estimates` row count.

**Published the same day at `e2f56916`** (desk; `/`, `/social-media/`,
`sitemap.xml` re-verified byte-identical here). The desk has no record of a
TikTok audit pass; its only rule is that `TIKTOK_DIRECT_POST=true` is safe only
with the composer's per-account choices. Audit status asked of Marketing
(`local_067b17d7…`), which owns it.
Marketing does not know either; the flag and publisher are **API
Integrations'** (`local_e76e374a…`, author of `4f8ee29b`), asked next. Note
for whoever reads a refusal as the audit's verdict: the one TikTok row
(2026-08-28) predates Direct Post (10-05), so **nothing on production has yet
exercised Direct Post at all** — the row says nothing about the audit.

**ANSWERED by API Integrations, and it made "connect today" false for TikTok:**
TikTok's app review has **not been submitted**, so no Direct Post audit either.
`TIKTOK_DIRECT_POST=true` exists only to record the review demo, and
**production runs TikTok's SANDBOX keys** — only the sandbox user `ezbizi_us`
can connect, posting `SELF_ONLY`. So no customer can connect TikTok at all.
Narrowed the same day: `/social-media/` meta description drops TikTok, "Nine
can be connected" → "Eight … TikTok is the ninth, and a business can connect it
once TikTok has reviewed EzBizi's app", TikTok row "Not yet open to connect";
`/` "posting to the others is still being proven" (was "the rest connect").
`/privacy/` keeps TikTok in its "for example" list — a disclosure, over-wide is
the safe direction. **Inverts in two steps, both from API Integrations:**
production keys move off sandbox (connectable) → then a real `sent` row (sends).

**Published at `a83ce119`** (desk; `/` and `/social-media/` re-verified byte-identical here). Nothing on this run is left unpublished.

**Later the same day, Pinterest INVERTED, on this table's own test.** API
Integrations relayed Standard access approved (email 2026-10-12). Measured on
production rather than taken: `services.pinterest.sandbox_token` is now
**unset**, and `social_post_targets` `01a13007…` is `sent` 2026-10-12 12:13 UTC,
`remote_id` 1129910994053982207, from A1-Group's real connection; the Pin URL
answers `200`. Both halves of the inversion condition hold. `/` "Facebook Pages,
Instagram, Threads and Pinterest send today"; `/social-media/` "four of the
nine", "For the other five", Pinterest row "Sends now, images only — video
publishing does not exist yet". Still no usage claim: one tenant, Jose's own.

**Published at `ded18bf2`** (desk; `/` and `/social-media/` re-verified byte-identical here). Nothing left unpublished.

## 2026-10-12 evening: nine releases, nothing on the site changes

Desk `0115`, measured ~23:10 UTC: production web **0.0.0413 at `78f458a6`**
(0405–0413 since morning). All 7 pages live = repo (`/privacy/` = `280de455`,
Last-Modified 22:17 UTC); `check-site.py` ok; forbidden terms ok - 4, none.
`/download/windows.json` 0.0.0412 is the 0413 native leg still building — not stuck.
Play 200; Apple lookup `resultCount` 1, `0.0.0363`.

- Today's four xAI privacy additions: review drafts and speaker split **live**;
  Monday summary code live, rule inactive (first run 2026-10-19); FB campaign
  names code live, **0** `facebook_ads` connections; call **scoring OFF**
  (`REPORT_CALL_SCORING` unset, `call_report_scores` 0). Page ahead of the
  switch = over-disclosure, the safe direction. Leave as is.
- **Training: `training_attempts` = 50** (0 new today). Built-and-UNUSED is no
  longer true. The card's imperatives stay true; the tense rule still forbids
  any usage claim ("teams train on it") — 50 attempts is not a customer base.
- Leads: `facebook` 4, `instagram` 1 (a tester). Messenger/Instagram row
  **stays held** — customer DMs wait on Meta's round two; `is_test` split of
  the 4 not taken. Ask for `WHERE is_test = 0` before inverting.
- Social: pinterest sent 1, tiktok 0, no new publisher. Ring groups not measured.

## 2026-10-13: AI, API keys and MCP on the homepage

Jose asked for it. New `/` card **AI & the API**, a Growth-card sentence on AI
reply drafts, a FAQ "Can an AI work in EzBizi?" (visible `<details>` and
FAQPage JSON-LD), and the `SoftwareApplication` description. Every clause
is in the Load-bearing row above with its test; both live facts were read on
production through the API, not from the repo. Deliberately NOT claimed:
claude.ai/ChatGPT connectors (no OAuth), call scoring, the Monday summary,
review drafts. Privacy needed no change: "audit entries showing who changed
what" already covers the key activity log, and the customer's own AI tool is
their choice of recipient, not our processor. `sitemap.xml` `/` lastmod 2026-10-13.

## 2026-10-13 evening: two privacy commits unpublished, nothing new to claim

Live `/` , `/terms/`, `/social-media/`, `/download/`, `/delete-account/`,
`robots.txt`, `sitemap.xml` are byte-identical to `www/`. **`/privacy/` matches
`323a93e0` and `/google-ads-api/` matches `ac795a4e` exactly — a stale deploy,
not a hand edit:** live `Last-Modified` 21:01 UTC precedes `ff16feb1` (21:44 UTC,
WhatsApp staff questions can send any record the asker sees to xAI) and
`5e5bacd1` (21:45 UTC, call recordings to staff over WhatsApp). Both are
disclosures the live pages lack — the under-disclosure direction — so asked the
desk to publish. `sitemap.xml` `lastmod` for `/privacy/` (was 10-10) and
`/google-ads-api/` (was 09-30) moved to 2026-10-13 to match the pages'
`dateModified`. `/download/windows.json` 0.0.0425 (`ba4eb0e6`).

Gate: `check-site.py` ok - 7 pages, 7 sitemap entries; forbidden-terms
(LINE-split) ok - 4 terms, none present; `index-landmines.sh` 0 stale, 0 live.
Stores: Play `200`; Apple `resultCount` 1, version 0.0.0363.

Still owed: lead counts by source with `is_test = 0`, the resolved ring-group
fraction, the Scheduling count, an `estimates` row count.

## 2026-10-14: the in-app AI tab started reading app data, and the pages said WhatsApp only

Production `/download/windows.json` 0.0.0435 at `939fdb3a`; 0.0.0426–0.0.0436
landed today. All 9 live files were byte-identical to `www/` at the start of
this run (`/privacy/` Last-Modified 22:02 UTC, `c8fd1896` live).

**The finding:** `5eb754c8` (in production since 0.0.0432) makes
`AssistantController` fall through to `AppAsk::answer($user, …)` when the
Library has no answer — so a question typed into the **AI tab in the app**
now sends any record the asker may see to xAI, exactly the WhatsApp staff-line
path. `/privacy/` (Calls and messages, Google section, Who we rely on) and
`/google-ads-api/` named only the WhatsApp staff line: under-disclosure, live
since this afternoon. Widened all four sentences to "in the AI tab or on the
WhatsApp staff line"; `/google-ads-api/` date and both `sitemap.xml` lastmods
to 2026-10-14. The Library paragraph (first step, Library + branches) stays
true as the first thing that happens. **Any third caller of `AppAsk::answer`
makes these sentences incomplete — `grep -rn "AppAsk::answer" app`.**

Nothing new to claim: Task Board permissions, routing road lines, scoring
weights (scoring itself already disclosed) and Sales lead dates change no
sentence. Desk `0122` counts (10-13 23:05 UTC): `training_progress` 64,
`phone_ring_groups` 3, local_services leads 45,670; no new social publisher.

Gate: `check-site.py` ok - 7 pages, 7 sitemap entries; forbidden-terms
(LINE-split) ok - 4 terms, none present. Stores: Play `200`; Apple
`resultCount` 1, version 0.0.0363.

## 2026-10-15: 0.0.0437-0.0.0442 shipped, nothing on the site went false

Production `/download/windows.json` 0.0.0442 at `eb3f4656` (= main). All 9 live
files byte-identical to `www/`; `0128` is live (`/privacy/` Last-Modified 21:15 UTC).

Checked against today's commits, no edit needed:
- `a2264488` crash/freeze/failed-call reports go to our own `POST /client-issues`
  (`client_issues`, 3 months, `ClientIssueScrubber`) - no new processor; "error
  diagnostics" under What we collect already covers it. A native crash file is
  sent on the next launch, i.e. while the app is in use.
- RingCentral is now an organization's own connection (`8a640f19`, `54305ddb`);
  `/privacy/` already says "a business's own RingCentral account where it has
  connected one". New host `platform.ringcentral.com` = an account the customer connects.
- `947e6cc2` adds `GoogleBusinessProfilePublisher` to `Publishers::for()` - **not a
  sending network**: Business Profile quota is 0 until Google approves. Do not add
  it to "Four networks send today"; the row's test is a `sent` row, not the publisher.
- `660508eb` reads A-1 Chimney's reviews on A1 Leads' grant - one tenant, interim;
  "reading and answering its reviews is waiting on Google" stays true for EzBizi.
- `92a55324` edit/reschedule/delete-from-network: unsold capability, no sentence wrong.
- `AppAsk::answer` callers still two (AssistantController, WhatsAppStaffBot).

Gate: `check-site.py` ok - 7 pages, 7 sitemap entries; forbidden-terms (LINE-split)
ok - 4 terms, none present; `index-landmines.sh` 0 stale, 0 live. Stores: Play
`200`; Apple `resultCount` 1, 0.0.0363. Counts asked as `0129`.

## 2026-10-16: 0.0.0443-0.0.0444 live, nothing on the site went false

Production `/download/windows.json` 0.0.0444 at `f13ac816`. Desk silent on `0129`
(counts re-asked in `0131`). Live = repo for every `www/` file except `/privacy/`,
which matches `c1dfbf1a` exactly (Last-Modified 18:45 UTC) — a stale deploy, not a
hand edit: `6d82a948` (WhatsApp actions skip the second factor) landed 20:19 UTC and
describes `AppAct`, which ships in 0.0.0446, so the page is ahead of the release and
rides it. Checked the paragraph against `AppAct::COMMANDS`: true; row added above.
`sitemap.xml` `/privacy/` lastmod 10-14 -> 10-16 to match `dateModified`.

Checked against today's commits, no edit needed:
- `1cd6618b` copies A-1's own Airtable into `airtable_records` hourly - an account the
  customer connects, no new processor; the site names neither Workiz nor Airtable.
- `d77c8371`/`6712c115` Reviews: Google's own replies counted; A-1 interim grant only.
  `BusinessProfileReviews` docblock still says 0 QPM, so "waiting on Google" stays.
- `342f2a2a`, RC call tagging, help-video storage: no sentence on the site touched.
- WordPress directory still 1.10.0 - widgets row stays held.

Gate: `check-site.py` ok - 7 pages, 7 sitemap entries; forbidden-terms (LINE-split) ok
- 4 terms, none present. Stores: Play `200`; Apple `resultCount` 1, 0.0.0363.

## 2026-10-17: nothing shipped, nothing on the site went false

Production `/download/windows.json` still 0.0.0444 at `f13ac816`. Main gained only
`4d3e66ed` (geocode coordinates moved off the lead payload; Photon already listed)
and a Field test — no sentence touched. Live = repo except `/privacy/` and
`sitemap.xml`, both still exactly `c1dfbf1a` (Last-Modified 10-16 18:45 UTC): the
stale deploy recorded yesterday, `6d82a948` rides 0.0.0446. `AppAsk::answer`
callers still two. Desk silent on `0131`; not re-asked, its counts still stand.

Gate: `check-site.py` ok - 7 pages, 7 sitemap entries; forbidden-terms (LINE-split)
ok - 4 terms, none present; `index-landmines.sh` 0 stale, 0 live. Stores: Play
`200`; Apple `resultCount` 1, 0.0.0363.

## 2026-10-18: nothing shipped, nothing on the site went false

Production `/download/windows.json` still 0.0.0444 at `f13ac816`; main gained
nothing since `1cccfb13`. Live = repo except `/privacy/` and `sitemap.xml`, both
still exactly `c1dfbf1a` (Last-Modified 10-16 18:45 UTC) — the same stale deploy;
`6d82a948` rides 0.0.0446. Desk still silent on `0131`; not re-asked.

Gate: `check-site.py` ok - 7 pages, 7 sitemap entries; forbidden-terms (LINE-split)
ok - 4 terms, none present; `index-landmines.sh` 0 stale, 0 live. Stores: Play
`200`; Apple `resultCount` 1, now 0.0.0443 (the download card names no version).

## 2026-10-19: 0.0.0445-0.0.0455 live, and `/privacy/` did not say expense photos go to xAI

Production `/download/windows.json` 0.0.0455 at `cc889e73` (= main). Live = repo for
every `www/` file before this edit: yesterday's stale `/privacy/` published 10-19
21:08 UTC. Desk still silent on `0131`; nothing below needed its counts.

**The finding.** `f33c1fc4` (Financials › Bills) and `ab5f50cb` (the same photo sent
to the WhatsApp staff line) send a **photo** of a branch's expense list to xAI
(`BillReader::read` → `GrokCli::ask` with the image bytes). `/privacy/` listed every
other xAI use and not this one, so the sub-processor list was exhaustive and short
again. Added a "Reading a photo of expenses" paragraph under Calls and messages and a
clause to the Anthropic-and-xAI sub-processor line. Retention stated is the model's
own (`FinancialBill::retention()` 84 months, "as tax records are").

- **Worded without the word "bill"** on purpose: `bill` is a publish-gate term that
  guards against claiming billing. These are a business's own expenses, not billing a
  customer; do not add the forbidden term to describe them.
- **Not claimed: what happens to an unsaved photo.** `FinancialBillsController::keep`
  writes it to disk and only the 10-minute cache *token* expires; nothing deletes the
  file. Do not write "discarded after ten minutes" — it is not true in the code.
- The AI-tab paragraph's "never audio, images or files" stays: it is scoped to staff
  questions (`AppAsk`), and the photo reader is a separate path.

Checked, no edit: WhatsApp Embedded Signup (`4d0a0a84`) — the business's own number,
still carried by Meta; Leads Stats Google map tiles (`35696199`) — tiles only, no new
data class; `BusinessProfileReviews` still documents 0 QPM, so "waiting on Google"
stays; recordings download, Settings redesign, RC Calls tagging — no sentence touched.

Gate: `check-site.py` ok - 7 pages, 7 sitemap entries; forbidden-terms (LINE-split) ok
- 4 terms, none present. Stores: Play `200`; Apple `resultCount` 1, 0.0.0443.

## 2026-10-20: 0.0.0456-0.0.0464 live, and `/privacy/` said "no camera" and "the video is on its own equipment"

Production `/download/windows.json` 0.0.0464 at `76e6d53e` (= main). Live = repo for every
`www/` file before this edit: `005d2600` published (desk `0133`, grep -> 1).

**Two `/privacy/` sentences went false today, both from Monitoring/Jobs work.**
- `199538a9` adds a camera to the Android and iOS apps (job photos, `ImageSource.camera`
  only). "We ask for nothing else: no camera" was false the moment a store build carries
  it. Now: "only when you tap to take a photo for a job, the camera — your iPhone asks
  first; on Android the phone's own camera app takes the picture." True before and after
  the store build ships. Device-permissions row above rewritten with the test.
- The camera bridge (`eea59de4`..`7f9a8373`): an EzBizi app on a site PC relays that
  site's cameras through the box (MediaMTX -> go2rtc -> MJPEG, `deploy/camera-relay/`),
  and `CameraBridgeController::still` keeps one still per camera on `local` disk
  (`camera-stills/<built-in function id>.jpg`, overwritten every 10 minutes). "We store the address …
  the video is the organization's, on its own equipment" no longer covered it. Now says
  the video passes through our server while watched and is not kept (`mediamtx.yml` has
  no record path; hls/webrtc off), and that one still per camera is kept, each replacing
  the last. **Not claimed:** deletion of a still when a camera or site is removed — nothing
  deletes `camera-stills/` in the code. **Re-check when phase 3 (recordings: clip download
  uploaded through the bridge) lands** — "EzBizi does not record" must then say clips a
  person downloads pass through, and whether they are kept.

Desk `0133` counts (10-20): local_services 46,706, thumbtack 18, facebook 11, instagram 1;
estimates 2,231 (still held: billing/payments do not exist and the term is gated);
**youtube sent 1, tiktok failed 1** — one YouTube `sent` row is not yet a customer's
proven account (org not named), so "newly built and not yet proven" stays; ask the org
before inverting. Ring groups not measured. No new social publisher, no Training/Scheduled
Jobs change asked of the site.

Checked, no edit: Workiz web-session extension and one-token Airtable — accounts the
customer connects, neither named on the site; Complaints, job notes/tags/files, HR delete,
Task Board, Automations tag/sub-status steps — no sentence touched. `AppAsk::answer`
callers not re-counted.

Gate: `check-site.py` ok - 7 pages, 7 sitemap entries; forbidden-terms (LINE-split) ok -
4 terms, none present. Stores: Play `200`; Apple `resultCount` 1, 0.0.0443.

## 2026-10-21: 0.0.0465-0.0.0471 live, invoices on main and not yet live, nothing on the site went false

Production `/download/windows.json` 0.0.0471 at `044b1ceb6`. All 14 live `www/` files
are byte-identical to the repo, which means `0134` (the camera privacy edit) is live.

- **Invoices** (`5e98257a`, `2b447afa`, release notes 0.0.0472) are on main and not in
  `044b1ceb6`. They make the "not built" reason in the do-not-list row false. The verdict
  holds; the row now carries a note saying so. Sending goes through the business's own
  mail server, which "EzBizi send mail on its behalf" already covers.
- **Disconnect now needs an authenticator code** (`6652afda`, live). Every site sentence
  that says "disconnect at any time" or "deletes … immediately" is still true.
- **New host `www2.census.gov`** (`SchedulingLoadZipCentroids`): EzBizi downloads a public
  ZIP gazetteer from it and sends no data, so it is not a processor.
- PTO, Project Management, push wording, Workiz web session, branch pickers: no sentence
  on the site touched. No permission or `ImageSource` change in the client.

Desk counts (10-21, production): `invoices` table absent (0.0.0472 building at
`e9b877b67`), so 0 invoices; `training_progress` 68; ring groups 5 of 14 by the COLUMN
only (the resolved fraction was not measured, so quote neither); newest real local_services
lead 10-21 22:52 UTC, 47,202 total; `social_post_targets` facebook_page sent 1, instagram
sent 1, pinterest sent 2 / failed 2, tiktok failed 1, youtube sent 1, so 5 providers and
no 7th publisher. **The YouTube `sent` row is Triplex Group (formerly A1-Group), Jose's own
org.** "Newly built and not yet proven" stays for one checkable reason: an unaudited YouTube
API project's uploads are locked to private. Invert only when that video is confirmed public. Gate: `check-site.py` ok - 7 pages, 7 sitemap entries; forbidden-terms
(LINE-split) ok - 4 terms, none present. Stores: Play `200`; Apple `resultCount` 1, 0.0.0461.

## 2026-10-22: 0.0.0472-0.0.0483 live, invoices now on production, nothing on the site went false

Production `/download/windows.json` 0.0.0483 at `f72e0f361`. All 10 live `www/` files
byte-identical to the repo, including the new **`/support/`** (`67d6a48ce`, another
session, for the Google Workspace Marketplace listing: email, postal address, in-app
Suggestions; in the sitemap). Its "Suggestions inside the app" line was left alone: the
Suggestions row's hold was about selling it, and the feature is live and pinned
(`client/lib/main.dart`); this is a support route, not a capability claim.

- **Invoices are LIVE** (`5e98257a` is an ancestor of `f72e0f361`). The do-not-list
  verdict still holds: gate terms, no real-tenant row count yet (asked in `0135`).
  `InvoiceChannels` sends through what the company connected — its mailbox (Google /
  Microsoft) or own mail server (`throughTenantProvider`, its first caller), its own
  Twilio number, its WhatsApp number — so no new processor. `/privacy/`'s "Those leave
  from EzBizi's own address" is about automation mail (`ActionRunner` still
  `platformForTenant`) and stays true.
- **Open, not a falsehood: `/privacy/` "Google user data" covers only Google Ads.**
  Gmail (`gmail.readonly` / `.modify` / `.send`) and, since 0.0.0475, Workspace admin
  (`admin.directory.user.readonly`, `gmail.settings.basic`) are Google user data too,
  and mailbox contents can reach xAI via AppAsk. Nothing on the page is false, but a
  Workspace Marketplace reviewer will look for a Gmail / Limited Use disclosure. Not
  written by this run: a Limited Use attestation is a legal claim to Google about the
  xAI path, which is Jose's call. Mail is read live (no message store; only
  seen/dealt/snooze markers), which is the fact a section would start from.
- Email reply/forward, PTO, Payroll API, Project Management, search bars, Scheduling
  catch-all areas: no sentence on the site touched.

Gate: `check-site.py` ok - 8 pages, 8 sitemap entries; forbidden-terms (LINE-split) ok
- 4 terms, none present. Stores: Play `200`; Apple `resultCount` 1, 0.0.0461; WordPress
directory 1.10.0 (widgets row stays held). Counts asked as `0135`.

## 2026-10-23: 0.0.0484-0.0.0489 live, and `/privacy/` did not name the tech assistant or Telegram

Production `/download/windows.json` 0.0.0489 at `b28f1c9e8`. Before this edit all 15 live
`www/` files were byte-identical to the repo. No answer to `0135` in the inbox.

- **The finding: two new parties-and-paths shipped in 0.0.0488 and `/privacy/` named neither.**
  `34ea9c3d2` (Tech Assistant) sends xAI the org's name, matching parts rows, manual pages,
  up to five Library answers, the tech's unit memory (brand/model/serial/fuel/ignition,
  parts discussed) and any photo (rating plate); `TechAssistantController` sends a manual PDF
  to xAI when local extraction fails. `ai_assistance.tech_mode` **defaults to true**.
  `eca6a6551` routes staff WhatsApp photos and parts questions to it — so the AppAsk
  paragraph's "never audio, images or files" is true only of AppAsk, and now sits beside a
  path that does send images. `35d9b46b9` adds a Telegram bot (`TELEGRAM_ORG_ID`, one
  organization, staff link by sharing their own phone number) — **Telegram** was missing
  from "these are all of them". Fixed: a "tech assistant" and a "Telegram" paragraph under
  Calls and messages, the xAI list item extended, a Telegram list item, Last updated /
  `dateModified` / sitemap lastmod 2026-10-23. Not measured: whether the bot token is set
  on production (`TELEGRAM_BOT_TOKEN`) — the copy says "where it is set up", which holds
  either way, matching the 10-06 precedent of disclosing before a switch-on.
- PM overhaul, PTO accrual/timeline, HR contract drafts, review reply pacing, missed-call
  focus: no sentence on the site touched.

Gate: `check-site.py` ok - 8 pages, 8 sitemap entries; forbidden-terms (LINE-split) ok
- 4 terms, none present. Stores: Play `200`; Apple `resultCount` 1, 0.0.0461; WordPress
directory 1.10.0 (widgets row stays held). Publish asked, and `0135`'s counts re-asked, as `0136`.

## 2026-10-24: 0.0.0490 live, nothing on the site went false, `/privacy/` edit still unpublished

Production `/download/windows.json` 0.0.0490 at `38beebb10` (only the PM review-reason
filter: `ecba203c9`, `88a32efb0`; no sentence on the site touched). 13 of 15 live `www/`
files byte-identical to the repo; `/privacy/` and `sitemap.xml` match `e8403d2f5^` exactly,
so they are yesterday's tech-assistant/Telegram edit not yet published, not a revert:
`e8403d2f5` was committed 23:06 UTC, after the 0.0.0490 publish (`Last-Modified` 22:11 UTC).
No release since, and `0136` (publish + `0135` counts) is still unanswered in the inbox, so
no new ask was filed; the next release of `main` publishes it.

Gate: `check-site.py` ok - 8 pages, 8 sitemap entries; forbidden-terms (LINE-split) ok
- 4 terms, none present; `index-landmines.sh` 0 stale, 0 live. Stores: Play `200`; Apple
`resultCount` 1, 0.0.0461.

## 2026-10-25: nothing shipped, `/privacy/` edit ~48h unpublished

Production `/download/windows.json` still 0.0.0490 at `38beebb10`; `main` gained nothing
since `3844c4096`. 13 of 15 live `www/` files byte-identical to the repo; `/privacy/` and
`sitemap.xml` still exactly `e8403d2f5^` (`Last-Modified` 10-23 22:11 UTC) — the same
unpublished tech-assistant/Telegram edit, not a revert. `0136` still unanswered in the
inbox; not re-filed, the next release of `main` publishes it.

Gate: `check-site.py` ok - 8 pages, 8 sitemap entries; forbidden-terms (LINE-split) ok
- 4 terms, none present; `index-landmines.sh` 0 stale, 0 live. Stores: Play `200`; Apple
`resultCount` 1, 0.0.0461.

## 2026-10-26: 0.0.0491-0.0.0494 live, and `/privacy/` said Telegram was EzBizi's bot carrying only the tech assistant

Production `/download/windows.json` 0.0.0494 at `428654263`. Before this edit all live
`www/` files were byte-identical to the repo, so `0136` (tech assistant / Telegram) and
`2d2567128` (footer Support / Delete account links) are live; `/privacy/` `Last-Modified`
10-26 19:54 UTC.

- **The finding, two understatements in one paragraph set.** `0.0.0491`
  (`TelegramConnectController`): the bot is each organization's own, connected by pasting
  its BotFather token — not "EzBizi's Telegram bot". `0.0.0493` (`StaffAnswer`, called from
  `TelegramStaffBot:143`): a Telegram question now runs tech assistant -> Library -> AppAsk,
  so a Telegram answer can carry any record the asker may see, and that passes through
  Telegram. Fixed: AppAsk paragraph and xAI list item name the Telegram bot; tech-assistant
  and Telegram paragraphs say the organization's own bot; Telegram list item says its
  answers can include any record the person may see. Last updated / `dateModified` /
  sitemap lastmod 2026-10-26.
- Windows signed by EzBizi LLC (0491): no sentence on the site says unsigned. Gmail scope
  narrowed to one (0493): the site names no Gmail scope. Fleet / Inventory General, PM-2,
  PTO requests, Inspection testing, Reviews inbox, Overbooking reschedule texts (company's
  own number), Settings › Pending, Services cards: no sentence touched.
- `0.0.0495` (RGB lighting, AppAsk across companies) is on main, not live; nothing on the
  site names either.

Gate: `check-site.py` ok - 8 pages, 8 sitemap entries; forbidden-terms (LINE-split) ok
- 4 terms, none present. Stores: Play `200`; Apple `resultCount` 1, 0.0.0461. Desk not
reachable from a scheduled run; publish asked as an inbox file.

## 2026-10-27: 0.0.0495-0.0.0498 live, and `/privacy/` did not say xAI work can go to Anthropic

Production `/download/windows.json` 0.0.0498 at `67c8089d1`. Before this edit all 10 live
`www/` files were byte-identical to the repo, so `0137` (Telegram, `06e20da37`) is live;
`/privacy/` `Last-Modified` 10-27 19:15 UTC.

- **The finding: `eb2e455d2` (0.0.0498) made AI a provider switch.** `GrokCli::provider()`
  with `AI_PROVIDER` default `auto` sends every GrokCli caller — every "sent to xAI" sentence
  on `/privacy/` — to Anthropic (Claude) whenever the Grok CLI is not configured. Not
  measured which one production resolves to; disclosed either way (10-06 precedent). Added a
  "When xAI is not available" paragraph and a clause in the Anthropic and xAI list item.
- **Report files on WhatsApp** (`f903e323d`, 0.0.0498): the message and branch names go to
  xAI to parse; the PDF/Excel goes over WhatsApp (Meta). Added a paragraph mirroring
  "Call recordings on WhatsApp", and named report files in the xAI and Meta list items.
  Last updated / `dateModified` / sitemap lastmod 2026-10-27.
- **Call recordings — left alone, on purpose.** 0.0.0498 shipped Phone and Google LSA
  recordings kept on EzBizi's disk (`4b19932ce`, `7d9cfd4e8`, `c0bb3b012`), contrary to
  "the audio is downloaded, turned into text, and deleted". Jose reversed it the same day
  ("I don't want you saving any call recordings"): the Phone revert `9d2ac4eef` is IN
  `67c8089d1`; the Leads revert `f1c2ec849` (+ migration deleting `lead-recordings/`) is on
  main for 0.0.0499, not live. The page states the intended state and is true again once
  0.0.0499 ships; do not write "EzBizi keeps recordings" anywhere. If 0.0.0499 has not
  shipped by the next run, say so.
- RGB lighting, AI across companies, CX takeover, Book panel, native technician roster,
  service-area drawing (still 0 customer setups not measured — the coverage row stands),
  Lead source results, Workiz outbound mirror (Workiz is a connected account), Airtable
  disconnect-safety: no sentence on the site touched.

Gate: `check-site.py` ok - 8 pages, 8 sitemap entries; forbidden-terms (LINE-split) ok
- 4 terms, none present. Stores: Play `200`; Apple `resultCount` 1, 0.0.0461. Desk not reachable from a scheduled run; publish asked as `0138`.

## 2026-10-28: 0.0.0499-0.0.0502 live, nothing on the site went false

Production `/download/windows.json` 0.0.0502 at `e3addf158` (= main). All 10 live `www/`
files byte-identical to the repo, so `0138` (xAI-to-Anthropic, report files) is live;
`/privacy/` `Last-Modified` 10-28 21:36 UTC.

- **Call recordings: the 10-27 caveat is closed.** The Leads revert `f1c2ec849` is an
  ancestor of `e3addf158`; 0.0.0499 "never copied into EzBizi". "Downloaded, turned into
  text, and deleted" and "any call recording the organization chooses to keep" (processed,
  not stored) are true as written.
- **WhatsApp for a business's own number (0.0.0502) — held, measured.** 0 numbers connected
  through signup on production; the WhatsApp row above carries the count and the inversion
  test. The marketing pages say nothing about WhatsApp; `/privacy/` already covers an
  organization sending and receiving WhatsApp messages.
- Email filters and speed, Task Board, PM-2 tabs, per-company ZIPs/slots, state picker,
  Move appointment, lock-screen skip, Core automations, Reports downloads: no sentence on
  the site touched.

Gate: `check-site.py` ok - 8 pages, 8 sitemap entries; forbidden-terms (LINE-split) ok
- 4 terms, none present. Stores: Play `200`; Apple `resultCount` 1, 0.0.0461. No `www/`
edit, so nothing to publish.

## 2026-10-29: 0.0.0503-0.0.0506 live, and `/privacy/` said nothing about a review is posted without a person

Production `/download/windows.json` 0.0.0506 at `dd01cf3b0`. Before this edit all 10 live
`www/` files were byte-identical to the repo; `/privacy/` `Last-Modified` 10-29 21:55 UTC.

- **The finding: "Nothing is posted until a person reads the draft" has been false in code
  since `5e886cf2b` (10-20, live).** A rule's `review.publish_reply` step posts an
  AI-written reply on Google by itself where a listing's automatic replies are on. It cannot
  succeed today only because Business Profile quota is 0 — a sentence true by Google's grace,
  with the approval pending. Also understated who triggers xAI: bulk Draft with AI (0.0.0506)
  and rule steps with `ai: true`. Rewrote the paragraph and the xAI list item; Last updated /
  `dateModified` / sitemap lastmod 2026-10-29. Google user data section NOT edited — see the
  Review drafts row: it needs a Business Profile purpose the day quota lands.
- Homepage "reading and answering its reviews is waiting on Google to grant Business Profile
  API access" (×3): still true — `BusinessProfileReviews` docblock unchanged, case re-filed
  10-26.
- Daily "Job assignments" WhatsApp text and Reports Workspace daily texts: technician names
  and counts over WhatsApp, no model (`DailyTexts`) — covered by "send and receive WhatsApp
  messages" and the Meta line. RingCentral transcripts for every account: the page already
  says "a business's own RingCentral account". WhatsApp to a business's own number arriving
  in Leads (0.0.0505): row unmeasured this run, held. Meta ad-question bubble, source colours,
  Workiz outside-company credit, PM-2 columns, Task Board sync, Monitoring online fix, Email
  connected-page copy: no sentence on the site touched.

Gate: `check-site.py` ok - 8 pages, 8 sitemap entries; forbidden-terms (LINE-split) ok
- 4 terms, none present. Stores: Play `200`; Apple `resultCount` 1, 0.0.0461. Desk not
reachable from a scheduled run; publish asked as `0139`.

## 2026-10-30: 0.0.0507-0.0.0513 live, nothing on the site went false

Production `/download/windows.json` 0.0.0513 at `27b215107` (= main). All 10 live `www/`
files byte-identical to the repo, so `0139` (review auto-reply) is live.

- **A1 Leads reader removed** (`config/services.php` lost `a1leads`): the site never named
  A1 Leads; the sub-processor list is unaffected. **eKomi reviews** (0.0.0507) are read from
  an organization's own connected eKomi account (`EkomiReviews`, shop id + password on an
  `ekomi` connection): inbound, covered by "the accounts you connect".
- **WhatsApp media "streamed, never stored"** (0.0.0507): consistent with `/privacy/`'s "we
  store what each message says". New lead columns (`handle`, `avatar_url` as a URL,
  `referral`, `form_answers`, WhatsApp `profile_name`/`saved_name`) are enquiry details kept
  with the lead; the paragraph is not exhaustive, so not false. **Hidden leads excluded from
  AI** narrows what reaches xAI; every xAI sentence is still true.
- WhatsApp row: still held (no customer-number count taken this run). Reports Performance /
  date bar / Sources ticks, Workiz job sales read-in, Field map colours, Business Profile bulk
  WhatsApp link, phone-app downloads (share sheet, no new permission in manifests), Leads
  layout, 7,000 lines removed: no sentence on the site touched.

Gate: `check-site.py` ok - 8 pages, 8 sitemap entries; forbidden-terms (LINE-split) ok
- 4 terms, none present. Stores: Play `200`; Apple `resultCount` 1, 0.0.0502. No `www/`
edit, so nothing to publish.

## 2026-10-31: nothing shipped, nothing on the site went false

Production `/download/windows.json` 0.0.0513 at `27b215107`; main has only the 10-30
website commit since. All 10 live `www/` files byte-identical to the repo. WhatsApp row:
still held, not re-measured (nothing shipped to move it).

Gate: `check-site.py` ok - 8 pages, 8 sitemap entries; forbidden-terms (LINE-split) ok
- 4 terms, none present. Stores: Play `200`; Apple `resultCount` 1, 0.0.0502. No `www/`
edit, so nothing to publish.

## 2026-11-01: nothing shipped, nothing on the site went false

Production `/download/windows.json` 0.0.0513 at `27b215107`; main has only the 10-31
website commit since. All 15 live `www/` files byte-identical to the repo. WhatsApp row:
still held, not re-measured (nothing shipped to move it).

Gate: `check-site.py` ok - 8 pages, 8 sitemap entries; forbidden-terms (LINE-split) ok
- 4 terms, none present. Stores: Play `200`; Apple `resultCount` 1, 0.0.0502. No `www/`
edit, so nothing to publish.

## 2026-11-02: 0.0.0514-0.0.0519 live, and three sentences went false on the first WordPress day

Production `/download/windows.json` 0.0.0519 at `3848d575f`. First run against WordPress:
edits went to the WP pages (wp-cli, `--user=ezbizi_admin`), not `www/`. Pre-edit copies of
post_content / `_ezb_head` for pages 4, 8, 9 and `sitemap.xml` are in `~/ezb-wp-bak-20261102/`
on the websites box. **`www/` was NOT updated** — it still carries all three false sentences, so
rolling back to the static copy re-publishes them.

- **Business Profile works on production.** `ezbizi:review-backlog` (`0541f7b07`, daily 15:00)
  PUT a reply to Google v4 at 20:36 UTC (`review_replies` automation/published); 610 Google
  reviews with a `connection_id` were created today. So the homepage "reading and answering its
  reviews is waiting on Google to grant Business Profile API access" (×3: two in the body, one in
  the FAQ JSON-LD) was false. **Pulled, not replaced**: now "A Google Business Profile can be
  connected too." / "Ads brings its Local Services enquiries in as leads." Re-adding "read and
  answer reviews" is allowed (the row-100 test passed) but was not done on one day's evidence.
  The `BusinessProfileReviews` docblock still says 0 QPM — stale. **Owed:** the `/privacy/`
  Google user data section names no Business Profile purpose, and review text read through our
  Business Profile OAuth now reaches xAI — Google API module's call, not edited here.
- **`/privacy/` "only to a review less than 30 days old"** was false: the backlog job posts AI
  replies by itself to up to 50 four- and five-star reviews a day of any age (auto-reply listings,
  60 on), and drafts ≤3-star ones on every listing — xAI triggered with nobody pressing anything.
  Rewrote the paragraph and the xAI list item; Last updated / `dateModified` / sitemap 11-02.
- **`/social-media/` "We do not: Read or reply to direct messages, or use Messenger"** was false:
  `leads` source facebook 77, instagram 14, `is_test = 0`, newest today. Line removed; date
  bumped. The Messenger/Instagram do-not row's count test now passes; the Growth card was NOT
  extended (Meta app status not checked this run).
- Messenger/Instagram photos streamed not stored, YouTube delete (`youtube.force-ssl`), Google
  login recorded on connections, Leads rail, Scoring, Task Board, Development tab, web `--wasm`:
  no other sentence on the site touched.

Gate: `check-site.py` ok - 8 pages, 8 sitemap entries (repo copy); forbidden-terms (LINE-split)
over the 8 LIVE pages ok - 4 terms, none present. Stores: Play `200`; Apple `resultCount` 1,
0.0.0502. WP pages 5, 6, 7, 10, 11 unmodified since the 11-02 import. Desk not asked: there is
no publish step left for it.

## 2026-11-03: 0.0.0520-0.0.0525 live, and `/privacy/`'s xAI list missed two new sends

Production `/download/windows.json` 0.0.0525 at `c32d81b3a`. All 8 live pages match their WP
post_content (only theme-rewritten logo `src` differ). Another session added a true "Job-visit
recordings" paragraph to `/privacy/` at 18:49 UTC (checked: Whisper on our box, chunk deleted
after transcribing, 1-day sweep, `KEEP_DAYS = 90`, Grok summary, consent-state refusal) but not
to the xAI item under Who we rely on, and did not bump the date.

- **Edited WP page 8** (backup `~/ezb-wp-bak-20261103/` on the websites box): new "Weekly Google
  posts" paragraph (`WeeklyBusinessProfilePosts::write` sends business name, city/state, category
  and recent posts; `auto_post` default false, 0 listings on, 0 drafts yet) and both sends added to
  the xAI item; Last updated / `dateModified` / sitemap lastmod 11-03. `www/` not updated (still
  frozen at the 11-02 import).
- **Booking bot held, measured:** 0 rules use `book_by_chat` — see the new load-bearing row; the
  first one falsifies `/google-ads-api/`.
- Estimates Board (0.0.0525) is a do-not row; nothing added. STOP/START per company concerns
  tenant texts, not the sign-in code sentence. Personal Google mail sign-in retired: no page names
  it. Customer tags, Integrations health, Company chart, Reports overrides, Task Board views: no
  sentence touched.

Gate: `check-site.py` ok - 8 pages, 8 sitemap entries; forbidden-terms (LINE-split) over the 8
LIVE pages ok - 4 terms, none present. Stores: Play `200`; Apple `resultCount` 1, 0.0.0502.

## 2026-11-04: 0.0.0526-0.0.0530 live, and `/social-media/` said we never manage advertising

Production `/download/windows.json` 0.0.0530 at `ba84e0543`; 0.0.0531 (Google call leads
answered by SMS) is on main, not live. All 8 live pages matched their WP post_content before
the edit; no WP page had changed since the 11-03 run.

- **`/social-media/` "We do not: Run or manage advertising of any kind"** was false:
  `a24e0de6f` (0.0.0528, in `ba84e0543`) lets a business pause/resume Meta campaigns, ad sets
  and ads and change a daily budget (`MetaAdsManager`, `MetaAdsController`), and the Page
  connect now asks for `ads_management` (`FacebookOAuth`). "Collect … insight data" was
  already stretched: `MetaAdInsights::sync` stores ad spend/leads since 10-12. **Edited WP
  page 9** (backup `~/ezb-wp-bak-20261104/` on the websites box): use column gained "Show a
  business its own Facebook ad campaigns … pause, resume or change a daily budget when somebody
  at the business asks for it"; do-not column now "Create or remove ads or campaigns, change
  their targeting or creative, or change anything nobody at the business asked for" and
  "Collect audience or follower data". Last updated / `dateModified` / sitemap lastmod 11-04.
  Writes reach Meta only through the two API endpoints (no automation caller), hence "asks
  for it" rather than "presses". Not measured: whether any grant holds `ads_management` or
  Meta has approved it — the sentence describes what the app does, not that anyone has used it.
- `/google-ads-api/` "set or adjust bids or budgets" still true: the Google side is
  `campaigns:mutate` status only (`CampaignPerformance`); budgets are Meta-only.
- Times per company time zone, Settings › Lists, Email paging, Reports audit, review replies
  for every company, WhatsApp media fixes: no sentence on the site touched. "Post anything a
  person did not write and send" left alone: weekly Google posts still `auto_post` false
  (11-03 row), not re-counted.
- `www/` not updated (frozen at the 11-02 import).

Gate: `check-site.py` ok - 8 pages, 8 sitemap entries; forbidden-terms (LINE-split) over the 8
LIVE pages ok - 4 terms, none present. Stores: Play `200`; Apple `resultCount` 1, 0.0.0502.

## 2026-11-05: 0.0.0531-0.0.0538 live, and three sentences were false or about to be

Production `/download/windows.json` 0.0.0538 at `fa411af62`; 0.0.0539 (`ccc4992c5`, ad on/off
schedules) on main, not live. All 8 live pages matched their WP post_content before the edits.
Backups of every page's post_content / `_ezb_head` and `sitemap.xml`: `~/ezb-wp-bak-20261105/` on
the websites box. `www/` not updated (frozen at the 11-02 import).

- **`/google-ads-api/` "No rule or schedule does it"** goes false when 0.0.0539 deploys:
  `ezbizi:ad-schedules` (every minute) calls `CampaignPerformance::setStatus` at the times an
  `ads_performance.manage` holder set, audited `by=schedule`. Rewritten to be true before and after
  the deploy: a person presses Pause/Resume "or sets days and times for the campaign to be switched
  on and off"; audit "the person or the schedule that made it"; table row "or at the on and off
  times a person set for it". Status only, so "nothing else" still holds.
- **`/google-ads-api/` "calls and bookings are never replied to"** was false since 0.0.0531
  (`b7d7a6e47`): the not-booked rule texts a Local Services caller from the company's cleared line
  (skipped 71 of 125 before the fix, so it runs). Now "never replied to through Google; a caller can
  instead be sent a text from the customer's own phone line, by an automation rule the customer set
  up". "Two writes to Google" still true (Twilio, not Google).
- **`/privacy/` xAI list** missed the Leads proofreader (`bdc83ca79`, `leads.proofread` default
  true): the reply being typed goes to Grok (Bedrock/Anthropic when on) for spelling/grammar
  fixes, offered and never sent by itself. Added to the xAI item. Anthropic fallback sentence
  already covers Bedrock.
- Dates: Last updated / `dateModified` / sitemap lastmod 11-05 on both pages.
- One Google sign-in for Business Profile, Ads and YouTube (`6262b4928`) asks only the ticked
  products' scopes, so the YouTube "read-only … used once" sentence was not re-read against it —
  **owed**: confirm the combined callback does not read the channel for a non-YouTube connect.
  Meta catch-up, Facebook client accounts, Integrations redesign, Gmail IMAP fill, Ads under
  Marketing: no sentence touched.

Gate: `check-site.py` ok - 8 pages, 8 sitemap entries; forbidden-terms (LINE-split) over the 8
LIVE pages ok - 4 terms, none present. Stores: Play `200`; Apple `resultCount` 1, 0.0.0502.
Desk (11-05): 0.0.0539 is LIVE (`7305be3c3`), so the schedule sentence above is now load-bearing, not
pre-emptive; nothing merged today touched Training, ring groups, Scheduled Jobs, LSA ingest or
publishers (no row counts taken).

## 2026-11-06: `/developers/` and `/developers/reference/` published (Jose, via the desk)

Jose asked (relayed by the deploy desk) for a public developer section in the shape of the one
he built for Memberly: a guide page and a Scalar reference. New WP pages 22 (`developers`) and 23
(`reference`, child of 22). Footer link "Developers" on `/` and `/download/`; sitemap +2 entries.

- **The reference is generated, never hand-written.** `GET /api/v1/openapi.json`
  (`OpenApiController`, public, throttle 60/min) is built from `McpController::routes()`, the
  table `list_endpoints` reads, so the blocked prefixes (auth, staff, mcp, me/api-keys,
  me/sessions) never appear; test `test_the_public_reference_lists_what_list_endpoints_lists_…`.
  The page fetches `/ezbizi-api.json` on www (CSP `connect-src 'self'`); the websites box copies
  the app's spec there hourly (ezbizi_ftp crontab, `refresh-api-spec.sh`, keeps the last good
  copy). Until the endpoint is released the seed copy is one generated locally from main
  (734 paths, 989 operations).
- **No key is ever typed into www**: Scalar test requests and client button hidden, no proxy, no
  telemetry. App CORS still allows only app/dev origins — do not add www to make "try it" work.
- **Load-bearing on `/developers/`** (re-check if these move): levels Viewer = GET, Editor = no
  DELETE, Full = all (`EnforceApiKeyAccess`); role grants `api_keys.view/edit/delete`; no key may
  touch password/2FA/SMS codes/sessions/keys/account deletion; keys never idle out, end on revoke
  or password reset; MCP 120/min (`routes/api.php` `mcp` throttle) and no general per-key limit;
  error codes `api_key_*`, `permission_denied`, `subscription_inactive` (402); webhook signature
  = hex HMAC-SHA256 of `timestamp.body` (`OutboundWebhook`); "Not yet: OAuth, SSE" (`McpController`
  docblock). If OAuth lands, the claude.ai/ChatGPT note goes false.
- **Checked, and deliberately left alone:** the reference lists every tenant route, so it names
  modules on the do-not list (Training, WhatsApp, invoices…) as paths. That is a route table,
  not a capability claim; the forbidden-terms gate is for page copy. The spec currently has none
  of the four terms anyway.

Gate: forbidden-terms (LINE-split) over `/`, `/download/`, `/developers/`, `/developers/reference/`
ok - 4 terms, none present. MCP/API tests 23/23 (`ApiKeysAndMcpTest`, private schema).


## 2026-11-06 (evening): 0.0.0540-0.0.0542 live, nothing on the site went false

Production `/download/windows.json` 0.0.0542 at `94a3e89d0`. All 10 live pages match their WP
post_content (only block markers and theme-rewritten logo `src` differ); pages 5, 10, 11 untouched
since the 11-02 import, 4/6/22/23 last written by the 11-06 developers publish.

- **`/developers/` reference is now the released spec, not a local seed.** `GET /api/v1/openapi.json`
  shipped in 0.0.0540 (`9aec70f44`); www `/ezbizi-api.json` (refreshed 23:17 UTC by the hourly
  `refresh-api-spec.sh`) is byte-identical to it, 739 paths. The 11-06 "until the endpoint is
  released" caveat is closed.
- Website bookings answered by a text (`57737e4de`): an EzBizi `website_booking` lead, not a Google
  channel, so `/google-ads-api/`'s "never replied to through Google" still holds.
- RingCentral callers raise `lead.received`, HR Training tab redesign (tense rule unaffected), HR
  several places, Social "Posted · deleted", Scheduling Availability, Payments plan (research only,
  no processor shipped): no sentence on the site touched.

Gate: `check-site.py` ok - 8 pages, 8 sitemap entries (repo copy, frozen); forbidden-terms
(LINE-split) over the 10 LIVE pages ok - 4 terms, none present. Stores: Play `200`; Apple
`resultCount` 1, 0.0.0502. 
**Desk (11-06 evening):** 0.0.0543 (`1c56f5bfd`) is also live; 0.0.0544 (`f53a26020`) was cutting.
Training: 0542 UI only. Ring groups, Scheduled Jobs, LSA ingest, publishers: no release touched them
(desk took no row counts; not measured here either). Re-read against both releases, no page edited:

- **Stripe Connect invoice payments (0543): dormant, with no keys on production.** No payer data reaches
  Stripe, so `/privacy/` naming no Stripe is still true. **Trigger:** the day Stripe keys land on prod or
  a company connects, `/privacy/` must name Stripe (payer data goes to it), and the invoices do-not row
  needs a fresh look. `invoic`/`bill` stay gate terms.
- **Promotions (0544): a business texts its own past Done-job customers from its line, with STOP.**
  `/terms/` "never marketing … we do not send offers, news or promotions by text" is about EzBizi's own
  sign-in-code texts (its own number, account holders only), so it still holds; the tenant clause
  "not … unsolicited marketing calls or messages" is a duty on the customer and stays consistent. No
  page says a business cannot text its customers. Do not add a Promotions card until a real send is counted.
- **Project Management 1/2 removed, project-board/jobs added (0544):** `/developers/` prose names no
  project endpoint; the reference re-reads the app's spec hourly, so it follows the deploy unedited.

Separately (relayed by the desk, Jose's word to the Website Changes session): Toula Elwell / Itzhak
Swarch off every site's Team page. That session cleaned 48 sites; re-measured all 138 WP installs on
the websites box (posts + postmeta): one leftover, calla1.com page 149 `post_content` (the
Elementor fallback; the rendered page already showed neither). Removed (backup
`~/swarch-bak-20261106/` on the websites box). Remaining matches are the town "Ponchatoula". The 9
SiteGround-era domains not served from the websites box: live pages, 0 matches.

## 2026-11-07: 0.0.0544-0.0.0548 live, nothing on the site went false

Production `/download/windows.json` 0.0.0548 at `c035699e5`. No WP page modified since the 11-06
runs (newest `post_modified_gmt` 2026-11-06 13:58); all 10 live pages match their post_content
(only theme-rewritten logo `src` differ). www `/ezbizi-api.json` byte-identical to the app's
`/api/v1/openapi.json`.

- **AI assistant reads in rounds (`d47831939`, `030be6215`, `f374ee43b`):** same `GrokCli`, same
  as-the-asker GETs, Library still tried first, so `/privacy/`'s "records that person may see"
  and xAI list still hold. No new destination.
- Project Management rebuilt (`project_board`), Task Board off for Sub Companies, Meta cost per
  lead from instant forms, report-text branch names, schedule-tick fix: no page names any of them.
- Promotions (0544) and Stripe Connect (0543): unchanged from the 11-06 triggers; not re-counted.

Gate: `check-site.py` ok - 8 pages, 8 sitemap entries (repo copy, frozen); forbidden-terms
(LINE-split) over the 10 LIVE pages ok - 4 terms, none present. Stores: Play `200`; Apple
`resultCount` 1, 0.0.0502. Desk (11-07): 0544-0548 all live; nothing on Training, ring groups, Scheduled Jobs, LSA or
publishers (no row counts); no STRIPE keys in prod `.env`, no company connected, so `/privacy/` unchanged.

## 2026-11-08: nothing shipped, nothing on the site went false

`main` and `origin/main` have no product commit since `c035699e5`, and production `/download/windows.json`
still reads 0.0.0548 there. No WP page modified since 11-06 13:58 (`post_modified_gmt`). All 10 live
pages match their post_content, apart from the logo `src` the theme rewrites. www `/ezbizi-api.json`
is byte-identical to the app's `/api/v1/openapi.json`.

Gate: `check-site.py` ok - 8 pages, 8 sitemap entries (repo copy, frozen); forbidden-terms
(LINE-split) over the 10 LIVE pages ok - 4 terms, none present. Stores: Play `200`; Apple
`resultCount` 1, 0.0.0502. Desk asked (11-08) for anything live since 0548 and for row counts on the
do-not rows; no answer had arrived when this was written. Nothing above depends on its answer.
Note for the next run: on the websites box wp-cli needs `/opt/plesk/php/8.4/bin/php /usr/local/bin/wp --path=/var/www/vhosts/ezbizi.com/httpdocs`, because `ubuntu` cannot `cd` into the docroot and there is no `php` on PATH.
