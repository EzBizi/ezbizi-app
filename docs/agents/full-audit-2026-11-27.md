# EzBizi full audit — 2026-11-27

Audited tree: `main` at `9417352cc` (2026-11-27). Scope: `app/`, `client/lib`, `client/windows/runner`, `routes/`, `config/`, `scripts/` — 61 slices, about 724k lines.

Method: every file read by a reviewer; every finding then checked by an adversarial verifier (critical and high checked twice).

- Raw findings: **673**. Confirmed after verification: **621**. Entries below: **615** (6 true duplicates merged into another entry; each merge is noted on the surviving entry).
- Files read: **2403**. Slices whose review failed: **none**.
- Files not fully read:
  - `app/Http/Controllers/Api/AutomationsController.php` — lines 390-700 (catalogue tail) and 2250-2750 (actionTypes / assertField*) only skimmed by grep for queries and auth calls; everything else read.
  - `client/lib/core/version_history.dart` — partly read (classes, first and last entries, a script check of order and duplicate numbers); lines ~80-6620 are release-note strings, not read one by one.
- No critical findings survived verification. Owners come from `docs/MODULE-OWNERS.md` and `docs/SESSIONS.md` (role names only). "Unowned" means neither file names an owner; the desk routes those.

## Summary: severity × category

| Severity | bug | concurrency | data-loss | dead-code | duplication | performance | security | ux | Total |
|---|---|---|---|---|---|---|---|---|---|
| Critical |  |  |  |  |  |  |  |  | **0** |
| High | 6 | 1 | 1 |  |  |  | 8 |  | **16** |
| Medium | 77 | 18 | 50 |  |  | 6 | 35 | 17 | **203** |
| Low | 139 | 55 | 25 | 11 | 1 | 49 | 38 | 78 | **396** |
| **Total** | **222** | **74** | **76** | **11** | **1** | **55** | **81** | **95** | **615** |

## Findings per owner

| Owner role | High | Medium | Low | Total |
|---|---|---|---|---|
| Phone System | 3 | 22 | 27 | 52 |
| Task Board | 1 | 14 | 22 | 37 |
| Financials |  | 7 | 27 | 34 |
| Leads + Google API | 1 | 10 | 20 | 31 |
| Human Resources | 1 | 7 | 21 | 29 |
| Reports | 1 | 10 | 18 | 29 |
| Email | 3 | 8 | 14 | 25 |
| Marketing |  | 4 | 17 | 21 |
| Web Management | 1 | 11 | 9 | 21 |
| Monitoring |  | 4 | 16 | 20 |
| Scheduling |  | 6 | 14 | 20 |
| Customer Experience |  | 5 | 14 | 19 |
| Settings |  | 4 | 15 | 19 |
| Field | 1 | 3 | 14 | 18 |
| Inspection |  | 8 | 8 | 16 |
| Automations | 2 | 6 | 7 | 15 |
| Fleet |  | 3 | 12 | 15 |
| Unowned — platform auth (desk) |  | 7 | 8 | 15 |
| Unowned — platform core (desk) |  | 5 | 10 | 15 |
| Deployment |  | 2 | 11 | 13 |
| AI / Grok |  |  | 11 | 11 |
| Jobs | 1 | 2 | 8 | 11 |
| Jobs (Project Management code) |  | 7 | 4 | 11 |
| Meta / WhatsApp |  | 7 | 4 | 11 |
| Training | 1 | 3 | 6 | 10 |
| Internal Chat |  | 5 | 4 | 9 |
| Workiz |  | 5 | 4 | 9 |
| Clock In / Work Hours |  | 6 | 2 | 8 |
| UI/UX |  | 2 | 6 | 8 |
| Inventory |  | 6 | 1 | 7 |
| MCP / API |  | 1 | 6 | 7 |
| Roles & Permissions |  | 2 | 5 | 7 |
| Customers |  | 1 | 5 | 6 |
| Dashboard |  | 2 | 4 | 6 |
| Membership Program |  |  | 6 | 6 |
| Services |  | 2 | 4 | 6 |
| API Integrations |  | 4 | 1 | 5 |
| Notifications |  | 2 | 3 | 5 |
| Plaid Integration |  |  | 3 | 3 |
| Amazon SES (platform mail) |  |  | 2 | 2 |
| Guidelines |  |  | 2 | 2 |
| Suggestions (session gone) |  |  | 1 | 1 |

Entry format: **title** — `file:line` — what goes wrong. Fix: what to change. The category is in brackets.

## Critical (0)

None survived verification.

## High (16)

### Email (3)

- [security] **`</style >` breaks out of sanitised email CSS into live HTML** — `app/Support/Email/HtmlEmailSanitiser.php:172` — Style blocks bypass HTMLPurifier and the regex stops only at the exact `</style>`. An incoming email can inject a meta refresh or a credential form; on Windows (WebView2, JS on) opening the mail sends the reader to a phishing page. Fix: parse and re-emit CSS (or escape `<` in it); add `form-action 'none'` and `base-uri 'none'` to the CSP.
- [bug] **Outlook watcher re-announces old mail every time a message is read, flagged or moved** — `app/Support/Email/MicrosoftMailboxReader.php:244` — Graph delta returns updated messages and every non-removed row is treated as an arrival; nothing de-duplicates by message id. Auto-reply rules on `email.received` resend to the customer on each read. Fix: keep announced ids per mailbox, or drop rows received before the previous mark.
- [security] **IMAP command injection through CR/LF in the search box** — `app/Support/Email/ImapMailboxReader.php:204` — Search quoting escapes only `\` and `"`; an embedded CRLF ends the command and the rest runs as new IMAP commands in the signed-in session (e.g. move the whole INBOX to Trash) with only `email.view`. Fix: reject `[\r\n\x00]` (use the existing `quoted()`), and strip control characters in the controllers' validation.

### Phone System (3)

- [security] **Twilio subaccount reused by company name hands one tenant another tenant's credentials** — `app/Support/Phone/HttpTwilioGateway.php:32` — `createSubaccount` returns the first platform subaccount whose friendly name matches the company name, with its auth token, and nothing checks other organizations already use it. A tenant who names (or renames) a company like another tenant's gets their numbers, messages and recordings. Fix: never reuse by name alone; only reuse an account_sid no other org's connection holds; apply the same filter to the subaccount listing.
- [bug] **on_call presence set on one line and released on another, leaving users stuck as on_call** — `client/lib/screens/phone/call_tab.dart:681` — The tab writes on_call for the selected line; the service releases the primary or ringing line. Server never clears on_call, and ring groups skip on_call people, so multi-company dispatchers silently stop receiving calls until re-login. Fix: let the call service own the on_call write and release the same connection id.
- [bug] **Windows softphone ignores every token renewal, so the desk stops ringing after about an hour** — `client/lib/screens/phone/voice_device_windows.dart:162` — `_starting` is set true and never reset, so every renewal `setup(newToken)` returns early. The Twilio registration lapses and inbound calls go to voicemail silently. Fix: reset in `finally`; on repeat setup post the new token (or `updateToken`) without reloading the page; add a two-setup test.

### Automations (2)

- [security] **Run-now runs a rule's real steps against any event in the organization** — `app/Http/Controllers/Api/AutomationsController.php:1606` — The event is loaded with tenant scope only; nothing checks its trigger, the rule's scope or the caller's reach. The client always posts `{}`, so "Run now" on a branch rule fires on the newest matching event anywhere in the org: real SMS/email to another company's customer, webhook posts their lead data. Fix: require `event_key == trigger_key`, `coversScope` and the caller's reach (resolve the event through `recentInView`), 404 otherwise.
- [security] **Rule templates read secret and encrypted attributes (2FA seeds, mailbox credentials)** — `app/Support/Automations/ActionRunner.php:3936` — `interpolate()` reads any `{{key}}` via `getAttribute`, which decrypts casts and ignores `$hidden`. A dry-run with `{{totp_secret}}` on a `user.activated` event returns another user's decrypted 2FA seed; saved rules can email it. Fix: resolve only an allow-list of trigger fields; skip hidden, encrypted and hashed attributes; add tests.

### Field (1)

- [bug] **Phone refuses to send an SOS when it has no GPS fix** — `client/lib/screens/field/dashboard_tab.dart:134` — On Android/iOS a null position (denied, services off, 20 s timeout) returns "Could not read your location" and never POSTs, although the server accepts an SOS without coordinates; the button shows no progress, so double taps arm it again. Fix: always POST, add lat/lng only when available; add a sending flag with a spinner.

### Human Resources (1)

- [security] **Reset-security lets a lower role take over an Administrator** — `app/Policies/UserPolicy.php:129` — `resetSecurity` checks only the permission, not-self and reach, never `mayAssignRole`. A non-admin holding `hr.security.reset` with org reach can wipe an admin's 2FA, fetch a live reset link and sign in as them. Fix: add the `mayAssignRole($user, $person->role)` guard `deletePermanently` already has; add a test with an admin target.

### Jobs (1)

- [data-loss] **Saving a title or note on an A1-imported estimate wipes its total** — `client/lib/screens/jobs/estimates_tab.dart:969` — Any dirty save on a draft/sent estimate sends `lines: []`; the server's `replaceLines` deletes lines and sets total to 0. Quoted totals on imported estimates are silently zeroed. Fix: send `lines` only when they changed; server could refuse empty lines on an estimate with a total and no lines.

### Leads + Google API (1)

- [concurrency] **Booking Approve/Book can run twice: two jobs, two Workiz jobs, two customer texts** — `client/lib/widgets/lead_thread.dart:2787` (merged: `app/Http/Controllers/Api/LeadsController.php:1163`, same root cause: `book()`/`decideBooking()` never lock the lead or re-check `job_id`) — Approve/Decline have no busy guard and the server reads `job_id` unlocked before slow Workiz calls. A double click books duplicate jobs with technicians and sends two uncapped confirmations. Fix: client busy flag; server transaction with `lockForUpdate` on lead and booking, re-check status/`job_id`, 409 if already booked.

### Reports (1)

- [bug] **`Auth::setUser` in a queue job leaks that user into every later job on the worker** — `app/Jobs/SendRequestedReport.php:75` — The auth singleton survives between jobs. Later jobs (any tenant) that audit-log without an actor, or create jobs, record this person as the actor or "created by" for up to an hour per worker. Fix: restore in `finally` (`app('auth')->forgetGuards()`), as `InternalRequest` does.

### Task Board (1)

- [security] **Task record fill looks up jobs and customers across every organization** — `app/Support/Tasks/TaskRecordFill.php:124` — `withoutGlobalScopes()` drops the tenant filter on Job/Customer lookups by number, phone and name, so another tenant's customer name, address, phone and job number are written into this org's task (and pushed to Monday) every 10 minutes. Fix: drop `withoutGlobalScopes()` or add `organization_id` to every query (lines 48, 50, 124-157).

### Training (1)

- [bug] **No control publishes a test, so tests made in the app stay drafts forever** — `client/lib/screens/training/test_builder.dart:132` — Tests are created as DRAFT and no client request ever sets status; learners, assignments, dashboard and automations only see published tests. Fix: add the Publish/Unpublish control the guide builder has to the test editor panel.

### Web Management (1)

- [security] **Public website chat joins a stranger's lead and returns their whole thread** — `app/Http/Controllers/Api/Web/EnquiriesController.php:147` (merged duplicate: `app/Support/Web/WebsiteEnquiries.php:70`, same root cause) — `startChat` reuses any chat lead with the same typed phone or email from the last 24h and mints a new token; `messagesFor` returns every conversation row on the lead. Anyone who knows a recent visitor's phone reads their messages, photo links and live staff replies. Fix: scope what a chat token reads to that chat (its own rows and replies after its `created_at`), or only join an earlier lead when the caller presents that chat's token.

## Medium (203)

### Phone System (22)

- [bug] **Staff message thread returns the oldest 200 messages, hiding new ones while marking them read** — `app/Http/Controllers/Api/StaffMessagesController.php:56` — Busy threads never show the latest message. Fix: take the newest 200 and reverse; add a `before` cursor.
- [performance] **Paid CNAM lookup runs on every inbound call and SMS, blocking the webhook, result unused** — `app/Http/Controllers/Api/Phone/VoiceWebhookController.php:96` — Also in SmsWebhookController:58 inside a transaction. Fix: remove `lookupQuietly()`; if needed, queue it and store the result.
- [concurrency] **Real-time transcript append is an unlocked read-modify-write** — `app/Http/Controllers/Api/Phone/TranscriptionWebhookController.php:70` — Overlapping utterance callbacks lose sentences. Fix: `lockForUpdate` or an atomic `CONCAT_WS` update.
- [bug] **Company after-hours default never applies** — `app/Models/PhoneNumber.php:207` — Every line stores "voicemail", which `resolved()` treats as an explicit choice, so a company "forward after hours" default is ignored. Fix: make the column nullable (null = inherit), migrate untouched rows.
- [bug] **Line-level "record off" cannot override a company "record on" default** — `app/Models/PhoneNumber.php:237` — `?:` treats false as unset, so calls on a line switched off are still recorded while the screen says not recording. Fix: nullable columns, resolve `null` as inherit; show resolved value and source.
- [bug] **STOP opt-out check compares the raw "to" string, so formatted numbers get past it** — `app/Support/Phone/SmsConsent.php:90` — A typed "(203) 555-..." or stored customer phone texts someone who opted out (TCPA exposure). Fix: normalise to E.164 at the gate and in `PhoneTexts::send`.
- [security] **Default "Everyone" ring group on a company account includes phone staff of every company** — `app/Support/Phone/ConnectionDefaults.php:43` — Other companies' dispatchers get Voice tokens and ring on that company's calls. Fix: narrow role members to the connection's company scope.
- [bug] **Any message containing "stop" is sent without the opt-out line** — `app/Support/Phone/SmsConsent.php:107` — "Stop by this week…" promotions go out without opt-out wording; the preview agrees. Fix: only skip for an actual "reply/text STOP" instruction, in server and client preview.
- [bug] **Blocks saved on the first phone connection only, so other companies' lines still ring** — `client/lib/screens/phone/blocked_numbers_pane.dart:118` — Fix: block per chosen or every reachable connection; fix copy.
- [bug] **Switching "Calling as" does not reload that company's rules, presence or thread** — `client/lib/screens/phone/call_tab.dart:1771` — Texts can go from a number whose history is not on screen. Fix: reload rules, desk and messages on pick.
- [bug] **"Tag required before next call" only applies when this user presses Hang up** — `client/lib/screens/phone/call_tab.dart:757` — Fix: prompt on any on-call to off-call transition.
- [data-loss] **Editing hours deletes every opening period after the first on each day** — `client/lib/screens/phone/hours_pane.dart:788` — Fix: edit all intervals or preserve the hidden ones.
- [data-loss] **Removing a menu key leaves stale text in shifted rows** — `client/lib/screens/phone/flows_pane.dart:792` — Saved values differ from the screen. Fix: stable row keys.
- [ux] **Every company account has the same label in the ring-group account picker** — `client/lib/screens/phone/ring_groups_pane.dart:1146` — Fix: use `scope_name`.
- [bug] **New IVR menus (and hours) always created on the first phone account** — `client/lib/screens/phone/flows_pane.dart:317` — Fix: add an account picker; filter ring groups.
- [data-loss] **One click Remove on hours or a menu silently detaches every line using it** — `client/lib/screens/phone/hours_pane.dart:324` — Fix: confirm naming the lines, or refuse while in use.
- [bug] **Cost pane always asks for the organization's usage, which never has an account** — `client/lib/screens/phone/settings_tab.dart:580` — Fix: scope to a company.
- [bug] **A failed handset call is swallowed, leaving a fake "On a call" state** — `client/lib/screens/phone/voice_device_mobile.dart:613` — Fix: read the result and throw CallRefused.
- [bug] **Tag rule and wrap-up write an arbitrary company's account** — `client/lib/screens/phone/tags_pane.dart:127` — Fix: pick the account from scope or a picker.
- [ux] **Trust status shows one arbitrary account with no way to choose** — `client/lib/screens/phone/trust_pane.dart:94` — Fix: account picker and label.
- [ux] **A failed voicemail load looks like "No voicemails yet"** — `client/lib/screens/phone/voicemails_pane.dart:171` — Fix: show the error with Try again.
- [ux] **An open staff thread never shows new replies** — `client/lib/screens/phone/staff_thread.dart:82` — Fix: live subscription or poll.

