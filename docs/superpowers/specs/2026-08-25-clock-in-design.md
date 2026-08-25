# Clock in — design

**Date:** 2026-08-25
**Status:** approved in session 2026-08-25; not yet built
**Product:** EzBizi customer app (`app.ezbizi.com`)

This is an HR extension, not a new sidebar tab. It does not copy A1 Tools timekeeping. A1 Tools may be read for what a field team punches; the shape here is EzBizi’s: organization setting, role grant, `/me` chrome, Refusal sentences, automations declared on day one.

---

## 1. What it is

A punch clock. Some organizations use it; some do not. When they do, some roles punch and some do not.

Two surfaces:

- A **Clock in / Clock out** control in the top bar, immediately before the light/dark switch, on every screen.
- A **Clock in–out** chip on Human Resources: one day of shifts for people in reach.

---

## 2. What it is not (v1)

- Not a sidebar module. No Clock in tab in the rail, no dashboard glance.
- Not an action under Human Resources. Any HR action implies `hr.view`; a technician who punches must not see people, invitations, or contracts.
- Not GPS, geofence, photos, or a device check.
- Not a requirement that blocks the rest of the app until they punch.
- Not overtime, breaks, lunch, or rounding.
- Not correcting, deleting, adding, or exporting times. The list does not open a panel.
- Not punching on someone else’s behalf.
- Not a personal history for people who cannot open HR.
- Not live push into an already-open other tab. A new session, a reload, or a place change refetches `/me` and is enough.
- Not a “who is in right now” board. Overnight still-open shifts appear on the day they started, not on every later day they spill into.
- Not the browser’s timezone, the phone’s timezone, or a timestamp the client sends.

---

## 3. Settled decisions

| # | Decision |
|---|---|
| 1 | Organization master switch, **off by default**, organization-only. Settings group **Human Resources**. Key `time_clock.enabled`. Label **Clock in**. |
| 2 | Who punches is its own grant: module `time_clock`, label **Clock in**, action `view` only (`time_clock.view`). Registered immediately after `hr`. **Not** an `hr.*` action. |
| 3 | Who sees the day list is existing `hr.view`. The chip is absent when the org switch is off. |
| 4 | A shift is one row: clock in opens it, clock out fills `clocked_out_at` and duration. At most one open shift per person. |
| 5 | A shift belongs to the **person**, not a place. No franchise stamped on the row. |
| 6 | Stored values are **UTC instants**. The server’s clock. The client never submits a time. |
| 7 | One clock per screen: the **current view’s** resolved `timezone` setting. The page names it (`Times in UTC−5`). Switching place can move a borderline punch onto the adjacent calendar day; the instant does not move. |
| 8 | The HR tab is **one day**, opening on **today in the view timezone**, not the device’s today. Overnight sits on the day the shift **started** in that zone. |
| 9 | People with no shift that day are omitted. Two shifts the same day are two rows. |
| 10 | If they are already in and the org switch or grant is taken away, **Clock out stays** until they punch out. New clock-ins are refused. Open rows are not auto-closed. |
| 11 | Green / red are named on the theme (`clockIn` / `clockOut`). Label and colour together. One tap, no confirm, no duration on the button. |
| 12 | Sync is the server row plus `time_clock` on `/me`. Phone, browser, reload, place change all read that. |
| 13 | Automations: `time_clock.clocked_in`, `time_clock.clocked_out`. No automation action that punches. |

---

## 4. Gates

Four gates still apply. Clock in is not a billed sidecar in v1; availability fails **open** like every new module (no flag row). The org setting is tenant configuration (§2.1 category 5), not a release flag and not an entitlement.

**Button** shows when `time_clock.enabled` is on **and** (`time_clock.view` **or** an open shift exists).

**Chip** shows when `time_clock.enabled` is on **and** `hr.view`.

**Clock in** (write): org on, `time_clock.view`, no open shift, subscription not read-only.

**Clock out** (write): an open shift for this person. Org-off and grant-lost still allowed. Subscription read-only still refuses writes.

Administrator holds `time_clock.view` with every other key. They only see the button when the organization has turned Clock in on.

A new custom role holds nothing until Clock in is ticked. Ticking Clock in does **not** grant `hr.view`.

---

## 5. Why this is not `hr.clock`

The roles editor’s invariant: any action on a module implies that module’s `view`. Human Resources’ `view` **is** the tab. Putting Clock in on `hr` would open Records, Onboarding, Invitations, and Contracts for every technician who punches.

