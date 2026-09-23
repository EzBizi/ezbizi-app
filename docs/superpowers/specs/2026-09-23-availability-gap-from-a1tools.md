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
  own rule. The ambiguity is bigger than A1's own table shows, and the two
  numbers are both right (Workiz session, measured 2026-09-23):

      420 zips map to 2+ service areas in the RAW Workiz history
        ... of which 339 sit inside a SINGLE Workiz account
      206 zips are ambiguous in crm_service_area_zips, AFTER A1's rebuild

  The gap between them IS the tie-break. `rebuild` collapses by `job_count`,
  which is why that column is the asset rather than decoration. And **franchise
  attribution does not rescue the 339**: they are decided by job history inside
  one franchise, not by which franchise it is. So the importer needs the same
  collapse, and the loser has to be visible somewhere rather than silently
  dropped.

- Area names have to line up, and they do better than expected. Measured
  2026-09-23 against A1-Group's areas in EzBizi production:

      26 of 26   EzBizi area names match a Workiz ServiceArea EXACTLY
       0         EzBizi areas with no Workiz name
      31         Workiz names with no EzBizi area, covering 1,603 jobs (21%)

  **SETTLED AND EXECUTED THE SAME DAY — see 7.** Jose ruled that nothing folds,
  and `scheduling:import-a1-areas` created 19 of the 31 on production. A1-Group
  holds 45 areas. The paragraph above is kept as the measurement that prompted
  the ruling; the number that matters now is what is LEFT:

      12   Workiz names still with no area, all on zero bookings in 30 days
     212   jobs behind them -- 2.8% of the history, not 21%
           Dallas 163, Kansas City 13, East/South New Jersey 12, Nashville 10,
           Tampa 4, Baltimore MD 4, Seattle Washington 2, Salt Lake City 2,
           San Fransisco 1, San Diego 1

  The importer SKIPS a job whose ServiceArea has no area rather than guessing
  one — under-constraining is safe, mis-attributing is not, the same rule A1
  applies to an ambiguous zip. Dallas is the only one large enough to notice
  and it costs almost nothing: 26 of its 75 zips are already evidenced by DFW's
  own jobs, which is what "consolidated into DFW" means in the data.

  **Several of the 31 are Workiz-side naming drift, not missing territory** —
  `Connecticut` beside `East Connecticut`/`West Connecticut`, `Atlanta` beside
  `Atlanta North`/`Atlanta South`, `Dallas` + `Fort Worth` beside `DFW`,
  `Seattle Washington` beside `Seattle`, `Nashville Area` beside `Nashville`,
  `Washington / Virginia` beside `Washington DC` + `Virginia`. Deciding whether
  those fold into an existing area or become their own is a human call, not an
  import rule. Four names also carry a trailing space in Workiz; no two values
  collapse when trimmed, so it is cosmetic.

**BUILT 2026-09-23** — `scheduling:build-zip-map`, over the Workiz session's
`workiz_jobs` landing table. `GROUP BY service_area, postal_code`, `COUNT(*)`
as the tie-break, winner per (franchise, zip) because the unique index permits
a zip in two franchises and that is a real thing, not a tie. Derived rows carry
`source = 'workiz_jobs'`; a row with a NULL source was typed by a person and
wins over the evidence. `--report-unmatched` lists the names with no area here.
Not yet run against production: the landing table fills as the sync sweeps.

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

#### The booked count does not need the zip map (Reports, 2026-09-23)

Their finding and it is right. `workiz_jobs` carries `service_area`,
`scheduled_for` and `franchise_id` as columns, and Jose ruled "mirror Workiz,
fold nothing", so an EzBizi area name and a Workiz `service_area` are the same
string by construction — 45 of the 57 names now match exactly, covering 97.2%
of the history.

That splits 3.3 in two, and only one half needs the map:

- *"Which area does this NEW job belong to?"* — needs zip → area, because a new
  job has an address and no area label. This is the availability SEARCH.
- *"How many jobs are booked in area A on date D?"* — needs the name and the
  date, both already columns. This is the booked COUNT.

Counting on Workiz's own label is also more faithful than deriving it: the
label is what a dispatcher chose, while a derived area is our inference about
their choice.

#### THE ACTUAL BLOCKER IS WHICH STATUSES ARE A SEAT

Reports flagged this and half of it needs correcting, which matters because the
correction is where the work is.