### Task Board (14)

- [bug] **Tasks announced overdue on the evening of their due date** — `app/Console/Commands/TasksOverdue.php:33` — "Today" is the UTC date; after 7-8 PM Eastern every task due today gets an Overdue bell and fires overdue rules. Fix: compute today per organization timezone as TasksDatesArrived does.
- [data-loss] **Board purge leaves task-update attachments and their files behind** — `app/Console/Commands/PurgeDeletedTaskBoards.php:27` — SQL cascades skip model events, so attachment rows and files are orphaned indefinitely, outside privacy tooling. Fix: delete the board's attachments through the model/service before `forceDelete`.
- [security] **Board PATCH authorizes on which key is present but saves every validated field** — `app/Http/Controllers/Api/TaskBoardsController.php:605` — Including `position` lets a viewer with `tasks.create_boards` also change `edit_mode` or built-in columns. Fix: authorize per field group, or reject mixed requests.
- [security] **Updates feed exposes file names and links on sensitive tasks** — `app/Http/Controllers/Api/TasksController.php:743` — Body is hidden but attachments keep real name, size, type and URL. Fix: blank or stub attachments when the task is sensitive.
- [concurrency] **Kinds merged into a Monday outbox row during its send are lost when it is closed** — `app/Support/Tasks/Monday/MondayPush.php:561` — A rename during a status push never reaches Monday. Fix: close with a conditional update or a "sending" state.
- [bug] **Board merge re-points only rules whose conditions name the board** — `app/Support/Tasks/Monday/MondayBoardMerge.php:510` — Rules naming it in actions point at the deleted board and fail or skip silently. Fix: search conditions and actions.
- [concurrency] **Multi-select cells build the next list from stale ids, so quick ticks overwrite each other** — `client/lib/screens/task_board_columns.dart:934` — Fix: keep a local pending selection or apply optimistically and serialise writes.
- [bug] **Calendar month view drops its last week across the spring DST change** — `client/lib/screens/task_board_plan_views.dart:257` — Fix: count calendar days in UTC.
- [bug] **Timeline drag/resize saves the wrong day across DST changes** — `client/lib/screens/task_board_plan_views.dart:546` — Fix: use calendar arithmetic, not `Duration(days)`.
- [bug] **Copy task link throws on every native build** — `client/lib/screens/task_board_screen.dart:8039` — `Uri.base.origin` on a file: URI (also line 1249). Fix: use the web app origin off-web.
- [ux] **Opening a task from bell, My Work or a linked cell fails silently beyond the loaded page** — `client/lib/screens/task_board_screen.dart:2875` — Fix: open the panel from the fetched task when it is not loaded.
- [data-loss] **Ctrl+V of non-numeric text into a number range clears every cell** — `client/lib/screens/task_board_screen.dart:5822` — Fix: refuse the paste when parsing fails.
- [data-loss] **"Delete folder (boards stay)" deletes all subfolders in one click** — `client/lib/screens/task_board_sidebar.dart:616` — Fix: confirm in place with counts; re-parent subfolders or relabel.
- [data-loss] **Reordering a rolled-up row moves the branch's task to the company board** — `client/lib/screens/task_board_screen.dart:6085` — Fix: no drag/drop for rolled-up rows, or send the row's own group.

### Web Management (11)

- [security] **SEO Helper fetches any URL the customer's WordPress returns, following redirects (SSRF)** — `app/Http/Controllers/Api/Web/SeoHelperController.php:183` — A redirect reaches internal addresses and the analysis leaks parts of the body. Fix: use RemoteHostGuard, disable redirects, require host == site host, reject IP-literal hosts.
- [security] **Calls to customer WordPress sites follow redirects, defeating the host check (SSRF)** — `app/Support/Web/SeoHelper/SiteSeo.php:128` — Also SiteContent and SiteRefresh. Fix: `withoutRedirecting()`; treat 3xx as refusal.
- [bug] **Reviews list on websites shows removed reviews, and double-synced reviews twice** — `app/Support/Web/SiteLists.php:114` — Fix: exclude `removed_at` and de-duplicate like `reviewSummary`.
- [performance] **Every visitor chat poll scans all `lead.replied` audit rows** — `app/Support/Web/ChatPeople.php:58` — Missing `target_type` filter prevents index use; load grows with reply history. Fix: add `where('target_type', Lead::class)` here and in `team()`.
- [data-loss] **A failed list load leaves an empty list that Save writes, wiping the company list** — `client/lib/screens/web/web_lists.dart:195` — Fix: disable Save until loaded.
- [data-loss] **Failed widget-config load is invisible; Save overwrites config with defaults** — `client/lib/screens/web/widgets_tab.dart:477` — Fix: show error with Retry; disable Save until loaded.
- [bug] **On phones the pushed site page never rebuilds, so a new plugin token is never shown** — `client/lib/screens/web/seo_tab.dart:417` — Fix: keep the token in the page state or listen to shared state.
- [bug] **Hours grid ignores the controller after a "Convert hours from" save** — `client/lib/screens/web/hours.dart:259` — Next edit writes back unconverted hours. Fix: listen to the controller.
- [bug] **Page-override boxes created empty during loading never get the stored value** — `client/lib/screens/web/page_editor.dart:217` — Fix: create controllers after load or refresh them.
- [data-loss] **"Turn the chat on/off on all websites" discards unsaved edits that still show on screen** — `client/lib/screens/web/widgets_tab.dart:1238` — Fix: merge only `chat.enabled` or bump `_external`.
- [ux] **Business Profile listings tracked by `site_id`, which they lack** — `client/lib/screens/web/hours_bulk.dart:1374` — Blank result rows, hidden blocked listings, duplicate keys. Fix: use id plus destination.

### Leads + Google API (10)

- [data-loss] **Workiz lead watermark sent as a UTC date, skipping US-evening leads for good** — `app/Console/Commands/ImportWorkizLeads.php:162` — After ~8 PM Eastern the UTC date is tomorrow, so later same-evening leads are never requested. Fix: convert the watermark to the branch zone (or subtract a day; the upsert makes overlap safe).
- [security] **Phone-line mapping lets any `leads.manage` holder redirect another company's calls** — `app/Http/Controllers/Api/LeadPhoneLinesController.php:57` — No reach check on target or current place, so a branch manager can file company B's call leads (recordings, transcripts) under their own branch. Fix: require reach on both places, as LeadOfficeHoursController does; filter index by view.
- [bug] **`{{booking_link}}` always empty for leads filed under a branch with no company id** — `app/Models/Lead.php:871` — Local Services/Thumbtack branch-level leads never get the booking link in auto-replies. Fix: derive the company from the franchise when `company_id` is null.
- [data-loss] **Every Thumbtack message event overwrites the lead's name and phone, including with null** — `app/Services/Leads/ThumbtackLeads.php:398` — Manual corrections are reverted or blanked. Fix: drop null keys before fill (as LocalServicesLeads does); only fill empty contact fields.
- [data-loss] **Duplicate withdrawal deletes the dispatcher's outbound callback, then refiles it every sync** — `app/Support/Leads/RingCentralLeadCalls.php:325` — Outbound calls within 180 s of a Local Services call are removed from the thread. Fix: only consider inbound rows in `withdrawDuplicates`.
- [bug] **Ads report: an out-of-date response after a range change is applied** — `client/lib/screens/ads_performance_screen.dart:462` — Today's spend shows under a 30-day bar. Fix: recompute the window in the guard or use a request counter.
- [bug] **A lead opened from a notification stays pinned at the top of every list** — `client/lib/screens/leads_screen.dart:506` — Stale copy shows under unrelated filters. Fix: clear the pin when filters change.
- [performance] **Infinite scroll retries in a tight loop when the next page fails** — `client/lib/screens/leads_screen.dart:2186` — Offline or 429 floods requests. Fix: record the failure and stop auto-paging until retry.
- [bug] **Edit lead always sends `dispatcher`: 403 without the tick, otherwise marks it "set by hand"** — `client/lib/widgets/lead_edit_panel.dart:92` — Fix: send only when changed; hide without `leads.dispatcher`.
- [bug] **"Disconnect all" never sends the required authenticator code, so it always fails** — `client/lib/widgets/leads_accounts.dart:315` — Fix: route through `confirmDisconnect`.

### Reports (10)

- [bug] **Missed-call alerts read only one RingCentral account, outside the tenant** — `app/Console/Commands/WatchMissedCalls.php:59` — Reads run outside `tenant->run` and never loop accounts, so a second account's missed calls never raise `reports.call.missed`, and other orgs fall back to the env org's log. Fix: run inside the tenant and loop `accounts()` with `only($account)` as WarmCallLog does.
- [security] **Saved report update re-files the report to any place without authorizing it** — `app/Http/Controllers/Api/ReportsController.php:169` — `update()` saves new company/franchise ids (or the current view) without the `create` check and `PlaceInView` that `store()` runs. Fix: authorize the destination; keep the stored place when none is sent.
- [security] **Sent reports list and history ignore the caller's view** — `app/Http/Controllers/Api/SentReportsController.php:107` — A branch manager reads other branches' report texts and figures. Fix: filter rules and history keys by the user's view.
- [security] **Report runner never checks the resource's own permission or module** — `app/Support/Reports/ReportRunner.php:138` — A `reports.build` user without `customers.view` exports every customer's name, email and phone. Fix: require the resource permission and module for root and joins.
- [security] **Report CSV does not neutralise spreadsheet formulas** — `app/Support/Reports/ReportCsv.php:56` — Lead names typed by outsiders run as formulas when the (also emailed) CSV is opened. Fix: share Exporter's defuse rule.
- [bug] **`jobs.scheduled_for` not marked wall-clock, so periods and buckets shift by the UTC offset** — `app/Support/Reports/ReportMap.php:151` — Fix: add `'wall_clock' => true`.
- [concurrency] **P&L figures from a previous base or table show under the newly picked one** — `client/lib/screens/profit_loss_screen.dart:176` — Fix: request token plus base/table check.
- [ux] **AI checks panel: tapping a job closes the panel and opens nothing** — `client/lib/screens/reports/ops_reports.dart:1116` — Leaves a pending deep link. Fix: read OpenDestination before opening the panel.
- [bug] **Report builder filter box keeps the previous filter's text** — `client/lib/screens/reports/report_builder.dart:1109` — Preview runs unfiltered. Fix: key by filter index, field and op.
- [ux] **On phones, report rows ignore `onTap`, so drill-down and open-job are unreachable** — `client/lib/widgets/report_grid.dart:431` — Fix: call `onTap`; separate expand control.

### Email (8)

- [security] **Changing an inbox's IMAP/SMTP host keeps the stored password and sends it to the new host** — `app/Http/Controllers/Api/MailboxesController.php:536` — An editor who never knew the password points the host at a server they control and receives it on the next login. Fix: clear credentials and mark the mailbox pending when host or username changes.
- [bug] **Outlook delta paging sends `$skiptoken:...` as the `$deltatoken` on page 2** — `app/Support/Email/MicrosoftMailboxReader.php:251` — After more than one page of changes the watcher re-marks silently or fails every poll. Fix: follow `@odata.nextLink` as returned.
- [security] **Signature preview makes unguarded server HEAD requests to tenant-supplied URLs (SSRF)** — `app/Support/Email/WorkspaceSignatures.php:348` — Template img sources can probe internal addresses; logo substitution leaks the answer. Fix: go through RemoteHostGuard/RemotePictureFetcher without redirects; check at save time, not render.
- [bug] **Brief network or DNS failures mark IMAP inboxes broken as "host not allowed"** — `app/Support/Email/ImapMailboxReader.php:831` — Connect timeouts map to HostNotAllowed, which marks broken on the first failure and alerts admins. Fix: map connect/DNS failures to hostNotAnswering.
- [bug] **Outlook watch mark fails for any inbox over about 1,000 messages** — `app/Support/Email/MicrosoftMailboxReader.php:268` — The first mark walks the full delta (max 10 pages), so large inboxes are never watched. Fix: start with `$deltatoken=latest`.
- [security] **"Verified sender" check shown on spoofed big-brand mail** — `client/lib/screens/email/sender_brand.dart:90` — The server's DMARC verdict is never read. Fix: show the badge only when authenticated is true; pass `from_authenticated` through.
- [bug] **`mail_server` sent as an empty list crashes the Settings tab** — `client/lib/screens/email/mailbox_settings.dart:216` — Reachable via API/MCP. Fix: parse tolerantly; server returns an object or null.
- [data-loss] **One tap on "Use a template" deletes a person's own signature** — `client/lib/screens/email/workspace_tab.dart:1809` — Fix: make the chip a view toggle and save explicitly, or offer undo.

### Inspection (8)

- [concurrency] **Inspection submit is not idempotent: a double submit files two reports and quotes work twice** — `app/Http/Controllers/Api/InspectionRunsController.php:349` (merged: `client/lib/screens/inspection/new_tab.dart:991`, the client has no in-flight guard) — Two overlapping submits each create a report, append job lines (doubling the customer quote) and fire `inspection.submitted`. Fix: client `_submitting` flag; server re-reads the run with `lockForUpdate` and returns 409 if gone.
- [bug] **Multi-select questions can only take one answer; list answers become the string "[a, b]"** — `client/lib/screens/inspection/new_tab.dart:738` — Fix: list-backed multi-select control.
- [bug] **Condition pickers offer follow-up questions the server rejects, blocking all later saves** — `client/lib/screens/inspection/builder_model.dart:268` — Fix: include nested keys server-side or stop offering them.
- [data-loss] **Run answers saved fire-and-forget: failed save ignored, Submit files stale answers** — `client/lib/screens/inspection/new_tab.dart:331` — Fix: stop Next/Submit when the save failed.
- [data-loss] **Typed text answers saved only on Next, lost when leaving the tab** — `client/lib/screens/inspection/new_tab.dart:832` — Fix: debounce-save and flush on dispose.
- [data-loss] **Switching inspections after a failed save drops the old edits; "Try again" saves the wrong one** — `client/lib/screens/inspection/builder_tab.dart:248` — Fix: stop switching when flush fails, or retry the dirty id.
- [data-loss] **Renaming an answer retargets conditions of a different answer** — `client/lib/screens/inspection/builder_question.dart:685` — Fix: rename on commit and only when no sibling holds the old value.
- [ux] **Photos inside follow-up questions never load in Generated Reports** — `client/lib/screens/inspection/reports_tab.dart:99` — Endless spinner. Fix: fetch nested ids; show a failure state.

### Financials (7)

- [data-loss] **CSV import splits on every newline, breaking quoted multi-line cells (including its own export)** — `app/Http/Controllers/Api/FinancialsController.php:579` — Notes with line breaks become broken records with shifted columns. Fix: parse with `fgetcsv` over the whole text.
- [data-loss] **Bulk Airtable write refreshes the whole mirror per 10 records and reports "Nothing was changed" after partial writes** — `app/Support/Financials/AirtableWriter.php:101` — Large pastes time out partway with a false message. Fix: refresh once at the end, cap or queue bulk writes, report partial success.
- [concurrency] **Invoicing a charge sheet can bill the same row twice** — `app/Support/Invoices/Invoicing.php:122` — Rows are read unlocked; concurrent requests make duplicate drafts. Fix: lock the sheet and rows; unique index on live invoices per source.
- [bug] **A failure partway through invoice send leaves sent invoices unmarked, so a retry resends them** — `app/Support/Invoices/Invoicing.php:271` — Fix: mark each invoice (or channel) sent as it succeeds; pass an idempotency key to SMS.
- [data-loss] **Charge-sheet cell edits waiting for autosave are thrown away on switch or leave** — `client/lib/screens/financials/invoice_sheets.dart:152` — Fix: flush pending edits before switching; send on dispose.
- [bug] **Editing a result's cost or sales leaves its profit stale** — `client/lib/screens/financials/financial_results.dart:585` — Fix: recompute profit on edit when blank (client or server).
- [data-loss] **Saving a new Airtable token deletes connected bases it cannot reach** — `client/lib/widgets/airtable_token_connect.dart:62` — Fix: show unreachable bases and confirm; server drops only explicit ids.

### Human Resources (7)

