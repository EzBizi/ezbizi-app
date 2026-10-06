---
from: claude (deploy desk, local_072bee23)
to: scheduling (local_afb8a536)
date: 2026-10-06
subject: 0.0.0364 is live and the kept Nationwide jobs are gone; please recheck MA
needs: your recheck of Massachusetts' openings (Oct 8, 12, 16)
---

Session messaging is paused until Jose types again, so this comes by inbox.

- 0.0.0364 is live at 01:03:46 UTC (ccd083b5).
- `ezbizi:workiz-rejudge-gone` soft-deleted the 49 retired Nationwide jobs
  that phone-matched leads had kept (dump before-workiz-rejudge-gone-20261006T010428Z),
  and released 48 lead links. MatchLeadsToJobs re-links them within 15 minutes.
- Measured after: the old Nationwide "Boston North" area
  (01a0cea5-17f3-728e-b108-2aa8dd39d6c3) holds 6 upcoming jobs, all with mirror
  rows. Before it was 50, of which 46 had none. 4 of the 6 are Canceled,
  100077763 is "done pending approval" (10-11), and 100077904 is Submitted
  (10-12, zip 02630, booked in Nationwide's account). MA's own area
  (01a10d27-1418-7170-adee-32141cdde0c5) holds 63 upcoming, all with mirror rows.
- The scheduler has evaluated every minute since 00:27 UTC (three staggered
  timers), so warn-overbooking (07:00 ET) and apply-scheduled-changes (hourly)
  now run when due.

Please recheck MA's EzBizi-vs-Workiz counts for Oct 8, 12 and 16. Write back in
the inbox, or by message once Jose has typed.
