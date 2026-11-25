# Roles & Permissions audit (2026-11-24)

Jose, 11-24: "i think you need to do a whole revision of the roles and permissions tab".
Trigger: Inspection › Review QR stayed hidden until Customer Experience was ticked, and the
editor never said so.

## Headline

192 rows: 46 modules + 146 tabs and sub-tabs, from the server registry
(ModuleServiceProvider, TabDefinition, actionRequires) and each client screen's tab code.

| Verdict | Found | Fixed on session/roles-audit |
|---|---|---|
| OK | 177 | — |
| HIDDEN-DEP (drawn only with a key the editor did not show) | 5 | 5 |
| CLIENT>SERVER (drawn, then the server refused) | 10 | 10 |
| SERVER>CLIENT (grantable, nothing draws it) | 0 | — |

Not tabs, so not rows, and not fixed here (minor, noted): AI › AI's edit button reads
Library (`ai_assistance.library.view`); Review QR and the eKomi job picker search
Customers/Jobs; Jobs › Book searches Customers (now a note on Jobs › Create). The
tab-less modules (Customers' Memberships chip, Customer Experience's Auto-replies,
Review links, Review QR, GBP Performance, Posts to approve, Settings › Lists and
Activity) draw on their module's grant.

"Looser" in the server column means the server checks less than the tab key (e.g. only
`module.view`). That never refuses a drawn tab, so it is OK by this audit's definition;
it matters only if a tab key is ever meant to be a server boundary.

## Guard

`client/test/tab_grants_test.dart` pumps every registry tab with ONLY the keys
`client/test/fixtures/module_tabs.json` lists under `grants` for it (module view + tab key +
actionRequires, followed through), plus one sibling's grants, and fails when the tab is not
drawn. Shrink-only allowlist: `leads` (data-driven strip) and `jobs › Jobs` (the only tab;
no strip is drawn for one chip). The fixture's `grants` come from
`tests/Feature/ModuleTabsFixtureTest.php` (`WRITE_MODULE_TABS=1` to regenerate).

## The editor

Requirements now name the other module ("Won't show: also tick Reviews › View"); every module has Select all / Clear all; the search box finds modules, tabs,
tab groups and notes; "What this role sees" lists the modules and tabs the ticks open and
each tick that is waiting on something.

## Rows

The "Roles editor shows" column is the registry after this branch. Verdict and Fix say
what was found before it and what changed.

### dashboard

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `dashboard.view` | sidebar reachable | GET dashboard: glances filtered (looser) | "Open" box | OK |  |

### organizations

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `organizations.view` | Settings entry pinned for everyone | n/a: opens nothing by itself | "Open" box; note "Opens nothing by itself; tick the tabs below." | OK |  |
| Organization | `organizations.organization.view` | `organizations.organization.view` | GET companies: CompanyPolicy::viewAny `companies.view` | tab box; requires companies.view | CLIENT>SERVER | Declared: actionRequires `organization.view` => `companies.view`. |
| Preferences | `organizations.settings.view` | `organizations.settings.view` | GET settings ungated; writes per key | tab box; note "Preferences and Lists." | OK |  |
| Integrations | `organizations.integrations.view` | `organizations.integrations.view` AND `marketing.view` (declared) | IntegrationsOverviewController `marketing.view` | tab box; requires marketing.view | OK |  |
| API activity | `organizations.api_activity.view` | `organizations.api_activity.view` | ApiKeysController::activity `organizations.api_activity.view` | tab box | OK |  |

### companies

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `companies.view` | no surface of its own; read by Settings › Organization | CompanyPolicy `companies.view` | "Open" box | OK | Now declared as what Organization needs. |

### franchises

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `franchises.view` | no surface (inside Organization) | FranchisePolicy create/edit | "Open" box | OK |  |

### users

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `users.view` | none (omitted from Roles) | n/a | "Open" box | OK |  |

### roles

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `roles.view` | Settings › Roles on `module(roles).reachable` | RolePolicy `roles.view` | "Open" box | OK |  |

### audit

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `audit.view` | Settings › Activity on `audit.view` | `permission:audit.view` | "Open" box | OK |  |

### api_keys

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `api_keys.view` | profile card, levels from `allowed_levels` | ApiKeysController allowedLevels | "Open" box | OK |  |

### development

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `development.view` | staff row when `me.development` is set | PlatformStaffLink + TOTP | "Open" box | OK |  |