- [concurrency] **Contract signing checks "already signed" outside the transaction, with no unique index** — `app/Http/Controllers/Api/HrOnboardingsController.php:1074` — A double tap stores two signatures for one field and can complete the onboarding twice (events, audit). Fix: lock the onboarding and re-check in the transaction; add a unique index on (onboarding_id, field_key).
- [security] **`hr.edit` can deactivate or demote people whose role outranks the editor** — `app/Policies/UserPolicy.php:84` — Only the new role is checked, so a lower HR editor can switch off a (non-last) Administrator. Fix: require `mayAssignRole` on the target's current role in `update`.
- [bug] **PTO with reset=period and an accrual wait inflates balances** — `app/Support/Hr/PtoPolicy.php:260` — Past windows are built from the start date and credited during the waiting time; some used days fall into no window. Fix: step windows from the accrual origin.
- [data-loss] **Removing a rate stage leaves the wrong numbers on screen and saves pay terms nobody saw** — `client/lib/screens/hr/onboarding_form.dart:2185` — Index-keyed fields with `initialValue`. Fix: stable per-row keys/controllers.
- [data-loss] **Back link throws away unsaved onboarding answers without asking** — `client/lib/screens/hr/onboarding_form.dart:1023` — Fix: confirm or save before leaving.
- [bug] **"Start an onboarding" only offers the 50 newest invitations** — `client/lib/screens/hr/onboarding_tab.dart:739` — Fix: server search or page through.
- [data-loss] **One tap on the timeline × deletes a whole run of recorded days off** — `client/lib/screens/hr/pto_tab.dart:1695` — No confirm, no busy guard. Fix: confirm and disable during the loop; bulk delete endpoint.

### Jobs (Project Management code) (7)

- [data-loss] **Repair-teams endpoint renames or deletes any Workiz tag on every account in the org** — `app/Http/Controllers/Api/ProjectBoardController.php:559` — Name is never checked as a repair tag and the change loops all connections, stripping the tag from every job. Fix: refuse non-repair names; limit to connections in view or require org-level reach.
- [security] **Project Inbox shows texts from every company's lines** — `app/Http/Controllers/Api/ProjectInboxController.php:207` — Messages are picked by customer or number org-wide, bypassing PhoneScope. Fix: restrict to number ids visible to the user, or the job's branch line.
- [data-loss] **A permanently failing client or crew text writes a job note every 15 minutes** — `app/Support/ProjectBoard/ClientMessages.php:86` — Up to ~96 notes per day per kind for 120 days. Fix: record the failure on the claim and back off; dedupe notes.
- [bug] **Date-based reminders are claimed once per job forever** — `app/Support/ProjectBoard/ClientMessages.php:63` — Rescheduled jobs never get reminders or crew work orders for the new date. Fix: include the scheduled date in the claim, or clear claims on reschedule.
- [concurrency] **Board load answer that arrives late overwrites the new tab's rows** — `client/lib/screens/project_board_screen.dart:254` — Fix: request counter.
- [data-loss] **Unparseable money entry silently wipes job cost, part price or project costs** — `client/lib/screens/project_board_screen.dart:1010` — "1,300" saves null. Fix: strip separators, validate, send null only for an empty field.
- [data-loss] **Board Settings can save defaults over real settings after the settings read fails** — `client/lib/screens/project_board_screen.dart:1786` — Saving one supplier deletes all others. Fix: show an error with Retry and disable Save until a successful read.

### Meta / WhatsApp (7)

- [security] **Meta deauthorize/data-deletion callbacks skip Facebook Ads connections** — `app/Http/Controllers/Api/MetaCallbacksController.php:149` — Ads tokens are kept after a Meta deletion request. Fix: include `facebook_ads` in `connectionsFor()`; add a test.
- [bug] **Staff-bot "Daily conversion" report uses UTC, not the org's day** — `app/Models/StaffBotItem.php:116` — Evening requests report the wrong day. Fix: use `OrgTimezone::of($organization)`.
- [security] **Customer erasure skips WhatsApp messages and contacts** — `app/Models/WhatsAppMessage.php:121` — `personalDataAbout()` returns null, so erased customers keep their number and message bodies for up to 24 months. Fix: match the customer's E.164 phones (and leads for contacts).
- [security] **Meta webhook routes a Page/Instagram message to whichever org's row sorts first** — `app/Support/Social/MetaMessages.php:77` — A Page connected in two orgs sends leads to the wrong tenant. Fix: refuse duplicate Page connects across orgs; return null on ambiguity.
- [security] **Meta app secret and tenant WhatsApp token sent in a GET query string; a timeout logs them** — `app/Support/Social/WhatsAppHealth.php:55` — Fix: send via POST/headers and catch ConnectionException without the URL.
- [security] **Page access tokens leak into logs through uncaught connection errors** — `app/Support/Social/MetaLeadForms.php:190` — Also FacebookPublisher:209 and PostsThroughMetaGraph:54. Fix: bearer header or sanitised rethrow; log only exception class in `within()`.
- [bug] **A second APPROVE sends an older, stale report batch; approve() is not atomic** — `app/Support/Social/WhatsAppPeriodReports.php:207` — Branches receive weeks-old PDFs, or every group gets the report twice. Fix: target the last previewed batch; conditional update and dispatch only on one changed row.

### Unowned — platform auth (desk) (7)

- [security] **SMS verification send and confirm have no rate limit** — `app/Http/Controllers/Api/SmsCodesController.php:71` — Loops can send unlimited paid texts to premium international numbers (toll fraud) and brute-force codes. Fix: per-user and per-destination limiter, country allow-list, failed-attempt cap in `confirm()`.
- [security] **/me/totp lets a stolen session guess password and TOTP without limit** — `app/Http/Controllers/Api/TwoFactorController.php:228` — No throttle; distinct error confirms the password, then the 2FA can be switched off. Fix: per-user RateLimiter on failures, audit repeated failures.
- [security] **Linked staff login falls back to the stale tenant password when the staff account is off** — `app/Services/Auth/AuthenticateUser.php:86` — Deactivating staff or resetting their TOTP reopens the old CRM password with no 2FA. Same fallback in `completeChoice` and `PasswordResets::subjectsFor`. Fix: when a link exists always use the staff credential and refuse if it cannot authenticate.
- [security] **Password reset or change on a linked staff account leaves CRM sessions alive** — `app/Services/Auth/PasswordResets.php:185` — Only staff tokens are revoked; a stolen CRM token keeps working up to 30 days. Fix: also delete tokens of every linked User.
- [bug] **Choosing a code-protected organization in the login picker never shows the code step** — `client/lib/screens/login_screen.dart:197` — Only an accidental Back gets through. Fix: clear `_choices` when the code is needed; add a widget test.
- [ux] **Text-code send/confirm errors are cleared straight away by the following reload** — `client/lib/widgets/sms_codes_card.dart:123` — Fix: reload only on success or keep the error.
- [bug] **"Turn off text codes" says off even when the request failed** — `client/lib/widgets/sms_codes_card.dart:154` — False opt-out confirmation. Fix: check `response.ok`.

### Automations (6)

- [security] **Dry-run and test preview any event's data, outside the caller's view** — `app/Http/Controllers/Api/AutomationsController.php:1504` — An explicit `event_id` skips `recentInView`; "Would use" text returns other companies' customer details. Fix: resolve event ids only through the in-view lookup; require matching `event_key` in `test()` too.
- [data-loss] **Saving a rule back through the API overwrites the webhook secret with the mask** — `app/Http/Controllers/Api/AutomationsController.php:1862` — `present()` masks secrets and `validated()` stores what comes back, so MCP/API edits break every webhook signature silently. Fix: restore the previous secret when the posted value equals the mask; 422 if none.
- [bug] **Switching a disabled rule back on replays nothing** — `app/Support/Automations/AutomationEngine.php:526` — `runOne` returns early on the existing FAILED run for the same version, so the "replay last day's failures" promise never happens and the count is wrong. Fix: a replay flag that resets the failed run; count only real runs; add a test.
- [data-loss] **Missed-call job step creates a duplicate customer when the stored phone is not E.164** — `app/Support/Automations/ActionRunner.php:2696` — Exact `where('phone')` misses formatted numbers. Fix: use `Customer::phoneLike`.
- [bug] **"Add a task" and "Close the call-back task" can never be added** — `client/lib/screens/automation_builder.dart:346` — `task_board` missing from `drawableKinds`. Fix: add it; test that every parameter kind is drawable.
- [bug] **Switching templates keeps the previous template's nested conditions and lock** — `client/lib/screens/automation_builder.dart:3159` — A rule saves another template's condition tree under a new trigger. Fix: reset opaque conditions, lock and loaded actions in `_fillFromTemplate`.

### Clock In / Work Hours (6)

- [data-loss] **Weekly payroll email marked sent before it is built and sent** — `app/Console/Commands/TimeClockPayrollSends.php:111` — The cache key is claimed first; any SES or spreadsheet failure leaves the key set, so that week's sheet never goes out. Same order in `managers()`. Fix: forget the key on failure and rethrow.
- [bug] **Payroll leaves out HR's extra hours** — `app/Support/TimeClock/TimeClockPayroll.php:89` — The pay sheet is lower than Work Hours, so people are underpaid. Fix: add `TimeClockDay.extra_minutes` to payroll hours.
- [bug] **Week arrows stick or skip at daylight-saving changes** — `client/lib/screens/hr/work_hours_tab.dart:1457` — Fix: calendar arithmetic.
- [data-loss] **Week note field keeps the previous week's text and can save it into the new week** — `client/lib/screens/hr/work_hours_payroll_tab.dart:782` — Fix: reset on value change or key by week.
- [data-loss] **On phones, a bonus being typed is saved into whichever week is shown on blur (and un-approves)** — `client/lib/screens/hr/work_hours_payroll_tab.dart:720` — Fix: unfocus and await commit before move/approve; send the week with the save.
- [ux] **Failed Extend or Clock out in the shift reminder closes silently** — `client/lib/widgets/shift_reminder.dart:114` — Shift then ends at the original time. Fix: keep dialog open and show the error.

### Inventory (6)

- [bug] **`isEmpty()` ignores sales-order lines, so deleting a sold item returns 500 and loses its attachments** — `app/Models/InventoryItem.php:84` — Fix: add a `saleLines()` relation to the check.
- [bug] **Stock can never move from an organization-level location to a company location** — `app/Policies/InventoryTransferPolicy.php:44` — A null source company is treated as a sibling company and refused. Fix: return not-sibling when the source company is null.
- [bug] **Low and out-of-stock counts include locations the reader cannot see** — `app/Support/Inventory/InventorySummary.php:477` — General tab counts disagree with the Stock list they open and leak other branches' stock. Fix: constrain the sum with InventoryVisibility.
- [concurrency] **Item pane has no stale-response guard; a count can post against the wrong item** — `client/lib/screens/inventory/item_pane.dart:250` — Fix: request token or `ValueKey(itemId)`.
- [ux] **Orders tab shows only the first 50 POs, issues and sales** — `client/lib/screens/inventory/order_list.dart:122` — Fix: paging with load-more and a request token.
- [ux] **Customer, item and location pickers silently truncated to one page** — `client/lib/screens/inventory/add_sale_pane.dart:78` — Fix: server-search pickers.

### Scheduling (6)

- [bug] **Full-window check compares Workiz wall time with UTC now** — `app/Console/Commands/SchedulingFlagFullWindows.php:40` — Same-day jobs within the UTC offset are treated as past, so same-day overbookings are never flagged. Fix: compare against now in each branch's zone.
- [bug] **ZIP contest decided org-wide although ownership is per company** — `app/Console/Commands/SchedulingBuildZipMap.php:235` — Only one company's area can win a ZIP, so the other company never gets it and treats it as uncovered. Fix: key the contest by company id plus ZIP.
- [bug] **Auto-reply slot offer picks any company's service area for the ZIP** — `app/Support/Scheduling/SlotOffer.php:31` — Offers and holds seats in the wrong company's area. Fix: pass the lead's franchise ids to `ServiceArea::forZip` (also LsaQuote:60).
- [data-loss] **"Clear drawing" saves at once and drops every drawn ZIP** — `client/lib/screens/scheduling/area_map_pane.dart:1377` — Fix: confirm with a preview, or only clear the editor.
- [ux] **Armed "Move" confirmation carries over to another job after a search or area change** — `client/lib/screens/scheduling/rescheduling_pane.dart:343` — One click reschedules the wrong job in Workiz. Fix: reset on selection change or key by job id.
- [data-loss] **ZIP add/remove PUTs a stale full list and wipes ZIPs added elsewhere** — `client/lib/screens/scheduling/capacity_settings.dart:661` — Fix: re-fetch on show; send deltas or versioned writes; keep `source`.

### Customer Experience (5)

- [security] **eKomi endpoints never check the connection is in the caller's view** — `app/Http/Controllers/Api/EkomiController.php:96` — A user in Company B can read Company A's request funnel (names, emails) or change its settings. Fix: filter with `SocialConnectionAccess::inView` in index and the route helper.
- [security] **Review customer suggestions search every company's customers** — `app/Http/Controllers/Api/ReviewsController.php:200` — Returns names, phones and emails from sibling companies; any customer can be linked. Fix: narrow by the viewer's franchise reach; refuse out-of-view customers in `setCustomer`.
- [data-loss] **"Draft all" overwrites the reply being typed in the open review** — `client/lib/screens/reviews_screen.dart:542` — Fix: skip the open review when its box has unsaved text.
- [data-loss] **Complaint note is cleared before the save, so a failed save loses it (silently on phones)** — `client/lib/screens/customer_experience/complaints_tab.dart:1416` — Fix: clear on success; show the notice in the pane.
- [bug] **Business Profile change arrows compare a partly filled period with a complete one** — `client/lib/screens/business_profile/performance_view.dart:152` — Every tile looks down. Fix: compare equal filled lengths, or hide arrows when data lags.

### Internal Chat (5)

- [security] **EzChat sign-out never revokes the server token or push registration** — `client/lib/main_ezchat.dart:255` — Also lines 88 and 194. The token stays valid and the phone keeps receiving chat pushes. Fix: a shared remote sign-out (logout + device delete) used by EzChat and HomeShell.
- [bug] **One participant's local call failure ends a group call for everyone** — `client/lib/screens/phone/chat/chat_calls.dart:412` — Fix: send `alone:false` on failure.
- [ux] **"Mark as read" / "Mark all read" never clear real unread counts** — `client/lib/screens/phone/chat/chat_tab.dart:797` — Fix: server read-marker action; bulk request.
- [bug] **Jumping to a message leaves a silent gap of newer messages** — `client/lib/screens/phone/chat/chat_thread.dart:180` — Fix: page forward with `after` or reload latest.
- [data-loss] **A failed send or edit discards what was typed with no retry** — `client/lib/screens/phone/chat/chat_thread.dart:343` — Fix: restore text; Retry/Delete on failed bubbles; keep pending items on refresh.

### Unowned — platform core (desk) (5)

- [data-loss] **Attachment files and rows destroyed before the parent delete is known to succeed** — `app/Models/Concerns/HasAttachments.php:59` — A later FK failure (e.g. inventory item on a sale) keeps the parent but loses every attachment. Fix: delete files in a `deleted` listener after commit, or wrap in a transaction with `afterCommit`.
- [data-loss] **An imported customer number can permanently block customer creation for the org** — `app/Support/Data/Importer.php:163` — The sequence is never advanced past imported numbers; the generator later collides and every create rolls back on the same number. Fix: advance the sequence on explicit numbers, or skip existing values in `next()`.
- [bug] **Import rejects the app's own customer export** — `app/Support/Data/Importer.php:233` — Export-only columns (`created_at`, `owning_company`) fail the header check. Fix: ignore known export-only headers; test with real export bytes.
- [bug] **Change events recorded in queue workers sit unflushed for up to an hour** — `app/Support/Live/ChangeFeed.php:137` — Live screens miss changes made by jobs. Fix: flush in `Queue::after` / `Queue::failing`.
- [ux] **If the server is unreachable at launch the app shows sign-in and never retries** — `client/lib/core/session.dart:117` — Startup before Wi-Fi or during deploys shows a blank login; signing in again can delete a kept token. Fix: expose `unreachable`, show a retrying state with a Retry button.

### Workiz (5)