Nested grants under HR (inboxes, named inspections) are taken away when the tab is unticked. A puncher’s grant cannot ride on that.

So `time_clock` is its own module, listed on Roles directly under Human Resources. It is **not** drawn in the sidebar: no entry in `moduleScreens`, not added to `GlanceRegistry::ORDER`. The top-bar button is the surface. The Roles row looks like Dashboard (one switch, View underneath) and is labeled **Clock in**.

---

## 6. Records

Table `time_clock_shifts`. UUIDv7. Soft-delete is not used; a punch is a legal artefact.

| Column | Notes |
|---|---|
| `id` | UUIDv7 |
| `organization_id` | Tenant isolation. Indexed. |
| `user_id` | The person. Indexed. |
| `clocked_in_at` | `datetime`, UTC. Required. |
| `clocked_out_at` | `datetime`, UTC, nullable. Null = still in. |
| `created_at` / `updated_at` | Ordinary. |

Partial unique index: one row per `user_id` where `clocked_out_at` is null.

Out must be strictly after in. A second clock-in while open is refused: “You are already clocked in.” Clock-out with no open shift: “You are not clocked in.”

The model implements `HoldsPersonalData`. Purpose: when this person started and finished work. Erasure **keeps** the instants (they are the history) and **keeps** `user_id` as a foreign key — the purge anonymises the account, the way `EmploymentEvent` does. Do not invent an out time. Do not drop the row. Retention follows the organization policy.

Audited. Each punch is an act.

---

## 7. Timezones

EzBizi timezones are whole-hour UTC offsets, no daylight saving (`timezone` setting, franchise override). Clock in does not invent a second system.

- **Write:** `now()` on the server, stored UTC.
- **Read:** convert with `SettingsResolver` for the current view (`ViewScope`’s settings scope).
- **“Today” and `?on=`:** a calendar date in that view zone. The list endpoint **returns** the date and the zone it used. The date picker is driven from that, never from `DateTime.now()` on the device.
- **Which rows:** `clocked_in_at` falls on that date in the view zone. A shift that started yesterday and is still open does **not** appear on today’s list; it appears on the day it started. (v1 has no “still in from yesterday” strip. The top-bar button still shows Clock out.)
- **Duration:** `clocked_out_at - clocked_in_at` in seconds. Zone-independent.
- **Display strings:** the API formats in and out with the view’s `time_format`. The client does not convert ISO itself for this list.

Device timezone is never read. A client-supplied timestamp is never accepted.

v1 does not snapshot “their” zone on the row. We did not stamp a place, so “local” would mean the device. A later column can snapshot the resolved zone at punch without changing the instant.

---

## 8. `/me` and the button

`GET /me` grows a `time_clock` block so the chrome is right on the first frame (same reason theme and two-factor ride here):

```
time_clock: {
  organization_enabled: bool,
  can_clock: bool,
  show_button: bool,
  open: null | { id, clocked_in_at }
}
```

`can_clock` is `time_clock.view`. `show_button` is the button rule in §4. `open` is the open shift or null. Instants on the wire are ISO-8601 UTC.

`POST /api/v1/time-clock/clock-in`
`POST /api/v1/time-clock/clock-out`

Empty bodies. Response `{ data: { time_clock: … } }` — the same block. The client writes it onto the session immediately so this device flips without waiting for another `/me`. Other devices see it the next time they load, restore, or change place (`Session.setScope` already refetches `/me`).

In flight, the button is disabled. Failure leaves the previous state and shows the server’s sentence (chrome notice, not a modal).

Placement: AppBar `actions`, immediately **before** the theme IconButton. Compact labeled control, not icon-only. Theme colours:

- `EzBiziTheme.clockIn` — green that works on light and dark chrome
- `EzBiziTheme.clockOut` — red, same constraint

No purple. No extra hex at the call site.

375px: the control stays in the top bar; label may shorten only if the words stay (`Clock in` / `Clock out`). It does not move into a menu.

---

## 9. Human Resources chip

Sixth chip, owner order:

**Dashboard · Onboarding · Records · Invitations · Contracts · Clock in–out**

Same `TabBarLike` strip. Offstage like the others. Fetch only once shown.

`GET /api/v1/hr/time-clock?on=YYYY-MM-DD`

