# RetardMoments.md

Jose's name for it (2026-11-13): every mistake the deploy desk made, so it is never made twice.
Newest first. Each entry: what happened, what it cost, the rule that stops it.

## 2026-11-13 — Audit hand-over hid 9 PHPUnit warnings (failed the 0574 precheck)
- **What:** the audit merge left `OAuthTokenRequestShapeTest`'s 3-argument tests on 6-field data-provider rows. PHPUnit warned 9 times and exited 1; I read the pao JSON, saw only the 17 known Windows fails, and called the red accounted for.
- **Cost:** 0574 precheck failed; the desk fixed it (36fce1c42).
- **Rule:** run phpunit with `--log-events-text <file>` and require 0 `PHPUnit Warning`; a red exit is unexplained until failures + errors + warnings account for it.

## 2026-11-12 — Automation text edit wiped the rules (biggest one)
- **What:** to change four auto-reply texts I PATCHed `/api/v1/automations/{id}` with only `actions`. The API saved new versions with **no conditions**. "Closed Office - Initial Auto Reply", "Closed Office - Message Received", "Not booked - after a call" and "Not booked - went quiet" fired on every event for ~18 h.
- **Cost:** ~340 "we're closed" replies (many during open hours), ~80 "we got your message", ~110 "went quiet", and not-booked texts to booked leads. Jose found it on an A1 Garage Doors lead that got an open AND a closed reply.
- **Rule:** when editing any versioned record (automation, template, setting), send the **whole** version and **read the saved version back and diff it** before moving on. Assume an omitted field means "clear".

## 2026-11-12 — Shipped 0.0.0566 without opening the screens it touched
- **What:** `WorkizHealth` used `->filter('is_array')`; Laravel passes (value, key), and `is_array` throws on 2 args. `GET social/connections` 500'd → Integrations showed "Server Error", no cards, no Monday button, search "not working".
- **Rule:** after every release, open each screen it touched as Jose (browser or HTTP kernel) before saying it shipped. Never pass a builtin name as a collection callback.

## 2026-11-12 — Hot-patch copied the wrong version of a file
- **What:** to hot-fix the line above I copied `main`'s whole `WorkizHealth.php` onto the 0.0.0566 release. Main's file referenced a class (`WorkizWatchdog`) that wasn't deployed → a different 500.
- **Rule:** a hot-patch edits **the deployed file** (back it up, change only the line, `php -l`, diff against the backup). Never copy a file from main onto a release.

## 2026-11-12 — Queued releases built from a SHA taken before the hotfix
- **What:** the 0567 waiter captured HEAD before my hotfixes; its deploy would have silently reverted them. Caught and re-queued twice.
- **Rule:** after any hot-patch on prod, re-queue the next release from a SHA that contains it, and have the waiter `merge-base --is-ancestor <hotfix> $SHA || exit`.

## 2026-11-12 — Committed release notes without checking analyze
- **What:** an apostrophe in a Dart single-quoted string (`Twilio's`) broke `version_history.dart`; analyze reported 12 errors after the commit.
- **Rule:** `flutter analyze` must print "No issues" **before** the commit (`… | grep -q "No issues" || exit`). Use ’ in notes.

## 2026-11-12 — Monday sign-in shipped against the legacy token endpoint
- **What:** the app has Monday's New OAuth Flow on; the code exchanged codes at `/oauth2/token` (401 invalid_grant). Connect failed in front of Jose; access tokens also expire under the new flow and nothing refreshed them.
- **Rule:** before shipping a provider connect, read the provider's current docs for the exact flow the app is configured for, and do one real connect on prod before reporting it works.

## 2026-11-12 — Imported files reported as done without checking their type
- **What:** the Monday import stored 79 files (67 videos) as `application/octet-stream`; they couldn't play and got no posters. Reported "all 705 files" before looking.
- **Rule:** after an import, group the result by type/status and look at it before reporting.

## 2026-11-12 — Customer texts sent the wrong way, three times asked
- **What:** Jose asked for the texts "to my phone"; I sent one WhatsApp summary instead of the real SMS. He had to ask three times.
- **Rule:** "send me the texts" = the exact messages, through the same channel the customer gets them, one per message.

## 2026-11-12 — Restarted 0566 three times for late merges
- **What:** stopped and recut the same release three times to squeeze in SHAs, costing ~30 min each.
- **Rule:** once a release is past precheck, late work goes in the next one unless Jose asks or it fixes something broken in prod.

## Earlier (from this session's history)
- Test job edited only in EzBizi instead of created through the normal path (Jose: "it needs to be as if it was a real job… copy on workiz too").
- `git add` + commit took a peer's staged deletions (3aaa977c1) → always path-limited commits.
- A kill on the box matched my own ssh command (pgrep on the SHA) → grep the gate-run path, kill by PID.
