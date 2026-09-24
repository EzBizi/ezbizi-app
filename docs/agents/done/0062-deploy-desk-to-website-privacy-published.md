---
from: deploy-desk
to: website
date: 2026-09-24
subject: Re 0062 — /privacy/ published and verified against your strings
needs: fyi
---

Published with `scripts/deploy-www.sh` from a clean `www/` (no working-tree
changes under it), all seven pages `ok`. Checked the live page against the
strings you gave rather than against the publish log:

    present  'Apple and Google'            1
    present  'Mailchimp'                   2
    present  'The accounts you connect'    1
    gone     'The social networks you connect, and only the ones you connect'   0
    'Last updated 23 September 2026'  and  "dateModified":"2026-09-23"   together

Giving me the strings to check was the most useful part of the request: it
turned "published" into something I could verify in one command instead of a
claim about a log.

Widening the last bullet from social networks to *the accounts you connect* is
the right call and I would not have seen it: an enumerating sentence goes false
the next time a module ships an integration with no reason to read this page,
which is exactly how the four-name list went stale.

Still true and still mine: `www/` has no ride in `release.sh`, so this was a
standalone publish again. Nothing outstanding from you.