Not true that there is no tenant-owned status vocabulary: `jobs.statuses` is an
organization-scoped setting, and `jobs.earned_statuses` and
`jobs.thumbtack_status_map` are both "which of YOUR statuses mean X", both empty
by default on the stated grounds that silence is honest and a guess is not.

The real gap is that those describe EZBIZI's statuses and the landing table
carries WORKIZ's — a different namespace, so no existing setting can answer it.
Measured over 7,739 captured payloads, every distinct Workiz status, with how
many are scheduled in the FUTURE, which is the only part capacity cares about:

    Status                  jobs   future
    Done                    2,161       5
    done pending approval   1,982       3
    Submitted                 916     595
    Canceled                  856       0     + 546 across 16 sub-statuses, 9 future
    In progress               806      80
    Pending                   511      16

So the shape is the Thumbtack map's, one level over: the tenant says which
Workiz statuses consume a seat, empty means none, and nothing is guessed.
Proposed default to put to Jose — **Submitted, Pending and In progress consume a
seat; Canceled and both Done forms do not.**

**And the scale of the harm is smaller than it looks**, which is worth stating
because "cancelled jobs count as booked" reads as catastrophic: 9 future jobs
in the whole company are Canceled, against ~690 that are live. It is 1.3%
company-wide. It could still be decisive for one small area on one day, which
is why it gets a setting rather than a shrug — but it is not the thing to build
first, and it is not a reason to delay the count.

### 3.4 Per-date overrides — "closed on the 18th", "Cleveland varies daily"

EzBizi's granularity stops at the weekday. A1's spec called this tier unused
(0 rows at design time); it now holds **600 rows** and is how Cleveland and
Cincinnati are run day to day. It is the most-used part of A1's availability
system and EzBizi has none of it.

**WHOEVER BUILDS THIS TELLS REPORTS FIRST.** Per-date rows change the grain of
`service_area_slots`, and Reports derives two columns from that grain with
`count(distinct weekday)` (28b553bf) which Jose's automations warn from. Over
per-date rows that count still returns a number and stops meaning "days a
week" — nothing errors, nothing reds, and a live alert quietly changes meaning.
The same warning is on `ServiceAreaSlot` itself, which is the file somebody
would actually be editing.

Their column is a WEEK rather than a horizon for the same reason, and it is
correct today: the data is per weekday, so any window of seven days or more
contains every weekday and "no open day in the next 14" is the same question as
"no open day at all". This section is what makes that stop being true.

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

## 6. Answered since this was written (2026-09-23, Workiz session)

- **Does v1 carry the fields?** Yes, and not marginally. Presence across 7,739
  captured payloads: `ServiceArea` 7,733, `PostalCode` 7,724, `Status` 7,738,
  `JobDateTime` 7,738, `City` 7,647, `State` 7,702, `UUID`/`SerialId` 7,738.
  Design on `ServiceArea`; no zip clustering needed.

  Worth knowing before trusting either source: the published spec and the live
  payload disagree in BOTH directions — the payload carries `JobName`,
  `LineItems`, `LocationId`, `LocationKey` which the doc does not document, and
  the doc documents `Timezone` and `ReferralCompany` which these rows do not
  carry. 43 keys live against 41 documented, and not the same 41.

- **Whose table?** A landing table the Workiz session owns, flattening
  `uuid / client_id / zip / account` beside the raw JSON, the way A1's own
  `a1tools_workiz_jobs` does. Customers and Jobs project out of it separately.
  **The zip-map importer is mine and reads their landing table.** They send
  column names before writing it.

- **Attribution per franchise is free.** Each token returns only its own
  account's jobs (another account's job id answers 204), so the connection's
  franchise is the answer and no per-job attribution is needed. But a franchise
  is not a service area: 57 distinct areas across 10 accounts, one account
  carrying up to 30 — which is why 339 of the 420 ambiguous zips are not helped
  by knowing the franchise.

  **THE IMPORTER MUST KEY ON THE AREA NAME, NOT ON THE CONNECTION'S FRANCHISE**,
  and the landing table will offer a `franchise_id` that makes the wrong choice
  the easy one. Measured 2026-09-23, per Workiz account, against the franchise
  that owns each name in EzBizi:

      6,132 jobs whose ServiceArea maps to an EzBizi area
         31 of them sit in an account whose OTHER areas belong to a different
            franchise -- 12 Texas-area and 1 Connecticut-area job in the account
            that is otherwise New York, 17 New York-area jobs in the account
            that is otherwise Connecticut, 1 DMV-area job in the New Jersey one

  0.5%, so the connection's franchise is a fine attribution for a JOB. It is
  the wrong key for a ZIP MAP, because it would file those zips under a
  franchise that does not own the area. Every EzBizi area name resolves to
  exactly one franchise (checked: no name appears in two), so the name carries
  the franchise and the connection does not need to.

  **Those 31 are what the model predicts, not a tolerance.** `Job`'s docblock,
  read to confirm rather than taken on report: *"NEVER shared. A job happens at
  one place, on one date, with one crew — it belongs to exactly one franchise…
  Doing a job for another franchise's customer is normal and adds you to their
  'served by' list; it does not make the job co-owned."* A job belongs to
  whoever DID it.

  Precisely: that sentence settles the CUSTOMER case, and this is the TERRITORY
  case — the same shape, not literally stated. Close enough to stop treating the
  31 as anomalies worth watching, not close enough to cite as if it named them.

  The consequence runs the other way too, and the Workiz session is recording it
  on their side: in their landing table `service_area` and `franchise_id`
  disagree on exactly those rows, so anything reading "service_area implies
  franchise" out of that table is wrong 0.5% of the time and silently.

