# 0036 — Clock In session to UI/UX: the Work schedule card was built straight to Jose's reference

**Date:** 2026-09-08, evening. **From:** the Clock In session (`local_8b413ba1-91de-47c8-8a59-92176a3599f7`).
**To:** whoever holds UI/UX. There was no UI/UX row in `docs/SESSIONS.md` this evening, so this is the address.

## What happened

Jose asked for a per-person work schedule, a shift-end reminder and a lock screen,
then left for the day with: *"finish END TO END since I won't be here and if you
have a question do as you believe or recommend, don't wait for me."*

The plan (`docs/superpowers/plans/2026-09-08-work-schedule-and-lock-screen.md`,
Task 12) said the **Your account › Work schedule** card goes through your mockup
flow. With Jose gone and a screenshot from him already in hand, I built it to that
screenshot rather than block the release: Mon–Sun rows, a green *in* chip with a
login arrow, a coral *out* chip with a logout arrow, *Day off* in italics, and an
**Edit** action only when the organization's *People can edit their own schedule*
switch is on. It is `_WorkScheduleCard` at the bottom of
`client/lib/screens/profile_screen.dart`, on branch `session/clock-in`.

Two other new surfaces you may want to look at, same branch:

- `client/lib/screens/lock_screen.dart` — the full-screen Clock In that replaces the
  shell while a locked person is not clocked in. Plain: mark, live clock, name,
  today's window, one Clock In button, Sign out.
- `client/lib/widgets/shift_reminder.dart` — the barrier dialog with the countdown,
  Extend N min and Clock Out. Jose asked for a modal here explicitly; the spec
  records that override.

Colours are the named `EzBiziTheme.clockIn` / `clockOut` pair; no hex at the call
sites. Copy is `ui_strings` keys under `time_clock.*`. The clock on the lock screen
uses `headlineMedium` because `displayMedium` is not filled in `theme.dart` and the
theme-conventions test refuses an empty slot.

## What is yours

Re-skin any of the three freely. The tests assert **content**, not geometry
(`profile_schedule_card_test.dart`, `lock_screen_test.dart`,
`shift_reminder_test.dart`), so a layout change should not break them. If you want
the three-option HTML round with Jose after all, the reference screenshot is in the
Clock In session's chat on 2026-09-08 and the spec is
`docs/superpowers/specs/2026-09-08-work-schedule-and-lock-screen-design.md` §10.
