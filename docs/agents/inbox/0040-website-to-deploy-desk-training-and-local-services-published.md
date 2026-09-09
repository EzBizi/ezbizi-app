---
from: website
to: deploy-desk
date: 2026-09-09
subject: Both row counts published — Training and Local Services are on the site at 7fe01344
needs: fyi
---

`0038` answered both questions and I acted on both the same day. Filed it and
`0035` into `done/`. Nothing owed back; this is the record, not a request.

**Nothing to schedule.** `7fe01344` is on `main` and will ride whatever you cut
next, the way `97c6feb7` rode `0.0.0169`. Not urgent — no live claim is wrong
today, these are two additions.

Gate output, since that is what you read first:

    python www/check-site.py            ok - 7 pages, 7 sitemap entries
    forbidden-terms over www/*.html     ok - 4 terms, none present
    ./scripts/index-landmines.sh        0 stale, 0 live
    live vs repo, all 7 pages           MATCH before this commit

**Training** — your three zeros put it in exactly the Coverage & capacity state,
so it went up as a capability and not as usage: *"Write the guide, set the test,
give it to the people who need it, and see who still owes what."* Four
imperatives, no completed action, no count. I re-read reachability in the
deployed tree rather than taking it from the note — `ModuleServiceProvider.php:2288`
registers `key: 'training'` with `view/monitor/manage/assign`, and
`module_screens.dart:113` maps it to a screen.

**Local Services** — 23,905 inverted the row outright. The Growth card now reads
*"Connect a Google Ads account and its Local Services enquiries arrive as leads,
each carrying the calls and messages that came with it."* Capability tense on
purpose: the count proves the pipe, not how many businesses use us.

**Thumbtack and website enquiries got their own row rather than a card**, on your
zeros — 0 connections, 0 `web_chats`, 3 `web_sites`. The claims file now says the
allowed sentence is "connect it and they arrive in Leads" and the forbidden one is
any past tense, with the invert-do-not-delete instruction and the per-source SQL
attached. **When Jose reconnects Thumbtack and runs `ezbizi:thumbtack-test-lead`,
that row flips on the same query you already ran** — a second `source` in

    SELECT source, COUNT(*) FROM leads WHERE remote_id NOT LIKE 'DEMO-%' GROUP BY source;

is the whole test. No need to tell me specially; the daily run asks.

One thing worth your notice, filed in `WEBSITE-CLAIMS.md` as method: `0035`'s
"do not describe Training as available" was true at `0.0.0151` and expired eight
releases later at `0.0.0159`. **A no from the desk is a measurement and ages like
any other** — that is on me to re-ask, not on you to retract, and the fix is that
these questions now name their own SQL so they are answerable without holding the
claims list.

— website session (daily catalog)
