# Tasks Phase 2 — planning and capacity

Accepted 2026-08-28. Phase 1 shipped the same day in release `20260828151058`.
Design for Phase 1: `docs/superpowers/specs/2026-08-25-tasks-design.md`.
Plan for Phase 1: `docs/superpowers/plans/2026-08-25-tasks-phase-1.md`.

---

## 1. What it is

Phase 1 replaced ordinary Monday board use: a grid of tasks in groups, owned and
commented on and filtered. Phase 2 answers the questions a grid cannot — *when*
does this work happen, *in what order*, and *who is holding too much of it*.

Five presentations over the tasks that already exist, one new structural date,
one small graph, and recurrence. No new access model, no new writer, no new audit
path. Phase 2 is mostly reuse, which is why it is smaller than Phase 1 despite
listing more features.

---

## 2. What it is not

- **Not a scheduler.** Dependencies are drawn, not enforced. Nothing reschedules
  itself, nothing is refused for being out of order.
- **Not time tracking.** No effort estimates, no hours, no capacity model.
  Workload counts tasks.
- **Not real-time.** Server-push refresh is dropped; see §13.
- **Not cross-organization.** Every view obeys the exact-view and private-board
  rules Phase 1 established. A Phase 2 presentation cannot reach a task its
  viewer could not already open.

---

## 3. Settled decisions

These were decided by the owner on 2026-08-28 and are not open in implementation:

1. **Dependencies display, they do not enforce.** No completion refusal, no
   override path, no date propagation.
2. **Start date is a structural field on the task**, not a per-board column type.
3. **Workload counts tasks.** No estimate field, no capacity setting.
4. **Recurrence supports both modes, chosen per task** — on-completion and
   on-calendar.
5. **Server-push is out of scope.** Polling with conditional ETags stays.

---

## 4. The presentation contract

Phase 1 stored every saved view as `task_views` with a versioned configuration
whose `presentation` was always `"table"`, and `TaskViewCodec` rejects any other
value. That rejection was the deliberate extension point. Phase 2 widens it.

The configuration schema becomes `schema:2`:

```
{schema:2, presentation:"table"|"kanban"|"calendar"|"timeline"|"workload",
 filters:[], sorts:[], hidden_columns:[], column_order:[], column_widths:{},
 group_by:null|string, search:string, window:null|<presentation window>}
```

**Schema 1 rows are read without migration.** The codec upgrades a `schema:1`
row to `schema:2` on read by setting `presentation:"table"` and `window:null`; it
writes `schema:2`. Nothing is rewritten in place, so a rollback does not strand
rows in a schema the previous release cannot read.

An older client reading a `schema:2` view **is** a case that exists, and will for
as long as `docs/ACTIVE-CONSTRAINTS.md` does: the binary in App Store review is
frozen at `v0.0.0112` and knows only `schema:1`. So the row the server returns to
any client keeps every `schema:1` field at its `schema:1` meaning, and adds
`presentation` and `window` alongside. A frozen client ignores the two it does
not know and renders the table it always rendered. This is why `presentation` is
an added field rather than a replacement for anything.

Every presentation validates its own `window` and nothing else. An unknown
presentation, or a window belonging to a different presentation, is refused by
the codec before any identifier reaches SQL — the same rule Phase 1 applied to
filters and sorts.

The permanent Main table keeps `presentation:"table"` and cannot be changed to
another presentation. Every other saved view may declare any presentation, and
the personal/shared-unlocked/shared-locked rules from Phase 1 §11 apply
unchanged.

---

## 5. Windowing, and why there are no new endpoints

Four endpoints would mean four places to get exact-view and private-board access
wrong. Phase 1's security rests on one path; Phase 2 keeps it.

`GET tasks/boards/{board}/rows` stays the single query surface. It gains a
`window` parameter whose shape depends on the requested presentation:

| Presentation | Window | Returns |
|---|---|---|
| `table` | `{page, per_page}` | unchanged from Phase 1 |
| `kanban` | `{bucket_field, buckets:[id...]}` | complete accessible set per named bucket |
| `calendar` | `{from, to}` (dates) | every accessible task whose due date falls inside |
| `timeline` | `{from, to}` (dates) | every accessible task whose span intersects |
| `workload` | `{from, to, unit:"day"\|"week"}` | per-owner counts per unit |