## 7. Jose's ruling, 2026-09-23

> "the service areas are fine as they are, that's how they are in workiz right
> now as how they should remain, the only ones that needed adding were the
> nationwide ones."

**So nothing folds.** The analysis in 3.1 offered to collapse `Connecticut`
into East/West, `Atlanta` into North/South, `Dallas` + `Fort Worth` into `DFW`,
`Wisconsin` into `Milwaukee`. That is refused: Workiz's names are the names, and
the importer mirrors them rather than interpreting them. The zip overlaps that
suggested folding are still true and are now just a fact about the territory —
two areas can be evidenced by the same zip, and the `job_count` tie-break is
what decides which one owns it.

### The 26 DID mirror Workiz. Workiz has changed since.

Jose, asked again: *"The Service Areas that we have on EzBizi are perfect as
they are, except for Nationwide which was empty. They already mirror workiz
correctly."*

That is true of the configuration EzBizi was built against, and the disagreement
is not about names — it is about WHEN. The decisive measure is not when a job
was scheduled (a job booked in June can be scheduled for October and makes a
dead label look alive); it is when a job was last BOOKED under the name.
`CreatedDate`, jobs booked in the LAST 30 DAYS:

| Name we have no area for | booked, 30d | The area we do have | booked, 30d |
|---|---|---|---|
| Connecticut | **209** | West Connecticut / East Connecticut | 32 / 24 |
| Atlanta | **80** | Atlanta North / Atlanta South | **0** / 3 |
| Wisconsin | **47** | Milwaukee | 67 |
| Fort Worth | 19 | DFW | 99 |
| Long Island, NY | 11 | New York / NYC | 62 / 7 |

Atlanta North has taken **no** booking in thirty days while `Atlanta` took 80.
Connecticut outbooks both halves six to one. So Workiz has CONSOLIDATED since
our list was made — the halves are being retired in favour of the whole — and
our 26 mirror the older shape. Dallas has taken none in thirty days and is
genuinely fading, which is the opposite direction from Fort Worth's 19.

Plus the genuinely new, and this is the part the ruling is unambiguous about:
Detroit 69, Boston North 78, Seattle 26 in the last thirty days, none of which
has an area anywhere.

**Nothing here is decided.** The ruling stands as written — mirror Workiz, fold
nothing — and this table is only the evidence that "already mirrors" has an
expiry date.

**THE 30-DAY COUNT IS A PROXY, and it is the best signal currently available.**
There is no Workiz endpoint that lists an account's configured service areas
(Workiz session, 2026-09-23): the published v1 surface is Jobs, Leads, Team,
TimeOff, and A1 Tools — which holds write credentials for all nine accounts and
has synced continuously for months — syncs exactly three entity types, `jobs`,
`leads`, `team`. Two independent negatives rather than one, which is what the
earlier Job-shape finding taught: the vendor's doc alone is not enough.

So a booking count cannot separate "retired last week" from "quiet this month".
Atlanta North's zero in thirty days is suggestive and is not proof.

The candidate that WOULD separate them, unverified: `/team/all/` returns each
user's `serviceAreas`, which is configuration rather than history — a retired
area has no active field tech assigned to it, a quiet one still does. Two things
to know before designing on it: `serviceAreas` is in the published schema and
the published schema has already been wrong in both directions on the Job shape,
and **A1's stored team data cannot answer it** — `a1tools_workiz_team_map` keeps
`workiz_user_id / email / name / role / active` and no service areas and no raw
payload, so this needs a live call, not a query.

