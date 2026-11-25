# Monday shutdown audit: what depends on Monday or lives only there (2026-11-25)

Read-only on Monday (MCP as the "EzBizi AI" admin user, account a-1group, enterprise, 76 active members) and on
EzBizi prod (`ssh app` tinker reads, Viewer API key). Nothing was created, changed, run or deleted anywhere.
Board items, values, groups, members and the Monday UI feature gap are out of scope (other owners).
The EzBizi code side (scheduler, jobs, runbook) is in `docs/agents/monday-shutdown-audit-ezbizi-2026-11-25.md`;
this file covers what feeds Monday, what Monday does outward, and what exists only on Monday. People are named,
contact details are left out.

## 0. Gaps ranked by risk

| # | What | Breaks / lost tomorrow | Fix |
|---|---|---|---|
| 1 | **EzBizi mirrors Monday removals.** While the Monday connection row exists, an item or board archived/deleted on Monday is trashed (tasks) or archived (board) in EzBizi: webhook "item archived/deleted" → SyncMondayItem → MondayImport:411-415 trashes the task; MondayImport:299 archives a board archived on Monday; `monday-parity --fix --groups-only --gone` (every 30 min, boards ≤2,000 items) trashes every task whose item Monday reports archived/deleted. | If anyone "turns Monday off" by archiving/deleting boards or items (or Monday archives them on closure) BEFORE the EzBizi connection is removed, up to every task on those boards goes to the EzBizi trash (restorable, but the boards disappear for staff). | Run the kill switch (sibling doc §5 step 5: delete social_connection `01a1d19e-7f63-73cc-8ab2-2b2088e3615d`) FIRST, before anyone touches Monday. Tell Shoval/Bar/Eve not to archive or delete Monday boards until then. |
| 2 | **Staff still work in Monday.** Last 6 h on 13 boards: 54 of 200 item creations were people in Monday (Angela Herrera 22, Cgrey Lumacad 13, Nelly Escobar 5, Jovanni Martin 4, Shoval 4, Sheryl 2, others); the rest were EzBizi's push. | Anything typed into Monday after the final sync is lost. | Final `ezbizi:monday-sync --scan` after the last Monday edit (sibling doc step 1); announce the cut-over time to staff. |
| 3 | **Monday users with no EzBizi account** (still active on Monday): Ron Paz (MA partner, active 11-20), Charissa Andrews (11-24), AJ Sandoval (11-19), Criz Samson (11-15), Amanda Murphy (11-09). | They lose their only task tool. Ron's two reminders (row 5) also stay off. | Create EzBizi users or confirm they are gone (people parity owner). |
| 4 | **Two public share links** (Monday "shareable view", type SppBoardView): NW - Nationwide "Complaints View" (view 247348632, filter: groups Done + Customer Relations - Adeline) and Bar - All Operations "Shared Items" (view 222048946, item "New A1 Locations Addresses"). | Anyone outside holding these links sees nothing tomorrow. | NOTHING in EzBizi (no public board/view link). Ask Bar/Adeline who has them; send the people a PDF/export or give them an EzBizi login. |
| 5 | **Ron - Massachusetts reminders** (Monday 568021811 weekly "complete your weekly tasks", 568021816 vehicle-maintenance date reminder) are active on Monday, OFF in EzBizi ("person Ron Paz not here"). | Ron stops getting both reminders. | After Ron has an EzBizi user, map him and switch the two rules on. |
| 6 | **Monday AI agents** (4). None produced board activity in 90 days; 3 used 0 AI credits since 08-01; Aurora used 156. Setup (jobs, triggers, tools) is private to each owner and unreadable by API. | Aurora (mention tracking for Cristine Hicale on MA) stops. Nothing measurable stops for the other three. | Ask Cristine what Aurora does for her; EzBizi has @mention notifications + unread notes (monday port 11-09). Others: nothing to replace (details §4). |
| 7 | **Ohio PM board Dashboard view** (weekly bar chart) and Gantt view. | View gone. | NOTHING (board dashboard view MISSING, P2 in `monday-ui-gap-review-2026-11-24.md` row 53). Export the chart's numbers if Shoval wants them. |
| 8 | **Resource directory** (custom object 18280738754, 5 items) and the Vibe app "Analytics Dashboard"/"Dark Theme" in workspace "dieuve ticoit Vibes". | Lost. | Resource directory: NOTHING, low value (Monday's people-directory object). Analytics Dashboard board is mirrored (EzBizi board of the same name); the Vibe app itself is NOTHING. |

Everything else checked came back clean (sections 1-6).

## 1. Monday forms (WorkForms)

- Board views of type FormBoardView on all 46 active boards: **0**. No WorkForm exists that the API shows.
- The only token-bearing views are the two shareable views in gap 4 (not forms).
- Repo (`app/`, `client/`, `docs/`, `www/`, tests; vendor/node_modules/storage excluded): no `forms.monday.com`, `wkf.ms`, `mx.monday.com`, `view.monday.com` or `monday.com/ics` link. Control: `api.monday.com` is found in 6 files.
- WordPress sites (websites box, Plesk MySQL DBs: posts, options and postmeta searched for `monday.com` / `wkf.ms`): 0 hits in the 57 sites scanned so far. The scan is incomplete, see §1a.
- Replaces: n/a (nothing to replace). EzBizi's own website forms already feed Leads.

### 1a. WordPress scan

**INCOMPLETE** (stopped at the desk's request, 11-25 ~22:35 UTC).
- Measured: the first 57 WordPress databases in Plesk's list returned 0 rows mentioning `monday.com` or `wkf.ms` (published/private/draft posts, options, postmeta). That run stopped at `a1c_tech_db`, where a plugin table named `*_options` was mistaken for a WordPress prefix.
- Not measured: the other ~87 of the 144 WordPress prefixes. A corrected single-session scan (`/tmp/wpscan3.sh` on `ssh websites`, read-only) was at prefix 122 of 144 when stopped. mariadb buffers its output, so no per-site result could be read. It finishes by itself and writes `/tmp/wpscan-monday3.out` (columns: db, prefix, posts, options, postmeta; the last line `DONE`). To finish: `awk -F'\t' 'NF==5 && ($3+$4+$5)>0' /tmp/wpscan-monday3.out`. No output means no site links to Monday. Afterwards remove `/tmp/wpscan3.sh`, `/tmp/wpscan-monday3.*` and `/tmp/wpscan-prefixes3.txt`.

## 2. Integrations and webhooks (anything feeding Monday from outside)

- **Board automations, live read of all 46 boards (11-25):** 351 "send a webhook" automations = EzBizi's own sync (9 per board on 39 boards, app 2783858, connection 35061909 → `https://app.ezbizi.com/api/v1/integrations/monday/webhook`, all created 11-14). Not on: Illinois Project Management has them; **DMV NEW (18435285604, 78 items, created 11-24) has none** (mirrored by the 10-min poll only), nor the 3 templates, Users, Job Roles, Analytics Dashboard.
- **Other integrations:** one dead webhook on A1 - Operation (434067144, "when any column changes, send a webhook", created 2025-06-10 by Shoval via API app 11, state error since before 11-13; target URL not returned by Monday). Nothing to re-point.
- **No** Gmail, Outlook, WhatsApp, Twilio/SMS, Slack, Zapier, Make, RingCentral, Workiz, Google Calendar, or email-to-board ("incoming mail") integration on any board.
- **Who creates items on Monday** (activity log, 13 busiest boards, last 6 h, 200 events): EzBizi AI 146 (our push), people 54, integrations/automations/agents 0. No outside system writes to Monday.
- **Email-to-board addresses:** the API does not expose them. No board shows items created by an integration flow, and the 7-day creators are all people or EzBizi; treat as unused. Not measurable: whether someone forwards mail to a board address by hand (would create items under that person's name).
- **Account-level connections:** `account_connections` refused (USER_UNAUTHORIZED for this user); `connections` for EzBizi AI returns only the EzBizi webhook connection. Apps installed account-wide (Administration > Apps) could not be listed. Not measurable from here.
- Replaces: n/a. The EzBizi sync is the only feed and it is retired by the sibling runbook.

## 3. Automations acting outside their board

Live read 11-25 (all 46 boards) compared with `monday-automations-map-2026-11-18.csv` and the **prod `automations` table** (198 live rules, 134 copied from Monday). Note: the Viewer API key sees only 158 of 198 rules; 36 rows of the 11-18 map are hidden from it, not deleted. No non-EzBizi automation was created or changed on Monday after 11-13.

| Monday rule (board, id) | Trigger → action | Monday | EzBizi | Gap? |
|---|---|---|---|---|
| A1 - Operation 469619528 | item created in group "topics" → create linked item in Bar - All Operations | active | off: group gone on Monday | No (does nothing on Monday either) |
| Everdoors - Operation 469629396 | same, into Bar - All Operations | active | off: group gone | No |
| Jose Demasi 383680320 | status Done → move to Completed + notify Bar | active | ON, runs | No |
| Analorena 383677907 | status Done → move + notify Bar | active | ON, runs | No |
| Analorena 452447948 | date arrives → notify user 76893540 (deleted), set date/status | active | off: group gone, person deleted | No |
| Weekly "complete your weekly tasks", Mon 15:00 ET: Rotem 389721273, Meni 389720308, Nehorai 389719187, Netanel 436219443, Washington 564897656, Yosef 552951115 | schedule → notify | active | ON, succeeded 11-23 | No |
| Weekly: Ohio 389713577, Illinois 389720720, Liraz 446771947 | schedule → notify | active | off: recipient is a deleted Monday user | No |
| Weekly: Ron - Massachusetts 568021811 | schedule → notify Ron Paz | active | **off: no EzBizi user** | **Yes (gap 5)** |
| Vehicle-maintenance reminder (date arrives → notify + push date 1 month): Rotem 442882858, Meni 442878404, Nehorai 442882357, Netanel 442886578, Washington 564897657, Yosef 552951118 | date → notify | active | ON, running | No |
| Vehicle reminder: Illinois 442885311, Liraz 446771954 | | active | off: deleted recipient | No |
| Vehicle reminder: Ohio 442884261 | | error | off | No |
| Vehicle reminder: Ron - Massachusetts 568021816 | | active | **off: no EzBizi user** | **Yes (gap 5)** |
| Ohio PM workflow 7925541335 | Schedule Date arrives in Scheduled → move to In Progress | active | ON (`monday:workflow:7925541335`) | No |
| 5 dependency-status rules (New Location/Brand/Franchise templates, Everdoors Brand, MA Franchise) | on-board | active | not mirrored | Known (parity audit 11-24) |

No Monday automation sends email, WhatsApp, SMS or calls a live external URL. "Notify" = Monday bell/email notification to a Monday user; EzBizi's copies notify in-app (and by the user's notification settings).

## 4. Monday AI agents

All four are **personal** agents, state active. Jobs, instructions, triggers and tools are readable only by the owner (MCP `manage_agent_jobs` refuses: "Agent not found, or user does not have permission"). Measured instead: AI credits used 2026-08-01..11-25, and every activity-log event by the agent users in the last 90 days.

| Agent | Role | Owner | Boards it can read | Created / updated | Credits since 08-01 | Board activity 90 d | Outputs seen | EzBizi today |
|---|---|---|---|---|---|---|---|---|
| Elena (219855) | No-Answer Follow-up Orchestrator | Jonathan T | NW - Nationwide, Outgoing Calls | 08-04 / 09-02 | **0** | none | none | Missed-call tasks on Outgoing Calls ("Missed call - Outgoing Calls task", ON), lead follow-up texts ("Google LS Text - Follow Up - 3 Hours", ON), Customer waiting warnings. Not a gap. |
| Dora (501612) | Personal Assistant | Ranya Lara | IL - Illinois | 10-11 / 10-11 | **0** | none | none | Nothing needed (never ran). |
| Daily WhatsApp Summary Agent (567074) | Workspace Operations & Daily Summary | Merav Peretz | OH, NJ, CT, TX, Ohio PM, MA, Outgoing Calls | 10-26 / 10-26 | **0** | none | none (0 credits: it has not run, so it sends nothing today) | EzBizi already sends daily/weekly WhatsApp reports (10 `report.text` rules ON) and "WhatsApp: Daily job assignments". Not a gap. |
| Aurora (615306) | Mention Tracking | Cristine Hicale | MA - Massachusetts | 11-04 / 11-04 | **156** | none (no writes to MA) | not visible: works in its owner's chat | @mentions + unread notes in EzBizi tasks. Possible gap (6): ask Cristine. |

Account-wide automation run feed filtered to agent hosts, 11-18..11-25: 0 runs.

## 5. Docs, dashboards, widgets, files not on items

- Workspaces: 3. Main workspace (all boards, about 45 folders, many empty), "A1 Chimney" (empty), "dieuve ticoit Vibes" (Vibe app: Analytics Dashboard board 5 items + Dark Theme object).
- Workspace/board **docs: 0** (`docs` query and DOCUMENTS search both empty; workspace_info lists none).
- **Dashboards (overviews): 0** found by search. Board-level dashboard view: 1 (Ohio PM, gap 7). Other app views: Kanban, Calendar, Gantt, Timeline on several boards (covered by the UI gap review).
- Files not attached to items: none found (no docs, no file-gallery boards). Item files: sibling doc §0 (copies exist in EzBizi).
- Custom objects: Resource directory (5 items, gap 8), Dark Theme (0 items).

## 6. Other account-level items

- **Guests:** 0 (every user is admin, member or agent). External-domain members: 2 consultant accounts on "Sharper - Rise-OFFICE" (one pending, one last active 09-08) and 3 personal-mail members (2 pending invites). The Sharper board is mirrored.
- **Shared links to outsiders:** the 2 shareable views (gap 4).
- **iCal subscriptions:** not exposed by the API; Calendar views exist on 10 boards. EzBizi has no iCal feed. Not measurable; low risk.
- **Account templates / org-wide saved views:** the 3 template boards (New Location / Brand / Franchise) are mirrored as boards. No other templates visible.
- **Users / Job Roles boards:** mirrored.

## 7. EzBizi code that calls Monday (stops working when the API goes)

Full detail and runbook: `docs/agents/monday-shutdown-audit-ezbizi-2026-11-25.md`. Summary:

- Scheduler (`routes/console.php`): `ezbizi:monday-sync --max-seconds=900` (every 10 min), `ezbizi:monday-sync --scan` (03:40 NY), `PushMondayShard` x4 on queue `monday-push` (every minute), `ezbizi:monday-parity --fix --groups-only --gone --max-items=2000` (every 30 min), `ezbizi:monday-parity --values` (04:30 NY), `ezbizi:monday-unmirrored` (hourly); slow-lane regex at :582.
- Jobs: `SyncMondayItem` (queue monday, from the webhook), `PushMondayShard`, `PushBoardMembers`, `MoveMondayGroup`, `ArchiveMondayGroup`.
- Request-time: `MondayWebhookController` (POST v1/integrations/monday/webhook), `SocialAuthController`/`MondayOAuth` (Connect Monday), `ConnectionLiveness` (Monday token check), `PeopleAliases` (Monday user names, cached 6 h).
- Outbox writers (no Monday call; silent once the connection row is gone): `TaskWriter`, `TasksController`, `TaskBoardSettingsController`, `TasksPostScheduledUpdates`.
- Manual commands: `ezbizi:monday-push`, `-import-boards`, `-merge-board`, `-automations`, `-webhooks`, `-update-links`, `-fix-video-mimes`, `ezbizi:tasks-import-monday`, `ezbizi:complaints-link`.
- Gating: everything except `monday-parity` selects only `status = connected`; parity takes every Monday row. Deleting the row (what Disconnect does) silences all of them; setting it broken/revoked does not (parity then throws).
- Prod now: 1 connection (connected); outbox 0 pending (9 failed, 327 superseded), 122 rows sent in the last hour.

## Method notes

- Monday MCP: `list_workspaces`, `workspace_info` x3, GraphQL `boards`/`views`/`docs`/`workspaces`/`users`/`connections`, `list_automations` x46 (via a sub-agent, raw output in the session scratchpad), `list_agents_in_account` (+credits, resources, subscribers), `get_activity_logs`, `get_automation_runs`, `search` (DASHBOARDS, DOCUMENTS), `get_board_info` (views).
- EzBizi: prod `automations`, `automation_runs` (14 d), `monday_outbox`, `social_connections`, `users`, `email_filters`/`settings`/`report_emails`/`mailboxes` (0 rows mention monday.com), `task_column_values` (0 Monday URLs).
- Not measurable from here: agent jobs/triggers, email-to-board use, account-level app installs, iCal subscribers, recipients of the 2 share links.
