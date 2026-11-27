# Monday shutdown audit — EzBizi side (2026-11-25)

Monday goes OFF Thu 2026-11-26. This is a read-only audit: nothing was edited, stopped
or written. Code read at `main` 9b6a0fc24; production read on `ssh app` at about
21:15 UTC 2026-11-25 (read-only tinker/SQL, one read-only Monday GraphQL query).

## 0. Summary

| Risk | Measured | Effect if nothing is done |
|---|---|---|
| `ezbizi:monday-parity` does NOT filter on connection status | `where('provider','monday')->get()` (MondayParity.php:39) | Once the connection is `broken`, or Monday answers with an error, it throws: **~49 failed scheduled commands/day** (every 30 min, plus 04:30 nightly), each an ERROR + reported exception in laravel.log, forever |
| Monday answers an error that is not 401/403 | e.g. 200 with `errors`, or timeouts | Connection stays `connected`, so sync (144/day), parity (49/day), unmirrored (24/day), push shards (each outbox row retried 8x then `failed`), PeopleAliases (every 6 h) all log errors. A hanging API (60 s timeout per call) also holds the **slow lane**, which delays Workiz/QuickBooks/Plaid syncs |
| Files hosted on Monday | **1,994 Monday URLs in 1,341 update bodies** (1,008 tasks); 1,989 of them are `*.monday.com/protected_static/.../resources/...` file links | Links go dead. **All 1,980 protected_static file links have an EzBizi copy** (same file name attached to the same update), so the files themselves are not lost |
| Files never copied | **5** `[file not carried from monday: …]` notes | Already lost (Onlince Search.pdf x2, New Text Document.txt, record.mpeg x2) |
| Monday webhooks still firing | **4,684** POSTs to `/api/v1/integrations/monday/webhook` in today's nginx log; `setup.monday.webhooks` is EMPTY (ids lost) | Harmless once the connection is gone (200, `queued: 0`), but noise if the Monday account stays up. The ids must be listed from Monday to delete them |
| Monday names as people-search aliases | PeopleAliases.php reads Monday `users` live (cached 6 h) | "Chris" → Kristopher-type aliases vanish after Monday is off |
| Outbox backlog | **0 pending**; 7 + 2 `failed`, 327 `superseded`; 105,128 sent since 11-16; 1,634 sent in last 24 h | Nothing waiting to reach Monday right now |
| Monday-related failed_jobs | **2 in last 24 h** (SyncMondayItem timeout + max attempts); **109 in 7 d** (92 PushMondayShard max-attempts, 12 PushMondayShard timeout, 3 PushBoardMembers USER_UNAUTHORIZED, 2 SyncMondayItem) | — |
| Unmirrored Monday boards | 46 active + 2 archived on Monday, **0 not mirrored**; 0 Monday docs visible to the API | Nothing left only on Monday at board level |

## 1. Code: every Monday dependency

All live paths reach Monday through `App\Support\Tasks\Monday\MondayClient` (reads, `https://api.monday.com/v2`,
60 s timeout, retries 429/complexity up to 5x, one token renew on 401/403, then `MondayRefused`) or
`MondayWriter` (mutations; 401/403 → `MondayRefused`, other errors → `RuntimeException`), with the token from
`MondayToken` (refresh under a cache lock; `invalid_grant` or a `broken` row → `MondayRefused`).
Nearly every caller selects the connection with `provider = monday AND status = connected`; the exceptions are marked **NO STATUS FILTER**.

### 1.1 Scheduled (routes/console.php)

