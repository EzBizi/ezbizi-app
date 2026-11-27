# Reports: full app review, 2026-11-02

Measured on production on 2026-11-02, using the API (MCP key 566) and SQL on `ssh app`.
Fixes: **cfce478ce** (DST test) and **5e52aa402** (WhatsApp delivery, P&L week order).

## 1. Working

- **Performance** (`reports/experimental/calls`, 10-26..11-01): 3,786 calls, 53 people, 10 Workiz accounts. No Workiz failures; the account list says what is unreachable.
- **Transcription:**
  - The ezbizi-transcribe service is running: Whisper 363 / 238 / 63 / 39 on 10-29, 10-30, 11-01, 11-02.
  - Only 2 failures (10-29).
  - EverDoors (RC 63713602007) has a call-log day for every date 10-21..11-01.
- **Scoring:** live, with weights and per-person strengths and improvements. Cristine Hicale has 179 calls scored and a score of 8.7.
- **Marketing:** every branch shows LS Ads clicks, cost, leads and jobs, plus sub-contracted sources (MS CHIMNEY, Alpha Flow, ChimCare…).
- **Google Ads:** `ads/accounts` returns all MCCs and sub-accounts (A1, AngiSweep, Ace Home Care).
- **P&L:** the Airtable sources list 6 bases and their tables; the NJ table returns 17 weeks.
- **Workspace:** `reports/texts/job_assignments` renders 120 In Progress jobs today (112 assigned, 8 unassigned) and lists its rule and recipients.
- **Daily rules:** 7 active rules, firing at 08:30 ET (12:30 UTC, then 13:30 UTC after DST).
- No failed queue jobs from Reports in the last 7 days.

## 2. Broken

1. **No scheduled WhatsApp report has ever reached anyone.** FIXED in 5e52aa402.
   - Evidence: every automation_runs row for the 7 rules since 10-30 fails with Meta `(#132001) Template name does not exist in the translation`.
   - Why: outside WhatsApp's 24-hour window the code sends the `report_ready` template, which was never approved on the Reports WABA (2086442795335686). That WABA has `report_summary`, `report_document`, `automation_alert` and `hello_world`.
   - Fix: the notice now uses the approved `automation_alert` template ("Your report is ready. Reply to this message and it arrives here."). The held report goes out on reply, through the existing flushPending.
2. **Retries reported "Nobody chosen has a phone number".** FIXED in 5e52aa402.
   - Why: the once-a-day key was claimed before the send, so after a refusal each retry skipped everyone and reported 0 recipients. The key is now released when Meta refuses.
3. **89 held reports were queued behind notices that never arrived** (10-26..11-02, 4 people). FIXED on prod.
   - The table was dumped to `~/whatsapp_pending_reports-2026-11-02.sql` on app.
   - The 89 rows were then marked delivered. Without that, the next reply would have flushed a week of stale texts.
   - Held reports are now created only after Meta accepts the notice.
4. **The P&L tab opened on the wrong week.** FIXED in 5e52aa402.
   - Why: Airtable returns weeks in its own order, and the default "latest complete" week was the last one in the list (6 Jul for NJ).
   - Weeks are now sorted oldest first, and a test was added.
5. **The DST test was red on main** ("the date range is one bar…", 145h ≠ 144h). FIXED in cfce478ce: it now compares calendar days.
6. **Meta tab: empty.** There are 0 meta_ad_insights rows ever, so it is not a Reports bug. Facebook `ads_read` is still waiting on App Review (Social).
7. **Thumbtack tab: empty for the week.** The last `source='thumbtack'` lead is from 2026-10-01 (19 total). This is upstream of Reports: either no Thumbtack leads for a month, or intake stopped. Leads/Social should check.
8. **Workspace weekly/monthly batches:** `reports/batches/week` and `reports/batches/month` return `null`, so there is no batch to approve yet. The first one is due once a weekly report is generated; re-check after the next Monday run.
9. **Marketing profit:** every branch row reads "the profit & loss table has no week 26 Oct - 1 Nov yet". This is correct; that week hasn't been entered in Airtable. MA has no P&L table mapped at all.

## 3. Cohesiveness (for UI-UX)

- **Marketing:** a zero-everything **PPC** row shows for every branch. Hide all-zero source rows, the way the other tabs hide empty data.
- **Meta / Thumbtack:** with no data they show the generic "Nothing yet". They should say why: "Facebook ads not connected yet (waiting on Meta review)" and "No Thumbtack leads since Oct 1".
- **Not measured:** phone width for Google Ads, P&L and Workspace was not opened in a device this pass. The shared date bar covers phone (a706982bf, 430px rule), but Google Ads and P&L have their own pickers (account search, week dropdown) and are not on the shared bar. UI-UX should open these three at 375px.
- **P&L:** a single week dropdown, while every other tab uses the shared date bar.

## 4. Suggestions (ranked)

1. **Delivery heartbeat (row 52):** a daily check that each active report.text rule delivered at least once. This outage ran 4 days unseen. Payoff: catches the next silent failure the same day.
2. **Show delivery status in Workspace:** the rule's last run, with the Meta error in plain words, next to the rule. Payoff: anyone can see "not delivered" without SQL.
3. **File a proper `report_ready` template** with a "Show report" button, if a tap-to-open flow is wanted. `automation_alert` works now but reads "automation just ran".
4. **Sort and fill Marketing:** drop the all-zero PPC rows and show cost per job next to gross. Payoff: shorter tables and a clearer return on ad spend.
5. **Move P&L onto the shared date bar,** picking the week that contains the end date. Payoff: one way to choose dates everywhere.

## 5. Needs Jose

- Is the interim WhatsApp notice wording OK: "EzBizi alert · Your *Daily job assignments · Nov 2* automation just ran. Your report is ready. Reply to this message and it arrives here."? Or should we file a dedicated template with a "Show report" button (it takes Meta about a day to approve)?
- Massachusetts has no P&L table mapped, so its Marketing profit is always blank. Should it be mapped, and to which Airtable table?