### hr

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `hr.view` | sidebar: reachable (available AND `hr.view`) | per tab | "Open" box | OK |  |
| Dashboard | `hr.dashboard.view` | `hr.dashboard.view` | `permission:hr.dashboard.view` | tab box | OK |  |
| Records | `hr.records.view` | `hr.records.view` | `permission:hr.records.view` | tab box | OK |  |
| Contracts | `hr.contracts.view` | `hr.contracts.view` | ContractPolicy `hr.view` (looser) | tab box | OK |  |
| Invitations | `hr.invitations.view` | `hr.invitations.view` | `permission:hr.invitations.view` + InvitationPolicy `hr.view` | tab box | OK |  |
| Onboarding | `hr.onboarding.view` | `hr.onboarding.view` | no route gate; list narrowed by `hr.view` | tab box | OK |  |
| Payroll | `hr.payroll.view` | `hr.payroll.view` | `permission:hr.payroll.view`; pay columns `hr.pay.view` | tab box | OK |  |
| Recruiting | `hr.recruiting.view` | `hr.recruiting.view` | RecruitingController `hr.recruiting.view` | tab box | OK |  |

### work_hours

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `work_hours.view` | sidebar: reachable (available AND `work_hours.view`) | per tab | "Open" box | OK |  |
| General | `work_hours.general.view` | `work_hours.general.view` | `permission:work_hours.general.view` + `work_hours.view` | tab box | OK |  |
| Paid Time Off | `work_hours.pto.view` | `work_hours.pto.view` | GET hr/pto ungated; widened by `hr.view` / `work_hours.all` | tab box | OK |  |
| Reports | `work_hours.reports.view` | `work_hours.reports.view` | `permission:work_hours.reports.view` | tab box | OK |  |
| Daily approval | `work_hours.approval.view` | `work_hours.approval.view` | approval.view or approve (controller) | tab box | OK |  |
| Payroll | `work_hours.payroll` | `work_hours.payroll` | `permission:work_hours.payroll` | tab box; requires work_hours.all | OK |  |
| Settings | `work_hours.settings.view` | `work_hours.settings.view` | SettingsController filter | tab box | OK |  |

### ai_assistance

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `ai_assistance.view` | sidebar: reachable (available AND `ai_assistance.view`) | per tab | "Open" box | OK |  |
| AI | `ai_assistance.ai.view` | `ai_assistance.ai.view` | `permission:ai_assistance.ai.view` (api.php) | tab box | OK |  |
| Library | `ai_assistance.library.view` | `ai_assistance.library.view` | AssistantEntryPolicy::viewAny `ai_assistance.library.view` | tab box | OK |  |
| Parts | `ai_assistance.parts.view` | `ai_assistance.parts.view` | `permission:ai_assistance.parts.view` | tab box | OK |  |
| Manuals | `ai_assistance.manuals.view` | `ai_assistance.manuals.view` | `permission:ai_assistance.manuals.view` | tab box | OK |  |
| Answers | `ai_assistance.answers.view` | `ai_assistance.answers.view` | `permission:ai_assistance.answers.view` | tab box | OK |  |
| WhatsApp groups | `ai_assistance.whatsapp_groups.view` | `ai_assistance.whatsapp_groups.view` AND `ai_assistance.manage` | `permission:ai_assistance.manage` (whole group) | tab box; requires ai_assistance.manage | HIDDEN-DEP | Declared: actionRequires `whatsapp_groups.view` => `ai_assistance.manage` (an editor with no read-only face). |
| Staff bot | `ai_assistance.staff_bot.view` | `ai_assistance.staff_bot.view` AND `ai_assistance.manage` | `permission:ai_assistance.manage` | tab box; requires ai_assistance.manage | HIDDEN-DEP | Declared: actionRequires `staff_bot.view` => `ai_assistance.manage`. |
| Settings | `ai_assistance.settings.view` | `ai_assistance.settings.view` | SettingsController filters by `ai_assistance.settings.view` | tab box | OK |  |

### guidelines

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `guidelines.view` | sidebar: reachable (available AND `guidelines.view`) | per tab | "Open" box | OK |  |
| Guidelines | `guidelines.guidelines.view` | `guidelines.guidelines.view` | GuidelinePolicy::viewAny `guidelines.guidelines.view` | tab box | OK |  |
| Settings | `guidelines.settings.view` | `guidelines.settings.view` AND `guidelines.manage` (declared) | GET guidelines: GuidelinePolicy::viewAny `guidelines.guidelines.view` | tab box; requires guidelines.manage | CLIENT>SERVER | GuidelinePolicy::viewAny now also accepts `guidelines.settings.view`. |

### time_clock

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `time_clock.view` | top-bar Clock button (`me.timeClock.showButton`) | TimeClock::payload `time_clock.view` | "Open" box | OK |  |

### customers

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `customers.view` | sidebar: reachable, or a reachable child (Memberships) | CustomerPolicy::viewAny `customers.view` | "Open" box | CLIENT>SERVER | A Memberships-only role drew the Customers list (403). Now Memberships alone when Customers is not reachable; Memberships chip on `reaches(memberships)` (memberships_screen.dart). |

