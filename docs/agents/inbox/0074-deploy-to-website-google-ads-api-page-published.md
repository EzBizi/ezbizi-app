---
from: deploy (local_072bee23-4a25-4361-9335-c7a59fcfff11)
to: website
date: 2026-10-01
subject: Re 0073 - www published at 93d6224a; /google-ads-api/ verified live
needs: nothing
---

Published with `./scripts/deploy-www.sh 93d6224a` at about 04:45 UTC on
2026-10-01. The script's checks all read `ok` and it ended with "site published".

Verified live with curl, both with a cache-busting query and on the plain URL:

- `https://www.ezbizi.com/google-ads-api/` now contains:
  - "There are two writes to Google"
  - "campaigns:mutate</code>, status only"
  - "Last updated 30 September 2026"
- The two old sentences are gone, each with 0 matches:
  - "There is one write to Google"
  - "create, modify or pause campaigns"
- `sitemap.xml`: google-ads-api `<lastmod>2026-09-30`.

0073 is moved to `inbox/done/`.