- [concurrency] **Projection lock expires after 300 s during long manual runs** — `app/Console/Commands/ProjectWorkizRecords.php:117` — A manual `--limit=10000` run outlives the lock; the scheduled run then projects the same unlinked rows, recreating duplicate customers and jobs. Fix: claim each row with `lockForUpdate` and skip if already linked; refresh the lock while running.
- [concurrency] **Watchdog force-releases the projection lock whenever any mirror row stays unlinked** — `app/Console/Commands/WorkizWatchdog.php:74` — Permanently unprojectable rows keep the trigger true, so the lock guarding create-then-link is dropped every 5 minutes, allowing a second EzBizi job for one Workiz job. Fix: remove the forceRelease or release only a provably stale lock.
- [concurrency] **`accounts()` switches the shared Workiz web session to master without the lock** — `app/Support/Workiz/WorkizWebSession.php:157` — A concurrent sub-account read lands master data under a branch (e.g. payments). Fix: run it under the same per-login lock; cache the account list.
- [concurrency] **Job create holds the projection lock 60 s but the v2 path can take ~105 s** — `app/Support/Workiz/WorkizJobWriter.php:168` — The lock lapses mid-create and the projection makes a duplicate job. Fix: resolve ids before locking; size the lock to all timeouts.
- [bug] **Editing a Workiz connection drops the new name and still requires the token** — `client/lib/widgets/workiz_connect_form.dart:98` — Fix: server accepts `label` and optional token, or client hides Name.

### API Integrations (4)

- [security] **`placeLeads` lets a branch user move every lead of an org-level account to any branch** — `app/Http/Controllers/Api/SocialConnectionsController.php:492` — Only `inView` (always true for org rows) is checked, then every historical lead is re-filed. Fix: require `reaches()` and a target franchise in the caller's reach.
- [security] **Disconnect checks only `inView`, so any branch user can delete org-level connections** — `app/Http/Controllers/Api/SocialConnectionsController.php:589` — A franchise manager with the connect permission can delete the org's bank feed (cascading transactions) or WhatsApp routing. Fix: require `reaches()` for every write in this controller.
- [security] **Telegram bot token in every request URL leaks through connection errors into logs and failed_jobs** — `app/Support/Telegram/TelegramApi.php:254` — Fix: catch ConnectionException and rethrow without the token; rotate any logged token.
- [bug] **Family Disconnect only reaches the first Google login's rows** — `client/lib/screens/settings/integrations_screen.dart:1030` — Fix: omit `account_email` for family disconnect or loop logins.

### Marketing (4)

- [data-loss] **Column inner-block fields keep a deleted block's text and write it into the next block** — `client/lib/screens/marketing/newsletter_editor.dart:4068` — Fix: key rows by block identity.
- [concurrency] **Switching newsletters mid-save puts A's revision on B and loses A's last edits** — `client/lib/screens/marketing/newsletter_tab.dart:582` — Fix: flush and cancel timers before switching; ignore responses for another id.
- [concurrency] **Planner stale-response guard compares a value with itself** — `client/lib/screens/marketing/social_media_tab.dart:5055` — Late range reads overwrite the shown month. Fix: generation counter.
- [bug] **A just-published post stays "queued" when the planner search has text, triggering a false outage alarm** — `client/lib/screens/marketing/social_media_tab.dart:322` — Fix: follow the new post by id regardless of search.

### Monitoring (4)

- [bug] **Moving a wall makes its coordinates doubles, so saving the floor plan fails on native builds** — `client/lib/screens/monitoring/general_tab.dart:656` — Fix: keep integers.
- [concurrency] **Remote-control input batches post concurrently and merge non-atomically** — `client/lib/screens/monitoring/desktop_monitoring_tab.dart:944` — A lost mouse-up leaves the remote button held. Fix: one flush in flight; atomic append server-side.
- [bug] **Filmstrip paging stops permanently after any quiet refresh** — `client/lib/screens/monitoring/desktop_monitoring_tab.dart:456` — Fix: reset `_loadingMore` on mismatch; separate identity token.
- [data-loss] **Renaming a floor plan re-sends a stale layout and overwrites a newer one** — `client/lib/screens/monitoring/general_tab.dart:1045` — Fix: send only the name.

### Settings (4)

- [bug] **Singleton settings cache serves stale values to queue workers for up to an hour** — `app/Support/Settings/SettingsResolver.php:32` — e.g. Workiz mirror keeps writing after being switched off. Fix: bind it `scoped`.
- [concurrency] **New list row posts on first blur, losing other columns or double-posting** — `client/lib/screens/settings/lists_screen.dart:451` — Fix: explicit Add, in-flight guard.
- [bug] **Activity "Show older" skips events sharing the boundary second** — `client/lib/screens/settings/activity_screen.dart:148` — Fix: compound (time, id) cursor.
- [data-loss] **Business-hours editor mutates the screen's settings even on Cancel or refused save** — `client/lib/widgets/setting_editors.dart:134` — Fix: deep-copy each day map.

### Field (3)

- [ux] **One failed map poll replaces the whole map with an error and resets the view** — `client/lib/screens/field/map_tab.dart:193` — Fix: quiet background reads that keep current data.
- [concurrency] **Route tab shows one person's route under another's name when responses cross** — `client/lib/screens/field/route_tab.dart:77` — Fix: apply the stale-response check to every load.
- [bug] **Recordings day filter matches the UTC day, not the user's day** — `client/lib/screens/field/recordings_tab.dart:96` (merged: `app/Http/Controllers/Api/FieldRecordingsController.php:175`, server side of the same bug) — Evening recordings appear under the next day. Fix: send local-day UTC bounds and filter `started_at` by range.

### Fleet (3)

- [bug] **A vehicle created in the app can never be deleted** — `app/Http/Controllers/Api/VehiclesController.php:463` — `store()` always creates an ownership period and `isEmpty()` treats any period as history; the test uses a factory without one. Fix: ignore the single creation period (or delete it) and test through POST /vehicles.
- [data-loss] **Vehicle pane not keyed by vehicle, so open card forms carry over and post to the next vehicle** — `client/lib/screens/fleet/vehicle_pane.dart:88` — e.g. a half-filled ownership period ends the new van's real period. Fix: `ValueKey(vehicle['id'])`.
- [bug] **Invalid toll flat rate locks the identity card** — `client/lib/screens/fleet/vehicle_identity_card.dart:304` — FormatException escapes after `_busy=true`. Fix: parse inside the try before setting busy.

### Training (3)

- [data-loss] **Guide and test builders silently drop unsaved edits on Leave, Publish or tab switch** — `client/lib/screens/training/guide_builder.dart:338` — Fix: dirty flag, ask on leave, save before publish.
- [bug] **Learn marks finished or passed assignments as overdue** — `client/lib/screens/training/learn_shelves.dart:148` — Fix: count only unfinished items.
- [bug] **Dashboard pane not keyed by item; assign form carries over to the next guide or test** — `client/lib/screens/training/dashboard_list.dart:167` — Fix: `ValueKey(row['id'])`; stale check on all loads.

### Dashboard (2)

- [bug] **Marketing box always reports zero failures** — `app/Support/Dashboard/Glances/MarketingGlance.php:540` — Failures are counted by `sent_at`, which failed targets never get; the test fixture fakes it. Fix: stamp a failure time (or use `updated_at`) and fix the fixture.
- [performance] **HR box loads every onboarding ever and runs one answer query per expiry field per onboarding** — `app/Support/Dashboard/Widgets/Hr/ExpiringCertifications.php:56` — Grows with hiring history on every dashboard load. Fix: fetch answers in one `whereIn` query. (Same pattern in `app/Support/Hr/HrOverview.php:218`, listed under low.)

### Deployment (2)

- [security] **Desktop auto-updater silently runs any .exe the web manifest points to** — `client/lib/core/desktop_update_io.dart:87` — Only "MZ" bytes are checked; a compromised web host can run code on every Windows client. Fix: verify the Authenticode publisher before running; pin manifest scheme and host. (Unsigned builds are currently allowed by release.sh.)
- [bug] **`runInBackground()` schedules are killed when the oneshot scheduler exits, leaving locks held** — `routes/console.php:315` — workiz-watchdog, check-mailboxes and warm-inbox-headers rarely finish. Fix: drop `runInBackground()` or move to the slow lane.

### Jobs (2)

- [performance] **Negative `per_page` turns paged lists into unbounded full-table reads** — `app/Http/Controllers/Api/JobsController.php:276` — `min()` has no lower bound and negative limits are ignored; same pattern in Leads and several Inventory controllers. Fix: clamp `max(1, min(..., 200))` via one shared helper.
- [performance] **Opening any job downloads every attached photo at full size** — `client/lib/screens/jobs/job_files.dart:75` — Fix: load lazily when the Files tab shows, ideally thumbnails.

### Notifications (2)

- [security] **A record with a company but no branch notifies every active user in the org** — `app/Support/Notifications/NotificationDispatcher.php:836` — Company-level vehicles, items and tasks leak titles to all companies' staff. Fix: filter by company reach when only `company_id` is set.
- [security] **Push device tokens are never unregistered on sign-out** — `client/lib/core/push_registration.dart:84` — A shared phone keeps showing the previous user's lead, chat and call notifications. Related: EzChat sign-out (Internal Chat, below). Fix: DELETE device endpoint called on sign-out; server ignores tokens whose user has no live session.

### Roles & Permissions (2)

- [security] **A user who reaches nothing gets an unrestricted organization view for company-level rows** — `app/Support/Scoping/ViewScope.php:307` — Newly invited users with no assignment see every company's company-level leads, reports and camera sites. Fix: flag whether org reach is real; otherwise return empty or reachable company ids.
- [data-loss] **Saving the Mobile bar discards unsaved permission edits on the role page (and vice versa)** — `client/lib/screens/roles_screen.dart:1479` — Fix: refresh without remounting, or block bar save while dirty.

### Services (2)

- [data-loss] **Replace photo deletes the old photo before the upload succeeds** — `client/lib/screens/services/service_photo.dart:76` — Fix: upload first, then delete.
- [data-loss] **"Delete service" hard-deletes on one tap** — `client/lib/screens/services/service_pane.dart:401` — Fix: inline confirm (also taxonomy delete).

### UI/UX (2)

- [bug] **Filter value box shows a stale value after a field change or removed rule** — `client/lib/widgets/list_filters.dart:275` — A visible filter matches everything. Fix: stable per-rule keys.
- [bug] **Pressing Enter before the search delay picks a row from old results** — `client/lib/widgets/suggest_search.dart:128` — Inspection > New can load the wrong customer. Fix: when a debounce was pending, run the search instead of picking.

### Customers (1)

- [data-loss] **Failed customer load leaves an editable empty form whose Save wipes contact details** — `client/lib/screens/customers/customer_detail_panel.dart:378` — Fix: show ErrorRetry instead of the form when the row did not load.

### MCP / API (1)

- [bug] **API activity "Show older" skips rows sharing the boundary second** — `client/lib/widgets/api_activity.dart:145` — Fix: compound cursor.

## Low (396)

### Financials (27)

- [bug] **Connecting a base another scope holds overwrites that scope's token** — `app/Http/Controllers/Api/AirtableConnectController.php:300` — Also BlueskyConnectController:60. Fix: refuse instead of rewriting a row outside the caller's reach.
- [security] **Financials CSV export does not defuse formulas** — `app/Http/Controllers/Api/FinancialsController.php:557` — Fix: reuse Exporter's defuse rule.
- [bug] **Limit applied before the view filter, so branch users silently lose rows** — `app/Http/Controllers/Api/FinancialAdjustmentsController.php:54` — Also results (1000) and bills (500). Fix: filter by scope in SQL before limiting.
- [performance] **One tenant's "Copy now" refreshes every organization's Airtable mirrors in a web worker** — `app/Http/Controllers/Api/FinancialSourcesController.php:140` — Fix: pass `--organization`; queue it.
- [bug] **Invoice list takes the 500 newest org-wide before the view filter** — `app/Http/Controllers/Api/InvoicesController.php:214` — Branch users miss their own older unpaid invoices and totals. Fix: scope in SQL; aggregate totals without a limit. Same in `sheets()`.
- [performance] **Invoice shaping runs 2-3 queries per invoice** — `app/Http/Controllers/Api/InvoicesController.php:457` — Fix: load names and issuers once.
- [security] **Invoicing settings accept any company id, not only companies in view** — `app/Http/Controllers/Api/InvoicesController.php:351` — Exposes another company's numbers, mailbox and Stripe account id. Fix: `coversCompany` check.
- [bug] **Expense totals paged with OFFSET over a non-unique sort key** — `app/Support/Financials/MarketFinancials.php:53` — Lines skipped or double-counted. Fix: `lazyById` or SQL sums.
- [performance] **Production-cost report reloads every repair table once per week** — `app/Support/Financials/RepairReport.php:64` — API/MCP only. Fix: load rows once per range.
- [performance] **Financials home loads every row of every mapped Airtable table several times** — `app/Support/Financials/FinancialsHome.php:168` — Fix: SQL counts or cache per refresh.
- [bug] **Voiding a partly paid invoice frees its row for re-billing at full amount** — `app/Support/Invoices/Invoicing.php:349` — Fix: refuse void when payments exist.
- [bug] **Invoice send uses the first invoice's issuer for the whole batch** — `app/Support/Invoices/Invoicing.php:236` — API/MCP can send company B's invoice from company A. Fix: group by issuer or refuse mixed.
- [bug] **Invoice dates use the UTC date** — `app/Support/Invoices/Invoicing.php:360` — Evening invoices dated tomorrow. Fix: issuer timezone.
- [bug] **Cut-over confirmation carries over to another table** — `client/lib/screens/financials_screen.dart:367` — One press cuts over a table never confirmed. Fix: reset on table change.
- [data-loss] **Shared view deleted on one click; button shown on the wrong permission** — `client/lib/screens/financials_screen.dart:876` — Fix: gate on delete; confirm.
- [performance] **Financials builds every tab at once, firing every tab's loads** — `client/lib/screens/financials_screen.dart:135` — Fix: build tabs on first open.
- [concurrency] **Switching tables quickly shows the table not picked last** — `client/lib/screens/financials_screen.dart:307` — Fix: request ticket.
- [bug] **Make invoices goes ahead after the last edits fail to save** — `client/lib/screens/financials/invoice_sheets.dart:284` — Fix: stop when flush fails.
- [bug] **Invoice search hides invoices the server matched** — `client/lib/screens/financials/invoices_tab.dart:188` — Fix: drop client re-filter.
- [ux] **Bills and charge-sheet searches promise matches the server never returns** — `client/lib/screens/financials/financial_bills.dart:73` — Fix: extend server `q` or narrow hints.
- [bug] **Figures that don't parse silently save as 0** — `client/lib/screens/financials/financial_results.dart:581` — Fix: validate, strip separators.
- [concurrency] **Adding invoice contacts quickly drops an address; a refused one is lost** — `client/lib/screens/financials/invoice_panel.dart:1043` — Fix: disable while busy; clear on success.
- [concurrency] **Double-clicking Submit in the form view creates duplicate records** — `client/lib/screens/financials/financial_views.dart:364` — Fix: busy flag.
- [data-loss] **After a failed place switch, the old company's invoice settings can be saved to the new one** — `client/lib/screens/financials/invoice_settings.dart:162` — Fix: clear on change; ignore stale responses.
- [ux] **Grid selection keeps old indexes after the record list shrinks** — `client/lib/screens/financials/financial_grid.dart:411` — Fix: clamp in `didUpdateWidget`.
- [bug] **"Last 3 months" starts on the wrong day near month end** — `client/lib/screens/financials/financial_markets.dart:28` — Fix: clamp day to month length.
- [ux] **A refused bill delete shows nothing** — `client/lib/screens/financials/financial_bills.dart:500` — Fix: busy flag and error message.

### Phone System (27)

