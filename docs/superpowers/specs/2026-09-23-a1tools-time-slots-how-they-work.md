# A1 Tools' time slots: how they are defined, and how they fill

**Written 2026-09-23** on Jose's instruction: *"we also need to make a change in
the availability system by allowing time slots… take the time to inspect the
entire availability system on a1 tools, to see how it works… do not implement
anything yet, i want you to understand it… see how it fills them as well."*

Measured against the shipped code and the live database, not the design docs.

---

## 1. Slots are defined ONCE PER COMPANY, not per service area

`crm_scheduling_slots`:

    id  company_id  franchise_id  slot_label  start_time  end_time  position  is_active

A-1's live rows, all three companies identical and `franchise_id` NULL on every
one:

| label | start | end | position | active |
|---|---|---|---|---|
| 8 AM - 11 AM | 08:00 | 11:00 | 1 | **yes** |
| 11 AM - 2 PM | 11:00 | 14:00 | 2 | **yes** |
| 2 PM - 5 PM | 14:00 | 17:00 | 3 | **yes** |
| 2-4 PM | 14:00 | 16:00 | 3 | no |
| 4-6 PM | 16:00 | 18:00 | 4 | no |

So "each service area has three time slots" is true in effect and not in
mechanism: **the COMPANY has three, and every area uses the same three.** The
per-area part is the NUMBER, never the times.

Two of the five are switched off rather than deleted, which is how the count
varies — the panel renders whatever the table returns and nothing assumes three.
`franchise_id` exists for a branch that wants its own windows and no row uses
it.

## 2. Capacity is typed as a DAY TOTAL and split across the blocks

`capacityForBlock($jobsPerDay, $blockIndex, $blockCount)` — `api/shared/area_capacity.php:520`:

    base      = jobsPerDay / blockCount        (integer division)
    remainder = jobsPerDay % blockCount
    the EARLIEST `remainder` blocks take one extra

So 12 over three blocks is 4/4/4, and 4 over three is **2/1/1** — the morning
gets the odd one. Eve types "Cleveland takes 12 a day" and never thinks about
blocks.

She can also type each block directly. Both paths call the same
`setSlotDayOverride()` and the day-total path simply calls it once per block
with the split, so typing "12" and typing "4, 4, 4" write byte-identical rows.
Neither path re-implements the other's rule.

## 3. How a job lands in a block — the filling

**Booking through A1 Tools:** `resolveSlotReservation()` takes the slot the
dispatcher picked and returns `date + slot.start_time` as the job's start. So a
job booked into 11 AM - 2 PM starts at exactly 11:00.

**Jobs arriving from Workiz:** `reconcileJobSlots()` runs on EVERY sync and
re-derives `slot_id` set-based for every scheduled, non-archived job:

    s.start_time <= TIME(j.scheduled_start) < s.end_time

It is deliberately self-healing — it recomputes every job rather than only the
NULL ones, so a job whose time moved in Workiz lands in the right block on the
next sync instead of keeping a stale one, and a job whose time matches no active
block is set back to **NULL**, which is the honest answer rather than a guess.

**Overlapping windows are resolved, not forbidden.** `SCHED_SLOT_PICK_ORDER`:

    (franchise_id IS NULL) ASC, TIMEDIFF(end_time, start_time) ASC, position ASC, id ASC

Company-wide before a franchise's own, then the **shortest** window, then
position. 14:30 falls in both "2 PM - 5 PM" and the disabled "2-4 PM"; the
narrower wins if both are active.

### What that produces in production, company 1, future jobs

| block | jobs |
|---|---|
| 8 AM - 11 AM | 337 |
| 11 AM - 2 PM | 253 |
| 2 PM - 5 PM | 177 |
| (no slot) | 28 |

Start times used: **08:00 ×291, 11:00 ×239, 14:00 ×163**, then a tail — 09:00
×36, 00:00 ×25, 12:00 ×10, 10:00 ×8, and single oddities like 06:45:07. The
clustering is the booking path writing the block's own start; the tail is jobs
booked in Workiz directly at a real clock time. Midnight is "no time set", which
is why 28 jobs carry no block.

## 4. What a block reports

`getSlotCapacity()` feeds the Jobs form's chips, per block:

    capacity        the resolved ceiling (8 tiers, then min with techs x 2)
    configured      the raw stated number, kept separate so the UI can say
                    "5 configured, 2 effective" when the roster is the binder
    available_techs null when unlimited
    taken           jobs in that (slot, date), AREA-scoped when the zip resolves
    remaining       max(0, capacity - taken) -- never negative
    closed          distinct from capacity 0
    area_id/name    so the figure has provenance rather than being a bare number

## 5. Three tables carry per-slot numbers, all keyed on `slot_id`

| table | grain | live rows |
|---|---|---|
| `crm_franchise_slot_capacity` | franchise, slot, weekday, capacity, **percent** | 15 |
| `crm_service_area_slot_capacity` | area, slot, weekday, capacity, **percent** | 3 |
| `crm_slot_capacity_overrides` | area/franchise, slot, **date** | 612 |

`percent` beside `capacity` is the "Friday is half" rule — stored as a
proportion so it survives a later change to the standing number, where an
absolute 2 quietly stops being half the moment 4 becomes 6.

The per-date table is by far the most used, which is the opposite of what its
own design doc predicted.

---

## 6. Where EzBizi stands against this

`service_area_slots(service_area_id, weekday, start_time, end_time, max_jobs)`,
unique on (area, weekday, start_time).

**We are already more granular than A1 and that is the problem, not the win.**
Our times are per area AND per weekday, so A-1's single company-wide pattern —
three windows — would be **34 areas × 7 days × 3 = 714 rows typed by hand**.
A1 types five rows once. The capacity per area per weekday is right; the
WINDOWS being per area per weekday is a cost nobody asked for.

What we do not have, in the order it matters:

1. **A place to state the pattern once.** A1's company-level slot list with
   `position` and `is_active`, so three windows are defined in one place and
   every area inherits them. Per-area windows become the exception rather than
   the only way to say it.
2. **The day-total split.** `capacityForBlock`'s "12 becomes 4/4/4, 4 becomes
   2/1/1". Ours can only be typed per window.
3. **`position` and `is_active`.** A1 varies the number of blocks by switching
   rows off; ours would need deleting, which loses the capacity with it.
4. **Job → slot.** We have no `slot_id` on a job and no reconcile step, so
   nothing fills a block yet. That is spec 3.3 in the gap document.
5. **Per-date override.** Their busiest table, our missing one (3.4).

Nothing here is built. This document is the understanding Jose asked for before
any of it is designed.
