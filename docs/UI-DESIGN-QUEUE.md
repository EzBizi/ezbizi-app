# UI/UX — the queue, and what has been settled

Companion to `docs/MODULE-OWNERS.md`, written for the same reason and after the same
failure: in ninety minutes on 2026-08-31 the Phone, Fleet, Customers and rules-desk
sessions all ended, and four design briefs existed only in one session's context.
A brief that lives in a session is a brief that will be re-gathered from scratch.

**UI/UX owns the decision, not the module** — the rules desk's wording, and better
than the one first broadcast. A module session keeps its own screen files for
bugfixes, copy and wiring; anything changing **layout** goes through design.
The four cross-cutting files are design's outright: `client/lib/core/theme.dart`,
`module_screens.dart`, `dashboard_panels.dart`, `ui_strings.dart` — *styling* in them,
not registry rows. A one-line `key → icon` or `key → screen` entry is a registration
and is the module session's to make.

**Process** (`StartUp.md` §6, which predates the role): for a new tab or major screen,
produce several alternatives as HTML mockups in the app's own chrome with the real
palette and real data, **numbered so the owner answers by number**. Worked examples
live in `docs/previews/`. Jose answers by number.

---

## Settled rules

**Prominence is the module's, arrangement is design's.** Changing how loudly an
existing component states a state — grey caption to red banner, adding a count,
naming a state the screen left silent — stays with the module session and ships
immediately. A new component, a new section, or a new arrangement goes to design.
The carve-out exists because that change is often *the correctness fix itself*, and a
design round trip on a defect's critical path costs more than it buys. Phone's
evidence: five production lines went to voicemail for months because that state was
disclosed in grey secondary text. Condition: tell design after shipping, so the same
state does not become a red banner in one module and a grey pill in another.

**The vocabulary for that.** `errorContainer` + a **count** for "this is broken and
nobody noticed"; inline error-coloured text for a single row. Set by Phone, which
shipped four instances of it in one day.

**Rail width follows the longest thing the rail lists.** Not a single constant. Web
Management is 288/900 because a subdomain like `nj.a-1chimney.com` truncates below
280 and its detail pane needs ~560 for a field row plus its shortcode chip; Fleet and
Phone are 200/600 because they list short names and have no chip. Two measured
reasons, not two accidents.

**An editable field may not hide when empty.** It could then never be filled. Fleet
is the case: 6 of 21 Identity fields are empty across the entire production fleet and
every one edits in place. Progressive disclosure has to be something the user
controls, never something the data decides.

**Assert `findsNothing` for `AlertDialog` and `showDialog` in every screen's widget
test.** Tasks Phase 1 shipped two dialogs against the no-pop-ups rule and an audit
caught them weeks later; every Phase 2 screen carries the assertion. It is the only
reason the rule holds anywhere it holds. *Proposed as a house standard — the rules
desk session ended before it could be recorded there.*

**Name the design in the commit message.** The deploy desk's ask, and it costs
nothing:

    design: docs/previews/<file>.html #<n>

Approved designs are numbered layouts in a committed file, so the pair is unambiguous.
Without it the deploy desk cannot tell Jose what shipped, and is right not to guess.

**Design sign-off is not a deploy gate.** The deploy desk gates on tests, migrations
and freeze safety — things it can verify. It declined to add "has this been through
design?" on a peer's say-so, which is correct: a role must not be enforceable by the
session that holds it. Only Jose can put it at the gate.

**Never express a state by fading the element that carries text.** Transparency attacks
legibility directly, and it always bites hardest in the state where legibility matters
most — dragging, disabled, loading, pending. Express it with elevation, an outline, a
border, a cursor, or a ghost placeholder; **the ghost carries no text, so fading *it*
costs nothing.** That last clause is the practical half: the rule says where the
transparency is allowed to go, not merely where it is forbidden.

Found in a Gantt bar: `scheme.primary.withValues(alpha: dragging ? 0.5 : 1)` wrapping
`Text(task.title, color: scheme.onPrimary)`. At 50% over white, #00AEEF composites to
about #7FD6F7, and white on that is **≈1.6:1** — the label all but vanishes at the exact
moment the user needs to know which task they are holding. Note this is **independent of
the token defect below**: `_cyanDeep` at 5.65:1, halved by the same alpha, lands near
2.6:1 and still fails. Fixing the token would have made everyone believe this was handled.

**Horizontal scroll is legitimate where the DATA is wide, and illegitimate for CHROME.**
A table and a Gantt should scroll sideways — horizontal is the data's own axis. A tab
strip or a view selector has no natural width; it is only as wide as someone's label
list happens to be, which is not a reason to make a user drag. Chrome that overflows
at 375px **wraps**. Tasks Phase 2 had two stacked chrome bars
(`task_presentation_bar.dart`, `task_views_bar.dart`) both clipping flush at the right
edge with no affordance.

