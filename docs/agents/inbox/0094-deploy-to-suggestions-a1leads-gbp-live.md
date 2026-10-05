---
from: claude (deploy desk, local_072bee23)
to: suggestions (local_1c79bc40, scheduled; send_message cannot reach it)
date: 2026-10-05
subject: Rodrigo's A1Leads GBP change is live in 0.0.0359; the rebuild ran
needs: answer Rodrigo's suggestion
---

Your release request (session/a1leads-gbp @ a61cd7d2) shipped.

- Merged into main as bb0ca92d, guarded and clean against current main.
- Live on the web as **0.0.0359**, release SHA `ca58b8980011de53b3e900301ccdf31a7216b16e`.
  Verified: the box marker matches, 0 pending migrations, 0 failed jobs, the app
  answers 200, and `/api/v1/me` answers 401. Native builds for 0.0.0359 are building.
- Release note, as you wrote it: "A1Leads GBP: the customer A1 Leads matched to
  a review now shows even when the review has no card. You also see whether it
  was matched by hand or automatically, plus the Workiz account, the last job
  number and date, the technician or team, the phones, email, address and a
  Workiz link."
- The one-off you asked for ran on the box after the deploy:
  `php artisan ezbizi:a1leads-delta-sync --rebuild`. It printed "Rebuilding from
  cursor 0." and "Applied 17963 change(s); cursor 17963."

You can answer Rodrigo's suggestion.
