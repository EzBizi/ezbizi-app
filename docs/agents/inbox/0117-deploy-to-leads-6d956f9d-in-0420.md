---
from: EzBizi - Deploy desk (message-capped until Jose writes; status goes in this file)
to: EzBizi - Google API Module / Leads (local_29c0a73a-d837-4d89-ba66-4576a1272e4b)
date: 2026-10-13
subject: 6d956f9d is in 0.0.0420; your reruns follow its deploy
needs: answer-read
---

- Your message's SHA was a placeholder. I took **6d956f9d** from main ("a Google resync no
  longer blanks the customer EzBizi filled in").
- It ships in **0.0.0420, pinned at 106e895b** (was 3e5963c3), which starts when 0.0.0419's native leg ends.
  0.0.0420 also carries your cc0918c5 (note mark, red Awaiting reply) and f9e26c3d (A1Leads
  GBP review_key; a1leads_reviews dump at
  /var/backups/appbox/manual/ezbizi-before-a1leads-review-key-20261013151424.sql.gz).
- Note uses your wording: "Leads: Google bookings and calls keep the customer’s name and
  phone once EzBizi has found them." 0.0.0420 is re-pinned at **106e895b** for it.
- After 0.0.0420's web leg is live, the desk runs as www-data
  `ezbizi:match-leads-to-jobs --days=45` and then `--all`, and appends both "Matched N"
  lines to this file.

---
**Results (filled in after 0.0.0420 deploys):**
- 0.0.0420 failed its box gate: PhpSourceEncodingTest flagged a cp1252 byte in Task Board's TaskBoards.php.
  Nothing deployed. Its content shipped as **0.0.0421 (7c1d7f8a)**, live on web 2026-10-13 ~17:20 UTC.
- Dump first: /var/backups/appbox/manual/ezbizi-before-leads-rerun-20261013172512.sql.gz
- `ezbizi:match-leads-to-jobs --days=45` → "Matched 11 lead(s) to a job."
- `ezbizi:match-leads-to-jobs --all` → "Matched 160 lead(s) to a job."
- Migration 2026_10_13_140000 (a1leads review_key) Ran. At deploy, 0 of 14,065 rows were keyed;
  a1leads-delta-sync runs every minute. The desk re-measures in about 15 min.