| Line | Entry | Does | When Monday is unreachable / token revoked |
|---|---|---|---|
| 220 | `ezbizi:monday-sync --max-seconds=900` every 10 min (slow lane) | Monday → Task Board: changed items since each board's cursor, via MondayImport (items, updates, files downloaded to EzBizi storage) | `MondayRefused` → marks connection `broken`, prints error, exit 1 (one "Scheduled command failed" ERROR), then no-op because no connected row. Any other error → `report($e)`, exit 1, **every 10 min**, connection stays connected |
| 230 | `ezbizi:monday-sync --scan` 03:40 NY daily | Lists every item to catch subitem-only changes | Same as above |
| 234-236 | `Schedule::job(new PushMondayShard($i,4),'monday-push')` x4, every minute | Task Board → Monday: drains `monday_outbox` (create/move/values/updates/archive) | Only `connected` rows; `rescue()` reports. Per-row error: attempts+1, backoff 2^n min, `failed` after 8. MondayRefused stops the run, retried next minute (reported each minute x4 until the row is `broken`) |
| 241 | `ezbizi:monday-parity --fix --groups-only --gone --max-items=2000` every 30 min | Compares mirrored boards with Monday; trashes here what Monday archived/deleted; queues outbox rows | **NO STATUS FILTER**. Broken row → MondayToken throws MondayRefused → uncaught → exit 1 + reported exception, every 30 min |
| 242 | `ezbizi:monday-parity --values` 04:30 NY | Value-level diff | Same; also already exits 1 on drift (09h ERROR today) |
| 244 | `ezbizi:monday-unmirrored` hourly | Lists Monday boards with no mirror; `report()`s them | Connected only; on a non-401 error throws hourly |
| 582 | slow-lane regex lists `monday-sync|monday-parity` | Routing only | Remove with the entries |
| 38 | `ezbizi:check-connection-health` 06:40 | Monday liveness (`{ me { id } }`, ConnectionLiveness.php:141) | 401/403 → marks `broken`; other → "unknown", stays connected |

Also scheduled but NOT Monday-dependent (comments only, keep): `tasks-post-scheduled-updates` (calls `MondayOutbox::record`, a no-op without a connected row), `tasks-nudges`, `tasks-dates-arrived`, `tasks-fill-from-record`.

### 1.2 Queued jobs

| Job | Queue | Dispatched by | Tries | Unreachable / revoked |
|---|---|---|---|---|
| `PushMondayShard` | monday-push | scheduler, every minute x4 (ShouldBeUnique 600 s) | 1, timeout 85 s | see above; timeouts → failed_jobs (12 in 7 d) |
| `SyncMondayItem` | monday | MondayWebhookController (+30 s delay) | 3, backoff 30/300 | Returns if no connected row; else throws → failed_jobs after 3 |
| `PushBoardMembers` | monday-push | TaskBoardsController:782/807/826 (every member add/remove/role change), MondayParity | 3, backoff 60/600 | Returns if no connected row. If `monday-push --pause` is on it `release(300)`s and lands in failed_jobs after 3 tries. USER_UNAUTHORIZED/403 swallowed once per board |
| `MoveMondayGroup` | monday-push | TaskBoardSettingsController:236 (group reorder on a Monday board) | 3 | Returns if no connected row; else throws → failed_jobs |
| `ArchiveMondayGroup` | monday-push | TaskBoardSettingsController:276 (group delete, +3 min) | 12 | Returns if no connected row; else throws → failed_jobs after 12 |
| `QueuedCommand['ezbizi:monday-sync --full']` | default | SocialAuthController:474 on (re)connect | — | Only on Connect |

If the monday/monday-push workers are stopped while these dispatch sites remain, jobs pile up in `queue_jobs` (PushMondayShard alone ≈ 4 per 10 min ≈ 576/day).

### 1.3 Writers that record to the outbox (no Monday call themselves)

`MondayOutbox::record()` — called from TaskWriter (145, 175, 565, 621, 647, 670, 681), TasksController:535,
TaskBoardSettingsController:273, MondayParity, TasksPostScheduledUpdates:38. It writes a row only when the board is
`monday:` + not archived AND the org has a `connected` Monday row (`linked()`), so it goes silent by itself once the
connection is gone. `TaskWriter::mondayKinds()` is ALSO used by `ModuleBoard::changed` and `ComplaintLink::changed`
(TaskWriter:176-177): rename it, do not delete it, when retiring the outbox.

