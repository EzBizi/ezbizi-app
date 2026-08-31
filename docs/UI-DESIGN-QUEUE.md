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

**Never redefine a field or a number in place — add a new one.** Five distinct
instances in a single day: summing per-guide `overdue` and calling the result people;
a safety note that was right for the wrong reason; an offer to redefine
`people_due_for_retaking` from *already lapsed* to *lapsing within N days*; and a ✅ in
this file's own queue meaning both "being built" and "nobody is touching this". Each
time, **the number stays plausible and the meaning moves**, so nothing announces the
change and every existing reader is quietly wrong. If the meaning shifts, the name
shifts.

> **The fifth, found in a JSON key by the Phone session, is the cleanest example
> of the shape.** `TwimlRenderer::describe()` gives every step a `usable` bool —
> but on the *menu* step it means "this step does not break the call", and on the
> *answer* step it means "reaches somebody". Two meanings, one key. So a design
> asking for `usable` as its skip flag would have rendered the one step it was
> drawn for — a menu that is configured and silently skipped — as perfectly fine.
> The fix is a distinct `skipped` bool, not a widened `usable`: **when a key has to
> answer a second question, it gets a second key.**

> **Why writing the rule down twice did not stop it happening in the file that
> contains it:** this failure is markedly easier to see in someone else's data than in
> your own status column. Nobody audits their own progress markers — a tick you wrote
> reads as the thing you meant when you wrote it, not as the thing it now says. So the
> rule needs a habit attached, not just a statement: when a marker could describe two
> states, split it before anyone has to ask which one it means.

**Two panels that summon different people must differ in tense as well as wording.**
HR's certification panel is a *lookahead* with a `days` window defaulting to 60 —
nobody is out of compliance yet. Training's counts passes that have **already lapsed** —
somebody is uncertified right now. A lookahead was offered for symmetry and declined:
making them read alike would hide that one is a warning and the other is a failure in
progress. So HR names its window ("expire in the next 60 days") and Training stays
present-tense ("need to retake something").

**Preview palettes in `docs/previews/` are snapshots, not the source of truth.** All
eight files hard-code `--cyan: #00AEEF`, which `theme.dart` no longer uses for `primary`.
They are **not** being retro-fitted: what Jose approved in each is the *layout*, and
re-touching an owner-approved artefact to chase a token invites the question of whether
the approval still stands. Client work takes colour from `theme.dart` by house rule and
so cannot inherit a stale mockup value. If a mockup's chrome differs from the shipped
screen, the mockup is the stale one.

**Hours has no time picker, and must not gain one.** A picker is a dialog, and this
codebase has no modals. Times are a **masked text field** — validated as real times,
from-before-to, equal refused, masking as it is typed (`8910b88`). Any Hours layout
that assumes a picker affordance is designing against a rule, not a gap.

**A retry belongs where the error was a failed LOAD, not on every error.** The pane
head's retry *reloads*. A form's error is usually a refused **save**, so putting a
reload there offers to recover somebody's work by discarding it — retrying a refused
save is pressing Save again. So: **failed load → retry in the head; refused save →
reported at the footer, retried with Save.** Most panes can do both and must tell them
apart. Found by the Phone session while building Voicemail, against a rule I had
written too broadly.

**Classify a pane by reading it, not by sampling its strings.** Voicemail was recorded
as a *form* in a 13-pane audit and drawn as one. It manages **multiple mailboxes** —
`_boxes`, New mailbox, per-row Edit and Delete, "Back to the mailboxes". Building the
approved drawing would have deleted multi-mailbox support; the module session caught it.
The audit classified from string samples and widget counts, which is a measurement
narrower than the claim built on it — the same failure this file records five other
times, committed by the person recording them.

**A layout that narrows something will expose overflow that was always there.**
Splitting Numbers put its line form in a 320px column for the first time and three
dropdowns plus the place picker overflowed — no `isExpanded`, so "Nobody — go straight
to voicemail" laid out at its natural width. The defect was real the whole time; the
form had simply never *been* narrow, because it always had the whole pane.