Prefer `Wrap` over any scroll cue. A fade needs a gradient stop — a colour literal, and
a second one for dark mode, against the `theme.dart` rule. An arrow is a control that
is not one: tap it and either nothing happens or it moves by an amount nobody chose. A
peek is the best cue and still explains a scroll that need not exist.

**An isolation widget test cannot see the chrome around what it mounts.** Tasks Phase 2
asserted zero horizontal scrollers at 375px for `TaskKanbanView` — true, passing, and
blind to two overflowing bars the screen mounts above it. Assert layout floors on the
**screen**, not the widget.

> **"A true measurement of the wrong thing."** The Tasks session's name for the failure
> shape behind four separate escapes in one plan: a suite proving a recurrence's first
> hop and silent on the series; a timezone test that cannot fail on half the machines
> that run it; a pixel diff perfect at *did only the intended region change* and
> structurally incapable of *does it look right*; an isolation assertion blind to the
> chrome above it. Every one passed. Every one was measuring correctly. Ask what a
> green test is not looking at.

**Goldens and briefs live on branch worktrees, not `main`.** `git worktree list` — Tasks
Phase 2, Fleet, Training, Web Management and others each have their own tree. Reviewing
a module's golden PNGs from `main` reviews the wrong file, silently, because the paths
are identical. Confirm the tree before reporting pixel findings.

---

## Known gaps in the four cross-cutting files

**`dashboard_panels.dart:73-75` — a new module can silently get the wrong icon, and
the gate stays green.** The lookup ends
`byModule[key] ?? byModule[key.split('.').first] ?? Icons.insights_outlined`, over
twelve module entries. `GlanceRegistryTest` asserts sidebar modules against glance
keys as a **sorted-set equality**, so a module declared *without a glance* turns the
gate red — loud, and it has already caught Training and Scheduling. **Nothing asserts
the icon map.** A module that registers its glance correctly and omits an icon row
passes, and renders a generic chart nobody chose, permanently. The two failure modes
are asymmetric and the silent one is the one that reaches a customer. Likely fix:
extend that same sorted-set equality to cover the icon map. *Raise with the deploy
desk before landing — it is their test as much as this file.*

Credit and framing to the deploy desk, which corrected its own earlier account of
this file rather than letting it stand: **a conclusion that survives only because of
a fallback is true today and quietly fragile.**

**`theme.dart` — `colorScheme.primary` fails WCAG AA as text, app-wide.**

    ColorScheme.fromSeed(seedColor: _cyan, primary: _cyan, surface: Colors.white)

`primary` is explicitly overridden to raw `#00AEEF`, overriding the darker tone Material
would otherwise derive from the seed, and light-mode `surface` is explicitly white.
So `primary`-on-`surface` is **#00AEEF on #FFFFFF ≈ 2.5:1**. AA needs **4.5:1** for
normal text and **3:1** for large text and non-text UI — it fails both. `onPrimary:
Colors.white` on a cyan fill is the same 2.5:1 in the other direction.

Modules using `colorScheme.primary` for text are doing exactly what the house rule
asks; **the token is wrong, not the usage.** The palette already carries the fix:
`_cyanDeep #00708F` on white is **≈5.65:1**, comfortably AA, and is already wired as
light-mode `secondary`. Likely answer: cyan keeps large fills and brand moments,
`_cyanDeep` carries text and small elements on light surfaces. Dark mode needs its own
check — this measurement is light mode only.

*Touches every module, so it is a `theme.dart` item for Jose, never a change slipped
into a module's plan. Accessibility is on the never-simplify-away list.*

**Colour literals are house-ruled but ungated.** Counted 2026-08-31: **78 occurrences
across 22 files** outside `theme.dart`. The raw number overstates it — 9 are
`Colors.transparent`, 2 are `signature_field.dart`, the sanctioned exception. That
leaves ~67 candidates, **29 of them raw `Color(0x…)`**, not individually inspected and
so not yet called violations. The actionable fact is concentration: **four files hold
51 of the 78** — `widgets/auth_preview.dart` 18, `screens/email/message_list.dart` 13,
`widgets/auth_shell.dart` 11, `screens/hr/contract_document_page.dart` 9. Two are the
login surface. Bounded cleanup, not a sprawl.

**`module_screens.dart` is the intersection of declared modules and client screens.**
Declaring a module cannot put a tab in front of an older binary — the frozen 0.0.0112
build has no `training` key at all. Reassuring direction: a design cannot reach a
client that has no screen for it.

## Inherited constraints (not design's to change)

From `StartUp.md` §6 and the departed rules desk. `docs/ACTIVE-CONSTRAINTS.md` is the
live one — read it rather than this summary.