A window is **bounded by the server**, not by the caller's honesty: a calendar or
timeline range wider than 400 days, or a kanban asking for more than 50 buckets,
is refused with a sentence. Unbounded windows are how a board view becomes a
table scan.

Summaries continue to describe the **complete filtered accessible result**, which
Phase 1 already promised for group counts. A Kanban column header showing 12 when
the board holds 40 matching tasks is the defect this rule exists to prevent.

Conditional ETags cover the window: two requests differing only in `from`/`to`
are different cache entries.

---

## 6. Kanban

Cards in columns. The column field is the view's `bucket_field` and may be
status, priority, owner, group, or any Label custom column — the same set Phase 1
allows for `group_by`, which is not a coincidence: Kanban is Group by, drawn
sideways.

Dragging a card between columns performs **the write that field already has**.
Moving between status columns is the Phase 1 status write, with its version, its
audit row and its `task.status_changed` trigger. Phase 2 adds no write path for
Kanban, and a field with no write path (a read-only or archived column) renders
its columns but refuses the drag with the existing refusal sentence.

Cards show the fields the view has not hidden, capped at what fits: title, owner,
status, due date, and up to three unhidden custom values.

Empty buckets render. A Kanban that hides its empty columns hides the fact that
nothing is in review.

---

## 7. Calendar

A month or week grid of tasks by due date. Tasks with no due date do not appear
and the view says how many were omitted — silence would read as "nothing is
undated".

Dragging a task to another day is the Phase 1 due-date write, carrying its
version and producing the `task.due_date_changed` trigger and its due-revision
bump. Phase 1 already ties overdue and reminder occurrences to the due revision,
so a dragged task reschedules its reminders correctly with no new machinery.

The month is rendered in the **board's place timezone**, resolved through
`SettingsResolver` exactly as the Phase 1 overdue worker resolves it. A task due
at 23:00 must not appear on the wrong day for a viewer in another timezone.

---

## 8. Timeline / Gantt, and `start_date`

`tasks` gains `start_date` — a nullable date beside `due_date`, structural rather
than a custom column.

The reason it is structural: a per-board Timeline column would make every
cross-board view handle boards that lack it, add a join and a null branch to
every query, and make Timeline a configuration people must discover. One nullable
field on the core table costs one migration and gives every board a Timeline.

It is **additive to the task response**, which matters on 2026-08-28: App Store
review is live against a frozen client and `docs/ACTIVE-CONSTRAINTS.md` forbids
changing an existing response shape. A new nullable field is permitted; renaming
or removing one is not.

Rendering degrades by design:

- both dates present → a bar from start to due;
- due only → a marker on the due date;
- start only → a marker on the start date;
- neither → not drawn, and counted in the omitted total.

Dragging a bar's edge writes that date through the existing writer. Dragging the
whole bar writes both, in one transaction, at one version. `start_date` after
`due_date` is refused with a sentence; it is not silently swapped.

Dependency lines are drawn between bars. They are lines. See §9.

---

## 9. Dependencies

A new table, `task_dependencies`, holding `(organization_id, board_id,
blocker_task_id, blocked_task_id)` with the compound tenant/board identity Phase 1
requires of every child, unique per pair.

**Both tasks must be on the same board.** Cross-board dependencies would need an
access recheck per edge on every render, and a dependency whose blocker the
viewer cannot see is worse than no dependency — it is an unexplained line.
Phase 3 may revisit this with the connected-board work.

**Display only.** A dependency draws a line on the Timeline and a "Blocked by"
chip on the task detail. It refuses no write, changes no date, and blocks no
completion. There is no override path because there is nothing to override.

**Cycles are rejected on write** with a sentence naming the cycle. Not because a
cycle would corrupt anything — nothing traverses the graph — but because a
Timeline cannot draw one and a person who has drawn a cycle has made a mistake
they want to know about. The check walks the graph from the proposed blocked task
under the same transaction as the insert; depth is bounded and a board that
exceeds it refuses.

Creating and removing a dependency are audited writes carrying the board
structure version, and emit `task.dependency_added` / `task.dependency_removed`
to the outbox.

