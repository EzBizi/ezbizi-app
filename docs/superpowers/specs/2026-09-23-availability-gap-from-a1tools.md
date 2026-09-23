# Availability: what A1 Tools does, and what EzBizi is still missing

**Written 2026-09-23** for Jose's instruction: *"we need to move away from A1
Tools and into EzBizi asap, and for that i need to complete the availability
system … analyze how the a1tools availability system works for a-1chimney, and
of course adapt it to our system, which is much simpler and less confusing, but
basically we need the workiz data, zips, etc. check what we are still missing."*

Everything below is measured against A1's shipped code and its live database,
not read off its own design documents: those describe a model that has since
moved twice. Production numbers are from the box, 2026-09-23.

---

## 1. How A1 Tools actually answers "can we take this job?"

Two numbers, for one (area, slot, date): a CAPACITY and the SEATS TAKEN.

### Capacity — `slotEffectiveCapacity()`, api/crm/index.php:2002

Eight tiers, first hit wins:

| # | Tier | EzBizi equivalent |
|---|---|---|
| 0 | `date < available_from` → **closed** (the booking horizon) | **missing** |
| 1 | per-date override, area | **missing** |
| 2 | per-date override, franchise | **missing** (no franchise tier at all) |
| 3 | area weekday exception (absolute **or a percentage** of standing) | native — each weekday is its own row |
| 4 | area standing number + area working days | native — `service_area_slots` |
| 5 | franchise weekday exception | not ported, see §4 |
| 6 | franchise standing + franchise working days | not ported, see §4 |
| 7 | `min(slot limit, technicians free × 2)` | **deliberately not ported** |

A1's tiers 3-6 exist because it states ONE number per franchise and then
subtracts exceptions from it. EzBizi states the number per area, per weekday,
per slot to begin with, so four of A1's tiers collapse into the table we already
have. That is the "much simpler" Jose asked for, and it is already built.

**The three absences must never be collapsed**, which A1's own spec calls "the
single most likely way to ship this wrong":

| state | means | renders |
|---|---|---|
| `0` | open, taking nothing more | red `0` |
| closed | not a working day, or shut for that date | grey `—` |
| unconfigured | nobody ever set this | "not set" |

### Seats taken — `slotSeatsTaken()`, api/crm/index.php:1761

Jobs in that (slot, date), scoped by franchise and — when the zip resolves
unambiguously — by area. Three rules worth copying verbatim:

- **A seat is taken by a BOOKING, not by an assignment.** A1 once counted only
  jobs a technician had accepted: Connecticut had 68 future jobs of which 5 were
  assigned, so 63 real commitments to real customers were invisible to every
  capacity check.
- **"Closed" is read from the company's own status table**, never a hardcoded
  `IN ('cancelled','completed')` — status pipelines are per-company.
- **An unmapped status counts as OPEN.** It constrains booking rather than
  freeing it, erring toward protecting the schedule.

### Where the zips come from — the part Jose is asking for

`crm_service_area_zips` is DERIVED FROM WORKIZ JOB HISTORY, not typed in.
`service_area_zips.php` action `rebuild` reads every synced job's
`$.PostalCode` and `$.ServiceArea`, matches that area NAME case-insensitively to
an existing service area, and writes one row per (zip, area) carrying
`job_count` — how many historical jobs are evidence for the mapping.
`source = 'workiz_jobs'` rows are replaced wholesale on each rebuild; manually
added rows survive it.

`job_count` is then what breaks ties: `resolveFranchiseForZip()` takes the
best-evidenced area, while `resolveAreaForZip()` **refuses to guess** and
returns null when a zip maps to 2+ active areas, because guessing wrong books
against another area's allowance.

### Live, on the box, 2026-09-23

```
crm_service_areas active        44   (71 total)
crm_service_area_zips        2,935 rows, 2,717 distinct zips
  ... mapping to 2+ areas        206 zips (7.6%)
crm_franchise_capacity           9 rows, 8 with a number
crm_service_area_capacity       47 rows, 25 with a number
crm_slot_capacity_overrides    600 rows          <-- see 3.4
a1tools_workiz_jobs          7,738 raw jobs
crm_jobs                     7,737
crm_scheduling_slots             9 active (3 blocks x 3 companies)
```

EzBizi production, same day: **29 service areas, 10 zips, 159 slot rows, 25
jobs, 9 Workiz connections.** The areas exist. The zips do not.

---

## 2. What EzBizi already has

- `service_areas`, `service_area_zips` (zip + city, `UNIQUE(franchise_id, zip)`),
  `service_area_slots` (area, weekday, start, end, `max_jobs`).
- Availability search by zip / area / city with type-ahead, a week view, arrows.
- A Settings pane stating capacity per area per weekday, and closing a day.
- A closed day is the ABSENCE of rows, never a zero — enforced on both sides.
- Nine Workiz account tokens already connected, one per franchise
  (`social_connections`, scope `franchise`).