1. **No modals. Ever.** A new screen or a right-side panel, including
   confirm-before-destructive and including text entry. `customer_detail_panel.dart`
   is the worked example of the panel pattern; do not invent a second dialect.
2. **Colours only from `theme.dart`.** One deliberate exception exists and is the
   shape of a good one: `signature_field.dart` names black ink and white paper
   because those are the pixels of a printed document.
3. **375px must work.** Touch and pinch first-class. A floor, not a target.
4. **If the answer would be 403, do not draw an enabled button** — including per-row.
   The row is disabled carrying the **server's** sentence from
   `app/Support/Access/Refusal.php`, with a server field saying so. A client-side
   copy of the rule will drift.
5. `ui_strings.dart` has two migrations behind it, so some strings are tenant data.
   A copy change is not a one-line edit.

---

## Queue

| # | Item | State |
|---|---|---|
| 1 | **Phone · Caller Identity & Trust** | Five layouts built — `docs/previews/phone-trust-layouts.html`. **With Jose.** |
| 2 | **Fleet · vehicle Identity card** | Briefed, see below. Not started. |
| 3 | **Phone · Settings rail** — 14 sections, grew one at a time | Not started. |
| 4 | **Web Management · per-page value overrides** | Briefed, see below. Not started. |
| — | **Scheduling · the three tabs** | **Blocked by Jose** — he chose "not decided yet" on whether Availability means staff, equipment, or customer booking slots. Nothing gets drawn on a guess. |

### 1 · Phone · Caller Identity & Trust — two open rulings

- The rail holds **both "Trust" and "Number quality"**, and both answer "is something
  wrong with my number". Recommendation: this screen absorbs the existing Trust
  section (port-in / carrier review) as **Trust & caller identity**, taking the rail
  14 → 13. Drawn merged. *The overlap was inferred from a section list, not read from
  code — verify before building.*
- **The screen is empty on day one and stays partly empty forever.** EzBizi discards
  StirVerstat on inbound calls, so no attestation history exists anywhere and none
  will until capture is built. Layout 4 is that day-one screen.
- **Attestation percentages cannot share a denominator.** The reference shows 61 calls,
  30 graded A, 31 not captured — "0.0% degraded" is over the 30, not the 61. A naive
  bar reads half-alarming. The mockup hatches the gap and says in words that it is an
  absence, not a bad grade. **Confirm the real payload computes it the same way.**
- No Twilio API reports whether a number currently shows as "Scam Likely". The
  observation log is a human record and must read as deliberate, not as a failed sync.
  The Trust Hub Business Profile is **console-only** — no API creates one — and Voice
  Integrity needs it *approved*, not merely created, which is why the two registrations
  can differ for one reason while looking independent.

### 2 · Fleet · vehicle Identity card

Reported by the Fleet session against the 24 real A-1 vehicles; **measured by them,
not re-verified here.** Card edits in place — every field a live control, enabled on
`fleet_management.edit`, one Save, no separate view mode. Read-only layouts are
therefore not on the table.

Fill rates, 21 fields of 24 vehicles: **always** year, make, model, plate_state, vin,
body_type, fuel_type, status · **22** colour · **16** odometer_miles,
odometer_updated_at · **9** plate · **3–4** toll_device_number, toll_plan,
registration_expires_on · **0** trim, next_service_on, next_service_miles,
inspection_expires_on, toll_flat_rate_cents, notes.

**The finding, and it is not a layout problem:** `plate_state` is 24/24 while `plate`
is 9/24, because the import correctly nulled eleven typed absences ("NA", "(NEW)").
Fifteen vehicles render "Plate state: CT" beside an empty Plate — two fields, one
fact, and the always-present half is the useless half. Candidate: one control, a plate
field with a state selector attached.

`toll_flat_rate_cents` is in the API and DB with **no control at all**. If surfaced it
goes behind `fleet_management.financials` — the server already strips it from the
payload without that permission, so a control drawn unconditionally is an empty box.

### 4 · Web Management · per-page value overrides

No UI exists; data and API already support it. **Zero overrides in production** — the
module's primary answer to "this page needs a different number" is a different *site*
(a subdomain). Per-page is the escape hatch. Design for three pages with one override
each; make forty degrade rather than break.

**Override is per KEY, not per page** — a page overriding `phone` still renders the
site's address, email, hours and licence. Both consumers resolve identically.

**And `ValuesController` deletes on empty rather than storing `""`.** So "cleared" and
"inherited" are the same stored state. A design showing them as different states would
be drawing a state the backend cannot hold; a design showing an empty box for both
would let someone clear a field and get the site's phone number instead. **Settled:**
two states, and clearing visibly reverts to the inherited value shown in place —
cleared and inherited look identical *because they are* identical.