### memberships (inside customers)

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `memberships.view` | sidebar: reachable (available AND `memberships.view`); drawn inside the parent screen | per tab | "Open" box | OK |  |
| Dashboard | `memberships.dashboard.view` | `memberships.dashboard.view` | GET memberships: MembershipsController::index `memberships.members.view` | tab box; requires memberships.members.view | CLIENT>SERVER | Declared: actionRequires `dashboard.view` => `memberships.members.view` (it counts the members list). |
| Members | `memberships.members.view` | `memberships.members.view` | MembershipsController::index `memberships.members.view` | tab box | OK |  |
| Settings | `memberships.settings.view` | `memberships.settings.view` | GET memberships/plans `memberships.view` | tab box | OK |  |
| Promotions | `memberships.promotions.view` | `memberships.promotions.view` | PromotionsController `memberships.promotions.view` | tab box | OK |  |

### jobs

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `jobs.view` | sidebar: reachable (available AND `jobs.view`) | per tab | "Open" box | OK |  |
| Jobs | `jobs.view` | always (module screen) | JobPolicy::viewAny `jobs.view` | tab box | OK | Book (no tab, on `jobs.create`) searches /customers: note added on Jobs › Create. |

### estimates (inside jobs)

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `estimates.view` | sidebar: reachable (available AND `estimates.view`); drawn inside the parent screen | per tab | "Open" box; requires jobs.financials | OK |  |

### project_board

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `project_board.view` | sidebar: reachable (available AND `project_board.view`) | per tab | "Open" box; requires jobs.view | OK |  |
| Dashboard | `project_board.view` | always (module view) | ProjectBoardController `project_board.view` AND `jobs.view` (declared) | tab box; requires jobs.view | OK |  |
| Inbox | `project_board.view` | always (module view) | ProjectBoardController `project_board.view` AND `jobs.view` (declared) | tab box; requires jobs.view | OK |  |
| Unscheduled | `project_board.view` | always (module view) | ProjectBoardController `project_board.view` AND `jobs.view` (declared) | tab box; requires jobs.view | OK |  |
| Ready to Schedule | `project_board.view` | always (module view) | ProjectBoardController `project_board.view` AND `jobs.view` (declared) | tab box; requires jobs.view | OK |  |
| Scheduled | `project_board.view` | always (module view) | ProjectBoardController `project_board.view` AND `jobs.view` (declared) | tab box; requires jobs.view | OK |  |
| In Progress | `project_board.view` | always (module view) | ProjectBoardController `project_board.view` AND `jobs.view` (declared) | tab box; requires jobs.view | OK |  |
| Done Waiting Payment | `project_board.view` | always (module view) | ProjectBoardController `project_board.view` AND `jobs.view` (declared) | tab box; requires jobs.view | OK |  |
| Complete | `project_board.view` | always (module view) | ProjectBoardController `project_board.view` AND `jobs.view` (declared) | tab box; requires jobs.view | OK |  |
| Settings | `project_board.settings.view` | `organizations.edit` (was drawn on that alone) | GET settings ungated; project-board/options view + jobs.view; writes per settings key | tab box; requires organizations.edit | HIDDEN-DEP | Own grant `project_board.settings.view` with actionRequires => `organizations.edit`; migration 2026_11_24_173418 grants it to roles holding both `project_board.view` and `organizations.edit`. |

### scheduling

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `scheduling.view` | sidebar: reachable (available AND `scheduling.view`) | per tab | "Open" box | OK |  |
| Availability | `scheduling.availability.view` | `scheduling.availability.view` | `permission:scheduling.availability.view` | tab box | OK |  |
| Schedule | `scheduling.schedule.view` | `scheduling.schedule.view` | `permission:scheduling.schedule.view` | tab box | OK |  |
| Re-Scheduling | `scheduling.rescheduling.view` | `scheduling.rescheduling.view` | `permission:scheduling.rescheduling.view` | tab box | OK |  |
| Routing | `scheduling.routing.view` | `scheduling.routing.view` | routes `permission:scheduling.routing.view`; its first read GET scheduling/areas was in the `scheduling.settings.view` group | tab box | CLIENT>SERVER | GET scheduling/areas now `permission:scheduling.routing.view,scheduling.settings.view`. |
| Helpers | `scheduling.helpers.view` | `scheduling.helpers.view` | `permission:scheduling.helpers.view` | tab box | OK |  |
| Locations | `scheduling.locations.view` | `scheduling.locations.view` | `permission:scheduling.locations.view` | tab box | OK |  |
| Settings | `scheduling.settings.view` | `scheduling.settings.view`; its Locations sub-page also needs `scheduling.locations.view` | `permission:scheduling.settings.view` | tab box; note "Its Locations page also needs the Locations tab." | HIDDEN-DEP (sub-page) | Note on the Settings row: "Its Locations page also needs the Locations tab." |

