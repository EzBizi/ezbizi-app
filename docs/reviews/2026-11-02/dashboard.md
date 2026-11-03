# Dashboard — review, 2026-11-02

Measured on production (`ssh app`, tinker as www-data, read-only): every
dashboard box resolved as each organization's Administrator, Day period.
`client_issues` read for module = dashboard, last 14 days.

## 1. Working

- Every registered box resolves without an error for all 13 organizations (no failed panels).
- Totals for 12 of 13 organizations: 41–79 ms for the whole dashboard.
- Sizes (S/M/L), hide / Add a box, Customize drag and move: layout saves keep size, hidden and chart mode when an older build or the HR tab saves without them.
- Templates: five layouts (Whole business, Office and dispatch, Field operations, Sales and marketing, People) set order, size and chart mode; Undo restores the layout before.
- Figures with the change against the period before, on all glances; attention rows from each module; notes beside figures.
- Money only for `<module>.financials` holders (Jobs earned, Inventory stock value); the guard pair in DashboardTest checks the raw response both ways.
- Day / Week / Month / Quarter / Year chips; Day is the default; hourly buckets on Day.
- Coming back to the dashboard paints the last view at once (0d225feb).

## 2. Broken

1. **Email box: no unread count for any Triplex Group inbox, and 8.5 s on every load.**
   Evidence: all 54 readable inboxes throw `CannotReadMailbox` with "the mail service did not say how many unread messages there are" (provider `gmail_workspace`, reader `RecordingMailboxReader` → Workspace). Two consecutive resolves: 8 466 ms and 8 583 ms; the count cache was empty for every inbox.
   Why: (a) the Workspace reader's `unreadCount` gets an answer without a count. This is the Email module's reader, handed to Email. (b) The dashboard's 2026-10-16 cache (f2aa9082) stored counts but not failures, so a failing inbox was re-asked on every load.
   Fixed (b) in 8bcedf8ff: a failed ask is remembered like a count and retried after the response.
2. **UI freezes on the dashboard screen: 13 in 14 days, 2–18 s, on Windows and web, latest today on 0.0.0513.**
   Cause not yet measured. The server side is not it (≤ 0.1 s for every organization except the Email case above). Next step: profile a cold paint of the A1 dashboard (Large boxes with charts, 54 Email "could not be asked" sentences) in profile mode.
3. The 155 `api_failure` rows tagged dashboard are background calls (monitoring heartbeat, notifications, time-clock, phone presence) made while the dashboard was open on a machine that was offline (9–36 ms "could not reach the server"). They are not dashboard faults, but they are filed under the dashboard because it is the open screen.

## 3. Cohesiveness

- The Email box can list 54 near-identical "could not be asked for unread mail just now" sentences. One count ("54 inboxes could not be asked") would match how every other box speaks in counts.
- Boxes say "N need attention" in Small and Medium, but the attention rows wrap at different line heights per module because the text lengths vary (Jobs and Scheduling are long). UI/UX owns the copy length rule.
- Empty states differ in wording ("No jobs to show.", "Nobody in this view.", "No items to show."). These are deliberate per module, but they are not one pattern.

## 4. Suggestions (ranked)

1. Fix the Workspace unread count in Email: the box is blank for the biggest tenant. Payoff: the Email box works at all.
2. Fold repeated per-inbox failures into one counted sentence. Payoff: a readable Email box when a provider hiccups.
3. Load boxes separately on a cold start, one request per glance or streamed. Payoff: a first paint under 1 s, where the slowest box today sets the whole wait.
4. Warm the Email unread cache from WatchMailboxes, which already connects. Payoff: no live ask on the dashboard path at all.
5. Tag client issues with the request's owning module, not the open screen. Payoff: dashboard health numbers mean the dashboard.

## 5. Needs Jose

- None blocking. One question: should the dashboard show a box for a module whose provider is down at all, or hide it until it answers? (Today it stays, says why, and keeps its place.)

## 6. Fixes from this review

- Email box remembers failed asks: 8bcedf8ff.
