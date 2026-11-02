# Customer Experience — review 2026-11-02 (measured on prod via the API, viewer key)

## 1. Working
- Reviews list: 24,655 reviews in reach, 34 locations, 763 unanswered; stats (monthly, histogram, replies) answer 200.
- Sources: Google 24,258 (avg 4.88, synced 12:44 today), eKomi 397 (avg 4.96, synced 12:51 today), Thumbtack shown as not connected.
- Review links report: 1,601 sent, 190 opened, 124 happy → Google, 34 unhappy, 27 private feedback; per branch and per person.
- Phone check endpoint answers for all 38 listings.

## 2. Broken
- **Phone check said "mismatch" on 28 of 38 listings, wrongly.** Why: it only read branch-scoped EzBizi lines, but A1 keeps all 14 lines on the company, so "EzBizi lines" was always empty; it then compared each local listing number with the website's central (888) 710-3353, which never matches. Fix: company lines count; the website number decides only when EzBizi has no lines. SHA 924034944.
- **Reviews screen lists 20 of 37 Google connections** (meta.connections). Not yet traced; may be the viewer key's reach rather than a defect — to confirm with an admin login.
- 8 listings are named just "A1 Chimney" (no city) in the location filter, so they can't be told apart. Their Google titles carry no city; needs a label fallback (address locality).

## 3. Cohesiveness
- Location filter mixes "A1 Chimney" and "A1 Chimney · City" labels (above).
- 1,000+ review-link sends have no sender ("by person" null row); the report shows it as a blank name — should read "Automation".
- "Removed account" appears 6 times as separate rows in the by-person table; merge into one row.

## 4. Suggestions
1. Turn on AI drafts for the 763 unanswered reviews (batch drafting is built; by_ai = 0 today) — clears the backlog in one pass once Google writes are on.
2. Label sender-less review links "Automation" — makes the per-person table readable.
3. Address-locality fallback for city-less listing names — filter becomes usable.
4. Show the low-star card count on the Reviews header (638 one-star reviews all-time) — bad reviews surface without scrolling.

## 5. Needs Jose
- Turn on Google writes (replies, media, services, phone fixes)? Everything is built and refused until then.
- Enable the review auto-reply template once Rodrigo's reconciler is off?