- [security] **Tagging a call checks only `phone.view`, never access to the call** — `app/Http/Controllers/Api/Phone/CallTagsController.php:143` — Fix: `authorize('view', $call)`.
- [security] **Outbound caller ID accepts any number on the account, not just granted lines** — `app/Http/Controllers/Api/Phone/VoiceWebhookController.php:410` — Fix: require `mayView` on the chosen line.
- [bug] **Connection default flow/group/hours/voicemail ids saved without existence or same-account check** — `app/Http/Controllers/Api/Phone/ConnectionsController.php:486` — Fix: validate and require same connection.
- [performance] **Call history lazy-loads each tag and loads every user per request** — `app/Http/Controllers/Api/Phone/CallsController.php:1277` — Fix: `with('tag')`; query only needed users.
- [security] **Extension directory lists every active user regardless of reach** — `app/Http/Controllers/Api/Phone/ExtensionsController.php:111` — Fix: apply `PhoneScope::people`.
- [security] **Any report viewer can delete shared saved phone reports** — `app/Http/Controllers/Api/Phone/ReportsController.php:107` — Fix: require creator or edit permission.
- [dead-code] **MessagesController has unused methods and injected services** — `app/Http/Controllers/Api/Phone/MessagesController.php:146` — Fix: delete `matchCustomer()`, `payload()` and three constructor params.
- [performance] **Attestation summary loads every outbound call to count four values** — `app/Http/Controllers/Api/Phone/TrustController.php:69` — Fix: GROUP BY.
- [concurrency] **`restart()` during a start allows two starts and an orphaned Twilio device** — `client/lib/core/phone_call_service.dart:599` — Fix: start generation counter.
- [ux] **Failed save discards the forward/fallback number typed** — `client/lib/screens/phone/call_flow_pane.dart:161` — Fix: keep field open on failure.
- [ux] **Any other save overwrites an unsaved recording notice** — `client/lib/screens/phone/call_flow_pane.dart:134` — Fix: preserve dirty text on reload.
- [bug] **Message thread requests not guarded against out-of-order responses** — `client/lib/screens/phone/call_tab.dart:385` — Previous customer's texts can show under the new one. Fix: check peer/line after await.
- [ux] **State picked under Local still sent, hidden, in Toll-free or Vanity searches** — `client/lib/screens/phone/buy_number_panel.dart:254` — Fix: clear `_region` on mode change.
- [ux] **Add extension button stays disabled after digits are typed** — `client/lib/screens/phone/extensions_pane.dart:402` — Fix: `onChanged` setState.
- [concurrency] **Phone report runs not sequenced; older answers overwrite newer filters** — `client/lib/screens/phone/reports_tab.dart:184` — Fix: run sequence.
- [ux] **Time mask turns "9:00" into "90:0", which the server rejects** — `client/lib/screens/phone/hours_pane.dart:629` — Fix: respect typed colon.
- [bug] **Open call detail pane never picks up live updates** — `client/lib/screens/phone/records_tab.dart:978` — Fix: resolve by id from `_calls`.
- [bug] **Call tiles count only loaded pages** — `client/lib/screens/phone/records_tab.dart:646` — Fix: server totals or label as partial.
- [concurrency] **Enter can start duplicate paid carrier lookups** — `client/lib/screens/phone/number_quality_pane.dart:121` — Fix: `_looking` guard.
- [performance] **Every live change refetches all loaded pages plus every number** — `client/lib/screens/phone/records_tab.dart:308` — Fix: re-read page 1 and merge.
- [bug] **Carrier observations saved against the first number in the list** — `client/lib/screens/phone/trust_pane.dart:152` — Fix: number picker in the form.
- [concurrency] **Out-of-order voicemail responses show one mailbox's messages under another** — `client/lib/screens/phone/voicemails_pane.dart:144` — Fix: request id.
- [data-loss] **Editing a number-owned mailbox silently turns it into a company mailbox** — `client/lib/screens/phone/voicemail_pane.dart:192` — Fix: keep owner unless changed.
- [ux] **One click permanently deletes a mailbox** — `client/lib/screens/phone/voicemail_pane.dart:483` — Fix: in-place confirm.
- [ux] **A failed tag colour change replaces the whole Tags pane with an error** — `client/lib/screens/phone/tags_pane.dart:705` — Fix: use `_formError`.
- [ux] **Opening another phone request discards the decision being written** — `client/lib/screens/staff/phone_requests_screen.dart:130` — Fix: disable other cards or keep per-request state.
- [ux] **After one failed "Move to my phone", the button is gone for the rest of the call** — `client/lib/widgets/incoming_call_bar.dart:191` — Fix: show error beside the button.

### Task Board (22)

- [performance] **Monday parity re-reads the whole task table once per board** — `app/Console/Commands/MondayParity.php:78` — Fix: compute the known refs once before the loop.
- [bug] **Scheduled record fill aborts for all remaining orgs on any concurrent event** — `app/Console/Commands/TasksFillFromRecord.php:55` — Fix: count only events this command causes; catch per org.
- [bug] **Tasks on deleted boards keep raising nudges, overdue alerts and digests for 30 days** — `app/Console/Commands/TasksNudges.php:42` — Also TasksOverdue, TasksDailyDigest, TasksDatesArrived. Fix: `whereHas('board')`.
- [security] **Mirror columns read connected tasks' values without a view check** — `app/Http/Controllers/Api/TasksController.php:847` — Fix: filter by `can('view')`; validate connect ids.
- [data-loss] **Any board editor can delete files on someone else's task update** — `app/Policies/TaskUpdatePolicy.php:22` — Fix: require author or Administrator.
- [security] **A lone `assignee_user_id` skips the assignable check and notifies without a view check** — `app/Support/Tasks/TaskWriter.php:784` — Fix: same check as the list branch; gate notification on view.
- [concurrency] **Monday sync save overwrites concurrent webhooks and people settings** — `app/Support/Tasks/Monday/MondaySync.php:308` — Fix: merge only sync-owned keys under a lock.
- [security] **Files column accepts any attachment id and shows its unsealed name** — `app/Support/Tasks/TaskWriter.php:1228` — Fix: require attachments on this task.
- [concurrency] **Create-then-activate leaves an invisible rule and duplicates it on retry** — `client/lib/screens/task_board_automate.dart:358` — Fix: reload and close after POST; guard null id.
- [bug] **Board automation composer keeps hidden stale values, saving conditions nobody sees** — `client/lib/screens/task_board_automate.dart:525` — Fix: pass current values as initial, or reset on open/close.
- [concurrency] **Activity log filters can show results for an older filter** — `client/lib/screens/task_board_activity.dart:47` — Fix: request counter.
- [data-loss] **Status rename debounce is one shared timer cancelled on dispose, dropping typed names** — `client/lib/screens/task_board_columns.dart:2602` — Fix: per-status timers flushed on dispose.
- [data-loss] **Number/currency typed with a comma or $ is silently discarded** — `client/lib/screens/task_board_columns.dart:1324` — Fix: normalise like paste; show an error otherwise.
- [performance] **One board drag renumbers every unfiled board with one PATCH each** — `client/lib/screens/task_board_sidebar.dart:196` — Fix: scope siblings; one ordered-list call.
- [performance] **Board tree sorts all boards three times per tile on every rebuild** — `client/lib/screens/task_board_sidebar.dart:839` — Fix: lazy menu; compute order once.
- [data-loss] **Import truncates every pasted title at its first comma** — `client/lib/screens/task_board_screen.dart:3692` — Fix: split only on tabs.
- [ux] **Dropping a task on a board says "Moved" even when the move failed** — `client/lib/screens/task_board_screen.dart:913` — Fix: report only on success.
- [bug] **Undo, bulk Duplicate and Move to top ignore failed requests** — `client/lib/screens/task_board_screen.dart:984` — Fix: collect and report failures.
- [bug] **Drop and "Create below" positions come from the filtered/sorted list** — `client/lib/screens/task_board_screen.dart:4651` — Fix: send target task id, or disable under sort/filter.
- [performance] **Every column heading scans all tasks twice per build** — `client/lib/screens/task_board_screen.dart:2335` — Fix: lazy menus or memoised values.
- [bug] **Hide panel skips remaining built-in column changes after the first failure** — `client/lib/screens/task_board_screen.dart:2535` — Fix: attempt all, report count.
- [bug] **Due/Created labels wrong on DST change days** — `client/lib/screens/task_board_screen.dart:328` — Fix: compare UTC calendar days.

### Human Resources (21)

- [security] **Payroll CSV writes employee-controlled names unescaped** — `app/Http/Controllers/Api/HrPeopleController.php:457` — Formula injection on open. Fix: defuse cells.
- [concurrency] **Adding PTO days checks duplicates outside the transaction** — `app/Http/Controllers/Api/HrPeopleController.php:498` — Double submit counts a day twice; `setPtoUsed` double-adds. Fix: lock the user row; unique index.
- [concurrency] **Approving a PTO request is not atomic; two approvals double-deduct** — `app/Http/Controllers/Api/PtoRequestsController.php:136` — Fix: lock and re-check status.
- [bug] **Held invitations counted as Abandoned while shown In progress** — `app/Models/Invitation.php:464` — Fix: mirror `hasExpired()` (held_at) in the scope.
- [bug] **Impossible contract dates are silently shifted** — `app/Services/Hr/OnboardingForm.php:511` — "2026-02-30" becomes March 2. Fix: round-trip check after parse.
- [performance] **HR certifications run one query per onboarding** — `app/Support/Hr/HrOverview.php:218` — Fix: one `whereIn` query.
- [bug] **"Extend 14 days" sets expiry to now+14, shortening links or adding one where none was** — `client/lib/screens/hr/invite_links_panel.dart:395` — Fix: extend from current expiry; hide when none.
- [ux] **Contract preview lists reserved-but-never-asked keys under "Always asked"** — `client/lib/screens/hr/contract_form_preview.dart:108` — Fix: use the asked list.
- [ux] **Cover copy says logo changes reach every contract, the opposite of what happens** — `client/lib/screens/hr/contract_cover_panel.dart:288` — Also contract_designer:1769. Fix: correct both strings.
- [ux] **Publish panel never shows cover changes, including a removed cover** — `client/lib/screens/hr/contract_designer.dart:2097` — Fix: render the server's cover block.
- [performance] **Every caret move in the contract wording sends a server render** — `client/lib/screens/hr/contract_body_editor.dart:85` — Fix: skip when text unchanged.
- [bug] **"Onboarding" figure counts awaiting-approval forms but its list leaves them out** — `client/lib/screens/hr/hr_overview.dart:315` — Fix: include "To approve".
- [bug] **Days until a certification expires is one short** — `client/lib/screens/hr/hr_overview.dart:172` — Fix: compare calendar days; "today" for 0.
- [ux] **A failed live re-read on an empty list leaves a spinner forever** — `client/lib/screens/hr/invitations_tab.dart:188` — Also onboarding_tab. Fix: don't touch `_loading` on quiet loads.
- [ux] **A failed contract open disables "New contract"** — `client/lib/screens/hr/contracts_tab.dart:508` — Fix: separate error field.
- [bug] **Recruiting spend cell shows the sum but writes only the first row** — `client/lib/screens/hr/recruiting_tab.dart:1423` — Fix: edit by difference or make read-only; unique key server-side.
- [ux] **Adding or removing a place throws away unsaved Employment edits** — `client/lib/screens/hr/records_tab.dart:1198` — Fix: don't re-key the section.
- [concurrency] **Applicant list loads lack a request token; a slow "Show more" lands under a new filter** — `client/lib/screens/hr/recruiting_tab.dart:208` — Fix: request counter.
- [bug] **W-9 sends hidden LLC code and "other" text, and the server prints them** — `client/lib/screens/hr/tax_form_panel.dart:180` — Fix: send only for the chosen classification; gate server-side.
- [bug] **UTC timestamps shown as dates without local conversion** — `client/lib/screens/hr/records_tab.dart:2722` — Fix: `toLocal()` for instants.
- [concurrency] **HR Payroll filter reloads can finish out of order** — `client/lib/screens/hr/payroll_tab.dart:92` — Fix: request counter.

### Leads + Google API (20)

- [performance] **Lead-to-job matching loads every customer and job, plus every inbox, every 15 minutes** — `app/Console/Commands/MatchLeadsToJobs.php:62` — Fix: restrict to phones of unmatched leads; join instead of a correlated subquery.
- [security] **Google access token in a query string; a connection error logs it and stops the loop** — `app/Console/Commands/GoogleConnectionEmails.php:45` — Manual command only. Fix: POST or header, catch ConnectionException.
- [bug] **Only one Thumbtack grant per organization is kept alive** — `app/Console/Commands/ThumbtackKeepAlive.php:33` — A second login goes idle and stops delivering leads. Fix: dedupe by refresh token, not org.
- [security] **Hidden-leads list ignores the reader's reach and view** — `app/Http/Controllers/Api/LeadHiddenController.php:31` — Fix: build the query through `scopeToReader`.
- [data-loss] **"Read all" clears unread marks on leads outside the view, including hidden ones** — `app/Http/Controllers/Api/LeadsController.php:1977` — Fix: use `scopeToReader`.
- [security] **Lead detail returns matching customers' names and phones without `customers.view`** — `app/Http/Controllers/Api/LeadsController.php:661` — Fix: gate `customer_matches` on permission and `can('view')`.
- [ux] **`firstNameOf()` strips accented letters, mangling greetings** — `app/Models/Lead.php:786` — "José" becomes "Jos". Fix: keep `\p{L}`; use `mb_convert_case`.
- [bug] **`invalid_grant` "mark broken" save throws without a tenant context** — `app/Support/Social/GoogleToken.php:180` — Fix: save inside `TenantContext::run`.
- [ux] **Failed Ads account list shown as "No Google Ads account is connected"** — `client/lib/screens/ads_performance_screen.dart:657` — Fix: show the error with Try again.
- [bug] **Pause/Enable acts on campaigns hidden by the current filters** — `client/lib/screens/ads_performance_screen.dart:556` — Fix: clear picks on filter change, or act on visible rows only.
- [bug] **Starring a lead clears @mentions typed in an unsaved note** — `client/lib/screens/leads_screen.dart:1909` — Fix: clear mentions only when a note was sent.
- [bug] **A poll started before Send can hide the reply just sent** — `client/lib/widgets/lead_thread.dart:506` — Fix: load generation counter.
- [bug] **"Not classified" option in Edit lead does nothing** — `client/lib/widgets/lead_edit_panel.dart:90` — Fix: send null when changed.
- [ux] **Re-classify offered without `leads.reclassify`** — `client/lib/widgets/lead_edit_panel.dart:210` — Fix: check the permission.
- [bug] **Note editor caps plain text at 4,000 but the server caps markup** — `client/lib/widgets/lead_thread.dart:2437` — Fix: validate markup length or raise the server limit.
- [bug] **"Followed up" hides itself before the request and swallows failures** — `client/lib/widgets/lead_thread.dart:3215` — Fix: check `response.ok`.
- [bug] **Stats map ignores the branch filter** — `client/lib/widgets/leads_stats.dart:94` — Fix: pass the query to points and coverage.
- [data-loss] **Text typed during a template save is discarded** — `client/lib/widgets/reply_templates_manager.dart:264` — Fix: disable fields while saving.
- [concurrency] **Recovery preview can show the wrong text when responses cross** — `client/lib/widgets/leads_recovery.dart:291` — Fix: sequence check.
- [bug] **Stats map outcome chips cannot hide unclassified leads; size ignores the filter** — `client/lib/widgets/leads_stats.dart:63` — Fix: add a chip; size by selected counts.

### Reports (18)

- [security] **Job reports drop the branch filter when the view has zero branches** — `app/Http/Controllers/Api/JobStatusReportController.php:55` — Returns every org job (also JobsReportController:62). Fix: branch on `null` explicitly.
- [bug] **A goal without a `value` key returns 500** — `app/Http/Controllers/Api/ReportGoalsController.php:72` — Fix: `$g['value'] ?? null`.
- [bug] **Thumbtack report bounds days in UTC** — `app/Http/Controllers/Api/ThumbtackReportController.php:43` — Fix: convert with `ReportView::zone()`.
- [bug] **A past day's call log is stored permanently on its first read, minutes after midnight** — `app/Support/Reports/Experimental/RingCentralCallLog.php:340` — Late records lost. Fix: persist only days ended a few hours ago.
- [bug] **Local Services lead counts use UTC day boundaries** — `app/Support/Reports/MarketingReport.php:315` — Fix: convert local range to UTC first.
- [bug] **An Airtable network error aborts the snapshot for every remaining org** — `app/Support/Reports/Airtable/AirtableSnapshot.php:47` — Fix: catch Throwable per table and per org.
- [concurrency] **Report-draft corrections overwrite each other** — `app/Support/Reports/Drafts/ReportChanges.php:66` — Fix: lock and re-read the batch.
- [ux] **Failed scored-calls search reads as "No scored calls"** — `client/lib/screens/leaderboard_screen.dart:592` — Fix: show error; ticket searches.
- [performance] **Initial P&L load fetches the same slow week twice** — `client/lib/screens/profit_loss_screen.dart:137` — Fix: reuse the winning probe's body.
- [concurrency] **Paged reports: double "Show more" duplicates rows; parallel loads mix filters** — `client/lib/screens/reports/jobs_report.dart:86` — Also job_status_report, ops_reports. Fix: request counter; disable while loading.
- [concurrency] **Report reloads not sequenced; an older range can overwrite the newer one** — `client/lib/screens/reports/experimental_call_report.dart:233` — Also marketing, meta ads, thumbtack, workspace. Fix: ticket as ScoringReport.
- [ux] **A failed row action replaces the whole Performance report with an error** — `client/lib/screens/reports/experimental_call_report.dart:574` — Fix: separate action errors.
- [bug] **Approve failure message wiped by the reload that follows** — `client/lib/screens/reports/report_workspace.dart:200` — Fix: reload only on success.
- [concurrency] **"Send to WhatsApp now" and "Approve" have no in-flight guard** — `client/lib/screens/reports/report_workspace.dart:204` — Double send. Fix: busy flags.
- [performance] **Calls tab builds every row eagerly on each rebuild; People cells rescan all calls** — `client/lib/screens/reports/experimental_call_report.dart:1790` — Fix: lazy builder; precomputed per-person maps.
- [bug] **Scoring detail shows a conversion rate next to counts it wasn't computed from** — `client/lib/screens/reports/scoring_report.dart:1119` — Fix: server sends booked and decided counts.
- [bug] **Default "Weekly" range off by a day after the spring DST change** — `client/lib/screens/reports/report_workspace.dart:85` — Fix: calendar arithmetic.
- [dead-code] **`ReportBuilder.editing` is never read or passed** — `client/lib/screens/reports/report_builder.dart:51` — Fix: remove or implement editing.

