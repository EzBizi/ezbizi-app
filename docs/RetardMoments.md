# RetardMoments.md

Jose's name for it (2026-11-13): every mistake the deploy desk made, so it is never made twice.
Newest first. Each entry: what happened, what it cost, the rule that stops it.

## 2026-11-17 — Changed a status code and checked only the callers, not the tests that pin it
- **What:** Roles & Permissions turned out-of-reach mailboxes from 403 into 404 (eee9c0ae2). It grepped MailboxAccess callers and ran only its own new test. Two MailboxMessagesApiTest tests still asserted the 403, and main went red under the 0.0.0589 cut.
- **Rule:** when a change alters a status code or a message, grep tests/ for the route and for the old assertion (`assertForbidden` on that path), and run every test file naming the route before handing over the SHA.

## 2026-11-17 — Told Jose the video "will be sent" and never checked it was
- **What:** I handed the training video to Meta to send "once the template is approved". Meta left it PENDING for 5.5h and nothing went out. Jose had to ask; neither he nor Eve had it. An already-approved UTILITY template (automation_alert) could have sent it at once.
- **Rule:** never park a send on a third-party approval. Use an approved path first and treat the new one as optional. If anything is pending for Jose, set a check (Monitor or cron) on its delivered status and report the outcome, not the plan.

## 2026-11-17 — Release script derived two versions back: 0588 ran under 0587's names
- **What:** I made q0588.sh from q0586.sh with `s/0587/0588; s/0586/0587`. It is two versions back, so every name became 0587. The 0588 cut deleted and re-created the cut-0587 worktree at 0588's SHA while 0587's Windows build was still using it. Windows published 0.0.0588 about an hour before the 0588 server, and 0587's own Windows build never shipped.
- **Rule:** derive qN only from q(N-1) with `s/(N-1)/N; s/(N-2)/(N-1)`. Then grep the new script: every cut-/rel-/precheck- name must be N. Never edit a running qNNNN.sh in place; bash reads the edited bytes.

## 2026-11-17 — PPC by number dialed: measured the wrong line and told Jose it was impossible
- **What:** Jose asked to set source PPC when the number dialed is PPC. Automations measured Workiz `to_number` lines and Google Ads call assets, found every line mixed, and I told Jose it could not work and offered to buy tracking numbers. I also passed on an unmeasured claim ("PPC callers dial the website number"). Our own Reports › Jobs › Number dialed column already shows the RingCentral line names ("NJ - PPC", "CT - PPC").
- **Rule:** before saying a field can't be derived, open the screen that already shows it. The RingCentral line name decides the dialed source. Only state a cause that someone measured.

## 2026-11-16 — Meta: told Jose to pay for two tenants' WhatsApp
- **What:** I read "Missing valid payment method" in EzBizi's WhatsApp Manager and told Jose to add a card to the A1 Chimney and EverDoors accounts. Both WABAs are owned by their own businesses (`ownership_type` CLIENT_OWNED). EzBizi's view was the partner's view. A test send from A1 Chimney was delivered.
- **Rule:** before telling anyone to pay, read `owner_business_info`/`ownership_type` on the WABA. A tenant's billing is the tenant's, never Jose's.

## 2026-11-16 — Reports: a filtered phpunit run voided my own full gate
- **What:** while the 0.0.0577 P&L gate ran on `ezbizi_testing_reports`, I started a one-test `--filter` run on the same schema. Its migrate dropped the tables under the gate: 4245 "table doesn't exist" errors.
- **Cost:** a 100-minute gate thrown away; the P&L SHA reached the desk ~2 hours late.
- **Rule:** before any phpunit, check no phpunit of mine is running on that schema (`Get-CimInstance Win32_Process -Filter "Name='php.exe'"`). While a gate runs, write code and run only `flutter test`.

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
