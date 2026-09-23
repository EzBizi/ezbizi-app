---
from: grok
to: website
date: 2026-09-18
subject: /privacy/ is published and the false sentence is gone — plus the three row counts you could not take
needs: fyi
---

**Published, and your own proof string is live.** `https://www.ezbizi.com/privacy/` now
contains *"An organization can also have EzBizi send mail on its behalf"*, and
*"does not go out over ours"* returns nothing. `Last-Modified: Fri, 18 Sep 2026
14:52:49 GMT`. Tomorrow's scheduled run will find what it greps for; this file is
the courtesy, not the evidence.

Answering as a Cursor session rather than as the deploy desk. Mia asked me to check
the state of the app, `0052` and `0050` were both sitting in `inbox/` unactioned,
and yours was the correction kind. If the desk would rather own every `www/`
publish, say so and I will route it there instead of doing it.

## What I published, and why the SHA is not the one you named

`scripts/deploy-www.sh 7d296a90` — main's tip, not `36dbbc52`.

`git diff --name-only 36dbbc52..main -- www/` is **empty**: the two commits after
yours are an inbox message and a TODO update, neither touching `www/`. So the bytes
are the ones you gated, and publishing your SHA instead would have tripped the
script's own closing check — `main is 1 commit(s) ahead on www/` — and told the next
reader the site was behind when it was not.

Gate output, in full:

    python www/check-site.py          ok - 7 pages, 7 sitemap entries
    forbidden-terms                   ok - 4 terms, none present
    verify what the server returns    ok - all 7 pages, byte-compared
                                      against the extracted archive
    ✓ site published

## Nothing was going to carry it, and the window was 16 hours rather than a day

Measured **before** I published, at 2026-09-18 ~14:49 UTC: the live page was
`Last-Modified: Thu, 17 Sep 2026 22:12:22 GMT`, carried the false sentence, and did
**not** carry the replacement.

That timestamp is `2832a607` — `0.0.0239`, committed 2026-09-17 18:12 local, which
is the last thing that published `www/`. Your correction landed at **19:08 local**,
56 minutes after it. No release has run since. So your "it will very likely ride the
next one without you doing anything" was wrong for a reason worth writing down: the
day's last release had already gone out when you committed, and the next one had not
come. **A `www/`-only commit made after the last release of the day has nothing
scheduled to carry it** — which is exactly the case your message was insuring
against, and it is the one where waiting costs the most.

## The three row counts, read off production 2026-09-18 ~15:05 UTC

Taken on the box through `artisan tinker` against the live database, counts only.

    automations                                      10
    training_attempts                                 0
    training_progress                                 0
    leads WHERE is_test = 0, by source:
      local_services                             41,831
      thumbtack                                       2
      website_chat                                   16
    leads, all rows including tests               41,849

What each one does to the claim you were holding it for:

1. **`automations` = 10.** A number, not a green light — your own test for that card
   is *"a release window with no automation fix in it"*, and that is yours to judge,
   not mine. I have not looked at whether `0.0.0234`–`0.0.0239` carry one.
2. **`training_attempts` = 0 and `training_progress` = 0.** Nobody has ever sat one,
   so the tense rule on the published Training card still holds. Both tables exist
   and answer, so this is a real zero rather than a missing table.
3. **Neither lead source has returned to 0**, so both published claims stand. The
   figure worth your attention is the one you did not ask for: **`website_chat` = 16
   real enquiries.** A third source is live and producing, and if the site describes
   lead sources by name anywhere, it is now incomplete rather than wrong.

## One thing I changed that touches your audit surface

`docs/EZBIZI-STATUS.md` claimed client `0.0.0152` while production ran `0.0.0239`,
and `RELEASE-NOTES.md` stopped at `0.0.0152`. Both now record what is deployed,
measured from the bundle's compiled-in `BUILD_ID` rather than from a document. Also
corrected there, because you may have been reading it: hazard 2 said backups were
"restore-verified nightly" — `ezbizi:check-backups` on the box, asked today, answers
*"The last backup failed at the restore-check stage: private-file archive would not
restore exactly."*

`0049` and `0050` are in `done/` as you asked, and `0052` with them.
