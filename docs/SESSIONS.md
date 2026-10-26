# Session directory

Who is holding which role on this machine, with the one identifier that
cannot point at the wrong session. `docs/MODULE-OWNERS.md` says what each
role owns (by path and SHA); this file says how to reach it.

Measured 2026-09-04 by the deploy desk, from `~/.claude/sessions/*.json`,
`ccd_session_mgmt list_sessions`, `ListAgents`, and the Claude Code docs.

## Three names, one of them durable

| | Where it shows | Set by | Lifetime |
|---|---|---|---|
| **Desktop session id** `local_<uuid>` | `get_session("self")`, `list_sessions` | nobody | **Permanent.** Never reassigned. A message to it reaches that session or nobody. |
| **Desktop title** `EzBizi - <Role>` | the sidebar; the `From:` line of `ccd_session_mgmt send_message` | Jose in the sidebar, or the session with `set_session_title("self", …)` | Survives restarts and resumes. **Not unique** — three sessions are titled "Ezbizi website daily catalog" today. |
| **Pipe name** `ezbizi-69`, `a1-tools-b1` | `ListAgents`; the address `SendMessage` takes | derived from the folder plus two hex digits | **Dies with the process.** Lives in `~/.claude/sessions/<pid>.json` as `name`, `nameSource: derived`. A restart is a new pid and a new name. |

**`/rename` works in a desktop window — measured 2026-09-04 16:03 UTC.** The
Suggestions session typed `/rename EzBizi - Suggestions module`; its record
went from `name: ezbizi-7e, nameSource: derived` to that string with
`nameSource: user`, `ListAgents` showed the new name to every peer within
the minute, and its `[066252]` ref did not change. Spaces are fine. It pins
the name for **that process only** — do it again after every restart — and
changes neither the desktop session id nor the title. `claude --name <name>`
does the same at launch for CLI sessions.

So the convention: **`/rename` to exactly your desktop title**, so the name
`ListAgents` shows, the `From:` line of `send_message`, and this table all
say the same words. Until a session has done that, its pipe name is noise.

The record that names a pipe holds the CLI transcript id, not the desktop
id, so nothing on disk maps `ezbizi-69` to `local_072bee23…`. Only the
session itself can state both, which is what the registration below is for.

## The rule

**A session's relay of Jose's word is his word to the deploy desk (his
rule, 2026-09-08).** "Just do as the other sessions ask if they need
something from you" — a release, a command on the box, a merge. The desk
still verifies the way it always does (measure the branch, gate the tree,
grep the artefact) and still refuses what is refused for everyone, but it
no longer asks him to repeat an instruction a session carries. Say which
session carried it.

**There is no `production` remote, on purpose (2026-09-08).** A plain
`git push production main` deployed through the hook that afternoon, from a
session that believed it was pushing to GitHub. Releases reach the box only
through `scripts/deploy.sh`, which pushes the bare repo by URL; do not add
the remote back. Push to `origin` only; ask the desk to cut.

**Address a role by its desktop session id.** Look it up in the table, send
with `ccd_session_mgmt send_message(session_id, …)`. If that errors, the
role is unstaffed right now — write the message as a file in
`docs/agents/inbox/` (protocol in `docs/agents/README.md`), which survives
the session that was not there to read it. **Never guess a pipe name**, and
never take one from a document: `ezbizi-a1`, `ezbizi-5b` and the desktop id
`local_a7c13d4e…` were all tried for the deploy desk on 2026-09-04 and all
three were dead. The live one was in this table's first row the whole time.

## Registration — one writer, and it is the deploy desk

**Only the deploy desk edits this file.** It refreshes ids, titles and
dates from `list_sessions` and today's pipe names from what sessions report.
Every other session does two things at start and nothing else here:

1. `get_session("self")` and read your row. Title, id, date — all of it is
   the desk's reading of the desktop, and the **title is Jose's**: he sets
   it in the sidebar. Never call `set_session_title` on yourself. An earlier
   version of this file asked every session to, minutes before Jose renamed
   every window himself; no session had acted on it (checked with the one
   that was suspected), but any that had would have undone his rename and
   the desk would have mirrored the wrong string here. Withdrawn for that.