Deleting or archiving either task removes the edge with it.

---

## 10. Workload

Per-owner task counts per day or week across the window.

It counts tasks, and the screen says so: "12 tasks" is a true statement, "12
hours" would be a fabricated one. A task counts on every unit its span covers
when both dates exist, and on its due date alone when it does not — so a
fortnight-long task reads as sustained load rather than a spike on its last day.

Only non-archived, non-completed tasks count. Unowned tasks are counted in an
explicit "Unassigned" row rather than dropped, because unassigned work is exactly
what a capacity view exists to surface.

There is no capacity line, no red "over capacity" state and no threshold setting.
EzBizi does not know how many hours anyone has, and a threshold invented here
would be a number the screen presents as fact.

---

## 11. Recurrence

A task may declare a recurrence. The declaration carries a mode, an interval, and
an optional end:

```
{mode:"after_completion"|"on_schedule", every:<n>, unit:"day"|"week"|"month",
 until:null|<date>, count:null|<n>}
```

**`after_completion`** — when the task is completed, one new task is created,
dated `every`/`unit` from the completion date. There is never more than one open
instance. A truck unserviced for a year yields one overdue task, not four.

**`on_schedule`** — instances are created on the calendar regardless of whether
the previous was finished. Right for filings and fixed-date inspections. Missing
one leaves it overdue and the next still arrives.

Both modes are served by **the existing hourly worker pattern** Phase 1 built for
overdue and due reminders: a bounded, overlap-locked command, evaluating in the
board's place timezone, with an occurrence row making creation idempotent. A
`task_recurrence_occurrences` row unique per `(task_id, occurrence_date)` is what
stops a retried or overlapping run creating duplicates — the same shape as
`task_overdue_occurrences`.

A created instance copies title, description, owner, collaborators, group,
priority and custom values. It does **not** copy updates, files, activity,
completion state, or dependencies — a dependency copied forward would point the
new instance at a blocker that is, by the time it is created, almost always
already finished, which draws a line that means nothing. It is a new task with a new number, and its
activity records which recurrence produced it.

Recurrence is edited on the task and is an audited, versioned write. Archiving or
completing the series (`until`/`count` exhausted) stops it; the emitted trigger is
`task.recurrence_completed`.

---

## 12. Cross-board summaries

Timeline, Calendar and Workload may be opened across the current EzBizi view
rather than one board, the way My Tasks already is.

They reuse **My Tasks' access path verbatim** — the same SQL prefilter for
scope-visible and private boards, the same participant-eligibility rule, the same
revocation-safe read. Phase 1 proved that path against a two-process membership
revocation race; Phase 2 does not write a second one.

Cross-board views are read-only. Dragging is disabled: a drag would need the
target board's write authorization resolved per card, and the honest version of
that is opening the board.

---

## 13. Server-push, and why it is not here

The Phase 1 design listed "optional server-push refresh". It is dropped.

Phase 1's polling already converges: conditional ETags, dependency-complete cache
keys, privacy-generation eviction, and a documented contract for what a 304
preserves. It was tested against revocation races. A push channel would add a
connection to run, authenticate, scale and reconnect, to improve a refresh that
works — and it would need its own answer to every access-revocation case polling
already answers.

If boards are observed going stale in real use, that observation is the
justification and this decision is revisited then. Building it now would be
building for a complaint nobody has made.

---

## 14. Permissions and refusals

No new permissions. Phase 2 adds no capability that `tasks.view`, `edit`, and
`manage` do not already describe:

- opening any presentation requires `tasks.view` plus board access;
- every drag is the underlying field's existing write and carries that field's
  existing permission;
- creating and removing dependencies requires `tasks.edit` on the board;
- declaring recurrence requires `tasks.edit`;
- saving a shared view of any presentation follows Phase 1 §11 unchanged.

Every refusal carries its sentence from `app/Support/Access/Refusal.php` and the
client renders that sentence. Phase 2 writes no second copy of a refusal reason.

---

## 15. Automations

Three new triggers, following §5.5 and the Phase 1 registration:

- `task.dependency_added`
- `task.dependency_removed`
- `task.recurrence_completed`

