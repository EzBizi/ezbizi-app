# Work Hours / Clock In: review, 2026-11-02

Measured on production with read-only queries on the box (release 20261030222016-27b215107bb2, A1-Group organization). I made no writes to production.

## 1. Working

- **Clock in/out**:
  - 634 shifts from 68 people in the last 14 days.
  - 37 shifts are open now. None has a missing end, none is past its end, and nobody has two open shifts.
- **End-of-day sweep**: in the last 14 days, 443 shifts were ended by the schedule, 143 by the person and 11 by a correction.
- **5-minute merge**: no re-punches under 5 minutes since 10-14, and 3 merges are on the audit.
- **Corrections**: 39 approved and 6 denied, none pending.
- **Daily approval**:
  - It is switched on for Ohio only, with the roles setting set to Technician.
  - The approver at Ohio is "shneor Cohen" (Local Manager, new account).
- **Geofence**: off by default. There have been 0 blocks, and no tenant has switched it on.
- **Weekly hours report**: the rule is active, and its next run is 2026-11-09 06:00.
- **Settings**:
  - On: enabled, lock screen, self schedule.
  - Values: max hours 14, extension 5 minutes, week starts Monday.
- **Failed queue jobs**: none from Time Clock or Work Hours in 14 days.

## 2. Broken

1. **A re-punch on a day off restarted the full 14-hour limit.**
   - Evidence: Romnick Sator, Sunday 2026-10-25. The shift closed at 18:00:01 and he clocked back in at 18:00:03. The merged shift ran to 08:00:03 Monday, 20 hours in all.
   - Why: when a shift is continued, it took a new end as if it were a new clock-in, and a day off has no schedule window, so that end was the full limit.
   - **Fix**: continuing a shift that the clock itself closed now buys one extension (`app/Support/TimeClock/TimeClock.php`). The new test failed on the old code and passes with the fix. Commit 28077ae57 on session/clock-in.
   - Romnick's 10-25 hours are not corrected. That correction needs HR or Jose.
2. **Daily approval is unused.**
   - Evidence: 19 days from 10-21 to 10-30 are waiting, and 0 have been approved.
   - The old Shneor account (01a0eddf…) was removed today, and its notifications were deleted with it, so I can't tell whether the morning reminders reached him.
   - The new account holds `work_hours.approve` at Ohio, so the reminder will go to him from tomorrow.

## 3. Cohesiveness

- No new differences found in this pass. Screen changes go through UI-UX's queue as usual.

## 4. Suggestions

1. Give the approval reminder a push and email, not only the bell. Shneor's old account received about 600 notifications a day, so a bell entry gets buried. Payoff: approvals actually happen.
2. Add a "longer than X hours" flag on Work Hours › General. Payoff: a 20-hour row gets caught the same week.
3. Keep a person's waiting approval days when their approver's account is removed. Payoff: no orphaned approval backlog.

## 5. Needs Jose

- Should Romnick Sator's 2026-10-25 shift be corrected to end at 18:00?
- Should Shneor (new account) approve the 19 waiting Ohio days, or should they be cleared?