### Marketing (17)

- [bug] **Replacing a post's picture stores it under the client's extension** — `app/Http/Controllers/Api/SocialPostsController.php:611` — Extensionless images 500 and fail at publish. Fix: use the guessed `extension()`.
- [security] **Newsletter image SSRF guard misses CGNAT and other non-global ranges** — `app/Support/Newsletter/RemoteImageFetcher.php:180` — Fix: `FILTER_FLAG_GLOBAL_RANGE`.
- [security] **Raw HTML on a newsletter block skips the script check** — `app/Support/Newsletter/BlockRenderer.php:262` — Fix: run `unsafe()` over every block `html`.
- [bug] **Reload reopens a stale snapshot for newsletters outside the newest 50** — `client/lib/screens/marketing/newsletter_tab.dart:644` — Leads to 409 loops. Fix: fetch the open row by id.
- [bug] **Schedule "already passed" compares view wall time with the device clock** — `client/lib/screens/marketing/newsletter_editor.dart:643` — Fix: rely on server validation.
- [data-loss] **Delete removes a customer's public comment in one click** — `client/lib/screens/marketing/comments_inbox_tab.dart:443` — Fix: second-tap confirm.
- [ux] **Image and Button blocks inside a column cannot get a picture or link** — `client/lib/screens/marketing/newsletter_editor.dart:4088` — Fix: per-kind inner editors, or limit add buttons.
- [bug] **New list returned without its Mailchimp list id** — `client/lib/screens/marketing/newsletter_new_list_panel.dart:91` — Fix: `NewsletterAudience.fromJson`.
- [bug] **"Use template" works before pictures and booking link resolve** — `client/lib/screens/marketing/newsletter_templates.dart:503` — Fix: disable until resolved; cache.
- [ux] **Applying a starter fails silently when the restore point cannot be saved** — `client/lib/screens/marketing/newsletter_editor.dart:2662` — Fix: say so.
- [bug] **Conflict Compare shows custom HTML as the local copy even when blocks are active** — `client/lib/screens/marketing/newsletter_versions.dart:279` — Fix: pass the active design.
- [bug] **Month grid drops the last week across a DST change** — `client/lib/screens/marketing/social_media_tab.dart:5880` — Fix: count UTC calendar days.
- [bug] **Tapping the same day's + again opens the composer on "Now"** — `client/lib/screens/marketing/social_media_tab.dart:1369` — Can publish immediately. Fix: clear `_composeAt` after use or a request token.
- [bug] **Attaching a file keeps a previously chosen library item, which returns after Remove** — `client/lib/screens/marketing/social_media_tab.dart:1624` — Fix: clear `_libraryItem` in `_attach`.
- [ux] **Planner range read failure swallowed; calendar silently shows an empty month** — `client/lib/screens/marketing/social_media_tab.dart:5054` — Fix: show error with retry.
- [ux] **Accounts pane offers Disconnect/Reconnect/Connect to roles that will be refused** — `client/lib/screens/marketing/social_media_tab.dart:3046` — Fix: wrap in `Refused`.
- [dead-code] **`SocialPostState.nextStep` has no callers; the tab duplicates the publish-now check** — `client/lib/screens/marketing/social_post_state.dart:38` — Fix: delete; use the shared helpers.

### Monitoring (16)

- [performance] **Ownership chart runs 1-4 queries per person** — `app/Http/Controllers/Api/Monitoring/OwnershipController.php:281` — Fix: eager-load assignments.
- [bug] **Unvalidated `before` cursor in the screenshot list returns 500** — `app/Http/Controllers/Api/Monitoring/ScreenshotsController.php:173` — Fix: validate as date.
- [concurrency] **A remote-control poll in flight at dispose still injects input and leaves the indicator on** — `client/lib/core/remote_control_service.dart:169` — Fix: check `_disposed` after awaits.
- [bug] **Failed go2rtc launch latches the key, so the camera bridge never retries** — `client/lib/core/camera_bridge/engine.dart:87` — Fix: set the key only after success; retry on crash-restart errors.
- [security] **go2rtc API on loopback has no credentials; recorder password is plaintext on disk** — `client/lib/core/camera_bridge/engine.dart:28` — Fix: per-launch API credentials, allow_paths, delete config after start.
- [concurrency] **Stopping lighting during slow startup leaves an orphan poll timer** — `client/lib/core/lighting/lighting_service.dart:111` — Fix: stopped flag checked after each await.
- [bug] **Camera health reports "ok" for any configured stream** — `client/lib/core/camera_bridge/engine.dart:159` — Fix: require an active connection or growing bytes.
- [concurrency] **Stop during a go2rtc launch leaves an unkillable process** — `client/lib/core/camera_bridge/engine.dart:101` — Fix: kill if disposed after `_start`.
- [bug] **Late folder listing overwrites the current folder or device; downloads use the wrong path** — `client/lib/screens/monitoring/files_tab.dart:213` — Fix: request token; key previews by device.
- [bug] **Day navigation uses 24-hour arithmetic, breaking across DST** — `client/lib/screens/monitoring/desktop_monitoring_tab.dart:1951` — Fix: calendar arithmetic.
- [ux] **A failed background refresh replaces the General table and discards a desk rename** — `client/lib/screens/monitoring/ownership_tab.dart:140` — Also pc_specs_tab. Fix: quiet refreshes.
- [ux] **Native MJPEG reader ignores HTTP status: endless spinner, re-requested every 4 s** — `client/lib/screens/monitoring/camera_view_stub.dart:104` — Fix: error on non-2xx; back off.
- [performance] **MJPEG framing rescans and copies the whole buffer per chunk** — `client/lib/screens/monitoring/camera_view_stub.dart:236` — Fix: incremental scan.
- [ux] **A failed "give this person a desk" is silent** — `client/lib/screens/monitoring/ownership_tab.dart:665` — Fix: show the error in the picker state.
- [bug] **"Found on the recorder" name fields shared across sites by channel number** — `client/lib/screens/monitoring/camera_sites_settings.dart:354` — Fix: key by site plus channel; clear on site change.
- [concurrency] **Dash cam filter can show results for a previous type** — `client/lib/screens/monitoring/dashcam_events_panel.dart:280` — Fix: drop stale responses.

### Settings (15)

- [data-loss] **Chained job-status renames in one save merge statuses** — `app/Http/Controllers/Api/SettingsController.php:367` — Fix: apply the rename map in one CASE update.
- [bug] **The year in a record number is the UTC year** — `app/Support/Numbering/RecordNumberGenerator.php:163` — Fix: scope timezone.
- [bug] **`castJson` returns a scalar for scalar JSON, causing 500 instead of 422** — `app/Support/Settings/SettingDefinition.php:595` — Fix: reject non-arrays.
- [data-loss] **Company form closes before the save, so a refused save loses everything typed** — `client/lib/screens/company_form_panel.dart:313` — Fix: save inside the panel; pop on success.
- [ux] **On phones, saved work schedule or "sign out everywhere else" does not update the open page** — `client/lib/screens/profile_screen.dart:373` — Fix: rebuild the pushed page from state.
- [bug] **Text controllers disposed while the side panel is sliding out** — `client/lib/screens/organization_screen.dart:649` — Debug-only error (also project_board_screen:1011, 1133). Fix: dispose in the panel's own `dispose`.
- [bug] **Duration control re-saves on every outside tap and goes stale after a reset** — `client/lib/screens/settings_screen.dart:2300` — Fix: compare before commit; unfocus; key by value.
- [concurrency] **Pressing Enter in a settings field sends the same PATCH twice** — `client/lib/screens/settings_screen.dart:1792` — Fix: track the in-flight value.
- [data-loss] **Business address typed in the last 1.2 s before leaving is never saved** — `client/lib/screens/settings_screen.dart:316` — Fix: flush on dispose or save on blur.
- [bug] **Restore defaults leaves old rows on screen; failure is silent** — `client/lib/screens/settings/lists_screen.dart:276` — Fix: rebuild rows on update; show errors.
- [performance] **One drag reorder sends a PATCH per row and reloads every list each time** — `client/lib/screens/settings/lists_screen.dart:265` — Fix: bulk reorder; reload once.
- [concurrency] **Activity filters unsequenced; older responses overwrite newer filters** — `client/lib/screens/settings/activity_screen.dart:137` — Fix: request counter.
- [concurrency] **Brand colour swatch saves have no busy guard** — `client/lib/widgets/brand_colour_card.dart:93` — Fix: disable while saving.
- [ux] **Undecodable image leaves the logo cropper spinning forever** — `client/lib/widgets/logo_cropper.dart:90` — Fix: try/catch with an error state.
- [ux] **Logo removal ignores the DELETE response** — `client/lib/widgets/logo_badge.dart:172` — Fix: check and report.

### Customer Experience (14)

- [security] **Business Profile list meta leaks other companies' connection names and sync errors** — `app/Http/Controllers/Api/BusinessProfileController.php:299` — Fix: filter meta with `inView`.
- [bug] **Complaints list date filters use UTC days** — `app/Http/Controllers/Api/ComplaintsController.php:50` — Disagrees with the complaints report. Fix: parse in `ReportView::zone()`.
- [performance] **Post queue loads every listing and lazy-loads each connection** — `app/Http/Controllers/Api/BusinessProfilePostQueueController.php:25` — Fix: eager-load and restrict to awaiting targets.
- [performance] **Reviews list runs two extra queries per row** — `app/Http/Controllers/Api/ReviewsController.php:708` — Fix: batch-load link matches and technician names.
- [security] **eKomi shop password in the query string leaks into logs on connection errors** — `app/Services/Reviews/EkomiAccount.php:31` — Also EkomiReviews:169. Fix: catch and rethrow without the URL.
- [performance] **Review-link matcher reads every tenant's runs and clicks, once per org** — `app/Support/CustomerExperience/ReviewLinkMatcher.php:38` — Fix: filter by organization and job ids.
- [data-loss] **Review note and report reason cleared before the save** — `client/lib/screens/reviews_screen.dart:2190` — Fix: clear on success.
- [bug] **"Needs a reply" pill still uses 1-2 stars while filter and tile use 1-3** — `client/lib/screens/reviews_screen.dart:1469` — Fix: `<= 3`.
- [data-loss] **Editing one listing service PUTs the whole Google list from a stale snapshot** — `client/lib/screens/business_profile/listing_services.dart:314` — Fix: re-read before PUT, or server-side single-service patch.
- [concurrency] **Copy media can be sent again after success, duplicating uploads on every listing** — `client/lib/screens/business_profile/listing_media.dart:328` — Fix: disable for succeeded targets.
- [bug] **Auto-replies branch picker built from loaded rows** — `client/lib/screens/customer_experience/auto_replies_tab.dart:107` — Shows "All branches" while filtering one. Fix: options from session.
- [concurrency] **On phones, Draft all never disables, so repeat taps start parallel AI runs** — `client/lib/screens/customer_experience/review_stats_band.dart:76` — Fix: `_drafting` guard; live state in panel.
- [bug] **eKomi Reviews tab ignores end date and account; caps at 100** — `client/lib/screens/customer_experience/ekomi_view.dart:130` — Fix: send `to` and account; page.
- [ux] **WhatsApp QR drawn inverted in dark mode** — `client/lib/screens/business_profile/whatsapp_row.dart:81` — Fix: paper colours as ReviewQrPanel.

### Email (14)

- [performance] **Snoozes that can never wake are retried and reported every minute** — `app/Console/Commands/WakeSnoozedMail.php:22` — Can starve live snoozes past 200 rows. Fix: drop or mark failed after a permanent refusal; order by `until`.
- [bug] **Reply-all splits To/Cc on every comma, producing invalid recipients** — `app/Support/Email/GmailMailboxReader.php:1200` — Fix: RFC 5322 address parser.
- [bug] **Typed body and headers go out as raw 8-bit with no wrapping or encoding** — `app/Support/Email/ReplyComposer.php:89` — Fix: quoted-printable/base64 body; RFC 2047 headers.
- [bug] **Unread filter and quotes break search on Outlook and IMAP** — `app/Support/Email/MicrosoftMailboxReader.php:155` — Fix: pass unread as a flag per provider; escape quotes.
- [security] **Gmail access token sent in a URL query string** — `app/Support/Email/GmailMailboxReader.php:1035` — Fix: POST it or read scopes from the grant.
- [ux] **A role without the Inbox tab opens Email on a blank pane** — `client/lib/screens/email/email_screen.dart:141` — Fix: resolve to the first granted tab.
- [data-loss] **Person pane reload overwrites unsaved own-signature edits** — `client/lib/screens/email/workspace_tab.dart:1651` — Fix: seed editor only when unmodified.
- [ux] **OAuth poll ends silently on timeout and never notices a closed window** — `client/lib/screens/email/consent_window.dart:155` — Fix: timeout sentence; check `window.closed`.
- [concurrency] **Overlapping consent polls can call onConnected twice** — `client/lib/screens/email/consent_window.dart:152` — Fix: self-rescheduling timer or in-flight flag.
- [ux] **Every app resume reloads the inbox to page 1** — `client/lib/screens/email/gmail_inbox.dart:236` — Fix: refetch mailboxes only; respect appended pages.
- [ux] **Failed "load older" page replaces the whole list with an error** — `client/lib/screens/email/gmail_inbox.dart:367` — Fix: inline append error.
- [concurrency] **Stale suggest-scope response can file a new inbox under the wrong company** — `client/lib/screens/email/mailbox_panels.dart:154` — Fix: match response to current address.
- [dead-code] **Unused pagination state and an unreachable linked-workspace card branch** — `client/lib/screens/email/gmail_inbox.dart:188` — Also settings_tab `_WorkspaceCard`. Fix: delete.
- [performance] **Email settings tab fetches three independent endpoints serially** — `client/lib/screens/email/settings_tab.dart:116` — Fix: run them concurrently.

### Field (14)

- [performance] **Every-minute sweep runs one geocode existence query per job in a 16-day window** — `app/Console/Commands/FieldSweep.php:68` — Fix: one `whereIn` on address hashes.
- [performance] **ETA candidate ranking runs one presence query per tracked person** — `app/Http/Controllers/Api/FieldController.php:361` — Fix: load presences once.
- [data-loss] **One truncated recording sidecar stops all later safety recordings from uploading** — `client/lib/core/field_recorder.dart:349` — Fix: per-directory try/catch; atomic writes.
- [concurrency] **Stop racing chunk rotation can leave the microphone recording** — `client/lib/core/field_recorder.dart:293` — Fix: serialise start/stop/rotate; re-check after awaits.
- [bug] **Recorder control stays busy forever when an action throws** — `client/lib/screens/field/recording_control.dart:45` — Fix: try/catch/finally.
- [concurrency] **Late response for the previous day overwrites the new day's jobs and trail** — `client/lib/screens/field/map_tab.dart:184` — Fix: capture day and drop stale.
- [performance] **Map's 30 s poll keeps running while the tab is hidden** — `client/lib/screens/field/map_tab.dart:95` — Fix: skip when not visible.
- [ux] **Map search text persists invisibly when switching People/Jobs** — `client/lib/screens/field/map_overlays.dart:313` — Fix: clear on tab change or use a controller.
- [ux] **Recordings person filter only offers people in the loaded rows** — `client/lib/screens/field/recordings_tab.dart:187` — Fix: server list of people.
- [ux] **Open recording's transcript never refreshes** — `client/lib/screens/field/recordings_tab.dart:109` — Fix: reload transcript on live reload while pending.
- [concurrency] **Out-of-order recording list responses show results for an old filter** — `client/lib/screens/field/recordings_tab.dart:94` — Fix: sequence token.
- [ux] **Transient tiles failure never retried and shown as "not set up"** — `client/lib/screens/field/map_tab.dart:173` — Fix: retry on failure.
- [ux] **Failed Acknowledge error stays on screen after later successes** — `client/lib/screens/field/dashboard_tab.dart:107` — Fix: clear on success.
- [bug] **Day arrows go beyond the date picker range; Next past +60 days snaps to today** — `client/lib/screens/field/map_tab.dart:580` — Fix: clamp both.