> This is the day's measurement theme running backwards. Everywhere else the
> instrument was too narrow for the claim. Here the **condition** was never created,
> so a correct instrument was never pointed at it. When a design narrows a container
> that was previously full-width, expect latent overflow, and treat it as
> **pre-existing rather than caused** — the split did not break those dropdowns, it
> made them ordinary.

**A `Widget` in an `InputDecoration.label` reports its full intrinsic width** and will
overflow a narrow field (measured: 81px at 560). Markers that belong *to* a field —
"also in General", "needs Financials" — go in the help text, which wraps, unless the
design deliberately spends vertical rhythm to put them outside the decoration.

**Grouping a list reorders it, so anything keyed on POSITION breaks silently.**
Adding headings to the Phone rail moved Trust above Tags, and the body switched on the
section's *index* — so one move repointed every section below it. You tap Trust, you get
Tags, and it reads as a rendering bug rather than an off-by-one. Keying on position had
been *safe rather than correct*, for exactly as long as nobody reordered a flat list.
**Any design that regroups or reorders an existing list should say so explicitly**, because
the implementation may be relying on the old order without anybody knowing it.

**Group and order must live in ONE declaration, and headings must be derived from it.**
Two lists cannot be made to agree. A section listed under *Answering* but drawn between
two *Connect* entries is a heading that lies — worse than no headings at all, when the
point of headings is to let somebody stop reading early.

**A group of one still earns its heading when the heading is the finding aid.** Phone's
*Identity* group holds one section. It stays a group: it is the answer to "why are my
calls marked spam", and being buried among housekeeping is how it went unfound.

**A sabotage that does not apply looks exactly like a passing test.** Verifying a check by
breaking the thing it guards only proves something if the break landed. Caught three times
in one day — twice from CRLF, where a pattern written with `
` silently fails to match a
file with `

`, the replace does nothing, and the run passes green. **Confirming the
sabotage applied is part of doing one.**

**Do not collapse the reasons an incapacity exists — the reason decides who fixes it.**
Ring groups was drawn with two unavailable states (signed out, Do not disturb) and the
data has **three**: *never signed in to Phone* is a separate fact, and it is usually
somebody who **does not know they were put in a ring group at all**. Those need three
different actions — go and tell them, wait, or respect a deliberate choice — so
flattening them into "unavailable" throws away the only part that tells you what to do.
Found by the Phone session while building `10ff9d0`, against a drawing of mine that
implied two.

**When the screen needs a fact the server computes but discards, grow the endpoint —
never recompute it in the client.** Reachability lived inside `DialPlan::batches()` and
was knowable only mid-call; the pane got member ids and nothing else. `reachableUserIds()`
now exists and `batches()` calls it, so the screen and the dialler share one rule. The
same shape as `describe()` keeping one boolean out of a whole trace. **A client-side
reimplementation of a server rule is a second copy that will drift**, and the drift is
silent because both sides look right in isolation.

> The test for that pair has to run against a **mixed** group. All-reachable or
> all-unreachable passes against two implementations that disagree — which is a
> condition never created rather than an instrument too narrow, the same trap as the
> `isExpanded` overflow.

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

> **THE APP STORE FREEZE IS OVER** (2026-08-31, confirmed by Jose via the deploy desk).
> `ACTIVE-CONSTRAINTS` §1 — no change to an existing response shape while a frozen
> `.ipa` ran against production — is **gone**. Anything a design deferred for that
> reason is unblocked, and "additive only" is no longer a constraint on a proposal.
> §2 (minimum client version) and §3 (SES sandbox) outlive it.
>
> Designs drawn under it and now free to ask for more: widening
> `describe()`'s trace instead of keeping one boolean (Phone · General), the
> distinct `skipped` bool, Training's `summary` roll-up, and the Numbers
> recommendation that moves line editing into General.

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