### 1.4 Webhook route and controller

`POST /api/v1/integrations/monday/webhook` (routes/api.php:689, `MondayWebhookController`): unauthenticated, JWT checked
against `MONDAY_SIGNING_SECRET`; queues `SyncMondayItem` only for an org with a `connected` row (also calls Monday
`{ me { id } }`, cached 24 h, for echo detection). With no connected row it answers 200 `{"queued":0}` and does no I/O.
Live: 4,684 hits in today's `app.ezbizi.com.access.log`. `MondayWebhooksCommand` stores ids in `setup.monday.webhooks`,
but the stored setup now has only `as, last, people, cursors, partial`: the ids were lost, so deleting them needs a
Monday `webhooks(board_id:)` listing per board (or uninstalling the EzBizi app from the Monday account).

### 1.5 Other request-time readers

- `App\Support\Tasks\PeopleAliases::mondayNames()` — people pickers; live Monday `users` query, cached 6 h per org; on error `report()` + `[]` (cached). Loses Monday display names as aliases after shutdown.
- `ConnectionLiveness::monday()` — Settings › Integrations recheck + daily health.
- `MondayOAuth` (`auth.monday.com`) + `SocialProvider::Monday` — Settings › Integrations › Connect Monday (SocialProvider.php:125, 162, 221, 297, 466, 521, 669).

### 1.6 Manual / one-off commands (retire with the code)

`ezbizi:monday-import-boards`, `ezbizi:tasks-import-monday`, `ezbizi:monday-merge-board`, `ezbizi:monday-automations`,
`ezbizi:monday-webhooks`, `ezbizi:monday-push` (manual + `--pause/--resume`), `ezbizi:monday-update-links`
(**NO STATUS FILTER**, `firstOrFail`), `ezbizi:monday-fix-video-mimes` (DB only). Support classes:
`app/Support/Tasks/Monday/*` (12 files, ~3,900 lines). Tests touching them: 9 files
(MondayBoardMergeTest, MondayClientTokenTest, MondayImportTypesTest, MondayPushTest, MondaySyncTest, MondayTokenTest,
MondayWebhookTest, MondayUpdateLinksTest, SensitiveTasksTest).

### 1.7 Config / env

`config/services.php:115` `monday.*`; prod `/var/www/ezbizi/.env` holds `MONDAY_CLIENT_ID`, `MONDAY_CLIENT_SECRET`,
`MONDAY_SIGNING_SECRET`. Table `monday_outbox` (RetentionPolicy lists it). Columns `external_ref` (`monday:<id>`) on
task_boards/task_groups/tasks/task_updates and `external_updated_at`/`external_synced_at` on tasks: keep as history.

### 1.8 Client (Flutter)

No client code reads `monday:` refs or calls Monday. Monday-facing UI is only: Settings › Integrations tile
(`integrations_screen.dart:1298` "Keeps the boards brought over from Monday in step.", `integration_provider_screen.dart:892-893`,
`social_network_mark.dart:52` monday.svg). All other "Monday" hits in client/lib are comments or weekday names.

## 2. Production state (read-only, 2026-11-25 ~21:15 UTC)

**Connection**: one row, `social_connections.id = 01a1d19e-7f63-73cc-8ab2-2b2088e3615d`, org `019fd712-74fd-712c-b179-fd88052cc371`
("A1 Group"), Monday account 27632623, `status = connected`, last_reached 20:17 UTC, last_checked 06:41 UTC.
`setup.monday`: 47 cursors, 27 people-map entries, no webhook ids.

**systemd units dedicated to Monday** (all `active running`, run as www-data from /var/www/ezbizi-current):

