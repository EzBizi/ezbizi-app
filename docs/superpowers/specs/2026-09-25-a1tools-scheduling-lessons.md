# What A1 Tools' Scheduling module learned the hard way

**Received 2026-09-25** from the A1-Tools Scheduling session
(`local_9a79fa08-e530-4b02-ac13-6789602a6362`). Jose asked for this before A-1
moves Availability onto EzBizi, on the explicit condition that **EzBizi's
structure is fixed**: this document is lessons, not a proposal to re-add A1's
tiers. Numbers are A1 production on 2026-09-25 unless marked otherwise.

Read this before building any of the six unbuilt pieces in
`2026-09-23-availability-gap-from-a1tools.md` §3.

---

## The three that matter most

1. **An honest unknown beats a confident wrong answer.** A1's checker used to
   say "Fully booked" and "We do not cover {zip}". Jose: dispatchers *"may think
   we really do not cover it, and we may — and just deny the job."* Both became
   "Information not available, please check availability on Workiz"
   (d76f4a43, 2026-08-19). Every capacity surface EzBizi builds follows this.
2. **ONE function computes "taken", or the product lies.** Connecticut showed
   free spots on a Friday booked 13 against 8 (d5054fbc, 2026-09-16): the rule
   lived in four places, each correct, and the one the technician checker used
   was missing it. The booking gate and the checker must call the same code.
3. **The checker IS the product.** Jose: *"the availability checker is that, an
   availability checker — based on what the app tells them, they schedule
   correctly on Workiz."* Accuracy of what the screen says outranks the
   enforcement gate.

---

## By unbuilt piece

### Seats taken (and job → window, which is the same feature)

- **Build them together, and assert non-zero on real data first.** A1 shipped
  with `slot_id` NULL on every live job; every occupancy figure was silently 0
  and read as a quiet day (2026-08-05). A remaining-count can only be wrong in
  the optimistic direction, so it never announces itself.
- **Half-open windows, [start, end).** A job at exactly 11:00 is in 11–2, not
  8–11. 11:00 and 14:00 are A-1's two most common start times.
- **Recompute every job every sync**, not only NULL ones, or a rescheduled job
  keeps its stale window forever. Reschedules are the common case.
- **Decide what a windowless job does BEFORE shipping.** A1 has 317 jobs with a
  date and no window (193 at 00:00:00, 124 at a time matching nothing), 24 of
  them future. A1's day total caught them; EzBizi has no day total, so by
  default they consume nothing and are invisible to every check.
- **Excluded tags are a SETTING from day one.** New Jersey read 31 of 30 on a
  Monday because three were `DEPOSIT RECEIVED` jobs done by a different crew.
  Live spellings include `DEPOSIT RECIEVED`, with trailing spaces; tags are a
  free-text JSON array, ~90 distinct. Case-insensitive, trimmed, applied in
  every counter at once.
- **Unmapped statuses hold seats** (correct — it constrains) **so report them
  somewhere visible.** Workiz `done` was unmapped and held 10 future seats until
  2026-08-19. EzBizi's shape is the status-map setting in the gap doc §3.3.
- **Never cache a remaining-count across a write or a sync.** A1 memoised
  per (area, date) and the checker read its own stale answer after an edit.
  Status churn at A-1 is ~940 transitions a week.
- **If there are ever two implementations** (per-row and set-based), a test pins
  them equal on the boundary cases.

### Per-date overrides

- **Re-key children when a parent is reassigned.** A1's override unique key
  used a generated `COALESCE(franchise_id, 0)` column; moving an area to another
  franchise left old rows on the old key, so the next edit would have inserted a
  duplicate instead of updating.
- **Scope every DELETE to its own tier.** Clearing a franchise-wide override
  deleted area overrides on the same date too (2026-08-16), live data loss.

### Booking horizon

- The horizon is a DATE in the company's clock, not a timestamp — A1's drifted
  at the day edge.

### Next available

