# Leads, Google (Ads, LSA, Business Profile) and Customer Experience: review, 2026-11-02

Everything was measured on production on 2026-11-02, between 14:30 and 15:40 UTC:
- with the live API as the Viewer key (`me`, `leads`, `leads/sources`, `leads/dashboard`, `leads/hidden`, `leads/office-hours`, `reviews/sources`, `business-profile/phone-check`, `business-profile/performance`);
- with tinker reads on the box (counts, last-arrival times, connections, failed_jobs);
- with nginx access logs (Thumbtack webhooks) and live calls to Google, Thumbtack and eKomi.

Nothing was written for testing except the two backfills under Broken. Both were dumped first.

## 1. Working

- **Leads intake, last 7 days:**

  | Source | Leads | Last one in |
  |---|---|---|
  | RC Calls | 951 | today |
  | Local Services | 1,200 | today |
  | Website forms | 69 | today |
  | Website chat | 49 | today |
  | Facebook | 39 | today |
  | Instagram | 9 | Nov 1 |
  | Online booking | 2 | Nov 1 |
  | WhatsApp | 2 | Oct 29 |
  | EZ Texts | 1 | Oct 28 |
  | Workiz | 1 | Oct 28 |

  57,084 leads in total; 217 in the last 24 hours.
- **Leads dashboard (Oct 27 – Nov 2):** 1,911 leads, 234 answerable, 188 answered, median first reply 86 s, 172 answered within 10 min, 630 booked, 155 automatic replies. Per-person rows are present (Ara 157 leads answered, median 81 s).
- **failed_jobs:** none in the last 7 days, for any class.
- **Google Ads / LSA:** 58 Ads connections, all `connected`, all reached today (oldest 15:20 UTC). Certification is `ok`, with campaigns `ENABLED / ELIGIBLE`. LSA leads arrive the same day.
- **Business Profile:**
  - 37 connections, all reached today.
  - 25,839 Google reviews held, last synced 12:45 UTC today; 128 new in 7 days. 24,159 replies are published (Google's own replies, imported).
  - GBP v4 has been enabled since 10-30. Reviews, media, review replies and writes are live (`GBP_WRITE=true`).
- **Business Profile performance:** 4 weeks of calls, clicks, directions and impressions, per listing and daily, through 2026-11-01.
- **Leads › Manage › Connections (`leads/sources`):** 58 web sites, with plugin version, chat/booking on, lead counts and edit rights.
- **Hidden conversations:** the endpoint answers. 0 hidden; nothing has been hidden yet.
- **Office hours:** the org → company → branch chain resolves. It's used by 6 live auto-reply rules.
- **Reviews › Sources:** Google 24,258 (4.88 average), eKomi 397 (4.96), both synced today. Thumbtack is honestly shown as "not connected" (it has no reviews API).
- **eKomi:** its connection is reached today. The live snapshot, recipients and activity stream all answer with the fields the new screen uses.
- **Thumbtack:** the grant is valid (keep-alive run by hand: "Refreshed 1, failed 0"). The webhook answers 200 (10 calls since Oct 21).

## 2. Broken

1. **1,288 of last week's leads had no name.**
   - Breakdown: RC Calls 720, LS calls 566, WhatsApp 2. Neither RingCentral's caller ID nor Google's call record carries one.
   - Fix: ran `ezbizi:leads-fill-details` on prod, which names leads from known customers and staff numbers. **15,335 leads named**, plus 7 website chats given their campaign.
   - Dump first: `/var/backups/ezbizi-leads-before-fill-details-202611021523.sql.gz` (4.9 MB, complete).
   - What's left is unknown callers. Nothing names those (see Suggestions 2).
2. **Thumbtack: no lead since 2026-10-01.**
   - Not broken on our side: the grant is valid, the webhook is registered and answering, and Thumbtack sent 10 calls since Oct 21, all answered 200.
   - Either A-1's Thumbtack ads stopped on Oct 1, or those calls named a business that isn't connected. Their API can't list leads, so EzBizi can't tell which. → Needs Jose.
   - Also ran `ezbizi:thumbtack-lead-costs --all`: 19 of 19 Thumbtack leads priced (same dump).
3. **"Business Profile performance" listed 37 rows all named "A1 Chimney".**
   - Cause: it printed the listing title, which is the same on every A-1 listing.
   - Fixed: `82f521b39` now uses the city label ("A1 Chimney · Houston, TX"), the way Phone check already does.
4. **Review auto-reply: 40 listings are switched on and nothing has been posted.**
   - Cause: no rule existed (`review rules: []`). 16 new reviews in 3 days went unanswered.
   - Fixed in `403bedc0b` + `9ab22f488` (the switch makes the rule, words per star rating), with Automations' `c42a9cb00`. After that release, `ezbizi:review-auto-reply-rule` must run once.
5. **Phone check marks 0 of 38 listings as matching** (25 "mismatch", 13 "unknown").
   - Cause: it compares each listing's local number with the website's single main number, (888) 710-3353, and with "EzBizi lines", which A-1 has none of (its lines are on RingCentral). So every listing reads as wrong.
   - Owner: Customer Experience (they shipped `business-profile/phone-check`).
   - Fix: compare with the branch's RingCentral/tracking numbers, and treat a different website number as "site uses a main number" rather than a mismatch.
6. **15 A-1 listings are stuck at their 10-29 sync.** They sit on the 4 Google accounts that were disconnected (Pittsburgh, Oklahoma City, New York, Rodrigo's). Their data ages, and they count in the 58 "listed". → Needs Jose (connect the accounts).
7. **33 of 58 listings have no branch:** the 15 above + 18 reachable. Their reviews and leads don't reach a branch. → Needs Jose (assign them in Business Profile).
8. **Massachusetts GBP:** one transient "Google did not answer while renewing" on 10-30. It recovered on the next run; no action.

## 3. Cohesiveness (for UI-UX)

- **Leads rail:** Facebook / Instagram / WhatsApp become one Meta tab (design 166, `d116ed803`). That removes the only three rail tabs that each held a handful of leads.
- **Business Profile performance:** the last 2–3 days read **0** because Google reports late, which looks like a collapse. Other date bars in the app end at the last day with data. It should end at `last_date` (already in the answer) or grey those days out.
- **Listing names:** Phone check and Performance now both use "Title · City". Review lists still show the raw location name in places.
- **Sources:** eKomi still shows only "Connected" until the eKomi screen (design 165) ships. The other two sources open screens.

## 4. Suggestions (ranked)

1. **Run the auto-reply rule backfill the moment the release lands.** Every day before that, new reviews on 40 switched-on listings go unanswered.
2. **Name callers with Twilio Lookup's caller name.** The Phone webhooks already fetch it and throw it away. Storing it on the lead would cut the ~1,300 unnamed leads a week.
3. **Mark unreachable GBP listings as "account not connected"** instead of listed, so counts and maps don't include data that's days old.
4. **Phone check against tracking numbers** (Broken 5). It's the only screen that tells Jose a Google listing points at the wrong line, and today it can't.
5. **Thumbtack heartbeat:** when a connected source with leads goes 14 days without one, put a notice on its rail tab. A month of silence went unnoticed.
6. **Reserve with Google:** the partner form was submitted 10-30 and the spec is in `docs/superpowers/specs/2026-10-30-reserve-with-google-channel-design.md`. Build only after Google's invitation.

## 5. Needs Jose

1. Did A-1's Thumbtack ads stop on Oct 1, or should Thumbtack leads still be arriving?
2. Connect the Google accounts holding the 15 unreachable A-1 listings: Pittsburgh, Oklahoma City, New York, Rodrigo's, plus Morton Grove IL and Woodbridge NJ.
3. Assign branches to the 18 reachable listings without one (Business Profile).
4. Which WhatsApp number goes on A-1, EverDoors and Roofirst profiles? A-1's show 888-673-5556, which isn't an EzBizi number.
5. Should A1 Leads' own Google grant be removed now that EzBizi writes to the profiles? While it stays, both systems can edit the same listings.

Fix SHAs from this review: `82f521b39` (performance names), `6ac351390` (reviews `?source=` for the eKomi screen). Prod backfills: leads-fill-details (15,335 named), thumbtack-lead-costs (19 priced).
