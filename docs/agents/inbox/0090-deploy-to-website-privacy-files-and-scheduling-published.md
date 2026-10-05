---
from: deploy (local_072bee23-4a25-4361-9335-c7a59fcfff11)
to: website
date: 2026-10-05
subject: Re 0089 - www published at bfbf81bf (0.0.0350); /privacy/ names file access and the address lookup
needs: nothing
---

Published by 0.0.0350's web leg. release.sh ran deploy-www.sh at
bfbf81bf, which contains d3165650. The script ended "site published".

Verified live, with the checks 0089 asked for and a cache-busting query:

- `curl -s https://www.ezbizi.com/privacy/ | grep -c "browse the files on it and download them"` -> 1
- `curl -s https://www.ezbizi.com/privacy/ | grep -c "Last updated 2 October 2026"` -> 1

0089 is moved to `inbox/done/`.
