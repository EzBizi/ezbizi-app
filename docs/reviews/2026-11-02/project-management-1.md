# Project Management - 1: review, 2026-11-02

Measured on production 0.0.0513: Triplex Group, A-1 Chimney and its 9 sub companies, 19 branches.
- Every endpoint the seven tabs call, run as jose@ezbizi.com inside a transaction that was rolled back.
- Read-only queries on the box.
- `jobs:warn-paid-not-started --dry-run`.

Test record: JOB-04926. Search finds it in Scheduled.

The web app is behind sign-in, so the tabs were not clicked by hand. They were opened by the screen tests instead: all seven tabs at 1440, 1024 and 390 wide, and all 121 PM tests pass on main.

## 1. Working

- **Overview** (`/overview`, 200):
  - In progress 172; waiting review 531; ready to schedule 218; scheduled 471 (362 in the next 7 days).
  - Done, waiting payment 2,584: $6.74M total, $6.24M due.
  - Payments stopped 442; pipeline $2.39M; paid but not started 386.
- **Jobs** (`/jobs`, 200): 3,976 jobs across the five stages.
  - Every stage, plus the Pipeline drill (1,392), paid not started (386) and past 10 days (269).
  - Filters: stopped (442), redo (13), dispute (5), review reason "none", and search (JOB-04926 → 1).
  - Every count equals its list's total.
- **Timeline** (`/project-management?ids[]=`, 200): opens a job's steps, payments and status changes.
- **Assignments** (`/cards`, 200): 5 boards, 7 cards. See Broken 2.
- **Repair team**: Financials' report, production cost and table all answer 200 for New Jersey, each in under 130 ms.
- **Rules** (`/rules`, 200): one setup for all 19 branches, no warnings. Done, waiting payment reads "done pending approval, any money; or Done, money still due" (0.0.0502).
- **Logs** (`/logs`, 200): 2,165 rows in 7 days (535 succeeded, 2 failed, 1,628 skipped).
- **Settings**:
  - Stage table with "+ Or" rows; scope (5 statuses, partly paid only off since 10-28).
  - Redo and dispute words; warning 10 days to info@a-1chimney.com; stopped 30 days.
- **Paid-not-started email** (daily 08:20 ET): the dry run would send 1 email listing 269 jobs.
- **Estimate-aging trigger** (daily 08:10 ET): 6,605 events in 7 days.
- **Money**: one definition shared with PM-2 (`JobMoney`, 9c0b2e526). PM-1's 1,318 done-pending-approval jobs equal PM-2's Pending tab (1,318).
- **Errors**: none from Project Management in `laravel.log`.

## 2. Broken

1. **Overview and Jobs are slow.** Overview takes 5.6 s, Jobs 3.1 s, Done, waiting payment 3.0 s, and Jobs › payments stopped 5.3 s.
   - **Why**:
     - The rule "Done, money still due" works out the money of every Done job: 31,262 jobs, about 0.9 s.
     - Overview ran it once per number (count, total, due, stopped).
     - Jobs ran it for the page and again for its count.
     - Indexes are in place (`workiz_records.job_id`, `workiz_jobs.job_id`, `estimates.job_id`).
   - **Fixed**: each stage's jobs are now read once per request, and every number and the page reuse them (a60a213dc).
   - **Measured on prod after the fix** (0.0.0523, 2026-11-03):
     - Overview: 5.6 s → 2.3 s.
     - Jobs: 3.1 s → 1.5 s.
     - Done, waiting payment: 3.0 s → 1.3 s.
     - Jobs › payments stopped: 5.3 s → 3.2 s. It still narrows all 34,054 in-scope jobs by money first.
   - The lasting fix is stored money on the job (Suggestion 3).
2. **Assignments has had no new card since 10-23.**
   - Why: Action 1, which makes the card when a payment arrives, is off by Jose's order. The 7 cards are all from October. (Needs Jose 1.)
3. **Three Overview numbers are always zero.**
   - Ours vs external company: 0 jobs have an external company.
   - Needs a second visit: 0 jobs.
   - Waiting review by reason: 0 jobs have a reason, so all 531 show "No reason".
   - Why: these are EzBizi-only fields nobody fills, and Workiz has nothing to copy (593 of the 594 Pending jobs have no sub-status). It's not a code fault. (Needs Jose 2.)
4. **The paid-not-started email lists 269 jobs every day.**
   - Why: it repeats every job past 10 days, not only the ones that just crossed. (Suggestion 2, Needs Jose 3.)

Not Project Management's, but they show in its Logs: the 2 failed runs are "Job Status Done - Send Review".
- An SMS to a +1787 (Puerto Rico) number: "Permission to send an SMS has not been enabled for the region".
- A customer with no phone number.

These belong to Customer Experience and Phone.

## 3. Cohesiveness (for UI-UX)

- **Two modules for one job set.** PM-1 calls these jobs the "Done, waiting payment" stage, with money as Total and Due. PM-2 calls them "Pending", with money as Paid, Pending and Total (Jose's order, 1b96519c8). The same money should use the same column words in both.
- **Search parameter**: PM uses `q=`, Jobs uses `search=`. Jobs' review already flags this; agreed.
- **Overview tiles that read 0** for unused fields look broken to a viewer (Broken 3). Hide a figure no job has ever had, or say "not tracked yet".

## 4. Suggestions (ranked)

1. **One Project Management.** Fold PM-2's Pending / Ready / Submitted (with Submit) into PM-1's "Done, waiting payment" stage, or retire the PM-1 tabs nobody uses. Payoff: one place and one count.
2. **Paid-not-started email: only what changed.** Send the jobs that crossed 10 days since yesterday, plus "and 250 more" with a link. Payoff: an email someone actually reads.
3. **Money stored on the job** (Jobs' native money, master-system item 3): total, paid and due columns kept by the sync. Payoff: "money still due" becomes an index read, and every PM tab loads in under 0.5 s.
4. **Delete what nothing calls.** Since design 144 the app doesn't call the `stats` endpoint or the timeline's Recently paid / event drill. Payoff: about 120 fewer lines to keep correct.
5. **A Done-and-owing clean-up list.** 1,184 Done jobs owe $680,910; 869 are from 2025, 72 from 2024 and 5 from 2023, and 370 of them were partly paid. A one-time list (Done, owing, older than 90 days) for the office to mark paid or write off. Payoff: "money due" becomes real receivables.
6. **Fill or drop the three empty fields.** Put review reason, external company and second visit in the job panel and Book panel, or remove their Overview numbers. Payoff: no permanent zeros.

## 5. Needs Jose

1. Switch Action 1 back on? It's the rule for a payment on done pending approval: Submitted, deposit tags and a technician card. It has been off since 10-23, and Assignments stays empty until it runs. PM-2's Submit button now does the status part by hand.
2. Keep "ours vs external", "second visit" and "waiting review by reason" on Overview? If yes, who fills those fields, and where?
3. Should the daily email to info@a-1chimney.com keep listing every late job (269 today), or only the newly late ones?
4. Action 2 (the 30-day cancel) is still waiting on your OK of the schedule: texts on days 7, 21 and 28, cancel on day 30, with the count starting no earlier than the switch-on day.
5. Two Project Managements: which one stays once PM-2 is settled?
