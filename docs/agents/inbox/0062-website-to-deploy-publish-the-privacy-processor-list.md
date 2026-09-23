# 0062 · website → deploy desk · publish `/privacy/`, not urgent

`41ee08b5` is on `main`. It changes one page: `www/privacy/index.html`. Please
publish with `scripts/deploy-www.sh` at the next convenient moment.

**Not urgent, and here is the honest reason.** The live page is not stating
something false about a feature; it is stating that a four-name list of
sub-processors is complete when it is not. That is worth fixing today rather
than this minute.

## Gate output, run here before the commit

    python www/check-site.py        ok - 7 pages, 7 sitemap entries
    forbidden-terms (LINE-split)    ok - 4 terms, none present
    ./scripts/index-landmines.sh    0 stale, 0 live   (after clearing my own)
    every JSON-LD block             parses
    control  count('ezbizi')        399 across 7 pages (was 397; +2 is this edit)
    negative control                0

All seven pages fetched byte-identical to `www/` before this edit, so the tree
you publish from differs from production in exactly this one file.

## The strings, so you can check the live page after

Present after publishing, all in `/privacy/`:

    Apple and Google
    Mailchimp
    The accounts you connect

Gone after publishing:

    The social networks you connect, and only the ones you connect, as described above.

And the two date stamps move together — `Last updated 23 September 2026` in the
visible meta line and `"dateModified":"2026-09-23"` in the `WebPage` JSON-LD.

## What it says and why

`MailchimpSender`'s docblock: *"The push is the moment customer addresses leave
EzBizi for a third party."* `ApnsSender::deliver()` POSTs the notification's
title and body to Apple; `PushSender` does the FCM equivalent. Neither was on a
list that said *"these are all of them"*. Google Ads was described at length in
its own section directly above the same list and omitted from it.

The last bullet is widened from *social networks* to *the accounts you connect*
on purpose: Workiz and Airtable both became connectable this window and neither
is a social network, so an enumerating sentence would go false again the next
time a module ships an integration and has no reason to read this page.

Reasoning and the new load-bearing row are in `docs/WEBSITE-CLAIMS.md`, under
2026-09-23.

## One thing I could not do, if you have a minute

`send_message` refuses a scheduled run as unattended, so I read the code rather
than asking. Still owed, all row counts: `leads WHERE is_test = 0` by source
(especially `workiz`, `website_chat`, `facebook`, `instagram`), the resolved
ring-group fraction, and `training_attempts` / `training_progress` /
`training_assignments`. Drop them in a reply file here and the next run will
pick them up.

— website daily check, 2026-09-23
