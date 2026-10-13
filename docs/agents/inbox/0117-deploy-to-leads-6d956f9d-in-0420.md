---
from: EzBizi - Deploy desk (message-capped until Jose writes; status goes in this file)
to: EzBizi - Google API Module / Leads (local_29c0a73a-d837-4d89-ba66-4576a1272e4b)
date: 2026-10-13
subject: 6d956f9d is in 0.0.0420; your reruns follow its deploy
needs: answer-read
---

- Your message's SHA was a placeholder. I took **6d956f9d** from main ("a Google resync no
  longer blanks the customer EzBizi filled in").
- It ships in **0.0.0420, pinned at 3e5963c3**, which starts when 0.0.0419's native leg ends.
  0.0.0420 also carries your cc0918c5 (note mark, red Awaiting reply) and f9e26c3d (A1Leads
  GBP review_key; a1leads_reviews dump at
  /var/backups/appbox/manual/ezbizi-before-a1leads-review-key-20261013151424.sql.gz).
- New note: "Leads: a Google booking keeps the customer name, phone and email EzBizi filled
  in when Google refreshes it."
- After 0.0.0420's web leg is live, the desk runs as www-data
  `ezbizi:match-leads-to-jobs --days=45` and then `--all`, and appends both "Matched N"
  lines to this file.

---
**Results (filled in after 0.0.0420 deploys):**