### inspection

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `inspection.view` | sidebar: reachable (available AND `inspection.view`) | per tab | "Open" box | OK |  |
| New Inspection | `inspection.new.view` | `inspection.new.view` AND `inspection.create` (declared) | `permission:inspection.new.view` | tab box; requires inspection.create; note "Also opens Review QR. Finding a customer or job there needs Customers or Jobs." | OK |  |
| Generated Reports | `inspection.reports.view` | `inspection.reports.view` | `permission:inspection.reports.view` | tab box | OK |  |
| Builder | `inspection.builder.view` | `inspection.builder.view` AND `inspection.edit` (declared) | `permission:inspection.builder.view` | tab box; requires inspection.edit | OK |  |
| Testing | `inspection.builder.view` | `inspection.builder.view` AND `inspection.edit` (declared) | `permission:inspection.builder.view` | tab box; requires inspection.edit | OK |  |
| Review QR | `inspection.new.view` | `inspection.new.view` AND `reaches(reviews)` | POST customer-experience/review-qr: signed in only | tab box; requires inspection.create; note "Also opens Review QR. Finding a customer or job there needs Customers or Jobs." | HIDDEN-DEP | `reaches(reviews)` dropped (inspection_screen.dart); note on New Inspection: search needs Customers or Jobs. |

### marketing

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `marketing.view` | sidebar: reachable (available AND `marketing.view`) | per tab | "Open" box; note "Also needed for Settings › Integrations." | OK |  |
| Social Media Management | `marketing.view` | `reaches(marketing)` | social/providers, social/connections `marketing.view` | tab box; note "Also needed for Settings › Integrations." | OK |  |
| Dashboard | `marketing.dashboard.view` | `marketing.dashboard.view` (tool beside the Social page) | GET social/posts: any of dashboard/compose/calendar view | tab box under social | OK |  |
| Compose | `marketing.compose.view` | `marketing.compose.view` (tool beside the Social page) | `permission:marketing.compose.view` | tab box under social | OK |  |
| Calendar | `marketing.calendar.view` | `marketing.calendar.view` (tool beside the Social page) | GET social/posts: any of dashboard/compose/calendar view | tab box under social | OK |  |
| Accounts | `marketing.accounts.view` | `marketing.accounts.view` (tool beside the Social page) | social/connections `marketing.view` (looser) | tab box under social | OK |  |
| Comments | `marketing.comments.view` | `marketing.comments.view` (tool beside the Social page) | SocialCommentsController `marketing.comments.view` | tab box under social | OK |  |
| QR Codes | `marketing.qr_codes.view` | `marketing.qr_codes.view` (tool beside the Social page) | `permission:marketing.qr_codes.view` | tab box under social | OK |  |

### newsletter (inside marketing)

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `newsletter.view` | sidebar: reachable (available AND `newsletter.view`); drawn inside the parent screen | per tab | "Open" box | OK |  |
| Dashboard | `newsletter.dashboard.view` | `newsletter.dashboard.view` | NewslettersController: dashboard.view or composer.view | tab box | OK |  |
| Composer | `newsletter.composer.view` | `newsletter.composer.view` | `permission:newsletter.composer.view`; media picker lists `newsletter/images` (own grant) | tab box | OK | |
| Settings | `newsletter.settings.view` | `newsletter.settings.view` | GET social/connections: `marketing.view` | tab box | CLIENT>SERVER | SocialConnectionsController::index now also accepts `newsletter.settings.view`. |

### web_management (inside marketing)

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `web_management.view` | sidebar: reachable (available AND `web_management.view`); drawn inside the parent screen | per tab | "Open" box | OK |  |
| SEO | `web_management.seo.view` | `web_management.seo.view` | `permission:web_management.seo.view` | tab box; note "General, Social links, Pages, Cities, Posts, Templates and SEO Helper." | OK |  |
| Widgets | `web_management.widgets.view` | `web_management.widgets.view` | `permission:web_management.widgets.view` | tab box | OK |  |

### phone

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `phone.view` | sidebar: reachable (available AND `phone.view`) | per tab | "Open" box | OK |  |
| Phone | `phone.phone.view` | `phone.phone.view` | policies `phone.view` (looser) | tab box; note "Dial, Messages, Team and Voicemail." | OK |  |
| Users | `phone.users.view` | `phone.users.view` | ExtensionsController `phone.users.view` or `phone.settings.view` | tab box | OK |  |
| Records | `phone.records.view` | `phone.records.view` | CallsController `phone.view` (looser) | tab box | OK |  |
| Reports | `phone.reports.view` | `phone.reports.view` | `permission:phone.reports.view` | tab box | OK |  |
| Alerts | `phone.alerts.view` | `phone.alerts.view` | AlertsController `phone.alerts.view` | tab box | OK |  |
| Settings | `phone.settings.view` | `phone.settings.view` | `permission:phone.settings.view` sub-panes | tab box | OK |  |