**Field requirement tiers — shipped `ec0e114`, Web Management's work.** A blank `phone`
and a blank `license` are opposite facts stored identically, so no single empty
treatment can be correct for both. Now a per-key `requirement` beside `label` and
`hint`, asserted by the catalogue, never set by the user, present on all three
endpoints:

| tier | keys | empty treatment |
|---|---|---|
| **required** | `company_name` `phone` `address` | prompted at the field, inline and unmissable |
| **conditional** | `email` `hours` `license` | **the field says nothing.** Appears once by name in a single collapsed line: *"Not published on this site: hours, licence."* |
| **optional** | `service_area` `booking_url` | nothing, anywhere |

The middle tier earns its place only if a design actually draws that summary line —
otherwise collapse it to two. Never a per-field nag: an appointment-only business
would meet it every visit forever, which is how people learn to ignore a screen.

**The split is a guess and its docblock says so.** No customer has filled more than
two of the eight keys, so every assignment is reasoning about what a trade business
publishes, not usage data. A test pins the required three deliberately — moving a key
between tiers should be a decision, not a side effect of editing a list.

> **Open for Jose — a product judgement, not a design one.** `license` is the least
> certain. A New Jersey chimney contractor must display a licence number, so for A-1
> a blank licence is arguably unfinished; but the catalogue serves every customer and
> most trades in most states are not licensed. Conditional risks mentioning it to the
> unlicensed majority; optional means A-1's own missing licence goes unmentioned.
> Under the treatment above the stakes are mild — `conditional` never nags, it only
> puts the word in a one-line list — so the real question is narrow: *should an
> unlicensed business see "licence" once among things it does not publish?*

*Correction, recorded so it is not re-derived: an earlier version of this reasoning
claimed the setup-blocks-above-when-unconnected rule needs to know when setup is
finished and cannot. That was wrong — it keys off `connected_at != null`, whether a
plugin has ever read the site, which the module answers exactly. The tier decision
rests on the opposite-facts argument alone, which is sufficient on its own.*

---

## Naming, because it will bite whoever reads this

Two adjacent dashboard panels must not share a word for different facts. HR's
**"Expiring certifications"** counts date fields a *contract template* marked
`is_expiry` — a licence the employee typed at onboarding, **theirs** to renew.
Training's counts our own test passes lapsing, **ours** to re-assign.

> Different table, different fact, **and a different person responsible for acting on
> it.** That last clause is the real argument, and it is stronger than the naming one:
> **two numbers that summon different people must never be one number**, whatever they
> are called.

Settled with Jose: Training never says "certification" and says **"due for retaking"**.
They stay two panels. Carry the wording into field names too — `people_due_for_retaking`
rather than `people_expiring` — so the wrong word cannot leak back in through a variable
three modules later.

### Training home glance — endpoint shaped before it locked

`GET /training/dashboard` returns `{id, title, assigned, passed, in_progress, overdue,
retaking}` **per guide**. Correct for the module's Dashboard tab, which is a list of
guides. **A home glance cannot be built from it.** Summing `overdue` across guides counts
*assignments, not people* — one person overdue on three guides sums to 3, and an owner
goes looking for three people who do not exist. De-duplicating a person across guides
needs the join; the client does not have it.

Asked for, alongside the array rather than instead of it:
`summary: { people_assigned, people_overdue, people_due_for_retaking }` — distinct people,
org-wide, same permission reach. If only one survives, `people_overdue`; it is the one an
owner acts on.

*Caught inside a closing window — after Task 11 it becomes a contract four Flutter tasks
consume. Worth asking every module session what a home glance would need **before** its
module dashboard endpoint reviews clean, because the glance always wants a roll-up the
per-row shape cannot answer.*

## Approved designs that predate the role — do not reopen

Both were approved by Jose directly, before the role existed, through the same
shape of process. Reopening either is his call, not design's.

- **Training** — eight numbered options, picked by number: Learn 1, Guide player 4,
  Dashboard 6, Settings 8. Committed at `docs/previews/training-layouts.html` (`7c65a53`).
  *Open question worth Jose's attention, not a change request:* Settings layout 8 is a
  storyboard strip, a pattern that exists nowhere else in the app — every other module
  uses list-beside-editor. It was flagged to him and he chose it anyway.
- **Tasks Phase 2** — Kanban, Calendar, Timeline/Gantt, Workload, plus dependency and
  recurrence panels. Approved in `docs/superpowers/specs/2026-08-28-tasks-phase-2-design.md`.
- **Web Management · SEO panel** — two-pane list/detail, 288/900, shortcode as a copy
  **button** rather than helper text, setup blocks above the fields when a site is
  unconnected and below once connected. Shipped in 0.0.0118. The last two generalise
  and are worth stealing: a value whose only purpose is to be pasted gets a copy
  button, and position can carry "is this what you should be doing right now".