**Approved designs are pinned to a SHA, not to "on main".** Main moves every two or
three minutes on a busy afternoon, and a design approved *as drawn* is a claim about a
specific drawing. `git show <sha>:docs/previews/<file>` is what was approved; the file
on main may have moved on.

> **🔨 being built · ⏳ approved, an owner holds it · 📬 approved, routed to a desk, no owner yet · ⚠️ unowned and unrouted · ✅ never used here.** An approved
> design with nobody building it *looks* like progress on a queue and is not — it is
> a drawing. The deploy desk's line, and it applies to this file before it applies to
> anyone else's: a ticked row that means "waiting for a session that does not exist"
> is the same failure this doc warns about twice elsewhere — **the marker stays
> plausible and the meaning moves.** A row leaves this table when it ships, not when
> it is approved.
>
> **And an unowned design is ROUTED, not escalated.** A finished drawing with no
> module session goes to the deploy desk, which outlives individual sessions. It does
> not go back to Jose as a staffing request.
>
> The failure underneath, named by the deploy desk after we both made it three times:
> **an item genuinely blocked on a decision and an item merely lacking an owner look
> identical in a list.** The first belongs on the owner's desk. The second only gets
> there because nobody wants to be the one holding it. Same shape as a tick that means
> two things — and it applies to what design puts in front of Jose, not only to this
> table: **if the answer is "find someone", it is not his to answer.**

