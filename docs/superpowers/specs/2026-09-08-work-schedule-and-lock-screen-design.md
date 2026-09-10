# Work schedule, shift-end reminder and lock screen — design

**Date:** 2026-09-08
**Status:** approved by Jose in session 2026-09-08 (EzBizi - Clock In); not yet built
**Extends:** `docs/superpowers/specs/2026-08-25-clock-in-design.md` (Clock in v1, live since `v0.0.0094`)
**Product:** EzBizi customer app (`app.ezbizi.com`), Windows and macOS desktop builds

Clock in v1 is a punch with no limits. This adds the three things A1 Tools has
and EzBizi does not: a per-person work schedule, a reminder before the scheduled
end with the choice to extend or finish, and a lock screen that takes over when
the person is not clocked in on a working day. A1 Tools was read for what it
does (`H:\A1Chimney\a1_tools\lib\features\timeclock`); nothing is copied.

---

## 1. What it is

- **A work schedule per person.** One start and end per weekday, or a day off.
  Set by Human Resources, or by the person when the organization allows it.
- **A reminder.** A few minutes before the scheduled end, a dialog with a live
  countdown: **Extend** or **Clock out**. When the countdown reaches zero the
  server clocks the person out.
- **A lock screen.** When a locked person is not clocked in on a working day,
  the app is replaced by a full-screen Clock In. On Windows and macOS the window
  also goes fullscreen, stays on top and refuses to close, so the desktop is
  covered until they clock in.

## 2. What it is not (v1)

- Not manager approval of an extension. Extend is granted on the spot.
- Not breaks, lunch, overtime, rounding, or hour totals.
- Not editing, adding or deleting past shifts.
- Not a "who is in right now" board.
- Not GPS, geofence, or a device check.
- Not a lock on Android or iOS. Phones get the reminder only, as A1 Tools does
  since its 2026-07-17 mobile lock removal.
- Not an OS-level block. A fullscreen always-on-top window cannot stop
  Ctrl-Alt-Del, Task Manager, or a second monitor's taskbar. Same ceiling as
  A1 Tools; the spec says so rather than promising otherwise.
- Not the device clock. The server's `now()` remains the only time.

## 3. Settled decisions

| # | Decision |
|---|---|
| 1 | **Server decides, client shows.** The server stamps each shift's scheduled end at clock in, moves it on extend, and a per-minute command closes any open shift past its end. The client arms a local timer from the end time it is told and renders the reminder and the lock. A closed laptop or a killed tab still clocks out on time. |
| 2 | Schedule is a nullable JSON column `work_schedule` on `users`, weekday shape identical to the `ops.business_hours` setting, plus a **timezone snapshot** taken from the editor's current view when saved. A person's day is evaluated in that zone; the command needs no "view". |
| 3 | Editors: HR Records (`hr.edit`) always; **Your account** only when the organization setting *People can edit their own schedule* is on. Your account always shows it read-only. |
| 4 | Clock In is **never refused** by the schedule. A shift on a day off, or started more than 30 minutes before the scheduled start, is stamped `outside_schedule` and tagged on the day list. |
| 5 | Reminder lead is an organization setting, default **5 minutes**. Extension length is an organization setting, default **30 minutes**, unlimited repeats. Each Extend moves the end by that length and the reminder returns before the new end. |
| 6 | Auto clock-out is an ordinary clock out with `ended_by = schedule`: audited, raised as `time_clock.clocked_out`, a normal row on the day list with an **Auto** tag. |
| 7 | Lock has three levers, all required: organization setting *Lock screen* (off by default), role action `time_clock.lock_screen`, and a per-person **exempt** flag on the user. |
| 8 | Lock rule: org lock on, role holds `time_clock.lock_screen`, not exempt, **today has a working window** in their schedule, and no open shift. A day off never locks. **No personal schedule falls back to the Business hours of the person's own place** (first assignment: franchise, company, then organization, with that place's timezone) — added 2026-09-09 after the lock did nothing for anybody until HR typed a personal schedule. A personal schedule always wins. |
| 9 | The lock is computed server side and sent on `/me`; the client never derives it from permissions. |
| 10 | The reminder is a **barrier dialog** with a countdown. This overrides the "no modal pop-ups" house rule on Jose's explicit instruction of 2026-09-08. The lock screen is a full-screen route, not a dialog. |
| 11 | `window_manager` is the desktop dependency. Windows is the verified target; macOS ships best-effort through the same package. Web covers the app only. |
| 12 | A `GET /time-clock/me` poll every 60 seconds while the organization has the clock on, so an exemption, schedule change, or server-side close reaches an open app within a minute. |

