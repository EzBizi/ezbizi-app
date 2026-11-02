# Project Management - 2: review, 2026-11-02

Measured on production on 2026-11-02, read-only. I ran tinker, called ProjectManagement2Controller as Jose (Triplex Group, in his current view) and made direct counts. Nothing was written.

Jose's test job JOB-04926 is Submitted rather than done pending approval, so I couldn't press Submit live (see section 5).

## 1. Working

- **Pending tab**: 1,400 jobs done pending approval. The first page, with all three tab counts and the money, loads in 758 ms.
- **Ready for Submission tab**: 39 done-pending-approval jobs with a payment, 24 paid in full and 15 partly. None of them is back to $0 after a refund. Loads in 731 ms.
- **Submitted tab**: 47 jobs now Submitted that came from done pending approval. There have been 143 such moves since 2026-09-24; the rest have since moved on.
- **Tab labels**: the counts (Pending · 1,400, Ready for Submission · 39, Submitted · 47) match each tab's total.
- **Search** (customer, job ID, address):
  - "Columbus" finds 23 in Pending, in 773 ms.
  - "04926" finds nothing there, which is correct, because JOB-04926 is Submitted.
- **Job panel** (row click, GET /project-management-2/jobs/{id}): opens in 53 ms with the job's timeline (created, status changes, payment). Its "Open the job" button opens the Jobs panel.
- **Money columns** (Paid · Pending · Total):
  - They come from JobMoney, the same definition PM-1 uses.
  - $0 draft estimates no longer count as a price (6af51e942).
  - A job with no price reads $0, by Jose's decision of 11-02 (751d8ab5b).
- **Created / "Job created … by"**: taken from the job's own record. On 10-29, 0 of 2,490 mirrored jobs differed from Workiz.
- **Submit (Ready for Submission)**:
  - Jose has `jobs.edit`, so he sees the button.
  - The tests cover the refusals (409 when unpaid or no longer done pending approval, 403 without `jobs.edit`), the audit row, the events, and the queued Workiz status write.
  - The Workiz call behind it (WorkizJobWriter::setStatus) has worked 11 times on production, the last on 10-23.
  - No one has pressed Submit yet: all 8 done-pending-approval → Submitted moves since it shipped were made in Workiz.

## 2. Broken

1. **The Submitted tab took 1.7 to 2.1 s to load.**
   - Evidence: the list query took 1,660 ms, and the whole page 2,106 ms.
   - Why: the tab was sorted by "when submitted" using a subquery per row, which re-read about 22,500 status-change events for every job.
   - Fixed in **0e0c0be28**: one grouped pass over the events, joined in. On the same live data it takes **42 ms** and returns the same 47 jobs in the same order.
2. **Every page recounted all three tabs (~370 ms), "Load more" included.**
   - Why: the counts were computed on every request.
   - Fixed in **0e0c0be28**: counts come with page one only. Load more changes no count, and the client keeps the last counts.
3. **Switching tabs briefly showed a false empty message.**
   - Evidence: "No job done pending approval has a payment yet." stayed on screen for 0.7–2 s while Ready for Submission was loading.
   - Why: the list was emptied before the new tab's rows arrived, and the empty message did not check for a load in progress.
   - Fixed in **0e0c0be28**: a progress bar shows while the tab loads.
4. **The list went stale after a change made through "Open the job".**
   - Why: only the side panel reloaded.
   - Fixed in **0e0c0be28**: the list behind it reloads too.

## 3. Cohesiveness (for UI-UX)

- **"Pending" means three things on one screen**: the first tab (done pending approval), the money column (what is still owed), and Workiz's own status "Pending" (533 jobs in Jobs). See section 5.
- **Search parameter**: PM-2 and PM-1 send `q=`, while Jobs and Customers send `search=`. It is the same behaviour under different names.
- **No branch filter**: unlike Jobs and PM-1, the list has no branch or company filter chips and relies on the scope switcher alone. Connecticut alone has about 40% of the jobs.
- **"Last modified"** is Workiz's last status change. A change made in EzBizi (Submit, for one) shows there only after the next Workiz sync.

## 4. Suggestions (ranked)

1. **Submit several at once** on Ready for Submission: tick the rows, confirm once. Payoff: the 24 jobs paid in full clear in one action instead of 24.
2. **"Paid in full / Partly paid" chips** on Ready for Submission (24 / 15). Payoff: the jobs ready without a question come first.
3. **Keep Submitted history past three months.** The tab reads the status-change events, which expire after 3 months under their retention policy. A stored "submitted on / by" on the job would keep it for good. Payoff: the tracking Jose asked for ("to keep it tracked") doesn't quietly drop old jobs.
4. **Days waiting on Pending**, sortable, oldest first, as PM-1 shows. Payoff: follow-ups on the oldest of the 1,400 first.
5. **Branch chips with counts** on each tab. Payoff: one tap per state, without changing the scope.
6. **Export the current tab** to CSV. Payoff: the list Shoval or the office works from off-screen.

## 5. Needs Jose

1. **May I prove Submit end to end on your test job?** I would put JOB-04926 into done pending approval with a payment, press Submit, and check that Workiz shows Submitted within a minute. Until then, the first real use is its live proof.
2. **Should the money column "Pending" become "Balance" (or "Due")?** It would stop clashing with the Pending tab and with Workiz's "Pending" status.
3. **Should the Submitted tab keep jobs for good?** That needs a stored submission record. Otherwise it keeps 3 months, as it does today.