| Unit | Queue | Enabled |
|---|---|---|
| `ezbizi-worker-monday.service` | monday | enabled (multi-user.target.wants) |
| `ezbizi-worker-monday-push@1..4.service` | monday-push | enabled (4 wants symlinks, 11-18) |
| `ezbizi-worker-monday-temp4.service` | monday | **transient** (systemd-run), started 20:58 UTC today, RuntimeMaxSec=2h (ends ~22:58 UTC) — someone else started it; ask before touching |

No Monday timers or cron entries; Monday work runs through the generic scheduler units (`ezbizi-scheduler-slow` for sync/parity, scheduler@N for the push shards and unmirrored).

**Queue / outbox**: `queue_jobs` holds 1 row (default), 0 on monday / monday-push.
`monday_outbox`: pending 0; sent 69,211 live + 35,917 backfill; failed 7 live + 2 backfill; superseded 174 + 153.
Rows created last 24 h: 1,662; sent last 24 h: 1,634.

**failed_jobs (Monday-related, by displayName)**:
- last 24 h: 2 — `SyncMondayItem` TimeoutExceeded (1), MaxAttemptsExceeded (1). All failed_jobs last 24 h: 2.
- last 7 d: 109 — `PushMondayShard` MaxAttemptsExceeded 92, TimeoutExceeded 12; `PushBoardMembers` "User unauthorized" 3; `SyncMondayItem` 2.

**laravel.log (11-24/25)**: hourly-ish `Monday parity: drift` warnings (1-346), one `monday-parity --values` exit 1 at 09h, three "Monday refused member changes" (Users, Job Roles, Analytics Dashboard boards: connection not owner).

**Mirrored data**: 46 live + 2 archived Monday boards, 278 groups, 92,624 live + 460 trashed tasks, 133,266 updates, 135 automations copied from Monday.

## 3. Data whose only copy is on Monday

| What | Count | Copy in EzBizi? |
|---|---|---|
| Attachments on Monday-imported updates | 29,156 rows, 29 GB, all `disk=local` | Yes — stored in EzBizi storage |
| attachments whose `path` is a URL / mentions monday | 0 / 0 | — |
| Monday URLs in `task_updates.body` | 1,994 URLs in 1,341 updates on 1,011 tasks (2 of them sealed/sensitive) | — |
| … hosts | a-1group.monday.com 1,951; a-1chimney.monday.com 41; monday.com 2 | — |
| … kind: file links `protected_static/<acct>/resources/<id>/<name>` | 1,989 (1,338 updates, 1,008 tasks) | **Yes, all 1,980 distinct-matched**: a same-named attachment exists on the same update. Only the inline link dies |
| … kind: board/item links (`/boards/…/pulses/…`) | 2 | No — die |
| … kind: other (`http://monday.com/`) | 3 | n/a |
| `[file not carried from monday: …]` notes | 5 on 5 updates | **No — never copied** (Onlince Search.pdf x2, New Text Document.txt, record.mpeg 2.2 MB, record.mpeg 1.0 MB) |
| task_column_values with Monday URL (any column type) | 0 | — |
| users.avatar_path http / monday | 0 / 0 | — |
| social_connections.avatar_url monday | 0 | — |
| board/folder icons, task_columns.settings, task_boards.builtin_columns, users.preferences with monday.com | 0 / 0 / 0 / 0 (boards and folders have only `colour`, no icon column) | — |
| tasks.title mentioning monday.com | 2 ("Local Partner Email & Monday.com" — text, not a link) | — |
| Monday-side: boards not mirrored | 0 of 46 active + 2 archived; workdocs visible: 0 | — |
| Monday display names (people aliases) | 27-entry people map stored; names themselves read live | **No** — snapshot before shutdown |