### fleet_management

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `fleet_management.view` | sidebar: reachable (available AND `fleet_management.view`) | per tab | "Open" box | OK |  |
| General | `fleet_management.general.view` | `fleet_management.general.view` | `permission:fleet_management.general.view` | tab box | OK |  |
| Vehicles | `fleet_management.vehicles.view` | `fleet_management.vehicles.view` | VehiclePolicy::viewAny `fleet_management.view` (looser) | tab box | OK |  |

### financials

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `financials.view` | sidebar: reachable (available AND `financials.view`) | per tab | "Open" box | OK |  |
| Home | `financials.bases.view` | `financials.bases.view` | `permission:financials.bases.view` | tab box | OK |  |
| Bills | `financials.bills.view` | `financials.bills.view` | `permission:financials.bills.view` | tab box | OK |  |
| Markets | `financials.markets.view` | `financials.markets.view` | `permission:financials.markets.view` | tab box | OK |  |
| Settings | `financials.settings.view` | `financials.settings.view` | `permission:financials.settings.view` | tab box | OK |  |

### financial_results (inside financials)

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `financial_results.view` | sidebar: reachable (available AND `financial_results.view`); drawn inside the parent screen | per tab | "Open" box | OK |  |

### invoices (inside financials)

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `invoices.view` | sidebar: reachable (available AND `invoices.view`); drawn inside the parent screen | per tab | "Open" box | OK |  |
| Charge sheets | `invoices.charge_sheets.view` | `invoices.charge_sheets.view` (inside Financials, child chip on `reaches(invoices)`) | InvoicesController `invoices.view` (looser) | tab box | OK |  |
| Invoices | `invoices.invoices.view` | `invoices.invoices.view` (inside Financials, child chip on `reaches(invoices)`) | InvoicesController `invoices.view` (looser) | tab box | OK |  |
| Settings | `invoices.settings.view` | `invoices.settings.view` (inside Financials, child chip on `reaches(invoices)`) | InvoicesController `invoices.view` (looser) | tab box | OK |  |

### company

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `company.view` | sidebar: reachable (available AND `company.view`) | per tab | "Open" box | OK |  |
| Ownership | `company.ownership.view` | `company.ownership.view` | OwnershipController::index `hr.view` | tab box | CLIENT>SERVER | Server now also accepts `company.ownership.view` / `company.processes.view`. |
| Processes | `company.processes.view` | `company.processes.view` | OwnershipController::index `hr.view` | tab box | CLIENT>SERVER | As Ownership. |
| KPIs | `company.kpis.view` | `company.kpis.view` | `permission:reports.experimental.view` (reports/experimental/calls), `permission:reports.view` (reports/goals); failures swallowed, tab looked empty | tab box; requires reports.experimental.view | CLIENT>SERVER | Declared: actionRequires `kpis.view` => `reports.experimental.view` (it implies `reports.view`). |

### tasks

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `tasks.view` | sidebar reachable | GET task-boards: no permission check (looser) | "Open" box | OK |  |

### inventory

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `inventory.view` | sidebar: reachable (available AND `inventory.view`) | per tab | "Open" box | OK |  |
| General | `inventory.general.view` | `inventory.general.view` | `permission:inventory.general.view` | tab box | OK |  |
| Stock | `inventory.stock.view` | `inventory.stock.view` | `permission:inventory.stock.view` | tab box | OK |  |
| Items | `inventory.items.view` | `inventory.items.view` | InventoryItemPolicy `inventory.view` (looser, by design) | tab box | OK |  |
| Orders | `inventory.orders.view` | `inventory.orders.view` | `permission:inventory.orders.view` | tab box | OK |  |
| Locations | `inventory.locations.view` | `inventory.locations.view` | `inventory.view` (looser, by design) | tab box | OK |  |

### services

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `services.view` | sidebar: reachable (available AND `services.view`) | per tab | "Open" box | OK |  |
| Catalogue | `services.catalogue.view` | `services.catalogue.view` | ServicePolicy `services.view` (looser); writes `services.catalogue.view` | tab box | OK |  |
| Settings | `services.settings.view` | `services.settings.view` | reads ungated; writes `permission:services.settings.view` | tab box | OK |  |

