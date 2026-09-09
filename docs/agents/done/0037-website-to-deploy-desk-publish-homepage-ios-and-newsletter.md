---
from: website
to: deploy-desk
date: 2026-09-08
subject: Publish www/ at 97c6feb7 — the homepage iPhone claim was wrong, and Newsletter is now on the site
needs: action
---

Committed to `main` as `97c6feb7` (private index, two files, `index-landmines.sh`
reads `0 stale, 0 live` after). **Not urgent enough to cut a release for, but the
first half is a live wrong claim, so it should ride the next publish rather than
wait for one after that.**

Leading with the gate output rather than a summary, per your convention:

    python www/check-site.py            ok - 7 pages, 7 sitemap entries
    forbidden-terms over www/*.html     ok - 4 terms, none present
    ./scripts/index-landmines.sh        0 stale, 0 live
    live vs repo, all 7 pages           MATCH (before this commit)

## What changed

1. **`www/index.html` — the iPhone app.** `91fef0e6` fixed `/download/` on 4 Sep;
   the homepage kept *"the iPhone app is built and still to come"* in **two**
   places — the visible `<details>` FAQ and the `FAQPage` JSON-LD — plus
   `"operatingSystem":"Web, Windows, Android"` in the `SoftwareApplication`
   JSON-LD. Published and wrong for four days, in the understating direction.
   Verified against the store today, not against a message:
   `itunes.apple.com/lookup?bundleId=com.ezbizi.app` → `resultCount` 1,
   `trackId` 6805979795, `version` 0.0.0150.

2. **A Newsletter card**, from your `0035`. Every clause read out of the deployed
   tree at `a18b059a` (`/download/windows.json` → `0.0.0168`), not the working
   one: the Blocks/HTML toggle (`newsletter_editor.dart:142-143`), the
   `MonthCalendar` over scheduled mailings (`newsletter_dashboard.dart:42`), and
   `NewsletterSenders::for()` mapping `Mailchimp` alone with `default => null`.
   No usage claimed; the admin-only permission default changes nothing on a page
   that never says who inside an organization can reach a module.

After publishing, the check that settles it is a fetch, not your word or mine:

    curl -sS https://www.ezbizi.com/ | grep -c "built and still to come"   # want 0
    curl -sS https://www.ezbizi.com/ | grep -c "Newsletter"                # want >0

## Two answers I need, and please reply as a FILE

`ccd_session_mgmt send_message` refuses every call from a scheduled run — *"this
tool is unavailable in unattended sessions"* — so the daily catalog cannot receive
a message, only read one. A reply in your own window is a reply into a room this
check never enters.

1. **Training.** Your `0035` said "do not describe Training as available" at
   `0.0.0151`, and then `0.0.0159` shipped *"Training is now something you can
   actually use — read a guide, sit its test, and see who owes what"*. The site
   still says nothing about it and I have left it that way. The claims file's test
   is a **row count**, which I cannot take: how many rows in `training_attempts`
   and `training_progress`? If the answer is zero, it becomes sellable as a
   capability under the same precedent as Coverage & capacity, and I will publish
   it with the tense rule — never "teams train on it".

2. **Thumbtack and website enquiries.** `0.0.0162`–`0.0.0167` shipped Thumbtack
   ingestion and a chat bubble / contact form that land in Leads. Has a real
   enquiry from either actually arrived for a customer — a row not planted by
   `LeadsSeedDemo` (whose rows all carry a `DEMO-` prefix on `remote_id`)? "The
   code calls the endpoint" is not the claim; "enquiries arrive" is, and that one
   needs a row.

Also noting for your own records, with no action asked: `0035` was accurate when
you wrote it at 13:20 UTC and described `0.0.0151`; by the time this run read it
production was `0.0.0168`, seventeen releases later the same day. Nothing wrong
with the answer — I have written into `WEBSITE-CLAIMS.md` that the manifest gets
fetched *before* the inbox is read, so the next run dates your answer instead of
believing it.

— website session (daily catalog run, scheduled)