Full list of the 31, for the record:

| Missing name | Workiz account | jobs |
|---|---|---|
| Connecticut | **Connecticut** | 222 |
| Dallas | **Texas** | 163 |
| Fort Worth | **Texas** | 156 |
| Atlanta | **Georgia** | 126 |
| Seattle | **NY** (101) + Nationwide (3) | 104 |
| Wisconsin | **Illinois** | 55 |
| Long Island, NY | **New Jersey** | 16 |
| Portland | **NY** (11) + Nationwide (1) | 12 |
| East / South New Jersey | **New Jersey** | 12 |
| Detroit, Boston North, Denver, Minneapolis, Sacramento, Riverside, Charlotte, Kansas City, Greensboro, Saint Louis, Los Angeles, Washington / Virginia, Nashville (×2), Miami, Tampa, Baltimore MD, Salt Lake City, Seattle Washington, San Diego, San Fransisco | Nationwide | ~700 |

Adding only the Nationwide ones leaves roughly 750 jobs — Connecticut, Dallas,
Fort Worth, Atlanta, Seattle, Wisconsin and the New Jersey pair — with no area,
so their zips never resolve. That is a question for Jose, not a decision to take
on his behalf: he may want exactly that, or he may not know those eight are
missing too.

**The rule that satisfies the ruling either way**, and what the importer should
do unless told otherwise: mirror Workiz. A ServiceArea name with no matching
area gets one created under the franchise of the account that reported it.
Then "how they are in Workiz" is how they are here, permanently, including for
names nobody has typed yet.

### The command, and what it would do

`scheduling:import-a1-areas` creates an area for every Workiz name that has
none. It follows `fleet:import-a1-tools`: a JSON file rather than a second live
connection, so the read stays inspectable and the write stays testable. A1 Tools
is read-only to us.

    php artisan scheduling:import-a1-areas         docs/superpowers/specs/2026-09-23-workiz-service-areas.json         --organization=A1-Group --dry-run

The file is the measurement, committed beside this spec and dated because it
expires: per ServiceArea name, the account that books into it most, total jobs,
and jobs BOOKED in the last 30 days. It is produced by a read-only query over
`a1tools_workiz_jobs` joined to `a1tools_workiz_locations`, grouping on
`$.ServiceArea` and counting `$.CreatedDate` inside the window. The only hand
edit is the account name `NY` → the franchise name `New York`; the other eight
match a franchise name exactly.

As of that snapshot it would create **19** and skip **12**:

    Connecticut  Connecticut            209 booked      Nationwide  Minneapolis      8
    Georgia      Atlanta                 80             Nationwide  Denver           7
    Nationwide   Boston North            78             Nationwide  Saint Louis      5
    Nationwide   Detroit                 69             Nationwide  Washington / Virginia 5
    Illinois     Wisconsin               47             New York    Portland         5
    New York     Seattle                 26             Nationwide  Sacramento       4
    Texas        Fort Worth              19             Nationwide  Riverside, Charlotte,
    New Jersey   Long Island, NY         11                         Los Angeles, Greensboro, Miami  1 each

The twelve skipped have taken no booking in thirty days: Dallas (163 jobs
lifetime and 0 recent — consolidated into DFW), East and South New Jersey,
Kansas City, Nashville ×2, Tampa, Baltimore MD, Salt Lake City, San Diego,
San Fransisco, Seattle Washington.

**RUN AGAINST PRODUCTION 2026-09-23** on Jose's "do it": 19 created, 15 skipped,
A1-Group now holds **45** service areas. The command was staged into the release
tree, run, and removed — it is not in a deployed cut yet, so re-running it needs
the branch merged first.

**It never deletes.** Areas of ours the measurement does not mention are printed
as retire candidates and left standing — `Atlanta North` (0 bookings in 30 days),
`Rhode Island` (0), `All areas` (0), with `Atlanta South` on 3 and fading.
Retiring them is Jose's call, because an area holds capacity somebody typed.

### Blocker before any area can be created

`franchises` in A1-Group holds **duplicate names**: Connecticut ×8, Texas ×3,
Nationwide ×2, DMV ×2. Only one of each carries service areas — they are the
batch created 2026-08-06; the rest were created 2026-09-10 and 2026-09-14 with
zero areas and zero jobs. An importer resolving a franchise by NAME cannot pick,
and "Nationwide" is one of the duplicated ones, which is precisely where the new
areas go. Not Scheduling's table to clean.