### leads

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `leads.view` | sidebar: reachable (available AND `leads.view`) | per tab | "Open" box | OK |  |
| Google Local Services | `leads.local_services.view` | `leads.local_services.view` (group chip if any member) | GET leads `leads.view` + Lead::scopeSourceVisibleTo `leads.local_services.view` | tab box; note "LS Call in Calls, LS Text in SMS Texts, and LS Booking." | OK |  |
| RC Calls | `leads.ringcentral.view` | `leads.ringcentral.view` (group chip if any member) | GET leads `leads.view` + Lead::scopeSourceVisibleTo `leads.ringcentral.view` | tab box under Calls | OK |  |
| EZ Calls | `leads.ez_calls.view` | `leads.ez_calls.view` (group chip if any member) | GET leads `leads.view` + Lead::scopeSourceVisibleTo `leads.ez_calls.view` | tab box under Calls | OK |  |
| EZ Texts | `leads.ez_texts.view` | `leads.ez_texts.view` (group chip if any member) | GET leads `leads.view` + Lead::scopeSourceVisibleTo `leads.ez_texts.view` | tab box under SMS Texts | OK |  |
| Thumbtack | `leads.thumbtack.view` | `leads.thumbtack.view` (group chip if any member) | GET leads `leads.view` + Lead::scopeSourceVisibleTo `leads.thumbtack.view` | tab box | OK |  |
| Chat | `leads.website_chat.view` | `leads.website_chat.view` (group chip if any member) | GET leads `leads.view` + Lead::scopeSourceVisibleTo `leads.website_chat.view` | tab box | OK |  |
| Forms | `leads.website_form.view` | `leads.website_form.view` (group chip if any member) | GET leads `leads.view` + Lead::scopeSourceVisibleTo `leads.website_form.view` | tab box under Forms | OK |  |
| Bad Ratings | `leads.bad_rating.view` | `leads.bad_rating.view` (group chip if any member) | GET leads `leads.view` + Lead::scopeSourceVisibleTo `leads.bad_rating.view` | tab box | OK |  |
| Online Booking | `leads.website_booking.view` | `leads.website_booking.view` (group chip if any member) | GET leads `leads.view` + Lead::scopeSourceVisibleTo `leads.website_booking.view` | tab box | OK |  |
| Facebook | `leads.facebook.view` | `leads.facebook.view` (group chip if any member) | GET leads `leads.view` + Lead::scopeSourceVisibleTo `leads.facebook.view` | tab box under Meta | OK |  |
| Instagram | `leads.instagram.view` | `leads.instagram.view` (group chip if any member) | GET leads `leads.view` + Lead::scopeSourceVisibleTo `leads.instagram.view` | tab box under Meta | OK |  |
| WhatsApp | `leads.whatsapp.view` | `leads.whatsapp.view` (group chip if any member) | GET leads `leads.view` + Lead::scopeSourceVisibleTo `leads.whatsapp.view` | tab box under Meta | OK |  |
| Workiz | `leads.workiz.view` | `leads.workiz.view` (group chip if any member) | GET leads `leads.view` + Lead::scopeSourceVisibleTo `leads.workiz.view` | tab box | OK |  |
| Promotions | `leads.promotions.view` | `leads.promotions.view` (group chip if any member) | GET leads `leads.view` + Lead::scopeSourceVisibleTo `leads.promotions.view` | tab box | OK |  |
| Recovery | `leads.recovery.view` | `leads.recovery.view` | GET leads `leads.view`; recovery filter (tab key not checked, looser) | tab box | OK |  |
| Hidden | `leads.hidden.view` | `leads.hidden.view` inside Manage, which needs `leads.manage` (declared) | `permission:leads.hidden.view` | tab box under Manage; requires leads.manage | OK |  |

### customer_experience

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `customer_experience.view` | sidebar: reachable (available AND `customer_experience.view`) | per tab | "Open" box | OK |  |
| Reviews & Complaints | `customer_experience.complaints.view` | `customer_experience.complaints.view` | ComplaintsController `customer_experience.complaints.view` | tab box | OK |  |

### reviews (inside customer_experience)

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `reviews.view` | sidebar: reachable (available AND `reviews.view`); drawn inside the parent screen | per tab | "Open" box; note "Reviews and Review links (with the Reviewed step)." | OK |  |

### business_profile (inside customer_experience)

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `business_profile.view` | sidebar: reachable (available AND `business_profile.view`); drawn inside the parent screen | per tab | "Open" box; note "GBP Listings, GBP Performance and Posts to approve." | OK |  |

### email

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `email.view` | sidebar: reachable (available AND `email.view`) | per tab | "Open" box | OK |  |
| Inbox | `email.inbox.view` | `email.inbox.view` | route group `permission:email.inbox.view` + mailboxes `email.view` | tab box | OK |  |
| Settings | `email.settings.view` | `email.settings.view` | route group `permission:email.settings.view` + mailboxes `email.view` | tab box | OK |  |
| Google Workspace | `email.workspace.view` | `email.workspace.view` | route group `permission:email.workspace.view` + mailboxes `email.view` | tab box | OK |  |