### Scheduling (14)

- [bug] **Changing a helper booking's helper keeps the old branch** — `app/Http/Controllers/Api/SchedulingHelpersController.php:137` — Booking shows on the wrong calendar. Fix: refuse cross-branch or update `franchise_id`.
- [bug] **Branch-level free statuses, excluded tags and full days are saved but ignored** — `app/Support/Scheduling/Openings.php:119` — Fix: restrict to org scope or resolve per branch.
- [bug] **Any reply starting with "No" declines the reschedule offer** — `app/Support/Scheduling/RescheduleOffers.php:276` — "No problem" blocks the move. Fix: bare-negative regex; match the sending line.
- [concurrency] **Reschedule `move()` can run twice on a double click** — `app/Support/Scheduling/RescheduleOffers.php:168` — Fix: claim with a conditional update.
- [bug] **Day stepping adds 24 h: stuck or skipped days at DST changes** — `client/lib/screens/scheduling/schedule_pane.dart:222` — Also routing_pane:204. Fix: `DateUtils.addDaysToDate`.
- [bug] **Stale ZIP stays on other searches' Report buttons and day suggestions** — `client/lib/screens/scheduling/availability_pane.dart:673` — Fix: clear on new search; take ZIP from the query.
- [bug] **Map tile URL cache keeps failures and expired sessions for the process life** — `client/lib/screens/scheduling/availability_suggestion.dart:336` — Blank basemaps. Fix: cache successes only with a TTL.
- [bug] **Refresh discards the day the dispatcher picked for the suggestion** — `client/lib/screens/scheduling/availability_pane.dart:372` — Fix: remember and resend the picked date.
- [bug] **Local "not a zip" refusal keeps the last query; refresh brings back the old answer** — `client/lib/screens/scheduling/availability_pane.dart:305` — Fix: clear `_lastQuery` and etag.
- [data-loss] **Dispatcher notes draft discarded when the settings tab is re-shown** — `client/lib/screens/scheduling/locations_pane.dart:1066` — Fix: refresh only unedited controllers.
- [bug] **Daily-limit fields save non-numeric input as "no limit"** — `client/lib/screens/scheduling/capacity_settings.dart:436` — Fix: validate before sending.
- [concurrency] **Roster reloads lack a request ticket** — `client/lib/screens/scheduling/technician_roster.dart:75` — Wrong branch's roster under the picker. Fix: ticket.
- [ux] **Month change shows the previous month's numbers under the new title** — `client/lib/screens/scheduling/availability_calendar.dart:120` — Fix: clear data on change.
- [dead-code] **OverbookingPane non-embedded mode has no production caller** — `client/lib/screens/scheduling/overbooking_pane.dart:136` — Fix: remove it and move tests.

### Fleet (12)

- [performance] **Vehicle list runs ~10 queries per row; `per_page=-1` removes the cap** — `app/Http/Controllers/Api/VehiclesController.php:161` — Fix: eager-load; clamp.
- [security] **Toll flat rate can be written without fleet financials** — `app/Http/Controllers/Api/VehiclesController.php:276` — Fix: strip financial keys at top level in store and update.
- [bug] **Logging an older service visit overwrites next-service date and miles** — `app/Http/Controllers/Api/VehicleServiceController.php:76` — Fix: write through only for the latest visit.
- [performance] **Driver picker loads every active user with per-user reach checks** — `app/Http/Controllers/Api/VehicleAssignmentsController.php:61` — Fix: refuse unreachable `company_id`; filter in SQL.
- [concurrency] **Identity save PATCHes every field, overwriting concurrent edits** — `client/lib/screens/fleet/vehicle_identity_card.dart:275` — Fix: send changed fields only.
- [concurrency] **Photos and papers loads lack a stale guard; Remove can delete another record's file** — `client/lib/screens/fleet/vehicle_photos_card.dart:250` — Also vehicle_papers.dart:48. Fix: request counter; key papers by id.
- [bug] **Assignment dates shown as the UTC date** — `client/lib/screens/fleet/vehicle_card.dart:91` — Fix: `toLocal()` for instants.
- [ux] **Record/start forms keep the previous entry after save, inviting duplicates** — `client/lib/screens/fleet/vehicle_payments_card.dart:153` — Also ownership, insurance, service cards. Fix: clear on success.
- [bug] **Bad mileage allowance throws uncaught, so Start period silently does nothing** — `client/lib/screens/fleet/vehicle_ownership_card.dart:105` — Fix: parse inside try.
- [bug] **Local `total` shadows the server total, so Load more shows a wrong count** — `client/lib/screens/fleet/vehicle_list.dart:116` — Fix: rename local; pass `this.total`.
- [performance] **Every vehicle photo downloaded at full size, serially, never evicted** — `client/lib/screens/fleet/vehicle_photos_card.dart:281` — Fix: thumbnails, bounded parallelism, clear on change.
- [duplication] **Duplicate name preview, status labels and a no-op parse wrapper** — `client/lib/screens/fleet/add_vehicle_pane.dart:127` — Fix: use shared helpers in vehicle_card.dart.

### AI / Grok (11)

- [bug] **"Booked, no job" list uses a UTC day and prints UTC times** — `app/Console/Commands/AiBookedNoJob.php:37` — Fix: use the org timezone.
- [security] **AI answers quote training guides from companies the asker cannot reach** — `app/Support/Assistant/AssistantBrain.php:231` — Fix: constrain with InventoryVisibility.
- [security] **WhatsApp command field allow-list bypassed with query-string parameters** — `app/Support/Ai/AppAct.php:240` — Fix: reject `?` or `#` in planned paths.
- [bug] **Branch lookups use the whole question as a LIKE substring and never match** — `app/Support/Assistant/AssistantBrain.php:160` — Fix: tokenise into place names.
- [data-loss] **Library answer hard-deleted on one tap** — `client/lib/screens/ai_assistance_screen.dart:1064` — Fix: inline confirm or undo.
- [data-loss] **One tap deletes a staff-bot menu and all its topics** — `client/lib/screens/ai_assistance/staff_bot_tab.dart:467` — Fix: confirm in place; `mounted` check.
- [concurrency] **Staff-bot role toggles and reorders built from stale snapshots overwrite each other** — `client/lib/screens/ai_assistance/staff_bot_tab.dart:130` — Fix: optimistic update or busy flag.
- [ux] **A failed delete or review blanks the whole tab with no retry** — `client/lib/screens/ai_assistance/tech_tabs.dart:501` — Fix: separate row-action errors from load errors.
- [data-loss] **Manual delete icon removes the PDF and pages in one tap** — `client/lib/screens/ai_assistance/tech_tabs.dart:619` — Fix: confirm.
- [bug] **Answers filter tiles show all-time counts but the list filters only the latest 200** — `client/lib/screens/ai_assistance/tech_tabs.dart:774` — Fix: send `review` and search to the server.
- [ux] **"Taken out" with Undo shows even when removing the member failed** — `client/lib/screens/ai_assistance/whatsapp_groups_tab.dart:171` — Fix: show only on success.

### Deployment (11)

- [bug] **Client error-spike alert sums lifetime counts, so chronic errors look like spikes** — `app/Console/Commands/CheckClientIssueSpikes.php:72` — Re-mails every 6 hours. Fix: count occurrences inside the window.
- [performance] **Unlocking Development also loads the hidden Organizations tab** — `client/lib/screens/development_screen.dart:40` — Fix: start `_opened` empty.
- [performance] **Releases tab polls every 10 s forever, even hidden** — `client/lib/screens/staff/releases_screen.dart:452` — Fix: pause when not visible.
- [bug] **Version bump committed to main before the dirty-tree refusal** — `scripts/release.sh:183` — A refused run burns a version number. Fix: check before bumping.
- [security] **PII guard misses bare-digit phone numbers** — `scripts/hooks/pre-commit:60` — Fix: add a bare-digit pattern (also in gitleaks).
- [bug] **`--ezchat` leaks into the macOS build under the EzBizi bundle** — `scripts/build-app.sh:379` — Fix: refuse with macos or apply only in iOS.
- [bug] **Release status says done even when the live bundle check fails** — `scripts/deploy.sh:648` — Fix: flag smoke failures and exit non-zero.
- [data-loss] **tree-blockers skips deleted files then suggests a checkout that restores them** — `scripts/tree-blockers.sh:34` — Fix: count deletions as real; build the command from the noise list.
- [security] **Any "..." on a line exempts a real secret** — `scripts/hooks/pre-commit:88` — Fix: test the matched token, not the line.
- [dead-code] **"web failed" branches in release.sh are unreachable** — `scripts/release.sh:584` — Fix: delete or make web failure non-fatal on purpose.
- [dead-code] **deploy-www.sh is orphaned and would clobber /var/www/www** — `scripts/deploy-www.sh:7` — Fix: delete or reduce to /download only.

### Unowned — platform core (desk) (10)

- [bug] **RetryOnDeadlock never sees the deadlock** — `app/Http/Middleware/RetryOnDeadlock.php:29` — The routing pipeline renders the exception first; the test calls `handle()` directly. Fix: inspect the returned response; add a route-level test.
- [performance] **Jobs export runs one franchise query per row** — `app/Support/Data/ResourceMap.php:126` — Eager load omits `timezone`. Fix: select it; eager-load in `Exporter::person()`.
- [ux] **Every import database error reported as "conflicts with an existing record"** — `app/Support/Data/Importer.php:175` — Fix: map by SQLSTATE; reject unparseable booleans.
- [bug] **A change-feed cursor older than the 1-day prune silently misses changes** — `app/Support/Live/ChangeFeed.php:175` — Fix: return `reload=true` when the cursor is below the oldest id.
- [bug] **A wrong password in Delete account signs the user out as "session expired"** — `client/lib/core/api_client.dart:535` — Fix: don't treat `invalid_credentials` 401 as dead session, or return 422.
- [ux] **Uploads turn 502/504 pages into "check your connection" and never report them** — `client/lib/core/api_client.dart:343` — Fix: keep the status code; report 5xx.
- [ux] **Web downloads label every file text/csv and revoke the blob URL immediately** — `client/lib/core/files_web.dart:14` — Fails on iOS Safari. Fix: real MIME type; delayed revoke.
- [ux] **`appPath` treats mailto:/tel: links as in-app paths on native builds** — `client/lib/core/deep_links.dart:107` — Fix: require http(s) with a host.
- [concurrency] **An older `/me` answer can undo a just-applied preference or punch** — `client/lib/core/session.dart:270` — Can persist a wrong column-width map or allow a second punch. Fix: bump the refresh generation on local writes.
- [dead-code] **`Session.staffToken` is written in four places and never read** — `client/lib/core/session.dart:45` — Fix: delete it and update the doc comment.

### Web Management (9)

- [security] **Creating a site accepts a branch from a different company** — `app/Http/Controllers/Api/Web/SitesController.php:126` — Leads land on another company's branch. Fix: same check as `update()`.
- [bug] **Bulk hours apply skips the Business Profile write kill switch** — `app/Support/Web/Hours/HoursBatch.php:168` — Fix: re-check `unavailable()` in `apply()`.
- [bug] **`say()` can return the wrong row on same-second ties** — `app/Support/Web/WebsiteEnquiries.php:215` — Reply attributed to the wrong message. Fix: tiebreak by id.
- [bug] **Template and page-field numbers reused after the highest is removed** — `client/lib/screens/web/templates_pane.dart:148` — Live shortcodes repoint. Fix: server-side high-water mark.
- [concurrency] **Site detail Save sends every field, overwriting concurrent edits** — `client/lib/screens/web/seo_tab.dart:1074` — Fix: send changed keys.
- [data-loss] **Invalid numbers sent as null; location country wiped on every save** — `client/lib/screens/web/web_lists.dart:229` — Fix: validate; carry unknown fields.
- [ux] **SEO page editor drops unsaved edits on Back or opening a shared page** — `client/lib/screens/web/seo_helper_pane.dart:1361` — Fix: inline confirm.
- [concurrency] **Content pager and search can pair one page number with another page's items** — `client/lib/screens/web/content_tab.dart:326` — Fix: request token or disable while loading.
- [security] **Plugin rollout never removes files deleted from the plugin** — `scripts/plugin-rollout.sh:108` — Fix: staged swap or `rsync --delete`.

### Inspection (8)

- [security] **Rehearse loads any job in the org by id, ignoring view** — `app/Http/Controllers/Api/InspectionDefinitionsController.php:176` — Fix: restrict to franchises in view.
- [ux] **Inspection shows a blank page when the role holds no sub-tab** — `client/lib/screens/inspection_screen.dart:146` — Fix: explanatory sentence like other modules.
- [bug] **Removing an answer leaves conditions waiting for it** — `client/lib/screens/inspection/builder_question.dart:708` — Dependent questions never show. Fix: value-aware dependency check.
- [ux] **Photo field never offers the camera on phones and uploads full-size images** — `client/lib/screens/inspection/photo_field.dart:70` — Fix: `Files.takePhoto()`, downscale, `cacheWidth`.
- [ux] **Signature pad inside a scrolling list: vertical strokes scroll instead of drawing** — `client/lib/screens/inspection/signature_field.dart:254` — Fix: eager gesture recogniser.
- [bug] **Client `required_if` check compares a bool with a string and never fires** — `client/lib/screens/inspection/new_tab.dart:478` — Submit fails later. Fix: compare scalars as strings.
- [concurrency] **Opening reports quickly can show an older report and keep downloading its photos** — `client/lib/screens/inspection/reports_tab.dart:205` — Fix: open ticket.
- [bug] **Screen-delete dependency check filters by label text** — `client/lib/screens/inspection/builder_tab.dart:533` — A same-named question elsewhere is ignored, blocking later saves. Fix: compare by key.

### Jobs (8)

- [concurrency] **Estimate status transition not locked: a double accept fires `estimate.accepted` twice** — `app/Http/Controllers/Api/EstimatesController.php:174` — Fix: lock and re-check, or conditional update with 409.
- [ux] **Empty Jobs list says "No jobs yet." under a money filter** — `client/lib/screens/jobs_screen.dart:1559` — Fix: include `_money` in the filtered check.
- [performance] **Estimate board reloads all columns on every keystroke without a stale guard** — `client/lib/screens/jobs/estimates_tab.dart:453` — Fix: pass the submitted term; per-column request counter.
- [ux] **Duplicate sibling keys from data values (payments, timesheets, uploads)** — `client/lib/screens/jobs/job_workiz.dart:309` — Also job_files.dart:676. Fix: index or id-based keys.
- [bug] **"For a job" choices search by customer name although `/jobs` supports `customer_id`** — `client/lib/screens/jobs/estimates_tab.dart:1077` — Fix: query by id.
- [bug] **Estimate line price silently drops the minus sign** — `client/lib/screens/jobs/estimates_tab.dart:715` — Fix: keep or reject negatives with a message.
- [ux] **Deleting a job note (and cancelling a sale) runs with no confirmation** — `client/lib/screens/jobs/job_notes.dart:477` — Also sale_pane:377. Fix: confirm in a side panel.
- [ux] **Options-load failure leaves a permanent booking error** — `client/lib/widgets/book_job_panel.dart:357` — Fix: separate error or clear on success.

### Unowned — platform auth (desk) (8)

