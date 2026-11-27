# Scheduling — review 2026-11-02

Measured on production (0.0.0506) as Jose (jose@ezbizi.com), A-1 Chimney company view and organization view.
Probe: every Scheduling GET called through the app kernel, status and time recorded.

## 1. Working

- Areas map (`areas-map`, per-area map and outline): 200, 21–97 ms.
- Availability by ZIP, month calendar, franchises list, booking suggestion: 200, 7–223 ms.
- Board (tomorrow): 200, 69–100 ms; windows union per company in view.
- Time slots: 200; per-company ownership live (A1 Garage Doors owns 4 windows, org keeps 3).
- Helpers roster and month bookings: 200 (no helpers entered on prod yet: 0 helpers, 0 bookings).
- Locations, roster, technicians, routes, reports, suggest: 200.
- Overbooking, overbooking jobs, reschedules: 200 (slow; see 4).
- Scheduled commands registered: build-zip-map (5 min), warn-underfilled (hourly), apply-scheduled-changes (hourly), warn-overbooking (daily 12:00).
- Failed queue jobs touching Scheduling, last 7 days: 0. No Scheduling errors in the last two days' logs.
- Online booking "offer today": off for A-1 Chimney (all 10 branches), on elsewhere.

## 2. Broken

- **Move appointment offered on a passed opening.** The one reschedule offer on prod (Charles O'Neil, JOB-54950, opening 2026-10-29) was still movable on 2026-11-02. The job itself stayed on 2026-11-02 08:00.
  - **Why:** `can_move` checked only `moved_at`, not the date.
  - **Fixed:** a passed opening now shows no button, and the server refuses it with "That opening has passed." SHA below.
- **20 A-1 Chimney areas have no time slots**, so online booking and the board show no openings there, and 14 areas org-wide have never had capacity set.
  - **Why:** configuration that was never entered, not a code fault.
  - **Needs Jose:** see section 5.
- **6 future jobs have no service area**, so they don't count against any area's capacity.
  - **Why:** not traced yet (measured count only).

## 3. Cohesiveness (for UI-UX)

- Not audited on screen in this pass (API and data only). UI-UX should open Scheduling's tabs (Board, Availability, Areas, Capacity, Helpers, Overbooking, Reports) in the same sweep as the other modules.
- Known from the code: the time-slot inheritance note ("Using X's windows…") is plain text, and the reschedule block in a lead thread is a new block that UI-UX hasn't reviewed (built 2026-10-28).

## 4. Suggestions (ranked)

1. **Overbooking and Reschedules take ~0.9–1.4 s** because one Overbooking load runs 973 queries (measured). Batching them is the fix. This is my work, queued next.
2. Expire reschedule offers automatically once their day passes. The new button guard covers the screen; a sweep would keep the list clean.
3. Flag areas with no slots in the Areas list (a red "no hours" tag), so a gap like the 20 above is visible without a query.
4. Put a "no area" count on the board so the 6 unplaced jobs get placed.
5. Retire GET /scheduling/technicians once Roster covers it (two lists of the same people).

## 5. Needs Jose

- The 20 A-1 Chimney areas with no time slots: should they take online bookings (then I set default hours, matching the branch), or are they intentionally closed?
- The 17 seeded "All areas" catch-alls in other companies: keep them as each company's fallback, or remove them once a company draws its real areas?
