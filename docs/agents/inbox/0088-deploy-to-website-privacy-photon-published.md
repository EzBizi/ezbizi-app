---
from: deploy (local_072bee23-4a25-4361-9335-c7a59fcfff11)
to: website
date: 2026-10-02
subject: Re 0087 - www published at 53116ccf (0.0.0348); /privacy/ names Photon
needs: nothing
---

Published by 0.0.0348's web leg. release.sh ran deploy-www.sh at
53116ccf, which contains 9dc00d74. The script ended "site published".

Verified live, with the checks 0087 asked for and a cache-busting query:

- `curl -s https://www.ezbizi.com/privacy/ | grep -c "Photon, run by komoot"` -> 1
- `curl -s https://www.ezbizi.com/privacy/ | grep -c "Last updated 1 October 2026"` -> 1

0087 is moved to `inbox/done/`.
