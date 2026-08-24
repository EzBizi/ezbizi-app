# Dashboard module boxes — design

**Date:** 2026-08-24  
**Status:** drafted for owner review; not yet built  
**Product:** EzBizi customer app (`app.ezbizi.com`)  
**Replaces:** the widget-picker dashboard (plan §18 Phase 4) as it ships today

The dashboard today is a personal composition of widgets (`jobs.by_status`, `jobs.revenue`, `hr.headcount`, …). This spec changes the unit. A box is a **module**, not a widget. If the role can open Inventory, Inventory is on the dashboard — even when the tab is still coming soon.

Email mailbox internals stay owned by the other session. This work reads unread counts through the existing mailbox access surface. It does not change connect, grants, or sync.

---

## 1. What it is

The Dashboard tab is one box per sidebar module this person can open. Each box is that module at a glance: a chart by default, or a sentence, under one period for the whole page.

A box is **not** a shortcut to the module. Tapping it stays on Dashboard. Dragging it changes order. Nothing hides it.

The Human Resources **Dashboard** chip is the same one HR box, not five widgets of its own.

---

## 2. What it is not

- Not the widget picker. There is no “Choose panels”. You cannot add a second Jobs box, and you cannot take Jobs off.
- Not a second home for a number. Jobs-by-status is computed once; the Jobs box draws it. The HR tab does not invent a second headcount.
- Not navigation. The sidebar is how you open a module. The box is how you see it without leaving Dashboard.
- Not a coming-soon chart. A module that is granted and not built is a sentence. No pie of zeroes, no chart switcher.
- Not Organization, Settings, Roles, Audit, or the Dashboard tab itself. Those are not work destinations in the rail.
- Not a stored copy of anybody’s mailbox. Unread is asked of the provider. See §10.
- Not a `jobs.financials` permission. Revenue lives **on** the Jobs box. It is still gated by `jobs.view`, as `jobs.revenue` is today.

---

## 3. Settled decisions

| # | Decision |
|---|---|
| 1 | **One box per granted module**, including coming soon. Inventory with `inventory.view` is an Inventory box. |
| 2 | Coming-soon boxes are **a sentence**. No chart switcher. |
| 3 | Default is **chart first**. Empty data is a sentence, not an empty pie. |
| 4 | Tapping a box **stays on Dashboard**. |
| 5 | Every reachable sidebar module appears. Order follows the **sidebar** (module registry). Drag reorders. **Cannot hide.** |
| 6 | HR’s Dashboard chip is **the same one HR box**. |
| 7 | **One period** for the whole dashboard: week / month / quarter / year. Calendar, not a rolling N days. |
| 8 | **Snapshots ignore the period** (current board). Time series use it. |
| 9 | Defaults: Jobs **pie**; Customers **line**; Inspection **bar**; Marketing **timeline** (sentence until compose); Fleet **pie**; HR **bar** (in flight vs stalled); Email unread-by-inbox; Automations healthy vs failed. |
| 10 | Revenue is **on the Jobs box**, not its own box. |
| 11 | Not boxes: Dashboard, Organization, Settings, Roles (and Audit, Companies, Franchises, Users). |

---

## 4. Who gets a box

A box exists when all of these are true, which is the same question the sidebar already asks:

1. The module is in the registry with `rolesGroup = tab`.
2. The module key is not `dashboard`.
3. Gate 1 — the platform has not switched it off for this organization (`ModuleAvailability`).
4. Gate 3 — the person’s role holds `{module}.view`.
5. The client can draw a destination for it (`moduleScreens`). Server and client still ship together; a newer server with no client screen is omitted from the rail and from the dashboard for the same reason.

Gates 2 (entitlement) and 4 (assignment) already sit inside “reachable” and inside each glance’s query. A franchise technician’s Jobs box counts jobs in reach, not the group.

`PermissionCatalogue::modulesFor` is the list. The dashboard walks it the way `HomeShell` does: reachable, in registry order, minus `dashboard`, minus anything that is not a tab.

Today that is, for an administrator with everything on:

Human Resources · Customers · Jobs · Inspection · Marketing · Web Management · Phone · Fleet Management · Inventory · Services · Email · Automations

A role holding only `inventory.view` and `jobs.view` sees two boxes, **Jobs then Inventory** — registry order, same as the sidebar — even though Inventory is coming soon.

When a module is switched off platform-wide, or the role loses `{module}.view`, the box disappears on the next load. When it comes back, it is **appended** (the saved order never heard of it). A saved order that still names it keeps its place through a temporary loss, the same rule `panelsInSavedOrder` already uses.