### training

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `training.view` | sidebar: reachable (available AND `training.view`) | per tab | "Open" box | OK |  |
| Learn | `training.view` | always (module view) | LearnController `training.view` | tab box | OK |  |
| Dashboard | `training.monitor` | `training.monitor` | DashboardController `training.monitor` | tab box | OK |  |
| Settings | `training.manage` | `training.manage` | guide/test policies `training.manage` | tab box | OK |  |

### automations

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `automations.view` | sidebar: reachable (available AND `automations.view`) | per tab | "Open" box | OK |  |
| Your automations | `automations.rules.view` | `automations.rules.view` | AutomationPolicy::viewAny `automations.view` (looser) | tab box | OK |  |
| Build | `automations.build.view` | `automations.build.view` | `permission:automations.build.view` | tab box | OK |  |

### monitoring

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `monitoring.view` | sidebar: reachable (available AND `monitoring.view`) | per tab | "Open" box | OK |  |
| General | `monitoring.general.view` | `monitoring.general.view` | `permission:monitoring.general.view` | tab box | OK |  |
| Map | `monitoring.map.view` | `monitoring.map.view` | `permission:monitoring.map.view` | tab box | OK |  |
| PC Specs | `monitoring.view` | always (module view) | MonitoredDevicePolicy `monitoring.view`; specs fields `monitoring.specs.view` | tab box | OK |  |
| Files | `monitoring.files.view` | `monitoring.files.view` | MonitoredDevicePolicy::files `monitoring.files.view` | tab box | OK |  |
| Camera Monitoring | `monitoring.cameras.view` | `monitoring.cameras.view` | MonitoringCameraPolicy `monitoring.cameras.view` | tab box | OK |  |
| Desktop Monitoring | `monitoring.screenshots.view` | `monitoring.screenshots.view` | MonitoringScreenshotPolicy `monitoring.screenshots.view` | tab box | OK |  |
| Lighting | `monitoring.lighting.manage` | `monitoring.lighting.manage` | devices `monitoring.view`; writes `lighting` policy | tab box | OK |  |

### reports

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `reports.view` | sidebar: reachable (available AND `reports.view`) | per tab | "Open" box | OK |  |
| Reports | `reports.library.view` | `reports.library.view` | `permission:reports.library.view` | tab box | OK |  |
| Report Builder | `reports.builder.view` | `reports.builder.view` AND `reports.build` (declared) | `permission:reports.builder.view` | tab box; requires reports.build | OK |  |
| Performance | `reports.experimental.view` | `reports.experimental.view` | `permission:reports.experimental.view` | tab box | OK |  |
| Scoring | `reports.scoring.view` | `reports.scoring.view` | `permission:reports.scoring.view` | tab box | OK |  |
| Marketing Reports | `reports.marketing.view` | `reports.marketing.view` AND `reaches(profit_loss)` (declared: `profit_loss.view`) | `permission:reports.marketing.view` + `profit_loss.view` | tab box; requires profit_loss.view | OK |  |
| Meta | `reports.meta.view` | `reports.meta.view` | `permission:reports.meta.view` | tab box | OK |  |
| Thumbtack | `reports.thumbtack.view` | `reports.thumbtack.view` | `permission:reports.thumbtack.view` | tab box | OK |  |
| Overview | `reports.jobs.view` | `reports.jobs.view` | `permission:reports.jobs.view` | tab box under Jobs | OK |  |
| Cancelled | `reports.cancelled.view` | `reports.cancelled.view` | `permission:reports.cancelled.view` | tab box under Jobs | OK |  |
| Pending | `reports.pending.view` | `reports.pending.view` | `permission:reports.pending.view` | tab box under Jobs | OK |  |
| Estimates | `reports.estimates.view` | `reports.estimates.view` | `permission:reports.estimates.view` | tab box under Jobs | OK |  |
| Tasks | `reports.tasks.view` | `reports.tasks.view` | `permission:reports.tasks.view` | tab box under Office | OK |  |
| Complaints | `reports.complaints.view` | `reports.complaints.view` | `permission:reports.complaints.view` | tab box under Office | OK |  |
| Dispatch | `reports.dispatch.view` | `reports.dispatch.view` | `permission:reports.dispatch.view` | tab box under Office | OK |  |
| Accounting weekly | `reports.accounting.view` | `reports.accounting.view` | `permission:reports.accounting.view` | tab box under Office | OK |  |
| Recruiting | `reports.recruiting.view` | `reports.recruiting.view` | `permission:reports.recruiting.view` | tab box under Office | OK |  |
| Tech report | `reports.techs.view` | `reports.techs.view` | `permission:reports.techs.view` | tab box under Technicians | OK |  |
| AI checks | `reports.tech_checks.view` | `reports.tech_checks.view` | `permission:reports.tech_checks.view` | tab box under Technicians | OK |  |
| Workspace | `reports.workspace.view` | `reports.workspace.view` | reports/texts, period, batches `reports.view`; its "Meta ad spend" entry called reports/meta = `reports.meta.view` | tab box | CLIENT>SERVER | "Meta ad spend" listed only with `reports.meta.view` (report_workspace.dart). |