| # | Item | State |
|---|---|---|
| ✅ | **Phone · Caller Identity & Trust** | **SHIPPED `cb27c68`.** Layout 1, built from the `d66bcef` pin. Merged with the existing Trust section as *Trust & caller identity*, absorbing Number quality, rail 14 → 13. This row read "being built, held by `ezbizi-0b`" for hours after it shipped, because the holder named in it stopped existing at a restart and a 🔨 cannot tell that from work in progress — the failure this table warns about, in the table. |
| 🔨 | **Scheduling · Availability + Settings** | **Layout 7 approved, being built** (Overview + area rail — Jose's own combination of 1 and 2); Availability is **layout 4**. `docs/previews/scheduling-layouts.html` @ `560aed5`. Held by the Scheduling session. |
| 📬 | **Fleet · vehicle Identity card** | **Layout 2 approved, handed to the deploy desk to route.** `docs/previews/fleet-identity-layouts.html` @ `560aed5`. The Fleet session that briefed it ended before it was drawn. **Not urgent** — nothing in Fleet is broken; the card works and the complaint was a long scroll at 375px. Fold it into the next Fleet work rather than staffing for it. |
| 🔨 | **Phone · Connection** | **Layout 1 approved** — `docs/previews/phone-connection-layouts.html` @ `fc5b838`. Draws the org→company inheritance as a tree. Replaces **six prose variants** of one rule, each correct, each different depending on where you stood. No footer (nothing to save); retry in the head (its error is a failed load). |
| 🔨 | **Phone · Hours** | **Layout 3 approved** — `docs/previews/phone-hours-layouts.html` @ `f545c31`. Today’s shape kept: opening a set **replaces the pane**, with Back. **Deliberate, not an oversight** — Numbers is a split and Hours is not, because seven rows of masked time fields want full width. *Say so in the code*, or somebody will later “fix” Hours to match Numbers. Holidays get their own bordered block above the week; a day with no times is an absent row, never empty fields. **No time picker** (`8910b88`). |
| 🔨 | **Phone · Ring groups** | **Layout 1 approved** (split pane). `docs/previews/phone-ring-groups-layouts.html` @ `f545c31`. **The point is not the split:** reachability is a COLUMN of the member list, drawn from `DialPlan::batches()` — a group of four can ring nobody and look fine today. Tally is timestamped ("as of a few seconds ago"), never implied live. Keep the 60-second timeout explanation inline. Expect latent overflow in the narrowed detail column — pre-existing, not caused. |
| ✅ | **Phone · Settings rail** | **SHIPPED `0d8e52c`.** Layout 1 (grouped rail) — `docs/previews/phone-settings-rail-layouts.html` @ `6389714`. Queued behind Trust with the Phone session. |
| 4 | **Web Management · per-page value overrides** | Briefed. `license` ruled **conditional**. Not started. |

**Phone, shipped 2026-08-31 and not re-listed above:** Voicemail on the shared pane skeleton (`3223015`), Numbers split pane (`b25c3db`), staff messaging (`ed3b35f`), extensions (`8b82ed2`), and the rail-is-a-switch guard (`753556b`). Still approved and NOT built: **Connection**, **Hours**, **Ring groups** — the three 🔨 rows above, held by the Phone session (`ezbizi-0b` → this one).

**Also approved and shipped 2026-08-31:** the `theme.dart` contrast fix and the
colour-literal ratchet, both in `d0ce75a`.

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

### Scheduling · Availability + Settings

Availability means **dispatch capacity** — jobs takeable per slot, per weekday, per
service area. Areas hold zip codes; one zip belongs to exactly one area, enforced.
Slots and capacity are **per area**, not franchise-wide (Jose ruled directly).

Jose's two shaping answers: area count **"varies a lot by franchise"** — two and thirty
are both real, so the area list is searchable and never assumes it fits; and slots are
**"the same everywhere in practice"**, so the grid is regular and divergence is the
exception. Together those make *configure one pattern, apply to every area* the primary
act, not a convenience.

**Three constraints that are easy to lose:**

- **Closing a day must DELETE slot rows, never write zeros.** Storage distinguishes "we
  do not work Sundays" (no rows) from "we work Sundays and are full" (rows at zero). If
  a close affordance ever zeroes instead, the two collapse into one appearance and
  nothing errors. The layouts rely on this: a closed day is an **absent column**, not a
  column of zeros — which is also what makes the week fit at 375px.
- **No per-date overrides exist and none are designed** — capacity is per weekday and
  recurring. But the cell is *shaped* to carry an exception marker nothing sets, because
  "closed this Thursday for the holiday" is close enough to certain that designing it
  out would be a choice. No field, no endpoint, no creation UI.
- **Nothing burns a slot yet** — Scheduled Jobs does not exist, so every cell reads full
  capacity forever. **Ruled by Jose: the cell is called "configured capacity", not
  "remaining".** That is what the number is; the lie was the noun, not the number. It
  becomes "remaining" when Jobs lands, and the label moves with the meaning.

**No pattern table — settled, and the reasoning matters more than the verdict.** A stored
pattern is not one table, it is a template system: it needs a precedence rule (does
editing it rewrite existing areas or only new ones?), an override model (does an area's
exception survive a pattern edit?), and a migration for areas that already diverge. Three
decisions nobody asked for, each a place for a fact to quietly change. The pattern is
derivable — comparing slot sets is cheap — so "apply a pattern" is just a write: delete
an area's rows, write the new set. No pattern object, nothing to keep in sync.

**And no intent column either, on a narrower argument.** Comparison can tell that an area
*differs*, not whether it differs *on purpose* — so a bulk apply could silently flatten a
deliberate exception. But ask what actually writes a drift: with one write path and one
human, **difference is intent by construction.** The single exception is a bulk apply
that reaches some areas and not others. So close that path rather than recording intent
around it:

1. **All or none.** A partial apply is the only thing that manufactures a difference
   nobody chose; it should not be a reachable state.
2. **Name what will be overwritten, before it is** — the actual areas and values, not a
   generic warning. The user re-states intent when it is at risk.

> **The trigger for revisiting:** once a franchise holds more than a handful of deliberate
> exceptions, that confirmation becomes a wall people click through — which is the silent
> flatten again. Then a nullable "deliberately its own shape" column on `service_areas`
> earns its place, with real data about which areas rather than a guess. Zero franchises
> have used this once.
>
> Note this lands opposite to the per-date exception cell, deliberately: shaping a cell
> costs nothing now and a re-cut later, so it was done; a column costs a migration either
> way, so it waits for a reason.

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
