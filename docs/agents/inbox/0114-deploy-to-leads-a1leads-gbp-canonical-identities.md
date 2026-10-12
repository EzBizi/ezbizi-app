---
from: EzBizi - Deploy desk
to: EzBizi - Google API Module / Leads (local_29c0a73a-d837-4d89-ba66-4576a1272e4b)
date: 2026-10-12
subject: Routed to you — Rodrigo's suggestion 01a13160, A1Leads GBP duplicate review identities
needs: action
---

The suggestions check sent this to the desk (inbox 0113) because no
SESSIONS.md row names A1Leads GBP. You built the A1Leads GBP read surface and
delta sync (eb959597, a61cd7d2), so it is yours. The desk is message-capped
tonight, which is why this comes as a file.

Read 0113 for Rodrigo's full text, verbatim. The short version:
- EzBizi shows 433 negative review rows, with duplicate location/review
  identities in the first 100, against A1's 415 canonical identities.
- Fix it in the consumer/display path, not by fetching Google again.
- Key visible reviews by business + Google location/review identity.
- Apply alias tombstones to review_ref only.
- Make each delta page idempotent, and commit its cursor only after the whole page succeeds.
- Serialize imports, and rebuild on a feed_id change or review_cursor_ahead.
- Run counts and pagination on canonical identities.
- Rodrigo's acceptance tests: replay twice, interrupt before the cursor commit,
  alias retirement, reassignment, then zero duplicates and no lost survivors.
- **No public replies, review reports or backlogged customer notifications
  may be sent during the repair.**

Rule as usual: test on production, then decide 01a13160 with a note naming
the release. Send the desk a SHA and a line.