---

## 3. What is missing, in the order it blocks the next thing

### 3.1 The zip map (blocks everything else)

10 zips against A1's 2,935. Needs an importer over Workiz job history building
(zip → area) with a job_count, the same evidence A1 uses.

Two decisions EzBizi's schema forces that A1's does not:

- `UNIQUE(franchise_id, zip)` means **one area per zip per franchise** — Jose's
  own rule. A1's 206 ambiguous zips cannot be carried across as they are; the
  highest `job_count` has to win, and the loser has to be visible somewhere
  rather than silently dropped.
- Area names have to line up. A1 matches Workiz's `ServiceArea` string to an
  area by lowercased name; EzBizi's 29 areas were typed by hand, so this will
  partially miss until somebody reconciles the two lists.

### 3.2 A Workiz job sync (blocks every "booked" number)

EzBizi reads Workiz today for ONE report and throws the rest away:
`WorkizJobs::shape()` keeps phone, created_by, created_at, scheduled_for and
status, and drops the address. Nothing is persisted.

Needed: jobs stored with `zip`, `scheduled_for`, `status` and the external
Workiz id, per franchise token. Plus a status → closed map per organization,
with unmapped statuses counting as open.

### 3.3 Jobs cannot be attributed to an area or a slot

`jobs` has no zip, no address, no service area and no slot. The service address
today is the CUSTOMER's `postal_code`, which is not the same fact — a job can be
at a property that is not the customer's address.

So, in order: a zip on the job → the area (via 3.1) → the slot (by time window,
the way A1's `reconcileJobSlots()` maps `TIME(scheduled_start)` into a block).
Only then can "remaining" exist. Until it does, the Availability tab is honest
that it shows CONFIGURED capacity, which is what it says today.

### 3.4 Per-date overrides — "closed on the 18th", "Cleveland varies daily"

EzBizi's granularity stops at the weekday. A1's spec called this tier unused
(0 rows at design time); it now holds **600 rows** and is how Cleveland and
Cincinnati are run day to day. It is the most-used part of A1's availability
system and EzBizi has none of it.

### 3.5 The booking horizon — "we are full until 7 September"

One nullable date per area. Every slot before it is CLOSED — not zero, not
full. The floor can only remove capacity, never invent it, and a past date is a
no-op.

### 3.6 "When is the next opening?"

A1's `next_available` scans forward up to 60 days and returns the first open
days, scoped to the AREA when the zip resolves to one, so a technician never
picks a date. Today is never offered. EzBizi's search answers "this week" and
makes the user press the arrow.

### 3.7 Unconfigured vs closed — live in our code today

`SchedulingAvailabilityController::week()` sets `closed => $slots->isEmpty()`,
so an area with no rows at all reads as CLOSED ALL WEEK rather than NOT SET.
That is exactly the conflation A1 calls the most likely way to ship this wrong.
Cheap to fix: zero slot rows across the whole week is unconfigured, not closed.

### 3.8 Enforcement

A1 has ONE booking gate (`slotCapacityEnforced`), **off by default in every
company** — capacity is informational until an owner turns it on. EzBizi has no
booking path to gate yet. The per-company enforced-vs-advisory setting is
already recorded in the 2026-08-31 spec and deliberately unbuilt; it becomes
real with 3.3.

---

## 4. What we should NOT copy

- **The technician inference** (`min(cap, techs × 2)`). It is the reason A1's
  managers could not trust the number: the system enforced an inference nobody
  stated or reviewed while the screen showed something else. EzBizi has no
  technician roster to infer from, and stated capacity is the whole design.
- **Franchise-level standing capacity with per-area exceptions.** Jose already
  ruled the other way — "per service area then, forget about franchise wide" —
  and A1's own history is the argument: franchise-level sums were "right in
  total, wrong in distribution", letting Houston eat Austin's allowance.
- **Percentage weekday rules** ("Friday is half"). They exist because A1 states
  one number and derives the rest. We state each day, so half of Friday is
  simply the number typed on Friday.
- **Copy-across buttons.** A1 built two and then deleted both.

---

## 5. Order of work

1. Workiz job sync carrying the address (3.2) — nothing else works without the
   history.
2. Build the zip map from it (3.1), with the ambiguity decision visible.
3. Zip + area + slot on the job (3.3) → booked counts → "remaining".
4. Per-date overrides (3.4) and the horizon (3.5) — one small table and one
   nullable column, both daily-use features in A1.
5. `next_available` (3.6).
6. Enforcement (3.8), off by default.

3.7 is independent and can land at any point.

## 6. Open, for Jose

- **Who owns the Workiz sync?** It is not Scheduling's table. A1's sync writes
  jobs; in EzBizi that is the Jobs module's territory and the Workiz connection
  already belongs to another session. Availability consumes it either way.
- **Do the 29 EzBizi areas correspond to A1's 44 active ones by name**, or does
  the import create what it does not find?
