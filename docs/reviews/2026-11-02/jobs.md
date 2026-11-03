# Jobs: review, 2026-11-02

Measured on production (the Triplex organization, through the API as the key owner and read-only queries on the box). Test record: JOB-04926 / C-04284.

## 1. Working

- **Jobs list** (GET /jobs): 53,671 jobs.
  - Status counts: Done 31,261; Canceled 19,630; done pending approval 1,400; Submitted 674; Pending 533; In progress 173.
  - Status chips come from `meta.statuses`, read where the viewer is looking (since 4d6d60bfc).
  - Money chips: unpaid 2,301; partial 884; paid 27,361; credit 516.
  - Service-area filter: 46 areas, plus 4,777 jobs not placed in an area.
  - Search: `search=JOB-04926` finds Jose's test job, and nothing else.
- **Labels**: job type, sub-status and tags are on every row, and the pickers list the words in use (18 types, 16 sub-statuses, 104 tags).
- **Job fields**:
  - created_at and created_by are on every row. JOB-55363 shows "Nicholas Frias", set by Workiz's projection for a new mirrored job.
  - review_reason, external_company and needs_second_visit are present.
- **Book panel server**:
  - GET /jobs/book/options, POST /jobs/book and the Workiz retry are live.
  - A lead booked through it converts (08396350a).
- **Estimate-aging trigger**: 6,605 `job.estimate_aging` events in the last 7 days, so the daily 08:10 run is firing.
- **Failed queue jobs**: none from Jobs in 7 days. The 5 there are EmailVerificationLink SMTP 451s, which belong to Auth/Mail.

## 2. Broken

1. **Workiz labels drift on newly mirrored jobs.**
   - Evidence: 2,174 mirrored jobs have a Workiz JobType but no `jobs.job_type`, and 1,770 have Workiz Tags but no `jobs.tags`.
   - Why: `jobs:backfill-workiz-labels` ran once (10-20), and the Workiz projection does not copy JobType, SubStatus or Tags on create. Every job mirrored since then has blank labels, so the Jobs filters and Project Management's redo/dispute words miss them.
   - **Fix**: the command now runs hourly. It fills only unset fields, so a person's edit survives. The commit is listed in the desk message. The lasting fix is for the projection to set them; I've asked Workiz.
2. **339 mirrored jobs have no creator.**
   - Why: the one-time `jobs:backfill-workiz-created` after 9b79b92f5 either hasn't been run or predates these jobs. New ones are set by Workiz's projection.
   - **Action**: the desk runs `jobs:backfill-workiz-created` (dry run first). It is idempotent.
3. **No job notes in EzBizi (0 rows).**
   - Why: the Workiz job-notes import that Customers asked for (74,857 notes) is queued behind the master-system work, not yet built. The customer Notes tab still reads Workiz.
   - Next for Jobs.
4. **The Book panel has never been used: 0 bookings through it.**
   - Why: UI/UX's client ad47800de isn't released yet, so the C-04284 proof (book, check Workiz, cancel) hasn't been run.

## 3. Cohesiveness (for UI-UX)

- **Search parameter names differ**: Jobs uses `search=`, while Project Management, Logs and Book use `q=`. They behave the same; pick one name.
- **Status words appear in Workiz's casing**: "done pending approval" is lower case beside "Done", "Canceled" and "In progress". The settings label "Done, pending approval" is used on chips but not everywhere (the PM timeline uses labels; some exports use raw values).
- **Tags are near-duplicates**: DEPOSIT RECEIVED and DEPOSIT RECIEVED, DONT CHANGE TECH and DON'T CHANGE TECH, PRIORITY, PRIORTY and PRIORITY JOB, and five spellings of "Estimate • Final Follow-Up". The tag picker shows each one separately.

## 4. Suggestions (ranked)

1. **Tag merge in Settings.** Pick duplicates, choose one spelling, rewrite jobs and Workiz. Payoff: cleaner filters, and the PM words match every spelling.
2. **Job numbering native** (master-system item 1). EzBizi numbers are already the primary on every row; show the Workiz serial as secondary everywhere.
3. **Native lists for types, sources, statuses and custom fields, editable per place** (item 2). Seed them from Workiz's mirrored lists, which now exist (job_type, ad_group, custom_field, service_area, external_company). Payoff: the Book panel and filters show the full lists, not only words already used.
4. **Native invoices and payments** (item 3). Replaces WorkizMoney and the payload money SQL. Payoff: the money chips work for EzBizi-only jobs. 59 jobs have no Workiz link today.
5. **Guard on far-future dates.** 4 jobs are scheduled in 2027 (e.g. JOB-55363 on 2027-10-28, which looks like a year typo). Ask "Did you mean 2026?" when a date is more than 6 months out.
6. **The 4,777 jobs with no service area**: suggest the area from the ZIP in a bulk "Place jobs" action.

## 5. Needs Jose

1. **Should EzBizi clean up the duplicate tag spellings** (DEPOSIT RECIEVED, then DEPOSIT RECEIVED, and so on) in both EzBizi and Workiz, or only in EzBizi?
2. **Should a job dated more than 6 months ahead ask for confirmation** when it's booked?