No new actions. Creating a recurring instance is not an action a rule invokes; it
is what the worker does.

Every emission is an outbox row inside the write's transaction, drained by
`ezbizi:dispatch-automation-events`. No write path dispatches directly, so a
failing rule cannot fail the write that triggered it.

---

## 16. Data shape

New:

- `task_dependencies` — `(organization_id, board_id, blocker_task_id,
  blocked_task_id)`, unique per pair, compound tenant/board identity, cascade on
  either task's removal.
- `task_recurrence_occurrences` — unique `(task_id, occurrence_date)`, storing the
  snapshot the instance was created from.

Changed:

- `tasks` gains `start_date` (nullable date) and `recurrence` (nullable JSON,
  validated by a codec, never read by key order).
- `task_views` configuration moves to `schema:2` with `presentation` and `window`.

Indexes: `(board_id, start_date, due_date)` for timeline and calendar windows;
`(organization_id, owner_id, due_date)` for workload; `(blocked_task_id)` and
`(blocker_task_id)` for edge lookup in both directions.

MySQL is the truth. JSON key order is never asserted — the `recurrence` codec
reads by key.

---

## 17. Screen behavior

Everything Phase 1 §12 requires still holds and is not restated here, except
where a presentation makes it sharper:

- **No pop-ups.** Presentation switching, window pickers and the dependency
  picker are panels or screens. `StartUp.md` says "Ever."
- **375px is first-class.** Timeline and Workload scroll horizontally inside
  their own container; the page body never scrolls sideways. Kanban becomes one
  column at a time with a bucket selector. Calendar becomes a week list.
- **Colours from `client/lib/core/theme.dart`.** A Gantt bar and a Kanban column
  header are exactly where a literal hex creeps in.
- Every presentation states what it omitted — undated tasks, tasks outside the
  window, tasks with no owner. A view that silently drops rows is the defect this
  rule prevents.

---

## 18. Test contract

Beyond the per-task coverage the plan will specify:

- **Isolation.** Every presentation is attacked with foreign board, group, owner,
  column and task identities, a sibling company present in `/me`, a
  company-to-organization reach, a private non-member, and a revoked membership
  mid-read. A presentation must never widen access by one row.
- **Windows.** Over-wide ranges and oversized bucket sets are refused, not
  truncated silently.
- **Summaries describe the whole result**, proven with a filtered set larger than
  one window.
- **Dependencies.** Direct and indirect cycles are refused; cross-board edges are
  refused; deleting either task removes the edge.
- **Recurrence.** Both modes; idempotency under a retried and an overlapping
  worker run; timezone correctness at a day boundary; `until`/`count` exhaustion.
- **Schema 1 views still read**, and the Main table cannot change presentation.
- **375px goldens** for each presentation, approved by eye before being checked
  in — Phase 1's goldens caught real layout regressions only because someone
  looked at the PNGs.

Tests run against a private schema (`DB_DATABASE=ezbizi_testing_tasks`), never the
shared one.

---

## 19. What Phase 3 still holds

Unchanged from the Phase 1 design §20: forms, reusable board templates,
chart/dashboard views, formula/roll-up/connected-board columns, external
guest/shareable boards with their own identity model, direct monday.com
migration, advanced reporting, and controlled public/embed surfaces.

Phase 3 receives its own design, plan and review gate. Two items in it are worth
naming now because Phase 2 touches their edges: **connected-board columns** are
the natural home for cross-board dependencies (§9), and **external guests** need
an identity model that does not exist anywhere in EzBizi today and should be
treated as its own project rather than a Phase 3 bullet.

---

## 20. Review and implementation gate

This design is accepted. The implementation plan is written next, as a separate
document, and carries its own numbered tasks and green checkpoints.

While `docs/ACTIVE-CONSTRAINTS.md` exists, nothing in Phase 2 may change the
shape of an existing server response, a permission answer, or a refusal sentence.
Every Phase 2 change is additive by nature — a new field, new tables, new
presentations — so this constrains sequencing rather than design. The
`schema:1` → `schema:2` view upgrade is the one place to be careful: it must
remain readable by the frozen client, which is why schema 1 rows are upgraded on
read rather than migrated in place.