---

## 5. Order, drag, cannot hide

Default order is sidebar order: the registry’s tab modules, skipping `dashboard`.

The person may drag (and the existing keyboard / touch move controls on `DashboardGrid` stay). That is the only layout preference.

They cannot hide a box. There is no checkbox list. A save that omits a reachable module is not refused: the omitted keys are appended, default presentation. A save that names an unreachable module keeps the name so a brief permission loss does not forget the place; the client does not draw it.

A new module the saved order has never heard of is appended, not dropped. Dropping the unnamed ones would make every new tab invisible to everybody who had ever rearranged their dashboard — the same trap the HR order already records.

The current `max:20` on `dashboard.widgets` does not apply. The cap is “every reachable tab module”, not a number.

---

## 6. Period

One control, in the Dashboard header: **Week · Month · Quarter · Year**. Default **month**. Stored as `dashboard.period` (§2.1 category 6). Not audited: rearranging your own screen is not something §10 records.

Calendar in the **current view’s timezone** (the franchise the switcher is pointing at; organization view uses the organization timezone). “This week” is from the start of the current week through now, not a future Sunday. `week_starts_on` is already an organization setting; the week bound reads it rather than assuming Monday.

| Period | Start |
|---|---|
| week | Start of the current week (`week_starts_on`) |
| month | 1st of this month, 00:00 |
| quarter | 1st day of this calendar quarter, 00:00 |
| year | 1 January, 00:00 |

End is always now. The payload echoes `period`, `period_starts_on`, `period_ends_on` so the client never recomputes the window.

This replaces the per-widget rolling 7 / 30 / 90 / 365. A calendar month **will** look thin on the 1st. That was the reason the widgets used a rolling window. The owner chose calendar periods anyway; the header names the window so the thin day is readable as “this month so far”, not as a collapse.

**Snapshots ignore the period.** Changing Week to Year does not change Jobs-by-status, Fleet-by-status, HR in-flight vs stalled, Email unread, or Automations health. It does change Customers added, Jobs revenue (the sentence), and HR starters / leavers (in the text of the HR box). Tests assert the snapshot numbers are identical across periods.

The HR Dashboard chip uses the same `dashboard.period`. Changing it on either surface changes both.

---

## 7. Presentation

Each box that is not coming soon offers:

- its **default chart** (pie, bar, line, or timeline)
- the other chart types that glance **declares**
- **text** — the same facts as sentences

Default is the chart, not text. The choice is stored per box, per user, as `presentation` on that glance. Changing it on the main dashboard and on the HR chip is the same preference when the box is HR.

Coming soon: `allowed_presentations` is empty. The client draws the sentence and no switcher. Marketing until compose is the same shape (the tab exists; the glance has nothing to plot).

The switcher lives on the card, in the header controls beside the drag handle. It is not a dashboard-wide “graph mode”.

**Tap does not navigate.** The title is the module’s label. It is not a link.

---

## 8. Empty, coming soon, configuring

| State | What is drawn |
|---|---|
| Coming soon | The glance’s sentence. Badge “Coming soon”. No switcher. |
| Nothing to plot (zero jobs, no vehicles, no customers in the period, no inboxes) | A sentence. Not an empty chart. |
| Chart with some non-zero slices | Empty slices **stay**, so the board does not reorder as work moves (the JobsByStatus rule). |
| Revenue with no earned statuses chosen | A sentence on the Jobs box: choose which of your job statuses count as earned. A control on **that** box, not a dashboard picker. Nothing is counted rather than everything. |
| Email with the tab but no inbox grant | “No inbox is assigned to your role.” |
| Automations with no runs | “No automations have run.” Waiting events, if any, are named in the same sentence. Silence is not health. |

The sentence is the server’s. The client does not invent “0” or “None”.

Coming-soon copy lives on the **glance**, so the dashboard is right without a client release for a new placeholder module. The Dart `ComingSoonScreen` strings may stay until those tabs ship; they are a second home and should be deleted with the placeholder, not kept in sync by hand forever.

---

## 9. The glance registry

Today `WidgetRegistry` lists questions (`jobs.by_status`, `hr.headcount`, …). The main dashboard lets a person pick a subset. HR’s tab picks a fixed five.

That unit is wrong for this product. The owner’s words: if you can open Inventory, you see the Inventory box.

So the dashboard unit becomes a **glance**, keyed by the **module key** (`jobs`, `hr`, `inventory`). One class per box. Declared, not scanned — same reason as `ModuleDefinition` and `Widget`.

A glance:

- `key()` — the module key. Stored in the preference, so it cannot change.
- `permission()` — `{module}.view`.
- `defaultPresentation()` / `allowedPresentations()` — empty allowed list means sentence-only (coming soon, or Marketing until compose).
- `snapshot()` — true if the chart ignores the period.
- `resolve(User, period, options)` — one payload that can feed a chart **and** text.

Existing widget classes stay as **counters the glance calls**. `JobsByStatus`, `Revenue`, `OnboardingsInFlight`, `InvitationsStalled`, `Headcount`, `StartersAndLeavers`, `ExpiringCertifications`, `AutomationHealth`, `CustomersAdded` keep being the one place those numbers are computed. The Jobs glance asks `JobsByStatus` and `Revenue`; it does not copy their queries. Two homes for one count is the trap §6 is about.

`UpcomingJobs` (the list of next five) does not become a box. A dashboard that renders a job list is the Jobs tab with extra steps. The Jobs box includes a **sentence** when any reachable job is scheduled in the future; it does not list them.

`WidgetRegistry::ON_BY_DEFAULT` and the catalogue-as-picker go away. `GET /dashboard/catalogue` is not needed: `GET /dashboard` already returns each glance’s allowed presentations.

`DashboardController::ORDERABLE = ['hr']` goes away. The HR chip is one box; there is no row to reorder on that tab. `dashboard.order['hr']` is ignored.

---

## 10. Each glance

Reach is the caller's current view intersected with §4, the same as today’s widgets. Amounts are never added across currencies.

### Human Resources — bar (snapshot)

**Chart:** two bars (pie offered as well): **Onboardings in flight** vs **Invitations stalled**. In flight is being filled in + waiting on a signature (`OnboardingsInFlight`). Stalled is expired + never opened (`InvitationsStalled`). Both empty-at-zero rules stay: a bar at zero is kept once there is anything to count; a business with nobody hired yet is the empty sentence.

**Text** (the HR plan’s five figures, not dropped):

