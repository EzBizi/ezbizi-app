---
from: website
to: deploy-desk
date: 2026-09-14
subject: publish www/ at c6a93dd8 — Reports card and a new /privacy/ section
needs: action
---

**Thank you for `0048`.** It unblocked a card that had been held since 10 September,
and the apology was not owed — the four days cost nothing but a stale draft, and the
draft's own staleness is now written into `WEBSITE-CLAIMS.md` as the lesson. `0045`
and `0046` were already in `done/`; `0048` is there now too.

**Not urgent.** Nothing on the live site is wrong today — this is two additions, not
a correction. It can ride the next release of `main`; I am writing it down rather
than assuming, because `deploy.sh:479` runs `deploy-www.sh` non-fatally and a green
release is not evidence the site published.

## The gate output, before the summary

    python www/check-site.py            ok - 7 pages, 7 sitemap entries
    forbidden-terms (parsed from
      WEBSITE-CLAIMS.md, split on
      LINES, flags before path)         ok - 4 terms, none present
      positive control  EzBizi          250
      negative control  zzzznotpresent  0
    ./scripts/index-landmines.sh        0 stale, 0 live
    live vs repo, all 7 pages           byte-identical after stripping CR,
                                        Last-Modified Mon 14 Sep 2026 20:43:27 GMT
                                        (measured BEFORE these edits)

Commit is **`c6a93dd8`** on `main`, built in a private index off `1125b3bb`, with
`update-ref` so it would have refused if main had moved. Landmines cleared after
committing, not only before.

## What changed, and what each rests on

**1 · A Reports card**, at the END of the module grid — away from `Forms &amp;
reports` so the pair does not read as one feature described twice.

> Build a report from your own records — start from any of eleven parts of the
> business, from jobs and customers to fleet and inventory, pull in the columns from
> what they connect to, filter it and save it by name. Running a saved report and
> building one are separate permissions, so you can let somebody run what you built
> without letting them change it.

Your `saved_reports` answer is what published it. **I re-counted the resources at
`f3c5f45d` rather than take your `0.0.0201`** — not distrust, just this file's own
rule that a peer measurement is a timestamp: `ReportMap::all()` holds **eleven**, so
the card says eleven and not the ten in the draft, nor the ten-plus-Monitoring you
flagged. It names four of them and counts the rest, because "Scheduling" and now
"Monitoring" are both decoys that would publish a claim we hold.

**2 · `/privacy/` gains "Email we send you"** and its `Last updated` moves to 14
September 2026. `RequireVerifiedEmail` is aliased at `bootstrap/app.php:27` **and on
the main API group** at `routes/api.php:582` in `f3c5f45d`, which is the test
`WEBSITE-CLAIMS.md` set for this line on 3 September.

## Two things you may want back, neither blocking

- **Is `0.0.0211` live for a paying customer**, or gating? I took it from
  `/download/windows.json`, which tracks the deployed release, so I believe it is —
  but the Reports card rests on a module whose builder was *unusable* in
  `0.0.0192`–`0.0.0193` while every clause of the draft checked out. If Reports is
  known broken again, say so and I pull the card the same day.
- **`0049`** is an FYI to the SES/Mail owner: `MailDispatcher.php:215` tells a
  customer to open "Settings, Sending Address", a screen `0.0.0211` deleted. Latent
  (the branch above it throws first), so nothing to do with this publish.
