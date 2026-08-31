# Scheduling — Availability and its settings

Design agreed with Jose, 31 Aug 2026. Covers the Availability and Settings tabs
of the Scheduling module. The Schedule tab and Scheduled Jobs are the consumer
and are **not** designed here — see §7 for the seam left for them.

## 1 · What Availability means

Dispatch capacity. **How many jobs a franchise can take, per time slot, per day,
per service area.**

It is not staff availability, not equipment availability, and not a
customer-facing booking surface. Those three were the standing guesses before
Jose ruled; recording the rejected readings because the name invites all three
and the next person will guess again.

## 2 · The two things a franchise configures

Both live in the **Settings** tab.

### Service areas

A franchise creates named areas — "Dallas", "Houston". Each holds a set of zip
codes.

**One zip belongs to exactly one service area within a franchise.** Enforced by a
unique index, not by a check somebody has to remember. Adding a zip another area
already holds is refused, and the refusal names the area that has it.

Overlap was considered and rejected. It was raised as "let the company decide in
settings", and the reason it was dropped is that the setting has no defined
behaviour when it is on: if a zip sits in two areas, a zip search must show
something, and "both", "the first" and "the sum" are three different products.
Allowing overlap does not answer that question — it creates it, on the busiest
screen in the module. If a genuine overlap case appears, the honest fix is a
*search* that returns two areas and asks which, not a global toggle.

### Time slots and capacity

Each area defines **its own** slots — 7–11am, 11am–3pm, 3–5pm — and its own job
capacity per slot, per weekday.

Per area, explicitly not franchise-wide: Jose ruled on this directly. Dallas may
run different hours from Houston, and an area's Saturday may differ from its
Monday. The brief was "as granular as possible, so companies have full control".

**A closed day is a weekday with no slot rows.** Not a flag, and not
all-capacities-zero: absence already means it, and there is nothing to keep
consistent.

## 3 · The Availability tab

Read-only. A search bar taking **a zip code or an area name**. A zip resolves to
its owning area, which is unambiguous by §2.

The result is a week grid — slots down, days across — showing remaining capacity
per cell, with arrows to page to the next and previous week.

Layout is owned by `ezbizi-ea`, not by this document. Two constraints it was
given: both grids are seven columns on a 375px floor, and the reader is usually
somebody on the phone to a customer, so speed of read beats density.

## 4 · Storage

Three tables. All are franchise-owned and tenant-stamped the usual way.

| table | holds | notes |
|---|---|---|
| `service_areas` | `franchise_id`, `name` | name unique per franchise |
| `service_area_zips` | `service_area_id`, `zip` | **unique on (franchise_id, zip)** — this index is what makes §2 true |
| `service_area_slots` | `service_area_id`, `weekday`, `start_time`, `end_time`, `max_jobs` | one row per (area, weekday, slot) |

A slot row is per (area, weekday), which is why capacity needs no table of its
own: the slot already knows its area and its day, so `max_jobs` sits on the slot.
An earlier shape had slots franchise-wide and a separate capacity table keyed
(slot × area × weekday); moving slots to the area collapsed it to this.

No `label` column on a slot. "7–11 AM" is derived from `start_time` and
`end_time`; a stored label is a second source for the same fact.

Denormalising `franchise_id` onto `service_area_zips` is deliberate — the
uniqueness that matters is per franchise, and a unique index cannot reach through
a join to get it.

## 5 · What is deliberately not built

- **Per-date overrides.** No "closed this Thursday for the holiday", no "only one
  job next Tuesday". Capacity is recurring by weekday and nothing else. This is
  the first thing a real dispatcher will want and it was left out on purpose: it
  is a fourth table plus a precedence rule, and it should be added when somebody
  asks rather than found already half-built.
- **Slot labels.** Derived, see §4.
- **A closed-day flag.** Derived, see §2.

## 6 · Automation

Not declared yet. The Scheduling module currently carries
`automationExemptBecause` worded as *unbuilt*, not *exempt* — see commit
`9d37660`. That string is the thing to replace when these tables land, and the
obvious triggers are a service area created or changed and capacity changed.
Naming them here would be guessing at wording nobody has asked for.

## 7 · The seam left for Scheduled Jobs

`remaining` for a cell is `max_jobs` minus the jobs already booked into that
(date, area, slot).

**The second half of that subtraction has no source yet.** `Job` has
`scheduled_for`, cast as `FranchiseWallTime`, and `duration_minutes` — but no
link to a service area. Until Scheduled Jobs exists, the booked count is zero and
every cell reads "2 of 2".

An earlier version of this section called that "correct-but-empty, not wrong".
**That was wrong, and `ezbizi-ea` was right to push back on it.** A cell reading
"2 of 2" that never moves however many jobs get booked is not empty — it is a
false statement in a confident format, and it is worse than a blank screen
because a blank screen admits what it does not know. Somebody on the phone reads
"Tuesday morning, 2 of 2 in Dallas", books it, and reads the same thing again
afterwards.

**Ruled by Jose, 31 Aug: the column is named for what it is.** Until a booking
can burn a slot, the number is **configured capacity** — not *remaining*.

This came from `ezbizi-ea` and it is better than both options this document
originally offered (annotate the number, or remove the screen). The lie was never
the number: "5" genuinely is what Dallas is set up to take on a Tuesday morning.
The lie was the noun — *remaining* asserts a subtraction nobody performs. Naming
it correctly makes the same cell true today, and the label becomes *remaining*
the day Scheduled Jobs lands, so the meaning and the word change together rather
than one changing underneath a reader who learned the other.

It also earns the tab rather than costing it. "What are we set up to take on
Tuesday mornings in Dallas" is a real question with no other screen to ask it on,
and a franchise that has just configured thirty areas needs to see the result
somewhere other than the form it typed into.

Whoever builds Scheduled Jobs owns two decisions this document does not make:

1. **How a job acquires its service area** — resolved from the customer's zip at
   booking time, or chosen by hand, or both.
2. **Whether capacity is enforced or advisory** — does booking into a full slot
   get refused, or warned about and allowed? Dispatch shops differ, and the
   answer changes whether this is a constraint or a display.

`FranchiseWallTime` on `scheduled_for` is worth keeping: slots are wall-clock by
nature ("the 7am slot" is 7am locally, whatever the offset that week), and the
cast means that question is already settled rather than waiting to be
rediscovered.

## 8 · Testing

The check that matters is the zip uniqueness, because it is the one carrying a
promise made to a person: a zip in two areas makes search ambiguous. One test
that a second area cannot take a zip the first one holds, and that the refusal
names the holder.

Capacity arithmetic gets a test when there is something to subtract (§7).