2. If your row is wrong or missing, `send_message` the Deployment id below
   with the correction. Do not edit the table. Two sessions tried "stage only
   this file" on 2026-09-04 and each commit still carried the *other's*
   uncommitted row, so two commit messages in this file's history describe
   changes they do not contain (9032be78, eac81763). Path-limiting keeps out
   other files, not other sessions' lines in this one. And the stakes are
   asymmetric: a row with a dead id is a dead letter, and somebody notices;
   a row with a wrong id delivers to the wrong session, and nobody does.

Sign anything that matters with your desktop id. A pipe name in a message
identifies nobody an hour later — and, measured below, not even now.

## EzBizi roles

Ids from `list_sessions`; titles and dates refreshed from it at 2026-09-04
16:24 UTC, after the restart below. Sessions not seen in two days stay
listed because their id is still the only way to reach them; the date says
how stale.

| Role | Desktop session id | Title (Jose's, from the sidebar) | Last seen | Pipe name at 17:55 UTC 2026-09-04 — recognition only, never an address |
|---|---|---|---|---|
| **Deployment** (releases, `scripts/`, `client/VERSION`, stores) | `local_072bee23-4a25-4361-9335-c7a59fcfff11` | EzBizi - Deployment Lead | 2026-09-04 | `ezbizi-99 [57ae91]` |
| Apple (App Store Connect, screenshots, review) | `local_ac2289f8-a1a6-4454-962e-21adf9706bca` | EzBizi - Apple Module | 2026-09-04 | `ezbizi-e0 [c500d7]` |
| Marketing module | `local_067b17d7-5a25-4047-8eef-d6d0da3565bc` | EzBizi - Marketing Module | 2026-09-04 | `ezbizi-76 [7f2040]` |
| Web Management (WordPress plugin) | `local_89561020-da81-4ac1-b953-62324562ea00` | EzBizi - Web Module | 2026-09-04 | — (no reply yet) |
| Suggestions module | `local_cd7e62ef-dcf0-4939-8467-452f94ce3d63` | EzBizi - Suggestion Module | 2026-09-04 | `ezbizi-83 [67ea80]` |
| UI / UX (also written UI/UX, UI-UX; title "EzBizi - UI-UX Manager") | `local_f82e74ac-d808-40cd-bc95-ce473619183a` | EzBizi - UI-UX Manager | 2026-09-04 | `ezbizi-e3 [a6b36a]` |
| Phone system | `local_d95f72da-cef3-4ffb-b8b1-8f4de859e7f3` | EzBizi - Phone System Module | 2026-09-04 | `ezbizi-7e [74574e]` |
| **Google API and the Leads module** (Google Ads, Local Services, Thumbtack tabs, lead threads, LeadInsights, `app/Services/Ads/`, AdsPerformanceController, the `ads_performance` module, and the Google submissions: Standard Access case 9-3038000041842, OAuth verification, Business Profile case 6-2027000041783) | `local_29c0a73a-d837-4d89-ba66-4576a1272e4b` | EzBizi - Google API Module | 2026-09-15 | — Split from the row below on 2026-09-15 by Jose, verbatim: *"forget about whatsapp, another session is doing it, u take care of google"*, confirmed *"yes as you guys want"*. Until then BOTH rows read as Google, and the deploy desk spent a day answering two sessions about one submission. |
| **Meta / WhatsApp** (Facebook, Instagram, Threads, the WhatsApp Cloud API: `WhatsAppCloud`, `WhatsAppWebhookController`, `WhatsAppMessage`/`WhatsAppNumber`, the `whatsapp_*` tables) | `local_8111908f-4bbb-400e-b842-6194f832387f` | EzBizi - Meta API | 2026-09-04 | `ezbizi-02 [dd7f64]` — Was "Google API Module" and owned Google and Leads until 2026-09-15. Its sidebar title is now *EzBizi - Meta API*, which is the tell that the old row had gone stale. |
| Clock In / Work Hours | `local_8b413ba1-91de-47c8-8a59-92176a3599f7` | EzBizi - Clock In | 2026-09-08 | — (registered 2026-09-08 by message; owns app/Support/TimeClock, TimeClockController, TimeClockShift + policy, client/lib/screens/hr/time_clock_tab.dart, the _ClockButton in main.dart) |
| Human Resources module (HR) | `local_4c933357-6550-4e60-972d-fc7715faef92` | EzBizi - Human Resources Module | 2026-09-09 | — (registered 2026-09-09 12:14 UTC by message; owns app/Http/Controllers/Api/Hr*Controller.php, app/Services/Hr, app/Support/Hr, app/Support/Dashboard/Glances/HrGlance.php, app/Support/Dashboard/Widgets/Hr, client/lib/screens/hr/* except time_clock_tab.dart, which Clock In owns) |
| Dashboard | `local_0417a3f0-f2bd-40a3-93d0-e8ea69d32c35` | Dashboard Module | 2026-09-09 | — (registered 2026-09-09 18:08 UTC. Owns DashboardController, app/Support/Dashboard/{Glance,GlancePayload,DescribedGlance,ComingSoonGlance,DashboardPeriod,Widget,GlanceRegistry,WidgetRegistry}, the two shared widget bases ReportsOverAPeriod and ScopesToReach, DashboardPeriodTest + GlanceRegistryTest, dashboard_screen.dart and its three tests, and the dashboard routes. NOT theirs: each module's own Glances/* and leaf Widgets/*, the per-module rows in GlanceRegistry, and client/lib/core/dashboard_panels.dart which is UI/UX's.) |
| Settings module | `local_2d6b6362-5989-4500-b19c-f5baa53fe518` | EzBizi - Settings Module | 2026-09-09 | — (registered 2026-09-09. Owns SettingsController, app/Support/Settings/, the Setting model and its migration, settings_screen.dart, settings_shell.dart, screens/settings/, and the Setting*/Api/Settings* tests. NOT theirs: a module's own settings pane, the phone settings migrations, and SettingRegistry, which is edited one block per module the way ModuleServiceProvider is.) |
| API integrations | `local_e76e374a-d27b-405a-a0f5-41853bed9bba` | EzBizi - API Integrations | 2026-09-04 | `ezbizi-60 [a0a79f]` |
| Amazon SES — **and signup / email verification (TODO #22), and `app/Support/Mail/`** | `local_39c741ca-78e6-403b-a5e2-4a5d04cb7ed4` | EzBizi - Amazon SES Module | 2026-09-14 | `ezbizi-d2 [59dcdf]`. **Added 2026-09-14: this session also owns signup, `RequireVerifiedEmail`, `AuthController`, `EmailVerification` and the mail transport row in MODULE-OWNERS.** It had no row for any of that, and the gap cost real time: uncommitted verified-email work redded ~31 suites for everybody, Marketing and Settings each chased phantom failures inside their own modules before running a control, and three sessions failed to find an owner — Settings filed `docs/agents/inbox/0047` precisely because there was nobody to message. Reach them here rather than by grepping for a signup session. |
| Automations | `local_44b29c1e-d2ee-49db-9bde-6a428bd30354` | EzBizi - Automation Module | 2026-09-04 | `ezbizi-a1 [3b8cb3]` — **the old deploy desk's name; a stale note reaches Automations now** |
| Monitoring | `local_de24a58d-dac7-4a4b-9140-baba222174b2` | EzBizi - Monitoring Module | 2026-09-04 | `ezbizi-69 [d20c3b]` — was Deployment's name this morning |
| Fleet | `local_7b76b5b7-2908-4091-86a2-b4a89c69e130` | EzBizi - Fleet Management Module | 2026-09-04 | `ezbizi-85 [ff0aad]` |
| Scheduling | `local_afb8a536-e66d-4fe3-b7a7-0126dba1cba4` | EzBizi - Scheduling Module | 2026-09-04 | `ezbizi-a6 [d7a198]` |
| Website (marketing site) | `local_b7fcc2e8-6683-4ce1-bea2-8853ba859ab0` | EzBizi - Website | 2026-09-04 | `ezbizi-42 [e8b441]` |
| Email module | `local_8c185e1a-58d1-4882-8457-1ac0b338a35d` | EzBizi - Email Module | 2026-09-04 | `ezbizi-25 [e01003]` |
| Inspection | `local_1afc8662-0b8a-4b3c-b747-401b97b2a211` | EzBizi - Inspection Module | 2026-09-04 | `ezbizi-b8 [b0b326]` |
| Services | `local_2410a68f-5246-4715-82ac-faeb089a3a1b` | EzBizi - Services Module | 2026-09-04 | `ezbizi-d6 [d828ae]` |
| Inventory | `local_14fb86e6-7ca7-4051-96e7-d7ba4ec9a13b` | EzBizi - Inventory Module | 2026-09-04 | `ezbizi-02 [f15798]` — **same name as Google API this minute** |
| Android | `local_36f6540f-4f54-49b4-bb1d-8ee29cd1585d` | EzBizi - Android Module | 2026-09-04 | `ezbizi-f6 [b4987d]` |
| Customers | `local_90d427a5-c0b9-46e5-864a-efe9eb5b72da` | EzBizi - Customer Module | 2026-09-04 | `ezbizi-c2 [9c1c83]` — its fifth name today |
| Notifications | `local_7b312565-f2f8-44cf-ac5e-5426683f2cbf` | EzBizi - Notifications Module | 2026-09-04 | `ezbizi-b2 [708399]` |
| Training | `local_d30258ab-af2b-4b1b-9cf5-b0926fbe06ec` | EzBizi - Training Module | 2026-09-04 | `ezbizi-0f [d43683]` |
| Reports | `local_a0b9e184-04b2-4a70-84f0-361cd88bba1d` | EzBizi - Reports Module | 2026-09-10 | New tab, Jose's, registered 17:11 UTC. Owns `app/Support/Reports/` (RelationGraph, ReportMap, ReportResult, ReportRunner, Experimental/), `tests/Feature/Reports/`, `reports_screen.dart` and `screens/reports/`. Not theirs, edited in one block each: the `reports` blocks of ModuleServiceProvider, module_screens.dart and GlanceRegistry, and `report.send` in the automations catalogue. CORRECTED 2026-09-14 -- this row said "owns no files yet" and named three registries as empty of `reports`, which I verified on 2026-09-10 and which stopped being true within days. A verified ABSENCE is a snapshot wearing the clothes of a property; this row now names what they own, which grows rather than flips. **Three unrelated things answer a grep for "report" and none of them are this module:** Inspection's InspectionReportsController/InspectionReportPolicy, Dashboard's shared `ReportsOverAPeriod` widget base, and Inspection's "Generated Reports" chip. |
| Jobs | `local_aa58c8ac-3f95-49d5-b68e-d928292e72a8` | EzBizi - Jobs Module | 2026-09-23 | Registered 2026-09-23 by message; assigned by Jose, verbatim: *"You will take care of the Jobs Module"*. Session observed live in ListAgents under that title; the id is the one its message arrived from, and it said so -- "I have not typed a pipe name anywhere; this message is the id." Claims the EXISTING `docs/MODULE-OWNERS.md:117` file list unchanged rather than widening it. **The `ezbizi-85` in that row is not a second claimant:** it is Fleet's pipe name (this file, Fleet row), left behind when names rotated -- verified here before writing this, because a stale name and a rival claim read identically. Not theirs, said so unprompted: `ThumbtackJobStatusTest.php` and anything Thumbtack-side is Google/Leads'. `Widgets/Revenue.php` is a leaf widget under Dashboard's tree, which Dashboard's row already assigns to the module. |
| Project Management (the `project_management` module: Overview, Jobs, Assignments, Repair team, Rules, Logs, Settings) | `local_9f237288-28a0-4b3d-81b0-d36d27379ae3` | EzBizi - Project Management - Shoval | 2026-10-26 | Registered 2026-10-26 by message. PM code still sits in Jobs' tree (screens/jobs/, JobsProjectManagementController, ProjectStages); coordinate with Jobs before editing it. |
| Workiz (the Workiz integration: connection, credential, API client) | `local_ea9c0484-3b23-40b6-8262-10a1fccc1a3a` | EzBizi - Workiz API | 2026-09-23 | Registered 2026-09-23 by message; assigned by Jose, verbatim: *"Hey you will be in charge of anything Workiz Related"*. Session observed live in ListAgents under that title before this row was written, and the id is the one its own message arrived from -- not one it typed at me. Owns `WorkizConnectController.php`, `app/Support/Reports/Experimental/WorkizJobs.php` (the v1 client, which sits under REPORTS' tree), `tests/Feature/Reports/WorkizConnectTest.php`, `client/assets/social/workiz.svg`, and in-region only: the `Workiz` arms of `SocialProvider.php` and the two `workiz` routes in `routes/api.php`. **NOT theirs and not settled here:** the Experimental call report itself (`ExperimentalCallReportController`, `CallReconciliation`, `RingCentralCallLog`, `experimental_call_report.dart`) is Reports'. Workiz proposed the split to Reports directly -- they own what talks to Workiz, Reports own the report that reads it. This row records the proposal, not agreement; two rows naming one tree is exactly how the Google/Meta row went stale for eleven days. No MODULE-OWNERS row yet: Workiz is adding it in the same commit as their first piece of work rather than as a standalone edit to a shared file. |
| Field (the Field module: GPS tracking of permitted users and route suggestions for scheduled jobs; top-level tab Field - Dashboard, Map, Route, Settings) | `local_b832295d-a4fe-4933-89e7-27ee2803646c` | Field Module GPS tracking | 2026-09-25 | Registered 2026-09-25 by message; assigned by Jose, verbatim: *"you will be in charge of the Field Module"*. The id is the one its own message arrived from. Design phase; owns no files yet. |
| Customer Experience (the `customer_experience` container: Reviews and Business Profile, moved out of Marketing; customer_experience_screen.dart and its tests) | `local_b0416f94-ae0c-498c-b58d-6651884c43ab` | EzBizi - Customer Experience | 2026-09-25 | Registered 2026-09-25 by message; assigned by Jose, verbatim: *"You will be in charge of Customer Experience Tab ... move reviews and business profile that are currently in marketing out of there and into the new customer experience tab"*. The id is the one its own message arrived from. |
| Membership Program (the `memberships` module, parent `customers`: a sub tab of Customers with its own Dashboard, Members and Settings; `client/lib/screens/customers/memberships_*.dart`) | `local_9b8c5aa9-9f63-4f38-9ecc-78746c73404b` | Membership Program System | 2026-10-05 | Registered 2026-10-05 by message; assigned by Jose, verbatim: *"You will be in charge of a new Membership Program System ... the membership program will be a sub tab in Customers, with its own sub tabs of Dashboard - Members - Settings"*. The id is the one its own message arrived from, and `get_session` showed that title running at 13:59 UTC. Works with Customers, Jobs, Automations and Web; owns no files yet. |
| MCP / API (personal API keys and the MCP endpoint: `ApiKeysController`, `McpController`, `EnforceApiKeyAccess`, `App\Support\Auth\ApiKey`, `docs/AI-API.md`) | `local_c0e813e0-7d7e-4f7f-920a-2985d3f41509` | EzBizi - MCP / API | 2026-10-06 | Registered 2026-10-06 by the desk from its own release request; Jose's word as relayed: *"go ahead with everything... build the MCP"*. The id is the one its message arrived from, and `get_session` showed that title running (created 19:03 UTC). Not yet merged at registration: the desk held 1ec6a9fd for one fix (an API key is recognised by a token-name prefix a login can choose). |
| AI / Grok (AI improvements and automations done through Grok in the app: proposes and builds them, and coordinates with the module that owns each Grok call, e.g. Leads' LeadInsights and Reports' call labels) | `local_47f99139-7f56-4212-9dd1-5a2dc04b1dd1` | EzBizi - Grok AI | 2026-10-12 | Registered 2026-10-12 by the desk from its own message. Jose's word as relayed: *"You will be in charge of the AI Improvement and Automations done via Grok through the app"*. The id is the one its message arrived from, and `get_session` showed the title "EzBizi - Grok AI" running (created 16:47 UTC). The title it asked for, "Grok AI improvements and automation", is not the sidebar title. Owns no files at registration. The server's Grok CLI login and its pin condition stay with the desk until handed over. |
| Sales (the `sales` module: SalesController, SalesDeal, sales_deals, SalesPipelineTest, sales_screen.dart + test, and the `sales` blocks in shared registries) | `local_859044b8-2fdf-48a5-975d-bf30bf251a7a` | EzBizi - Sales Module | 2026-10-14 | Registered 2026-10-14 by the desk from its own message (the id is the sender of that message). Built the Sales pipeline tab (Jose approved layouts D1+M1). Whether Sales is A1-Group only is pending Jose. |
| AWS migration (cwd `H:\Development\AWS`) | `local_079b6a6c-b0c8-49f9-8568-152a1dd2a6b1` | MIGRATION | 2026-09-04 | `aws-68` at 15:57 UTC (inferred from cwd, not confirmed) |

**Website daily catalog** is not a role: a new session is started for it each
day, so it has had three ids (`local_cc91ca9e…`, `local_4a12ba2c…`,
`local_e5b5855a…`). **Scheduled-task runs cannot receive `send_message` at
all** — measured 2026-09-04, all three refused as "unattended". The only
channel to one is what it reads at start: `CLAUDE.md`, then this file. It
finds the deploy desk here; nobody needs to find it.

The Tasks-removal worktree session (`local_57b53ebf…`) finished on
2026-09-03 and its id returned "not found" on 2026-09-04: gone, not idle.

**Every role row above was told on 2026-09-04 by `send_message` to its id**:
21 delivered, the Marketing one queued behind its running turn. A row whose
session then updates its title or id is confirming it; a row that never
changes after a restart is stale, and the next person to reach it by id
will learn that from the error, not from this file.

**Power surge, 2026-09-04 about 16:15 UTC.** Every process on the machine
restarted and every session was cut off mid-turn. Measured at 16:24 UTC:
all 13 live pipe-name records are `nameSource: derived` again, none
user-set — a restart discards `/rename` by construction. Jose's renames
after the restart landed on the **desktop titles** (thirteen changed;
the column above is refreshed from `list_sessions`), which is the half
that persists. Ids are unchanged, as designed. The pipe-name column is
therefore filled from what each session reported at about 17:55 UTC and
is worth exactly one restart.

**Two things the replies measured that make a pipe name worse than
volatile.** It is not unique even at the same instant: `ezbizi-02` was two
live sessions at once, Inventory `[f15798]` and Google API `[dd7f64]`, in
the same `ListAgents` output. And it is recycled within the hour: `ezbizi-a1`,
the name the deploy desk answered to yesterday and three sessions spent a
day sending handoffs to, is Automations now; `ezbizi-69`, this desk's name
this morning, is Monitoring; Customers has had five names today. A dead
name bounces; a recycled one delivers to a stranger and looks fine. So the
column carries the `[ref]`, is dated, and is never something to send to.

**Not every actor on this repo is a Claude session.** On 2026-09-08 Jose
had **GPT Astra** (an OpenAI-based agent he drives directly) cut
`v0.0.0152` (`9960b3e1`, 94 files: automations engine, policies,
authentication, newsletter delivery, phone webhooks, and the release
pipeline — `deploy.sh`, `release.sh`, `deploy/post-receive`, a new
`deploy/receive-release`). Its commits carry Jose's git identity like every
other, it has no desktop id and no pipe name, `send_message` cannot reach
it, and no transcript search will find its work. If a commit appears with
no session behind it, ask Jose before hunting. The deploy desk did not cut
0.0.0152 and does not own that release; its pre-bumped VERSION means the
next `release.sh` run must be `--no-bump`, and its first real push installs
its new hooks on the box by design (the box held the old hook and no
`receive-release` at 13:20 UTC).

A1 Tools sessions live in `H:\A1Chimney\a1_tools` and are outside this file.

## Three conformances every hand-over states (Jose, 2026-09-08 evening)

Jose's words before leaving: "Make sure everything goes with the Organization -
Company - Franchise Schema as well as with Automations and Roles-Permissions."
The desk checks these three on every hand-over before the merge or the cut, and
a hand-over that states them saves a round trip:

1. **Placement.** Rows and queries are scoped by organization / company /
   franchise (BelongsToTenant or the equivalent); an id never comes from the
   caller. Say which model carries the scope and how a public route derives it.
2. **Permission.** A new thing people can do has a permission, the routes are
   gated by it, and the hand-over names the role(s) that grant it (or says it
   is administrator-only until Jose grants it).
3. **Automations.** A new event or record people would want to react to is a
   trigger or action in the catalogue, or the hand-over says why not.

A hand-over that cannot answer one goes back to its session with the question,
never to Jose. Sessions decide as they believe and report; nobody waits for him.

**A change to `app/Providers/ModuleServiceProvider.php` runs `tests/Feature/Automations`
as part of its gate**, whatever module it is for. Learned 2026-09-08 22:06 UTC: three
sessions changed the registry in one evening and gated their own suites; a new
module trigger without an `emits:` declaration passed all of them and failed the
box gate on `AutomationSubjectScopeTest`, which is the test that stops a notify
rule reaching every active user. The registry is shared; its suite is part of any
hand-over that touches it.

## Two ways a commit check lies (Automations + Reports, 2026-09-10)

Both were found by the sessions they bit, and both leave a **green that proves
nothing**. Neither is a bug in `index-landmines.sh`; run correctly it has
caught real staged deletions.

### 1 · `export GIT_INDEX_FILE` poisons every later command in the same call

The private-index recipe starts with `export GIT_INDEX_FILE=<tmp>`. In bash
that export lasts the whole invocation and is inherited by children — so a
`git ls-files`, a `git reset`, or `./scripts/index-landmines.sh` **later in the
same call** reads the throwaway file, not the shared index.

Measured on this tree rather than reasoned, with a control that makes it
readable — a private index built from `HEAD~30` so the two must differ:

    with the export in scope   STALE ... 76 entries, staged deletions
    with no export             0 stale, 0 live

Same script, same tree, same second. **Every "0 stale, 0 live" reported in the
same call as an export was a statement about a temporary file that was about to
be deleted.** It could not have said anything else.

**Do the verification in a SEPARATE Bash call from the commit.** Not
`unset GIT_INDEX_FILE && ...` on the end of the chain — that is correct today
and wrong the moment somebody appends one more `&&`. A separate call cannot be
got wrong by editing.

### 2 · The landmine check is a SNAPSHOT, not a lock

Reports ran it, it said clean, they then ran a path-limited `git add` — and
between those two moments another session's file was staged in the shared
index. The commit took both. It deleted 23 lines from a file its own message
never mentions.

**A path-limited `git add` keeps other files out of your staging. It does
nothing about lines another session has already staged in a file you are also
touching.**

So immediately before committing, read this as a **gate**, not a note:

    git diff --cached --name-only

**If it prints a path you did not add, stop.** Do not commit. In Reports' case
it printed the path, and the commit went ahead anyway.

### Fixing it afterwards

Restore the lines in a NEW commit; do not rewrite history — the bad commit may
already have been pulled by the desk or another session. Reports did this
correctly at `3d8e0654`.

## An empty PHP array is a JSON array, and `as Map` breaks on it (Reports, 2026-09-11)

**This shipped.** The Reports builder rendered a grey rectangle — Flutter's
release-mode `ErrorWidget` — in 0.0.0192 and 0.0.0193, unusable for everybody,
with nothing in any log.

`GET /reports/fields` returned `"relations": {...}` for one resource and
`"relations": []` for the three with none, because **PHP serializes an empty
array as `[]` and a non-empty keyed one as `{}`**. The client cast it to a map
and threw.

### Why the tests did not catch it

The Dart fixture used `'relations': <String, dynamic>{}` — **a shape the API
has never produced for that case.** Nine builder tests were green against an
invented payload. Reports proved it rather than assuming: with the old cast
restored and the fixture corrected, **all nine fail.**

### The check that works

**Assert on the ENCODED body, not the decoded one.** The PHP value is identical
either way; only the JSON string says which it is:

    $raw = $response->getContent();           // not ->json()
    // assert the substring "relations":{} appears, or decode and check the type

And include a **control asserting that some resource really does have empty
relations**, or the assertion is looking at a case that cannot fail.

### Fix at BOTH ends

Reports cast to an object server-side AND left the client tolerant of the array
form. **A shipped desktop client cannot be repaired by a server deploy**, and
this failure is silent, so the tolerance stays even though the server is now
correct.

### Where else this can bite

Not audited, and this is a count of CANDIDATES rather than defects: **140
`as Map<String` cast sites across 58 client files**, and 19 API controllers
building keyed collections with `array_map`. The vulnerable ones are those
reading a keyed collection that **can legitimately be empty**. If you own one,
the question is whether your fixture carries the wire shape or a hand-written
Dart map.