- **"Tomorrow" is the company's tomorrow.** The box runs UTC; a bare
  `new DateTime('tomorrow')` rolled at 8 PM Eastern, so a 9 PM search skipped a
  whole working day, every evening (2b0ba268). A1 now has a
  `scheduling_day_timezone` setting, default America/New_York.
- **Don't cap the scan.** "Fully booked" past three weeks is not an answer a
  dispatcher can give (Jose, 2026-08-27). Walk to 365 days.
- **Unconfigured is not open.** Only `available > 0` is offered. The scan is
  where it is most tempting to be generous.

### Enforcement gate

- Off by default, and Jose has said why (above). Low priority.

---

## Timezones

- **A fresh MySQL has EMPTY named-timezone tables**, and `CONVERT_TZ` then
  returns NULL with no error. Killed two A1 features for three weeks. Load
  `mysql_tzinfo_to_sql` on every box and pin it with a test before trusting any
  named-zone math.
- **Windows are local wall-clock TIME, never a UTC instant.** EzBizi already
  stores `time` columns; keep it that way — normalising to UTC makes one window
  mean different hours in Seattle and Boston and moves it twice a year with DST.
- Dispatchers asked by name for **the searched zip's local time** (first three
  digits, no geocoding) — "is it too early to call", "can a tech make the
  afternoon".

---

## Workiz quirks

- **A rename creates a second area by name.** Milwaukee → Wisconsin: the account
  sent both names through September; on 2026-09-28 all 11 "Milwaukee" jobs were
  the same 11 "Wisconsin" jobs, and 45 of Wisconsin's 47 zips were Milwaukee's.
  A whole account renamed (Washington → NY) and moved its work coast to coast.
  Defences: tombstone a retired area rather than deleting it, and alarm on any
  new area whose zips substantially overlap an existing one.

---

## What the dispatchers tripped over

- Showing the RESOLVED number per area per weekday is what made capacity
  predictable. EzBizi's Overview grid already does this.
- **A date picker, never a typed date.** Verbatim: *"i don't want that format, i
  want a date picker, not the yyyy--mm-dd shit."*
- **If someone can see a tab, they can do everything in it** (Jose). A1 showed
  buttons the server then refused. EzBizi's Time slots split — org-level users
  change windows, branch users set their own numbers — is a deliberate exception,
  explained on screen; any other split needs the same honesty on both sides.

---

## Where it meets EzBizi's structure — checked against OUR data

**(a) Franchise ceilings, when Jose fills in capacity.** Four A-1 franchises
only worked under a franchise cap: Ohio cap 6 against areas summing 30, DMV 6
vs 19, Illinois 20 vs 30, New Jersey 30 with no area numbers at all. EzBizi
has no franchise cap by design and Jose is entering capacity by hand, so
nothing is ported — but **per-area numbers must add up to what the branch can
really take**, or those four over-offer.

**(b) Cross-franchise zip contamination — REPRODUCED IN OURS, measured
2026-09-25.** EzBizi's `New York` area holds 88 zips and **34 are outside New
York's 100–149 range**: 19030 (PA), 07068 (NJ), 02813 and 02886 (RI), 06830
(CT), 48197 (MI), 73034 (OK), 39426 (MS). Cause, per A1: the Nationwide call
centre tags out-of-state jobs "New York" (~15% error), and a map derived from
job history records it faithfully. `Upstate New York` and `NYC` have one stray
each. Border zips may be real service; MI, OK and MS are not. A decision for
Jose, not a rule to guess.

**(c) Closed vs unconfigured.** Already open as §3.7 of the gap doc — an area
with no rows reads as closed. A1's evidence that it matters: 16 areas with no
open day all week are still taking work (Minneapolis 28 jobs in 90 days,
Pittsburgh 27, Denver 23, Miami 20, Delaware 18).

**(d) Windowless jobs** — see Seats taken above.

---

Commits to read, in A1's order of value: d5054fbc, d76f4a43, 2b0ba268,
7f3b2bda. Regression intent to port first: the write-gate shape and the
day-cap-vs-block arithmetic blocks in `api/_tests/franchise_capacity_test.php`.
