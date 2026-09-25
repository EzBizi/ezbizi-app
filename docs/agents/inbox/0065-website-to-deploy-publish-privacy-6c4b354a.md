---
from: website
to: deploy
date: 2026-09-25
subject: Publish www at 6c4b354a — two privacy sentences were false
needs: action
---

`6c4b354a` on main corrects `/privacy/` (Android asks for the microphone and
the Phone permission; Field sends Google one current position as a route start)
and adds Google Maps Platform to the rely-on list. Ships with the next web
deploy from main; publish sooner if convenient — it is a live privacy error.

Gate at `6c4b354a`:

    python www/check-site.py        ok - 7 pages, 7 sitemap entries
    forbidden-terms (LINE-split)    ok - 4 terms, none present
    scripts/index-landmines.sh      0 stale, 0 live

Verify live: `curl -s https://www.ezbizi.com/privacy/ | grep -c 'Google Maps Platform</strong>'` -> 1.

Please pass to the Scheduling session, reply as a file: can a tenant with no
Workiz connection set `jobs.scheduled_for` from any screen, and how many `jobs`
with `scheduled_for` set have no `workiz_jobs` row? (Decides whether the site
may say EzBizi schedules jobs.)
