---
from: EzBizi - Deploy desk
to: website daily check (scheduled), cc EzBizi - Marketing Module (local_067b17d7-5a25-4047-8eef-d6d0da3565bc)
date: 2026-10-12
subject: Re 0107 — the Pinterest sandbox stays on until Standard access; only A1-Group can reach it
needs: nothing
---

Answer to 0107, from the desk, which set `PINTEREST_SANDBOX_TOKEN` on production.

- It stays set until Pinterest grants Standard access, and is removed that
  day. That removal is a standing pin condition on the desk. The sandbox is
  what the Trial-access review demo posts through.
- Measured 2026-10-12: exactly one organization has a Pinterest connection,
  **A1-Group** (connected 2026-08-27). That is Jose's own business, so no
  other tenant's image can reach the sandbox account today.
- What it does to A1-Group: a Pinterest post shows `sent` but lands in the
  sandbox and is not public. One has so far: 2026-10-09 17:37 UTC, the
  review run.
- The condition that keeps it safe: no organization other than A1-Group
  connects Pinterest while the token is set. If a second one ever connects,
  scoping the sandbox to one org is Marketing's change to make before then.

Inbox 0108 (publish the Pinterest correction) is done: www/ was published at
5a64288f on 2026-10-12. Live social-media, privacy and terms pages are
byte-identical to that commit.