- [security] **Development unlock TOTP throttle is per user and IP, so rotating IPs allows unlimited guesses** — `app/Http/Controllers/Api/DevelopmentUnlockController.php:37` — Fix: add a per-account limiter and alert.
- [security] **Staff login throttle keyed on email+IP only** — `app/Http/Controllers/Api/StaffAuthController.php:46` — Distributed guessing is unlimited. Fix: add a per-email limiter.
- [dead-code] **`managedElsewhere()` is never called** — `app/Http/Controllers/Api/TwoFactorController.php:249` — Fix: delete it.
- [bug] **Organization picker fails when the typed email's case differs from the stored one** — `app/Services/Auth/AuthenticateUser.php:209` — Fix: compare case-insensitively.
- [concurrency] **TOTP replay check is a non-atomic read-then-save** — `app/Support/Auth/TotpEnrolment.php:187` — Also StaffAuthenticator. Fix: compare-and-set update.
- [security] **`Session.signOut()` never revokes the token on the server** — `client/lib/core/session.dart:363` — EzChat and update-screen callers skip it. Fix: revoke inside Session. (Related to the EzChat and push sign-out findings.)
- [bug] **Staff session is signed out and its token deleted on any network or server failure** — `client/lib/core/staff_session.dart:92` — Fix: clear only on 401.
- [ux] **Continue with an empty password does nothing and says nothing** — `client/lib/widgets/two_factor_card.dart:173` — Fix: disable until filled.

### Automations (7)

- [performance] **`recentInView` loads 30 days of events before filtering and limiting** — `app/Http/Controllers/Api/AutomationsController.php:767` — Fix: limit in SQL.
- [concurrency] **Rule failure counter is non-atomic on a stale model** — `app/Models/Automation.php:175` — Can auto-disable on a wrong count. Fix: atomic increment and conditional disable.
- [bug] **Undefined `$event` in three task steps turns a deleted-task skip into a crash** — `app/Support/Automations/ActionRunner.php:4233` — Counts toward auto-disable. Fix: pass `$event` to moveTaskToGroup, shiftTaskDate, setTaskValue.
- [bug] **One recipient's WhatsApp refusal stops the report for every later recipient** — `app/Support/Automations/ActionRunner.php:4389` — Fix: collect refusals and continue.
- [concurrency] **"Turn on" for a new rule duplicates the rule when activation fails and the user retries** — `client/lib/screens/automation_builder.dart:1776` — Fix: keep the created id and switch to edit mode.
- [ux] **Catalogue failure hides the loaded rules list** — `client/lib/screens/automations_screen.dart:290` — Rules can't be switched off while it fails. Fix: separate catalogue error banner.
- [ux] **"Run it now for real" offered to people who cannot run it** — `client/lib/screens/automations_screen.dart:654` — Fix: `refusedMenuItem`/`Refused` with `noEdit`.

### MCP / API (6)

- [security] **Bulk-read detector counts non-atomically, so parallel scraping evades it** — `app/Http/Middleware/DetectBulkReads.php:77` — Fix: atomic `Cache::add` + `increment`.
- [bug] **"Changes only" filters only the latest 200 rows, falsely saying "no changes yet"** — `client/lib/widgets/api_activity.dart:177` — Fix: server-side `changes_only`.
- [bug] **Revert result tied to a list index that a reload reassigns** — `client/lib/widgets/api_activity.dart:787` — Fix: key by event id.
- [concurrency] **API activity loads lack a stale guard; older pages mix filters** — `client/lib/widgets/api_activity.dart:135` — Fix: request token.
- [ux] **A failed revoke replaces the card, hiding the shown-once key** — `client/lib/widgets/api_keys_card.dart:160` — Fix: inline revoke error.
- [security] **Monitoring agent routes skip the api-key middleware** — `routes/api.php:3901` — A read-only key can write heartbeats and screenshots; nothing audited. Fix: add `api-key` or refuse API keys; add a test.

### Membership Program (6)

- [security] **Adding a member bypasses the `jobs.financials` money gate** — `app/Http/Controllers/Api/MembershipsController.php:94` (merged: reported twice by two slices) — `store()` accepts `paid_minor` and returns amounts without the permission. Fix: same check as `update()`; pass the real money flag to `present()`.
- [performance] **Promotion picture copied and decoded again on every keystroke** — `client/lib/screens/customers/memberships_promotions.dart:1319` — Fix: store bytes once.
- [ux] **A chosen promotion filter with no options in the new range is hidden but still applied** — `client/lib/screens/customers/memberships_promotions.dart:301` — Fix: prune picks on reload.
- [bug] **Member plan picker offers plans closed to new members** — `client/lib/screens/customers/memberships_members.dart:629` — Fix: filter active (plus current); server refusal.
- [ux] **Rejected "Paid so far" edit closes the field and discards what was typed** — `client/lib/screens/customers/memberships_members.dart:499` — Fix: close only on success.
- [bug] **After a member edit, Est. gross drops the average job** — `client/lib/screens/customers/memberships_members.dart:503` — Fix: compute average in `update()`.

### Training (6)

- [bug] **Training overdue and expiry dates use the UTC day** — `app/Http/Controllers/Api/Training/DashboardController.php:626` — Also LapsedPasses and LearnController:345. Fix: org-zone today.
- [ux] **Submit enabled with unanswered questions, spending a capped attempt** — `client/lib/screens/training/test_player.dart:360` — Fix: disable or confirm.
- [concurrency] **Assignee search sends a request per keystroke and applies them in arrival order** — `client/lib/screens/training/dashboard_pane.dart:505` — Fix: debounce and drop stale.
- [ux] **Publish/Unpublish fails silently** — `client/lib/screens/training/guide_builder.dart:310` — Fix: show the reason; disable while in flight.
- [ux] **Phone view stuck blank when the selected item disappears after a reload** — `client/lib/screens/training/dashboard_list.dart:123` — Fix: base `hasPane` on the row.
- [bug] **Settings search filters server results again with different rules** — `client/lib/screens/training/settings_tab.dart:99` — Status searches never match. Fix: one filter.

### UI/UX (6)

- [security] **Organization-wide owners can be set by anyone with `company.edit`** — `app/Http/Controllers/Api/OwnershipController.php:66` — (Company chart.) Fix: require organization reach when no scope is given.
- [bug] **DateSpan.days undercounts by one across the spring DST change** — `client/lib/widgets/date_range_picker.dart:35` — Also availability_grid:100. Fix: UTC day counts.
- [performance] **Flow chart repaints everything at 60 fps while on screen** — `client/lib/widgets/flow_chart.dart:609` — Fix: static painter plus lightweight dash painter.
- [bug] **Quick search shows results for an older term after the box is shortened** — `client/lib/widgets/quick_search.dart:98` — Fix: bump `_asked` in `_changed`.
- [dead-code] **ListTable selection, bulk actions and grouping have no callers** — `client/lib/widgets/list_table.dart:303` — Index-based selection is a latent hazard. Fix: delete, or key by row id if revived.
- [ux] **A failed avatar download hides that photo for the whole session** — `client/lib/widgets/user_avatar.dart:135` — Fix: cache only real results.

### Customers (5)

- [performance] **Duplicate sweep runs one jobs COUNT per customer** — `app/Http/Controllers/Api/CustomersController.php:619` — Fix: `withCount('jobs')`.
- [ux] **Empty Customers list says "No customers yet." under a branch or tag filter** — `client/lib/screens/customers_screen.dart:812` — Fix: treat branch/tag as filters.
- [bug] **Lead rows label today/yesterday by 24-hour spans** — `client/lib/screens/customers_screen.dart:738` — Fix: compare calendar dates.
- [concurrency] **Customer save sends every field from a stale snapshot** — `client/lib/screens/customers/customer_detail_panel.dart:246` — Reverts concurrent changes. Fix: send changed keys only.
- [ux] **Delete customer fires on one click with no confirmation** — `client/lib/screens/customers/customer_detail_panel.dart:748` — Fix: two-press confirm.

### Roles & Permissions (5)

- [performance] **Every `hasPermission()` runs several uncached queries** — `app/Models/Role.php:92` — Roster filters become N+1 (~1,000 queries on chat contacts). Fix: memoise keys; eager-load role permissions.
- [performance] **`reachableFranchises()` runs a subtree query per franchise** — `app/Models/User.php:591` — Fix: compute the company set once; filter in SQL.
- [concurrency] **Last-administrator floor is check-then-write without a lock** — `app/Support/Access/AdministratorFloor.php:60` — Fix: lock admin rows in the same transaction.
- [bug] **Rename saves every pending permission edit along with the name** — `client/lib/screens/roles_screen.dart:1386` — Fix: PATCH only the name, or refuse while dirty.
- [ux] **Mobile bar options never reload after a failed load** — `client/lib/screens/role_mobile_bar.dart:62` — Fix: set loaded only on success; add retry.

### Dashboard (4)

- [bug] **Jobs pie uses one branch's status vocabulary; other statuses vanish** — `app/Support/Dashboard/Widgets/JobsByStatus.php:253` — Fix: build slices from counted statuses with labels.
- [bug] **Starters/Leavers counts erased accounts** — `app/Support/Dashboard/Widgets/Hr/StartersAndLeavers.php:423` — Fix: `removed(false)`.
- [bug] **Fleet overdue and expired dates compared against the UTC date** — `app/Support/Dashboard/Glances/FleetGlance.php:255` — Fix: use the user/org zone.
- [concurrency] **Overlapping layout saves and live reloads can undo a dashboard change** — `client/lib/screens/dashboard_screen.dart:229` — Fix: serialise PUTs; bump the request ticket on edits.

### Internal Chat (4)

- [security] **Chat file access ignores `left_at`, so removed members can still download attachments** — `app/Policies/ChatMessagePolicy.php:19` (merged: reported twice by two slices) — Fix: `whereNull('left_at')` and respect `cleared_at`.
- [concurrency] **Create group has no in-flight guard; double tap makes duplicate groups** — `client/lib/screens/phone/chat/chat_tab.dart:979` — Fix: `_creating` flag.
- [bug] **`pulse()` records the new rev before reload succeeds; a failed reload leaves the list stale** — `client/lib/screens/phone/chat/chat_store.dart:96` — Fix: update rev only on success.
- [performance] **Group info fetched from `build()` on every rebuild until it succeeds** — `client/lib/screens/phone/chat/chat_thread.dart:908` — Fix: load once with in-flight flag.

### Jobs (Project Management code) (4)

- [performance] **Tech expenses loads every job id in view and every org expense** — `app/Http/Controllers/Api/ProjectBoardController.php:961` — Fix: push the scope into SQL.
- [ux] **Board refreshes drop pages past 200 rows; closing a job resets to page 1** — `client/lib/screens/project_board_screen.dart:234` — Fix: page quiet re-reads; quiet reload after the panel closes.
- [ux] **Project Inbox searches per keystroke without staleness checks; failures look like an empty inbox** — `client/lib/screens/project_board_screen.dart:3966` — Fix: debounce, request counter, ErrorNote with Retry.
- [concurrency] **A sent inbox reply is added to whichever thread is open when it finishes** — `client/lib/screens/project_board_screen.dart:3936` — Fix: append only when the same thread is open.

### Meta / WhatsApp (4)

- [bug] **Meta rate-limit errors mark working connections broken** — `app/Console/Commands/CheckMetaAccess.php:111` — Any non-5xx is treated as refused, alerting admins to reconnect. Fix: only auth/permission codes are "refused"; space calls out.
- [bug] **Campaigns of an org with no categories block the suggestion queue** — `app/Console/Commands/SuggestMetaCategories.php:39` — Fix: stamp `suggested_at` or exclude such orgs; order the query.
- [bug] **Bill and results photos downloaded with the global token, not the number's** — `app/Support/Social/WhatsAppBills.php:42` — Also WhatsAppResults:51. Fix: pass the phone number id.
- [dead-code] **`flushPending` drains a table nothing writes any more** — `app/Support/Social/WhatsAppGroups.php:521` — Fix: remove it, its call, model and stale docblock.

### Services (4)

- [bug] **Moving a service to another company keeps a group/label from the old company** — `app/Http/Controllers/Api/ServicesController.php:197` — Fix: re-run `taxonomyInPlace` on a place change.
- [bug] **Price sort and kind chips only cover the loaded page** — `client/lib/screens/services/service_list.dart:201` — Fix: sort server-side.
- [performance] **Services list runs one attachments query per row** — `app/Http/Controllers/Api/ServicesController.php:61` — Fix: eager-load attachments.
- [ux] **Group/label delete errors invisible to users without `services.create`** — `client/lib/screens/services/service_settings.dart:502` — Fix: render error outside the create block; confirm.

### Workiz (4)

- [bug] **Unprojectable rows stay at the head of each batch and can stop new customers and jobs** — `app/Console/Commands/ProjectWorkizRecords.php:490` — 500+ permanently skipped rows (e.g. deleted branch) are reselected every minute. Fix: put the skip conditions in SQL or use a cursor.
- [bug] **Flag dedupe row written before the task and alert; a failure loses the flag** — `app/Console/Commands/FlagWorkizJobs.php:94` — Fix: transaction, or delete the row on failure.
- [bug] **Fuzzy tag matching maps short names to different ones** — `app/Support/Workiz/WorkizJobWriter.php:612` — "AM" becomes "PM". Fix: fuzzy only for long names.
- [data-loss] **`setTags` removes tags before the PUT that adds new ones** — `app/Support/Workiz/WorkizJobWriter.php:580` — A refused PUT leaves the job with no tags. Fix: add first, then remove.

### Notifications (3)

- [performance] **Sent-alerts list runs one replies COUNT per question** — `app/Http/Controllers/Api/AlertsController.php:86` — Fix: `withCount('replies')`.
- [performance] **A negative `?limit` removes the cap on the notifications poll** — `app/Http/Controllers/Api/NotificationsController.php:72` — Same in RecruitingController and ReviewsController. Fix: clamp both ends.
- [bug] **Queued notice emails lost for good after one SES failure** — `app/Notifications/PaperworkReadyToFinish.php:20` — Also NotificationNotice. Fix: `$tries = 3` with backoff.

### Plaid Integration (3)

- [performance] **Bank sync auto-match reloads every matched transaction per deposit** — `app/Support/Financials/Plaid/BankMatcher.php:132` — Fix: compute taken ids once per sync.
- [performance] **Reconnect polling reloads everything every 3 s and resets the transaction list** — `client/lib/screens/financials/financial_bank.dart:124` — Fix: poll status only; skip overlap.
- [concurrency] **Out-of-order transaction pages mix filters; a failed page is skipped** — `client/lib/screens/financials/financial_bank.dart:81` — Fix: sequence number; increment page on success.

### Amazon SES (platform mail) (2)

- [security] **Raw reset and verification tokens stored in plain text in jobs and failed_jobs** — `app/Notifications/PasswordResetLink.php:44` — Also EmailVerificationLink. Fix: implement `ShouldBeEncrypted`.
- [ux] **Link expiry times in emails are UTC with no zone label** — `app/Notifications/EmailVerificationLink.php:82` — Also InvitationLink:102. Fix: append " UTC" or use the org zone.

### Clock In / Work Hours (2)

- [security] **A payroll manager can bonus and approve their own week** — `app/Support/TimeClock/TimeClockPayroll.php:136` — Also daily approvals. Fix: refuse self unless Administrator.
- [concurrency] **A live refresh for the old day can overwrite the day just opened** — `client/lib/screens/hr/work_hours_approval_tab.dart:83` — Fix: shared request counter.

### Guidelines (2)

- [data-loss] **New guideline created twice when its image upload fails and the user retries** — `client/lib/screens/guidelines_screen.dart:614` — Fix: keep the created id and PATCH afterwards.
- [bug] **Drag-reorder sends one PATCH per card and ignores failures** — `client/lib/screens/guidelines_screen.dart:186` — Fix: one batch reorder; stop on failure.

### API Integrations (1)

- [performance] **`presentableInView` runs per-row queries over every connection** — `app/Support/Social/SocialConnectionAccess.php:48` — Fix: resolve view sets once.

### Inventory (1)

- [bug] **Receive/Fulfil fields keep stale quantities after a partial receive** — `client/lib/screens/inventory/purchase_order_pane.dart:86` — Also sale_pane. Fix: reset to remaining; key panes by id.

### Suggestions (session gone) (1)

- [ux] **Typing in the queue search wipes the reply being written** — `client/lib/screens/staff/suggestions_queue_screen.dart:165` — Fix: refill note only on selection change.