The `protected_static` links already need a Monday login to open, so for EzBizi users they are dead today; after
shutdown they are dead for everyone. A body rewrite (replace each link with the attachment's EzBizi link) is optional
cosmetics, not data recovery.

## 4. Things that SAY Monday

To retire or relabel after shutdown:

- **Settings › Integrations › Monday** tile and Connect flow: `SocialProvider::Monday` in the provider list (SocialProvider.php:297), its copy (:521 "connect Monday", :669 success sentence), client `integrations_screen.dart:1298`, `integration_provider_screen.dart:892-893`. Hide the provider so nobody reconnects it.
- **Automations copied from Monday**: 135 rules whose `description` starts `monday:<id> (Monday, …)`, shown by AutomationsController:2795-2803 as "Copied from Monday (… there). Off here: … (removed on Monday)". By state: 63 active, 28 inactive (plain), 28 inactive "gone on Monday" + "off: not here", 16 inactive "off: not here". The text should become neutral (e.g. "Imported 2026-11"); the 44 off ones citing Monday reasons are candidates to delete or explain without Monday.
- **Task Activity history**: 15,939 `task_activities` rows labelled with Monday ("trashed (gone on Monday)", "moved here (moved on Monday)", "Assigned in Monday", "Moved to trash: no longer on Monday (parity check)"). History, keep as is.
- **Update bodies**: 30,931 updates prefixed "Name (on Monday): " and 23,654 "Files from Monday" updates. History, keep.
- **Strings emitted by live code that stop being true**: MondayPush activity lines ("Not sent to Monday: …", "The item is archived on Monday…"), MondayImport ("updates edited on Monday", "… update on Monday rings the task"), TaskWriter:913 / MondayUpdateLinks:129 "Somebody on Monday". All go away with the code.
- **audit_events** relabel "Monday sync" (docs/agents/audit-monday-sync-relabel-2026-11-24.sql): history, keep.
- Comments saying "Monday parity"/"Monday's …" across ~60 files: harmless, no action.

## 5. Shutdown runbook (ordered; NOT run)

All commands on `ssh app` unless marked. Close stdin on tinker (`</dev/null`).

### Before Monday goes off (today / Thu morning, while Monday still answers)

1. **Final delta from Monday** (slow-lane job, ~minutes per board; run once, by hand):
   ```
   cd /var/www/ezbizi-current && sudo -n -u www-data php8.4 artisan ezbizi:monday-sync --scan </dev/null
   cd /var/www/ezbizi-current && sudo -n -u www-data php8.4 artisan ezbizi:monday-parity --values </dev/null   # report only, no --fix
   ```
2. **Confirm nothing is waiting to reach Monday**:
   ```
   cd /var/www/ezbizi-current && sudo -n -u www-data php8.4 artisan tinker --execute="echo DB::table('monday_outbox')->selectRaw('state, count(*) c')->groupBy('state')->pluck('c','state'), PHP_EOL, DB::table('queue_jobs')->selectRaw('queue, count(*) c')->groupBy('queue')->pluck('c','queue');" </dev/null
   ```
   Expect no `pending` and no monday/monday-push rows.
3. **Snapshot what lives only on Monday**:
   - Monday user names (for people aliases): tinker
     `file_put_contents(storage_path('app/monday/users-2026-11-26.json'), json_encode(App\Support\Tasks\Monday\MondayClient::for(App\Models\SocialConnection::withoutGlobalScopes()->where('provider','monday')->first())->query('{ users(limit:1000, kind:all) { id name email } }')['users']));`
   - Monday webhook ids per board (to delete them, step 6): `{ webhooks(board_id: <id>) { id event } }` for each of the 46 board ids in `task_boards.external_ref`.
4. **Dump the connection row** (before any change to it):
   ```
   cd /var/www/ezbizi-current && sudo -n -u www-data php8.4 artisan tinker --execute="file_put_contents(storage_path('app/monday/social-connection-2026-11-26.json'), json_encode(DB::table('social_connections')->where('id','01a1d19e-7f63-73cc-8ab2-2b2088e3615d')->first())); echo 'ok';" </dev/null
   ```
   (`credentials` stays encrypted as stored; restore = insert the row back.) `monday_outbox` needs no dump: nothing in this runbook writes it.

### At cut-over (T0)

5. **Kill switch — make every Monday path a no-op.** Preferred: a release that (a) deletes from routes/console.php only the Monday entries — :220 and :230 (monday-sync), :234-236 (PushMondayShard x4, plus the `use` on :3), :241-242 (monday-parity), :244 (monday-unmirrored) and their comments; NOT :222 (tasks-fill-from-record) or :226-229 (QuickBooks/Plaid), which sit between them — and `monday-sync|monday-parity` from the slow-lane regex (:582), (b) removes the dispatches in TaskBoardsController:782/807/826 and TaskBoardSettingsController:236/273/276, (c) hides `SocialProvider::Monday` from the Connect list. Gate + merge through the desk as usual.
   Without a release (or until it is live): remove the connection row, which every path selects on (parity included, which has no status filter):
   ```
   cd /var/www/ezbizi-current && sudo -n -u www-data php8.4 artisan tinker --execute="App\Models\SocialConnection::withoutGlobalScopes()->whereKey('01a1d19e-7f63-73cc-8ab2-2b2088e3615d')->delete(); echo 'deleted';" </dev/null
   ```
   (Same effect as Settings › Integrations › Monday › Disconnect, which needs Jose's authenticator code; it also writes an audit event, which the tinker line does not.) Do NOT just set `status='broken'/'revoked'`: parity would then throw every 30 min.
6. **Stop Monday calling us**: in Monday (Administration › Apps), uninstall the "EzBizi" app, or delete the webhooks listed in step 3. After the account is closed this is moot.
7. **Stop the surplus workers** (they no-op after step 5; keep one per queue until the release in 5 is live, so dispatched jobs do not pile up in `queue_jobs`):
   ```
   sudo systemctl disable --now ezbizi-worker-monday-push@2 ezbizi-worker-monday-push@3 ezbizi-worker-monday-push@4
   sudo systemctl stop ezbizi-worker-monday-temp4     # transient; ask its owner first, it ends by itself ~22:58 UTC 11-25
   ```

### After the release from step 5 is deployed

8. Confirm nothing more lands on the Monday queues, then stop and remove the last workers:
   ```
   cd /var/www/ezbizi-current && sudo -n -u www-data php8.4 artisan tinker --execute="echo DB::table('queue_jobs')->whereIn('queue',['monday','monday-push'])->count();" </dev/null
   sudo systemctl disable --now ezbizi-worker-monday-push@1 ezbizi-worker-monday
   sudo rm /etc/systemd/system/ezbizi-worker-monday.service /etc/systemd/system/ezbizi-worker-monday-push@.service && sudo systemctl daemon-reload
   ```
   and delete `deploy/ezbizi-worker-monday*.service` from the repo. (deploy/post-receive only runs `queue:restart`; no script names these units.)
9. Remove `MONDAY_CLIENT_ID`, `MONDAY_CLIENT_SECRET`, `MONDAY_SIGNING_SECRET` from `/var/www/ezbizi/.env`, then `config:cache` as ubuntu; drop `services.monday` and the webhook route in the same or a later release (the route then 404s, which Monday ignores once the app is uninstalled).
10. Code retirement (separate release): delete `app/Support/Tasks/Monday/*`, the Monday commands/jobs in §1.6/§1.2, MondayWebhookController, the 9 Monday tests; keep `TaskWriter::mondayKinds()` (used by ModuleBoard/ComplaintLink) under a neutral name; replace PeopleAliases' live Monday read with the step-3 snapshot; reword AutomationsController:2795-2803 and the 135 `monday:` automation descriptions; keep `external_ref` columns and `monday_outbox` as history (or drop the table after its retention).
11. **Verify the next day**: `failed_jobs` since T0 by displayName (expect no Monday names), `grep -c -i monday storage/logs/laravel.log` for the day (expect only old lines), slow-lane timing back to normal.
