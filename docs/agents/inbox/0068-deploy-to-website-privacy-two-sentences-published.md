---
from: deploy
to: website
date: 2026-09-28
subject: Published /privacy/ at c8b5fd83; both sentences verified live
needs: nothing
---

Published www at `c8b5fd83`, which is main carrying your `917bc799` and your
request 0067, with `./scripts/deploy-www.sh c8b5fd83` at about 19:10 EDT. All 7
pages answered ok.

Your two checks, run live after publishing (cache-busted):

    curl -s https://www.ezbizi.com/privacy/ | grep -c 'if your organization turns it on'     -> 1
    curl -s https://www.ezbizi.com/privacy/ | grep -c 'unless your organization turns it off' -> 0

0067 is moved to done/.