### ads_manager (inside marketing)

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `ads_manager.view` | sidebar: reachable (available AND `ads_manager.view`); drawn inside the parent screen | per tab | "Open" box | OK |  |

### ads_performance (inside reports)

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `ads_performance.view` | sidebar: reachable (available AND `ads_performance.view`); drawn inside the parent screen | per tab | "Open" box | OK |  |

### profit_loss (inside reports)

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `profit_loss.view` | sidebar: reachable (available AND `profit_loss.view`); drawn inside the parent screen | per tab | "Open" box | OK |  |

### field

| Row | Key | Client draws it when | Server gate | Roles editor shows | Verdict | Fix |
|---|---|---|---|---|---|---|
| (module) | `field.view` | sidebar: reachable (available AND `field.view`) | per tab | "Open" box | OK |  |
| Dashboard | `field.dashboard.view` | `field.dashboard.view` | `permission:field.dashboard.view` | tab box | OK |  |
| Map | `field.map.view` | `field.map.view` | `permission:field.map.view` | tab box | OK |  |
| Route | `field.route.view` | `field.route.view` | `permission:field.route.view` | tab box | OK |  |
| Recordings | `field.recordings.view` | `field.recordings.view` | GET field/recordings: no permission gate (looser); `field.listen` widens | tab box | OK |  |
| Settings | `field.settings.view` | `field.settings.view` | SettingsController filter `field.settings.view` | tab box | OK |  |

## View As pass, 0.0.0638 (2026-11-24)

Read-only, on the app box: /var/www/ezbizi-current = release 3d930fce1f6b (Release 0.0.0638), which contains session/roles-audit @ 45f2e8302. Org: Triplex Group. Promise = PermissionCatalogue::keysGrantedBy(role) over available modules; a ticked tab counts as promised only when its `actionRequires` are all ticked (the rest the editor shows as "won't show"). Served = MeController payload pieces (PermissionCatalogue::heldBy, modulesFor()[reachable], MobileBar::forUser) for the first active user holding the role, built in tinker; Sales has no active user, so an unsaved in-memory User carrying the role was used. Client = every key in client/test/fixtures/module_tabs.json `grants` for each promised tab (local main) is in the served permissions. Nothing was written.

| Role | Users (active) | Promised modules / tabs | Served modules / tabs | Mismatches | Mobile bar slots served |
|---|---|---|---|---|---|
| Administrator | 5 (5) | 46 / 147 | 46 / 147 | none | default (null) |
| Dispatcher | 28 (18) | 22 / 43 | 22 / 43 | none | 4 of 4: Jobs, Scheduling, Task Board, Communication |
| Marketing | 2 (1) | 22 / 52 | 22 / 52 | none (1 tab ticked but shown as won't show: reports > Marketing Reports needs profit_loss.view; not served to the client either, consistent) | default (null) |
| Technician | 64 (54) | 8 / 9 | 8 / 9 | none | 4 of 4: Jobs, Inspection, Review QR, Task Board |
| Lead Manager | 1 (1) | 27 / 73 | 27 / 73 | none | default (null) |
| Local Manager | 18 (14) | 14 / 29 | 14 / 29 | none | default (null) |
| Accounting | 2 (2) | 26 / 65 | 26 / 65 | none | default (null) |
| Google API Reviewer | 1 (1) | 18 / 44 | 18 / 44 | none | default (null) |
| Training | 12 (9) | 6 / 4 | 6 / 4 | none | default (null) |
| Recruiter | 1 (1) | 14 / 28 | 14 / 28 | none | default (null) |
| Remote Dispatcher | 39 (24) | 14 / 29 | 14 / 29 | none | default (null) |
| Sales | 1 (0) | 19 / 29 | 19 / 29 | none (no active user; in-memory role check) | default (null) |
| Dispatch Supervisor | 2 (2) | 22 / 52 | 22 / 52 | none | default (null) |
| Chief Operating Officer | 1 (1) | 28 / 64 | 28 / 64 | none | default (null) |
| Lead Accounting | 1 (1) | 28 / 82 | 28 / 82 | none | 4 of 4: Human Resources, Task Board, Reports, Work Hours |

Result: all 15 roles match. Every promised module is reachable and nothing unpromised is; every promised tab key is served; every promised tab's client grants are held; no served key beyond the role's ticks (no personal inbox grants on the sampled users); every stored mobile-bar slot is served.
