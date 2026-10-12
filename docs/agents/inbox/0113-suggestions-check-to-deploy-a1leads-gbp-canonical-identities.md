---
from: suggestions check (scheduled)
to: Deployment desk (local_072bee23-4a25-4361-9335-c7a59fcfff11)
date: 2026-10-12
subject: Suggestion 01a13160 (Rodrigo, A1Leads GBP) - duplicate review identities in the consumer/display path
needs: action
---

Module field: `a1leads_gbp`. No row in docs/SESSIONS.md owns A1Leads GBP, so this
comes to the desk to route. The previous A1Leads GBP work (a61cd7d2, released in
0.0.0359, see inbox 0094) was built on `session/a1leads-gbp`; Customer
Experience owns the general Reviews/Business Profile screens.

- Suggestion id: `01a13160-7f3d-722f-9da9-0e411ce03fcc`
- Author: Rodrigo Mena
- Date: 2026-10-12 18:29:30 UTC
- Attachments: none

Body, verbatim:

> A1Leads GBP import/display correction requested after the October 12 integration commissioning. The owner API now emits full-resource alias tombstones followed by canonical surviving-row assertions, identity-move retirements, stable feed_id and review_cursor_ahead recovery. Live read-only comparison before repair: EzBizi returned 414 distinct negative identities; all ratings/comments matched A1's 415 canonical identities. After the source repair at 18:22 UTC, the previously missing survivor appeared, but EzBizi returned 433 negative rows; its first100 had two duplicate location/review identities. Please fix the consumer/display path rather than fetching Google again: key visible reviews by business plus Google location/review identity (account IDs are routing aliases), keep source full-resource references for writes, apply alias tombstones only to review_ref without deleting canonical_review_ref, upsert/delete each delta page idempotently and commit its cursor atomically after the whole page succeeds, serialize overlapping imports, and rebuild when feed_id changes or cursor-ahead is returned. List filters/counts/pagination must run on canonical identities; keep sent replies/workflow/contact details from the representative. Internal surviving-row assertions use ordinary public kind=review. Generated projection timestamps must not certify Google/Workiz source freshness. Acceptance: replay pages twice, interruption before cursor commit, alias retirement and resource/business reassignment; compare complete negative identity set with owner data and confirm zero duplicate identities and no lost survivors. No public replies, review reports or backlogged customer notifications should be sent during repair. A1 source changes are regression-tested; remote DB transactionality is not yet verified.

Fix it, then test it on production in the real screen or data, and only then
decide the suggestion with a note naming the release that contains the fix
(Rodrigo, 2026-10-08: test before claiming as resolved). Send the desk a SHA
and a release note.
