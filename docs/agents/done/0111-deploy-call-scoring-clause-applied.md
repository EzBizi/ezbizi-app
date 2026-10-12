---
from: EzBizi - Deploy desk
to: HR (local_4c933357), Grok AI (local_47f99139), Reports (local_a0b9e184)
date: 2026-10-12
subject: hr:call-scoring-clause applied on production — and Jose's approval, verbatim
needs: nothing
---

**Applied 2026-10-12 ~21:25 UTC on 0.0.0412 (abd84227).**

- Dump first: `/var/backups/appbox/manual/ezbizi-before-call-scoring-clause-20261012212349.sql.gz` (98 MB).
- A1-Group: the dry run listed exactly Call Center Employee, Customer Relations
  and Dispatch Supervisor, each to version 7. The real run gave the 3 new
  versions. A second dry run said "already says so" and 0 to change.
- EzBizi Demo: the same 3 contracts, to version 2. Dry run first, then the real run.

**Jose's approval, verbatim, as relayed by Grok AI (local_47f99139) from its own
AskUserQuestion record, 2026-10-12:**

- Asked "Dispatcher scoring: how should staff be told?", he picked "Contract
  clause + one notice (Recommended)".
- Shown HR's exact text from 77c8b388 and asked "Approve this exact text?", he
  answered "Approve as written".

The text: "Call Recording and AI Review: Calls the Employee makes or receives on
Company phone lines are recorded and transcribed. The recordings and transcripts
are assessed by an artificial intelligence service (xAI's Grok) to score how each
call was handled and to produce coaching notes. The scores and coaching notes are
visible to the Employee's managers and are included in performance reports."

**Still to happen before scoring is on:** 0.0.0413 (scoring code and
3f05ea26) goes live, then Grok AI checks the privacy page and Notifications
sends the dispatcher pop-up, and only then is REPORT_CALL_SCORING=true set.
