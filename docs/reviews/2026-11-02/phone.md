# Phone / Communication — review 2026-11-02

Measured on production (API as Jose's Viewer key, tinker on the box) at ~15:05 UTC.

## 1. Working

- Connections: A-1 Chimney and AngiSweep subaccounts are both `connected`.
- Numbers: 13 lines. Each one's call-flow steps render (arrival → hours → menu → answer).
- Calls list loads (318 calls).
- Messages list loads (1,671 texts). The 855 line sent 659 review-request texts in the last 7 days, with caller names shown (e.g. "Cody Cabra").
- Ring groups (3): the members and their live state show (2 registered, 8 on do-not-disturb).
- Voicemail box, call tags (4), Reports run (30-day totals) and Service request all load and show their data.
- No failed phone/Twilio/SMS jobs in 7 days.
- No phone errors in today's log.
- Saved recordings: 0 files on disk, so the 10-27 cleanup held.

## 2. Broken

- **Number capabilities stored garbled.**
  - Evidence: `GET phone/numbers` returns keys `"\u0000*\u0000sms"` etc. on all 12 imported lines, and `[]` on the 855.
  - Why: Twilio's SDK returns a `PhoneNumberCapabilities` object whose properties are protected. An `(array)` cast turned them into NUL-prefixed keys, so every `capabilities['sms']` read is missing.
  - Fix: `HttpTwilioGateway::capabilities()` reads the getters (SHA below).
  - Already-stored rows refresh the next time a line's carrier panel is opened (`NumbersController` ~1039). They can also be rewritten in one go after a dump.
- **Call tagging never happens.**
  - A-1 has `require_call_tag: true`, but the 30-day report shows 21 of 21 calls untagged, and every tag has `call_count: 0`.
  - Cause not yet traced. Likely the wrap-up prompt only fires on the in-app softphone, and most calls ran elsewhere. Next for me.

## 3. Cohesiveness

- Messages and Calls lists download everything at once (848 KB and 367 KB). Every other module pages, with a date bar and search.
- The Voicemail box shows `line_count: 0`, yet it is the company default for every line. The count reads as "unused".

## 4. Suggestions (ranked)

1. **Page Messages and Calls** (`MessagesController:75`, `CallsController:303` are unbounded `get()`).
   - Texts grow by ~660 a week from review requests.
   - Payoff: the Communication tab stays fast.
2. **Store text delivery status.**
   - `phone_messages` has no status/error column, so an undelivered review text looks sent.
   - Payoff: see failures (STOP, landline, carrier blocks).
3. **Show "nobody answers this line" as a warning on the Numbers pane.**
   - Payoff: 7 of 13 lines silently go straight to voicemail.
4. **Count lines that inherit the company default voicemail box in `line_count`.**

## 5. Needs Jose

- 7 personal lines (General, Sasha, Adeline, Jonathan, Rodrigo, Eli, 855) have no ring group. Callers hear the IVR menu, then voicemail. Should each line ring its own person?
- Every A-1 line, including personal ones, plays the company IVR menu. Is that wanted on personal lines?
- 8 of 10 ring-group members are on do-not-disturb right now. Is that expected (e.g. calls still on RingCentral)?
- The "Testing" ring group and the 09-10 test service request ("eeeee dasdasd…") are leftovers. OK to delete?