- Headcount — distinct people, places as rows that do not sum to the total (`Headcount`).
- Onboardings in flight — the two states named.
- Invitations stalled — the two failure modes named. Revoked is not stalled.
- Starters and leavers — **uses the dashboard period** (`StartersAndLeavers`). Leaving still has no date for people deactivated before that table shipped; the existing sentence stays.
- Expiring certifications — still “not built yet” until a template marks `is_expiry` (TODO #26). The sentence says so.

Chart ignores the period. Starters and leavers in the text **use** the period (people who joined or left this week / month / …). Expiring certifications do **not**: they are a look forward from today, and a backward “this month so far” window would turn “coming due” into “already expired”. Until `is_expiry` exists they stay the “not built yet” sentence; when they ship they keep a forward window of their own, not `dashboard.period`.

HR’s Dashboard chip renders this same glance, same presentation preference, same period. One `resolve()`.

### Customers — line (period)

Customers **added** in the period, by owning franchise in reach, as a time series. Bucket by day in a week, by week in a month or quarter, by month in a year. The organization-wide total still sits beside the in-reach total, as `CustomersAdded` does now: a branch manager sees their share without guessing.

Empty: “No new customers this week.” (worded with the period).

Bar and text are allowed. Pie is not (a time series is not slices).

### Jobs — pie (snapshot)

Slices are the organization’s own job statuses (§2.1 category 5), including finished, including empty-at-zero once any job exists. Zero jobs in reach: “No jobs in reach.”

Bar and text allowed. Line is not — revenue is not a second Jobs chart.

**Revenue** is a sentence on this box, per currency, over the dashboard period, using the same “earned statuses” multi as `Revenue` today. The control that picks those statuses lives on this box. Left empty, the sentence is “Choose which of your job statuses count as earned.” A saved `jobs.revenue` statuses list in the old `dashboard.widgets` preference may be copied once onto this glance’s options so nobody re-ticks them; after that `dashboard.widgets` is ignored.

If any reachable job has a future `scheduled_for`, a sentence under the pie says how many. No list of jobs. Zero upcoming is omitted rather than “0 coming up” beside a pie that already has work on it.

### Inspection — bar (snapshot)

Three bars: **In progress** (`InspectionRun` in reach), **Submitted** (reports with `archived_at` null), **Archived** (reports with `archived_at` set). Reach is whatever the Inspection tab already uses (named-inspection grants ∩ view). Empty: “No inspections in reach.”

Pie and text allowed.

### Marketing — timeline, sentence until compose

STATUS records compose/publish as not built. Until a `social_posts` (or newsletter) row can exist, this glance is sentence-only: Marketing will show scheduled and published posts here once compose is live. No switcher.

When compose ships, this glance becomes a timeline of **our** posts (`scheduled_for` in the period, in the view). That change belongs to the Marketing plan, not a second dashboard project. The glance is declared now so the box exists.

### Web Management, Phone, Inventory, Services — coming soon

One sentence each, matching what the tab already says those modules will be. No switcher. `inventory.view` is enough to see the Inventory box.

### Fleet Management — pie (snapshot)

Slices are `VehicleStatus`: active, in shop, out of service, sold, returned. Visibility is `VehicleVisibility` (General, organization-owned, no upward authority). Empty: “No vehicles in reach.” Empty-at-zero slices stay once any vehicle exists.

Bar and text allowed. Fleet money stays off this box; `fleet_management.financials` is a vehicle pane, not a dashboard pie.

### Email — bar (snapshot)

One bar per **readable** mailbox in the current view (`MailboxAccess::readable` ∩ in view). The value is the provider’s **mailbox-level** unread count (Gmail profile and equivalents), not a listing of messages and not a table of our own.

- Tab, no inbox grant: sentence, “No inbox is assigned to your role.”
- Inbox connected but the provider cannot be asked (broken, unusable, IMAP not on this box): that inbox is named in a sentence; the others still draw.
- Do not cache unread in MySQL. A short request-scoped read is fine. A HeaderCache of one page is not an unread total and must not be treated as one.
- Do not add a message mirror. The owner’s line still stands: we do not store thousands of emails.

Pie (few inboxes) and text allowed. Period ignored.

This glance **reads** through `MailboxAccess` / the existing reader interface. It does not change connect, grants, or credentials. If a mailbox-level unread method is missing on a reader, add that read — not a new mail stack.

### Automations — healthy vs failed (snapshot)

Two slices: succeeded vs failed among the organization’s **last 100 runs** (not every run the organization has ever had). Waiting events **now** are a sentence on the same box. Idle (no runs) is a sentence, not 0/0 as health. Period ignored — this is whether the engine is working, not how busy this month was.

`automations.view` is the whole gate, as today: automations are organization-scoped, not franchise-filtered.

Pie (default), bar, text.

---

## 11. Wire and preferences

Chart glances send `slices` (pie/bar) and/or `series` (line/timeline). Text and empty states send `sentences` or `sentence`. The client never adds the numbers up.

`GET /api/v1/dashboard`

```
{
  "period": "month",
  "period_starts_on": "2026-08-01T00:00:00-04:00",
  "period_ends_on": "2026-08-24T16:00:00-04:00",
  "glances": [
    {
      "key": "hr",
      "label": "Human Resources",
      "presentation": "bar",
      "allowed_presentations": ["bar", "pie", "text"],
      "snapshot": true,
      "data": {
        "slices": [
          { "label": "Onboardings in flight", "total": 2 },
          { "label": "Invitations stalled", "total": 1 }
        ],
        "sentences": [
          "8 people on the books.",
          "2 onboardings in flight.",
          "1 invitation has stalled."
        ],
        "empty": false
      }
    },
    {
      "key": "customers",
      "label": "Customers",
      "presentation": "line",
      "allowed_presentations": ["line", "bar", "text"],
      "snapshot": false,
      "data": {
        "series": [
          {
            "label": "Added",
            "points": [
              { "at": "2026-08-01", "value": 0 },
              { "at": "2026-08-08", "value": 2 }
            ]
          }
        ],
        "sentences": [ "2 new customers this month." ],
        "empty": false
      }
    },
    {
      "key": "inventory",
      "label": "Inventory",
      "presentation": "text",
      "allowed_presentations": [],
      "coming_soon": true,
      "data": { "sentence": "Inventory is where the company will keep what it has on the shelf … The register is not here yet." }
    }
  ]
}
```

`PUT /api/v1/dashboard/layout`

```
{
  "period": "week",
  "glances": [
    { "key": "jobs", "presentation": "pie", "options": { "earned_statuses": ["Completed"] } },
    { "key": "hr", "presentation": "text" }
  ]
}
```

`surface` if sent is ignored (an old HR-tab client that still posts it must not 422 on the same deploy). Period is optional on a save that only reorders. Presentations not in `allowed_presentations` are dropped to the default (the `asDeclared` rule). Coming-soon keys in the list are kept for order and ignored for presentation.

Stored under the user (§2.1 category 6), not audited:

| Key | What |
|---|---|
| `dashboard.period` | `week` \| `month` \| `quarter` \| `year` |
| `dashboard.glances` | ordered `{key, presentation, options?}` |

`dashboard.widgets` is ignored on read. One optional copy of `jobs.revenue`’s `statuses` onto the Jobs glance options, then never read again. `dashboard.order` is ignored.

`GET /api/v1/hr/dashboard` stays as a route so the HR chip does not download every module. It returns **one** glance — the same object the main payload would include for `hr`. Same class, same `resolve()`, same stored presentation.

`GET /api/v1/dashboard/catalogue` is removed once the client no longer calls it.

The client:

- Drops “Choose panels” and the empty-state “Nothing chosen yet”.
- Header: period chips, refresh. No tune icon.
- `DashboardGrid` as it is (equal-height rows, drag / keyboard / touch). Cards are not links.
- A chart widget consumes `slices` / `series` from the payload. **Dart does not aggregate.** No chart library is mandated; pick the smallest one that draws pie, bar, line, and a timeline of points, or a custom painter. Theme colours, not a palette invented here.
- Coming soon: `DashboardNote` + badge, no switcher.
- `dashboardPanelIcon` already falls back by module prefix; glance keys **are** module prefixes, so the fallback is the icon.

---

## 12. HR Dashboard chip

HR plan: “Dashboard | Headcount by franchise, onboarding in flight, stalled invitations, expiring certifications, starters and leavers.”

Those five are the **text** of the one HR box. The chart is in-flight vs stalled, because that is the operational pair (paperwork moving vs invitations dying). Headcount, starters/leavers, and certifications are always in `sentences`, so they still show under the bar; text mode is those sentences without the chart.

The chip is not a second dashboard. It is the HR glance, full width, no reorder (one card). Period chips are on this chip too, writing the same `dashboard.period`, so the window is reachable without leaving Human Resources.

`HrDashboardController::PANELS` of five keys is replaced by the one glance.

---

## 13. What this does to today’s widgets

| Today | After |
|---|---|
| Pick any subset of widgets | Every reachable module, none hideable |
| Per-widget `days` 7/30/90/365 | One calendar period |
| `jobs.upcoming` list | A count-sentence on Jobs when something is scheduled; not a list |
| Five HR widgets on main + five on HR tab | One HR box, two surfaces |
| `ON_BY_DEFAULT` five widgets | Sidebar-order glances |
| Catalogue checkbox sheet | Gone |

The standing rule “a new number is a class, not a client release” still holds. A **new module** is a glance class. A new slice on an existing module is an edit to that glance. Adding a widget that is not a module is how we got a picker; do not add one.

---

## 14. Testing

Must hold:

- A role with `inventory.view` and nothing else gets one glance, `inventory`, coming soon.
- A role with `jobs.view` does not see `hr`, `customers`, or Settings/Organization.
- Kill-switch off for a module drops the glance; coming back appends if the saved order never named it, restores place if it did.
- Save omitting a reachable key returns it appended, 200, not 422.
- Snapshot glances return the same `slices` for week and year.
- Period-bound glances change with `dashboard.period`.
- `week_starts_on` moves the week bound.
- Jobs pie uses the tenant’s status vocabulary, including empty statuses once a job exists, and a sentence when none exist.
- Revenue never sums USD and CAD. Unconfigured earned statuses is a sentence, not a guessed total.
- HR glance `resolve()` on `/dashboard` and `/hr/dashboard` is the same payload.
- Franchise reach: a technician’s Jobs / Fleet / Inspection / Customers counts are not the organization’s.
- Email with no grant: the sentence, no provider call. Email with a grant: one bar per readable mailbox; unread is not stored.
- Client: no “Choose panels”; coming-soon has no switcher; tap does not change the shell destination; empty is a sentence; drag persists `dashboard.glances`.
- Old `dashboard.widgets` in a fixture does not 500.

---

## 15. Out of scope

- Building Inventory, Services, Phone, Web Management, or Marketing compose so those boxes can grow charts.
- A `jobs.financials` permission, or Fleet money on the dashboard.
- Cancel-onboarding (already shipped). Mailbox connect / grants / IMAP.
- Per-box periods. Per-organization dashboard layouts. Hiding boxes. Navigating from a box.
- Masonry, double-width cards, or a second dashboard surface besides HR’s chip.

---

## 16. Success

An administrator sees one box per tab they can open, in sidebar order, chart first. A technician with Jobs and Inventory sees those two — Inventory as a sentence. Dragging Jobs below Customers survives a reload. There is no way to take Customers off. The HR chip shows the same HR box. Week/Month/Quarter/Year is one control. Empty is a sentence. Tapping a box leaves you on Dashboard.
)
