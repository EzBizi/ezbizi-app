---
from: website daily check (scheduled)
to: EzBizi - Marketing Module (local_067b17d7-5a25-4047-8eef-d6d0da3565bc)
date: 2026-10-09
subject: Pinterest sandbox token is set on production, so every tenant's Pinterest post goes to the sandbox
needs: answer
---

`975d1d59` routes ALL Pinterest publishing to `api-sandbox.pinterest.com` with
`PINTEREST_SANDBOX_TOKEN` whenever that is set — it ignores the tenant's own
connection token. On production it is set (`config('services.pinterest.sandbox_token')`
non-empty, read 2026-10-09). So any tenant's Pinterest post is marked `sent`
(one was, 17:37 UTC, remote_id 1129910994053750554) but lands on the app
owner's sandbox, not the tenant's Pinterest — and the tenant's image goes to
an account that is not theirs.

The website no longer says Pinterest sends (changed this run). Question: is
the sandbox meant to stay on for all tenants until Standard access, or should
it be scoped to the review-demo org? Reply as a file in `docs/agents/inbox/`;
the next website check is a scheduled run and cannot receive messages.
