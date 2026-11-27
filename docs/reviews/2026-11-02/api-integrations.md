# API Integrations — review 2026-11-02

Measured on production (`app`, release 27b215107) by read-only tinker. No writes, no test posts.
UI screens (Settings › Integrations, Connect cards, disconnect panel) were NOT opened in this pass —
everything below is from live data, provider readiness and logs. UI-UX should pair this with a click-through.

## 1. Working

- 141 connection rows, all `status=connected`, none `broken`/`revoked` (A-1 Group org; plus 1 demo Google Ads row in EzBizi Demo).
- Provider mix: Google Ads 58, Business Profile 37, Thumbtack 12, Workiz 11 + Workiz web 1, Airtable 6, YouTube 2, RingCentral 2, Pinterest 2, and one each of Facebook Page, Facebook Ads, Instagram, TikTok, X, Bluesky, Mailchimp, WhatsApp, eKomi.
- Google Ads / Business Profile / Workiz web / eKomi reached today (refresh loop healthy); Workiz all 10 branches reached 10-26 (post key rotation).
- `notReadyBecause()`: every provider READY except LinkedIn ("no LinkedIn app set up") — matches known state (needs company page).
- Social publishing, last 30 days: 5 sent (YouTube 2, Pinterest 2, Instagram 1), 0 stuck pending/queued >1h.
- Pinterest Standard live: the only failure (10-09, "Trial access may not create Pins") predates the 10-12 approval; both posts since were sent.
- TikTok config: client key/secret set, redirect `https://app.ezbizi.com/api/v1/social/tiktok/callback`, `direct_post=true`; publisher reads `creator_info` and only offers the privacy levels TikTok returns (so an unaudited app posts SELF_ONLY, not a refusal).
- failed_jobs, last 30 days: zero rows touching social/integrations classes.
- Telegram: READY, no connections yet.

## 2. Broken

- **`ezbizi:airtable-snapshot` exits 1 every hour** — 24/day on each of the last 7 rotated logs, 15 today. Evidence: `laravel.log` "Scheduled command [...] ezbizi:airtable-snapshot] failed with exit code [1]".
  WHY (measured): FinancialSource `01a1547f-ce82-...` (role `repair`, markers Job / Weekly Summary, created 10-19) maps table `tblo2IX58b5GWiU7E` in base `appzD9I7nNkALmw4l`, and that table id is not in the base — Airtable's meta API lists the base (200) without it, for all 6 tokens. Zero rows were ever copied for it, so the id was wrong from creation (or the table was deleted the same day). The other 66 sources copy fine; the command's exit code just reports the one bad mapping hourly, so the error log has been noise for two weeks and a real Airtable failure would be invisible in it.
  Owner: Financials (the mapping). Not fixed here — it is tenant configuration; re-map or delete that source (question 1).
- **Workiz rate limit, today** — ~40 "Rate limit exceeded" warnings (sync, past-job re-read, lead import, records, calls) across Texas, New Jersey, Ohio, DMV, Illinois, Connecticut. Warnings, the next run retries; owner Workiz sync. Flagged because the re-read of past jobs and lead import now collide on the same per-account budget.

No code defect found in Settings › Integrations / publishing on the data measured; no fix SHAs.

## 3. Cohesiveness

- Connection health is only ever `connected` for TikTok, X, YouTube, Bluesky, Pinterest: their tokens are refreshed only when a post goes out, so `broken` is written at publish time, never on the card. TikTok's access token expired 10-16 and the row was last reached 08-28; Bluesky last reached 08-27; X/YouTube 10-12 — the card still reads "Connected". Google/Thumbtack/Workiz rows, by contrast, prove themselves daily. Same word, two meanings.
- Six Airtable connections in one organization (all company-scoped, all reach the same 6 bases). The snapshot uses whichever row the query returns first; the Integrations list shows six look-alike cards.

## 4. Suggestions (ranked)

1. Make the Airtable snapshot exit 0 when only some tables fail and log one WARNING per bad table — the hourly ERROR has trained everyone to ignore the log. (Small; Financials/Reports owns the command.)
2. Daily token probe for publish-only providers (TikTok, X, YouTube, Bluesky, Pinterest, Instagram): refresh + a cheap `me` read, write `broken` on refusal — the card tells the truth before a scheduled post fails.
3. Show "last checked" on every connection card (`last_reached_at` is already stored) — one cohesive health line across providers.
4. Collapse duplicate Airtable connections (same organization, same reach) to one, or label which one the snapshot uses.
5. Workiz: stagger the past-job re-read away from lead import so the per-account rate budget is not spent twice in the same minutes.

## 5. Needs Jose

1. Airtable source "repair" (Job / Weekly Summary) points at a table that does not exist in its base — re-map it to the right table, or delete it?
2. Six Airtable connections on A-1 Group — keep all six, or keep one?