Omit `on` for view-local today. `hr.view` required. Org switch off: refuse with “Clock in is switched off for this organization.”

Reach is Records’ reach: people this caller can already see. A franchise manager does not see another franchise’s punches.

Payload:

```
{
  date,                  // YYYY-MM-DD, the day this page is
  timezone,              // IANA id, e.g. Etc/GMT+5
  timezone_label,        // "UTC−5"
  shifts: [
    {
      id,
      user: { id, name },
      clocked_in_at,     // ISO Z
      clocked_out_at,    // ISO Z or null
      clocked_in,        // formatted wall clock in this timezone
      clocked_out,       // formatted, or null
      duration_seconds,  // null while open
      open               // bool
    }
  ]
}
```

Sort: `clocked_in_at` descending, then name. Empty: “Nobody clocked in on this day.”

Open row: Out column says **In**; duration is an em dash. Not a colour-only state.

Date control above the list. Changing it refetches. No side panel. No row tap.

---

## 10. Automations

Declared on module `time_clock` (null subject: the events are about a person punching, and a `time_clock` prefix cannot name `User` without lying to the field picker — same limitation HR already records).

| Key | Label | When |
|---|---|---|
| `time_clock.clocked_in` | Person clocked in | After the open row is written |
| `time_clock.clocked_out` | Person clocked out | After `clocked_out_at` is set |

Raised from the same transaction as the row (`EventEmitter`). Payload is the shift after the change (ids, instants), redacted like every other event. Actor is the person who punched.

No automation **action** that clocks someone in or out. A rule that punches would invent attendance.

---

## 11. Copy and i18n

User-facing words go through `ui_strings` (en + es), not class names. Including: Clock in, Clock out, Clock in–out, the empty-day sentence, timezone caption, In, the four refusal sentences in §6 and §9.

Roles catalogue labels come from the module registry (`Clock in`, `View`), not from Dart.

---

## 12. Tests that pin the design

Backend (PHPUnit), against MySQL:

- Org off: no button flags on `/me`, clock-in refused, HR list refused, chip hidden (client).
- Org on, role without `time_clock.view`: no button; with `hr.view`, list is visible.
- Org on, `time_clock.view` without `hr.view`: button yes, HR chip no.
- Clock in opens a row; second clock-in refused; clock out closes it; next clock-in is a new row.
- Clock-out allowed after the grant or org switch is removed; clock-in is not.
- Client-supplied timestamps ignored / not in the request.
- List buckets by **view** timezone, not UTC date and not a second franchise’s zone. A punch just after midnight in UTC−5 is the previous day in UTC−6.
- Duration does not change when the viewer changes place.
- Tenant isolation: another organization’s shifts never appear.
- Reach: a franchise assignment does not see a sibling’s people.
- Both automation keys are declared **and raised**.
- `HoldsPersonalData` is implemented; inventory lists the table.
- `time_clock.enabled` is read (SettingReaders).
- `time_clock.view` does not imply `hr.view` (withImpliedView / a roles save).

Client (widget tests):

- Button absent when `show_button` is false.
- Button sits before the theme control; Clock in green / Clock out red; one tap calls the matching endpoint; in-flight ignores a second tap.
- After a successful punch, the button flips from the response without a full navigation.
- HR chip is the sixth tab only when org enabled and `hr.view`.
- Day list renders formatted clocks from the payload; empty sentence; date change refetches `on=`.
- Date picker does not use the device’s today.

---

## 13. Files this will touch (for the plan)

Not an implementation list — a boundary. Stay off Email, Phone, Inventory, Fleet, Inspection, Marketing except where `/me`, the AppBar, `HrScreen`, `ModuleServiceProvider`, `SettingRegistry`, theme, and roles catalogue already sit.

- `app/Providers/ModuleServiceProvider.php` — register `time_clock`
- `app/Support/Settings/SettingRegistry.php` — Human Resources group
- `app/Models/TimeClockShift.php` + migration
- API: clock-in/out + HR day list; `/me` block
- `client/lib/main.dart` — AppBar
- `client/lib/core/theme.dart` — `clockIn` / `clockOut`
- `client/lib/core/session.dart` — `time_clock` on `Me`
- `client/lib/screens/hr_screen.dart` + new `hr/time_clock_tab.dart`
- Roles: no special nested UI; the new module appears from the catalogue
- `GlanceRegistry` and `moduleScreens`: **do not** add `time_clock`