## 4. Settings and grants

All organization-only, Human Resources group, beside the existing *Clock in* switch:

| Key | Label | Type | Default |
|---|---|---|---|
| `time_clock.lock_screen` | Lock screen | bool | off |
| `time_clock.warning_minutes` | Minutes of warning before a shift ends | int, 1–60 | 5 |
| `time_clock.extension_minutes` | Extension length, minutes | int, 5–240 | 30 |
| `time_clock.self_schedule` | People can edit their own schedule | bool | off |

Help text on *Lock screen*: "When on, people whose role includes Lock screen see
only a Clock In screen while they are not clocked in on a working day. Mark
somebody exempt on Human Resources › Records."

Module `time_clock` gains action `lock_screen`, label **Lock screen**. The key is `lock_screen` so the roles label reads "Lock screen" without a label override. The roles editor
invariant (any action implies `view`) is right here: a locked person must be
able to punch. `lock` does **not** imply `hr.view`.

Per-person exemption: boolean `lock_screen_exempt` on `users`, default false.
Toggled from the HR Records person menu ("Exempt from lock screen" / "Lock
screen applies"), needs `hr.edit`, audited as `person.lock_screen_exempt`.

## 5. Records

### 5.1 `users`

| Column | Notes |
|---|---|
| `work_schedule` | JSON, nullable. `{ "timezone": "Etc/GMT+5", "days": { "monday": {"start": "08:00", "end": "17:00"}, …, "saturday": null, "sunday": null } }`. `null` day = day off. Times are `HH:MM` wall clock in `timezone`. |
| `lock_screen_exempt` | bool, default false. |

`work_schedule` is HR data, not a preference: somebody else sets it and Human
Resources reads it. It does not go in `preferences`.

Validation on save: every weekday key present; a day is `null` or `{start, end}`
with `end` strictly after `start` on the same day; overnight windows are refused
in v1 ("A shift that crosses midnight is not supported yet."). `timezone` is
written by the server from the editor's view, never accepted from the client.

Both columns are declared on `User::personalDataHeld()`: `work_schedule` is
ACTIVITY, erased to null; `lock_screen_exempt` is dropped with the account.

### 5.2 `time_clock_shifts`

| Column | Notes |
|---|---|
| `scheduled_end_at` | `datetime` UTC, nullable. Null when the day has no window. |
| `extensions` | unsigned tinyint, default 0. |
| `outside_schedule` | bool, default false. |
| `ended_by` | string(16), nullable. `person`, `schedule`, `automation`. Null while open. |

No existing column changes. The `open_slot` unique is untouched.

**Migrations are new files** (never edit a deployed one): one for `users`, one
for `time_clock_shifts`, one for the `ui_strings` rows.

## 6. Clock in and the stamps

`TimeClock::clockIn` keeps its gates and adds, inside the same transaction:

1. Resolve today in the schedule's zone. No schedule, or the day is `null`:
   `scheduled_end_at = null`, `outside_schedule = true`.
2. Otherwise `scheduled_end_at` = today's `end` in that zone, as UTC.
   `outside_schedule = now() < start − 30 min`.
3. A clock-in after the scheduled end on a working day is **not** outside
   schedule (they are working late), and gets `scheduled_end_at = null`: no
   reminder, no auto close, the top-bar chip carries them until they punch out.

`TimeClock::clockOut` gains `endedBy` (default `person`; `automation` when
`$causedBy` is set; `schedule` from the command).

## 7. Extend

`POST /api/v1/time-clock/extend`, empty body, response `{ data: { time_clock } }`.

- Needs an open shift with a non-null `scheduled_end_at`; otherwise
  "You are not on a scheduled shift."
- `scheduled_end_at += extension_minutes`, `extensions += 1`.
- Audited `time_clock.extended`. No new automation trigger in v1.
- Allowed after the grant or org switch is removed, same as clock out: only an
  open scheduled shift is required.

## 8. Auto clock-out

`ezbizi:close-ended-shifts`, every minute, `withoutOverlapping(5)`, registered in
`routes/console.php` beside the social publisher. For each open shift with
`scheduled_end_at <= now()` it calls `TimeClock::clockOut($user, endedBy: 'schedule')`.
The command runs as the system: no view, no permission check (the row is the
authority), same audit and event as a person's punch.

There is **no midnight sweep**. A shift with no scheduled end stays open until
somebody closes it, as in v1. Adding one later is a second `where` in this command.

## 9. `/me` and the poll

The `time_clock` block grows. Existing keys are unchanged.

```
time_clock: {
  organization_enabled, can_clock, show_button, open,   // v1, unchanged
  lock: bool,                    // this session must show the lock screen now (§3.8)
  scheduled_end_at: ISO Z | null,// effective end of the open shift, after extensions
  warning_minutes: int,
  extension_minutes: int,
  today: null | { start: "08:00", end: "17:00" }   // wall clock in the schedule zone
}
```

`GET /api/v1/time-clock/me` returns the same block. Punch and extend responses
return it too. The client writes every one of them onto the session, as v1 does.

## 10. Client

### 10.1 Reminder

A `ShiftReminder` owner in the shell (beside `_punch`) arms one `Timer` for
`scheduled_end_at − warning_minutes` whenever the block changes, and cancels it
when `open` is null. At fire time:

- Desktop: `windowManager.show()`, `focus()`, `restore()` if minimized.
- A `showDialog` with `barrierDismissible: false`: "Your shift ends at 5:00 PM",
  a live `m:ss` countdown to `scheduled_end_at`, **Extend 30 min** (label
  carries the org's length) and **Clock out**.
- Extend posts `/time-clock/extend`, applies the block, closes the dialog; the
  timer re-arms from the new end.
- Clock out posts `/time-clock/clock-out` as the chip does.
- At zero the dialog closes, waits two seconds, fetches `/time-clock/me`, and
  applies it. The server has closed the shift; if the poll says otherwise (clock
  skew, command late) the dialog does not reopen; the 60-second poll catches up.
- The dialog is shown once per `scheduled_end_at` value. Extending changes the
  value, so it shows again before the new end. This is the "prompt on the edge,
  not the level" latch A1 Tools had to add after its clock-out loop.

Mobile shows the same dialog; there is no window to raise.

### 10.2 Lock screen

A `LockScreen` route replaces the shell when `me.timeClock.lock` is true, gated
exactly where the two-factor enrolment gate sits in `main.dart`. It shows the
EzBizi mark, a live clock in the schedule zone, the person's name, today's
window, one **Clock In** button, and **Sign out**. Clock In posts, applies the
block, and the gate falls away because `lock` is now false. A refused punch
shows the server sentence under the button.

Desktop (Windows, macOS): on enter every escape the window manager offers is
shut — `setResizable(false)`, `setMinimizable(false)`, `setMaximizable(false)`,
`setClosable(false)`, `setPreventClose(true)`, `setTitleBarStyle(hidden)`,
`setAlwaysOnTop(true)`, `setFullScreen(true)`, `focus()` — and fullscreen is READ
BACK and retried, because on 0.0.0173 it silently did not apply and the window
kept its title bar. A `WindowListener` answers what a flag cannot: minimize
restores, close refocuses, leaving fullscreen re-enters it. Every one of those
is given back on release, in reverse, and the listener is disarmed FIRST; on minimize, restore and focus; on window
restore after sleep, re-assert fullscreen and topmost. On leave release in this
order: always-on-top, prevent-close, fullscreen. Every release call is
try/caught on its own so one failure cannot strand the others (A1 Tools shipped
that bug). Web: nothing but the route.

Sign out from the lock screen releases the window first, then signs out.

### 10.3 Schedule editors

- **HR Records** person menu: "Work schedule" opens a side panel with the
  weekday grid editor the settings screen already uses for hours, and Save
  `PATCH /hr/people/{user}/schedule`. Also "Exempt from lock screen" toggle,
  `PATCH /hr/people/{user}` with `lock_screen_exempt`.
- **Your account**: a "Work schedule" card in the layout Jose supplied
  (Mon–Sun rows, green in / red out chips, *Day off*), read-only, with Edit only
  when `time_clock.self_schedule` is on. Save `PATCH /me/schedule`.
- Layout and the card go through the UI/UX session's mockup flow
  (`docs/MODULE-OWNERS.md`); the screenshot is the reference.

### 10.4 Day list

Rows gain tags: **Auto** when `ended_by = schedule`, **Outside schedule**, and
**+N** extensions. Payload adds `ended_by`, `outside_schedule`, `extensions`.

### 10.5 Platform gates

`window_manager` is called only behind `!kIsWeb && (Platform.isWindows ||
Platform.isMacOS)` through the existing `client_platform` split, so
`flutter build web` stays the dart:io gate. Android and iOS never enter the
lock route regardless of `lock` (the server sends it; the client ignores it
there and says so in a comment).

## 11. Copy and i18n

`ui_strings` rows (en + es) for: the reminder title and sentence, Extend
{minutes} min, Clock out, the lock screen sentence ("Clock in to start your
day"), Sign out, Auto, Outside schedule, Day off, Work schedule, the two
refusals in §5.1 and §7, and the exempt toggle labels.

## 12. Tests that pin the design

Backend (PHPUnit, MySQL):

- Schedule save validates shape, refuses overnight, snapshots the editor's view zone, ignores a client `timezone`.
- `PATCH /me/schedule` refused when `time_clock.self_schedule` is off; allowed when on.
- Clock in stamps `scheduled_end_at` in the schedule zone across a UTC-5 midnight; a day off stamps null and outside; 31 minutes early is outside, 29 is not; after end is not outside and stamps null.
- Extend moves the end by the org length, counts, audits, refuses without a scheduled shift, works after the grant is removed.
- `close-ended-shifts` closes only past-due open shifts, stamps `ended_by = schedule`, audits, raises `clocked_out`, and leaves unscheduled and future shifts alone.
- Lock truth table: each of the four gates false ⇒ `lock` false; all true and not clocked in ⇒ true; open shift ⇒ false; day off ⇒ false.
- `time_clock.lock_screen` does not imply `hr.view`.
- Both new user columns are on the personal-data inventory.

Client (widget tests):

- Reminder fires at end − warning and not one minute earlier; shows the countdown; Extend posts and re-arms; Clock out posts.
- Reminder shows once per end value.
- Lock route shown when `lock` is true, gone after a successful Clock In, error sentence on refusal.
- Window calls are made on the desktop platform stub and never on web.
- HR tab renders the three tags.

## 13. Files this will touch (boundary for the plan)

- `app/Providers/ModuleServiceProvider.php` — `lock` action on `time_clock`
- `app/Support/Settings/SettingRegistry.php` — four settings, HR group
- `app/Models/User.php`, `app/Models/TimeClockShift.php` + three migrations
- `app/Support/TimeClock/TimeClock.php` — stamps, extend, `endedBy`, lock rule, `today`
- `app/Http/Controllers/Api/TimeClockController.php` — `extend`, `me`
- `app/Http/Controllers/Api/HrPeopleController.php` — schedule and exempt writes
- `app/Console/Commands/CloseEndedShifts.php`, `routes/console.php`, `routes/api.php`
- `client/pubspec.yaml` — `window_manager`
- `client/lib/main.dart` — reminder owner, lock gate
- `client/lib/screens/lock_screen.dart` (new), `client/lib/widgets/shift_reminder.dart` (new)
- `client/lib/core/session.dart` — `TimeClockStatus` fields
- `client/lib/screens/hr/records_tab.dart`, `client/lib/screens/hr/time_clock_tab.dart`, `client/lib/screens/profile_screen.dart`
- Not touched: Email, Phone, Inventory, Fleet, Inspection, Marketing, `GlanceRegistry`, `moduleScreens`.
