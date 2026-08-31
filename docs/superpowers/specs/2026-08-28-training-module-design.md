# Training — module design

**Written 2026-08-28**, from a decision session with the owner. §18 of the build plan
asks each module for its own design with exact endpoints, migrations and acceptance
criteria rather than one document for the platform; this is that, for Training. Every
`§` reference points at `EZBIZI-BUILD-PLAN.md`.

Owner's brief, verbatim in shape: *companies create Guides and Tests for their
employees — screens containing text, images or videos referenced by link — and tie
those guides to tests with multiple choice answers.* Three tabs: **Learn**,
**Dashboard**, **Settings**.

Session: `ezbizi-c8`. Branch `session/training`, worktree at `H:/Development/wt/training`,
based on `0c0c21f`.

---

## 1 · What this is

A guide is an ordered run of screens. A screen is a title, a body of words, and
optionally one picture or one video **referenced by URL** — nothing is uploaded. A test
is a run of multiple-choice questions, optionally tied to a guide.

People reach training two ways, and **both exist** (owner: *"both options"*):

- **Library** — everything you can reach, browse and take when you like.
- **Assignment** — somebody gives you a guide or a test, with a due date.

That is the whole product. It is deliberately not a course catalogue, not a
certification authority, and not a content authoring suite.

---

## 2 · Decisions, and who made them

| # | Decision | By |
|---|---|---|
| 1 | Training decides nothing about audience. Tab permissions do. A company that grants a customer-facing role `training.view` has given its customers training, and no code notices. | owner |
| 2 | Guides and tests belong to a franchise, a company, or the organization, and use the visibility engine the other modules already use. | owner |
| 3 | Library **and** assignment, not one or the other. | owner |
| 4 | Gate-on-guide, pass mark, retake cap and pass expiry are **per-test options in the builder**, not platform constants. | owner |
| 5 | Media is links only. Video embeds on web; elsewhere it opens out. | owner |
| 6 | A pass can expire and reappear as a retake. | owner |
| 7 | Training results reach HR — but HR **reads** them; Training writes nothing into HR's tables. | owner |
| 8 | Training's expiry and HR's "expiring certifications" stay two separate panels with two different names. | owner |
| 9 | Assignment is bounded by the assigner's reach — same level or downwards, never sideways. | §4, via the HR plan |

### On decision 8, because the naming is load-bearing

`Support/Dashboard/Widgets/Hr/ExpiringCertifications.php` already counts date fields a
contract template marked `is_expiry` — a driving licence the employee typed during
onboarding. Training's expiry is *our* test pass running out. Different table, different
source, different person responsible for fixing it.

Two panels on adjacent dashboards, both called "expiring certifications", computed from
different data, would disagree — and disagree **correctly**, which is the worst kind.
So **Training never uses the word "certification".** It says *due for retaking*. This is
the same argument the codebase already makes about two copies of a rule.

---

## 3 · Data — five tables

Screens and questions are **JSON columns, not tables**. A screen has no identity anyone
queries, no permission of its own, and no life outside its guide; reordering is a list
splice rather than a position-column rewrite. That is two tables and two models that do
not exist. The cost is that "which guides embed this video" is not a query — nobody has
asked for it, and it stays available as a later migration.

```
training_guides
  id, company_id?, franchise_id?, title, summary, status(draft|published),
  screens JSON  [{ title, body, media_kind(none|image|video), media_url }, …]

training_tests
  id, company_id?, franchise_id?, guide_id?, title, status,
  questions JSON [{ prompt, choices: [{ text, correct }] }, …],
  requires_guide bool, pass_mark int, max_attempts int?, valid_for_days int?

training_assignments
  id, guide_id?, test_id?, assignee_type(user|role), assignee_id,
  due_on?, assigned_by_user_id, company_id, franchise_id?

training_progress
  id, user_id, guide_id, last_screen, completed_at?

training_attempts
  id, user_id, test_id, answers JSON, score int, passed bool,
  taken_at, expires_on?
```

`company_id` null means the organization. `company_id` set with `franchise_id` null
means that company's General. Both set means that franchise. This is the same column
pair Inventory and Services already use, which is what makes §4 below free.

**The four option columns on `training_tests` are decision 4.** `requires_guide` gates
the test on the guide being finished; `pass_mark` is the percentage needed;
`max_attempts` null means as many as they need; `valid_for_days` null means a pass never
runs out.

### The answer key never leaves the server on the Learn path

`correct` lives in the same JSON as the question. The Learn read **strips it**, and
grading happens server-side against the stored row. This is a trust boundary and it gets
its own test: a test that fetches a test as a learner and asserts no `correct` key
appears anywhere in the response.

### The MySQL key-order trap applies here

§8: MySQL does not preserve a JSON object's key order. Array **element** order is safe,
which is what ordering screens and questions depends on. **No assertion in this module
may depend on the key order of anything read back out of these JSON columns.**

---

## 4 · Scope and visibility — no new engine

`InventoryVisibility::constrain` is already this engine, and `ServiceVisibility` is a
22-line delegate to it. Training calls it directly.

| Row | Who sees it |
|---|---|
| `company_id` null — organization | everyone with reach in the tenant, **including franchises** |
| `company_id` set, `franchise_id` null — company General | anyone who can see that company, including a franchise-only user of it |
| both set — franchise | only when that franchise is in the current view; the parent company and organization see it, a sibling franchise never does |

Confirmed with the owner: an organization guide **is** visible downward. An org-wide
safety guide nobody below could see would be pointless.

<!-- ponytail: no TrainingVisibility delegate. The class is misnamed for what is
     plainly a shared place-scope engine, but renaming it edits Inventory's and
     Services' files, which are not this module's. Rename it when somebody owns
     all three; until then one import is cheaper than one wrapper. -->

**Filing uses `filingCompanyId` and `companiesInView`, never `me.companies.first`.**
That is the §3 leak StartUp records shipping in Inventory, Fleet and Phone. The proof is
a widget test with a sibling company in `/me`, modelled on the Inventory one.

---

## 5 · Assignment

An assignment points at a **user or a role**. A role assignment is **not** expanded into
per-user rows — the Learn query unions "assigned to me" with "assigned to a role I
hold", so adding somebody to a role gives them the training with no sync job that can go
stale.

**Assignment is reach-bounded.** The HR plan states it for onboarding — *"he should only
be able to see same level or downwards when his options to assign an employee are
presented"* — and cites §4 rather than anything HR-specific, so it applies here
unchanged. A Riverside supervisor with `training.assign` may not pile mandatory training
onto Northgate's crew.

**A role target is bounded by ORGANIZATION reach, always — not by its current
members.** (Ruled task-9, round 2, after a review found the hole an earlier,
per-member version left open.) A role assignment is one row, never re-checked
when membership changes, so assigning to a role is a promise about *everyone who
will ever hold it*, not about who holds it today. A Riverside supervisor whose
only current member is another Riverside employee cannot use that role to
smuggle mandatory training onto whoever the role picks up next — a Northgate
hire given that role tomorrow would inherit it with no re-check to catch it.
Only somebody who reaches the whole organization may target a role, whatever
its membership looks like right now.

The refusal carries a sentence, from the server, in plain words (§4). Not "out of
scope": *"You can assign training to people in Riverside. Sam works out of Northgate."*

---

## 6 · The three tabs

Layouts chosen by the owner from `docs/previews/training-layouts.html`: **1, 4, 6, 8**.

### Learn — layout 1, two shelves

*Assigned to you* on top with due dates and progress; *Library* below, everything else
reachable. The assigned shelf is always first and **cannot be filtered away** — that was
the deciding difference against the single filtered list.

Opening a guide gives **layout 4**: a screen rail on the left with ticks for what has
been seen, the reader on the right, the test as the last item in the rail showing
*"Unlocks when all 8 are seen"* when `requires_guide` is set. Below 600px the rail
collapses and the reader is the whole body, matching the split Inventory and Fleet use.

Video embeds on web. Where an iframe is not available it renders as an **Open video**
link. Nothing assumes a browser (§6).

### Dashboard — layout 6, training down, people inside

One row per guide or test with a completion bar and counts; selecting one opens a pane
listing the people, their progress, score, due date and state.

Chosen over the people×training matrix for a reason found by drawing the narrow case:
**the matrix cannot be a matrix at 375px** and needs a second layout built and
maintained, while a list is already a list. It also keeps working past eight guides.

### Settings — layout 8, storyboard

Screens as a horizontal strip of thumbnails showing the guide's shape rather than a list
of titles, with the screen editor and the test editor below. The four per-test options
from decision 4 are edited here.

**No modals anywhere.** Selection is a pane or a screen (§6).

---

## 7 · Automations

Training raises events through the **outbox** (§10, StartUp §10). A write path inserts a
row inside the same transaction; `ezbizi:dispatch-automation-events` drains it. A failing
rule can never fail the request that triggered it.

This matters most for the expiry sweep: a nightly job finding lapsed passes **inserts
outbox rows**, it does not dispatch. One rule failing while re-assigning a lapsed
ladder-safety pass must not take the sweep down.

Triggers:

| Key | Meaning |
|---|---|
| `training.assigned` | Somebody was given a guide or a test, with the due date if there is one. |
| `training.completed` | Every screen of a guide has been seen. |
| `training.passed` | A test was taken and the pass mark met. |
| `training.failed` | A test was taken and it was not. |
| `training.expired` | A pass ran out and is due for retaking. |

Action: `training.assign` — give the event's subject a guide or a test.

**The rule this module exists for** needs nothing new from HR: `user.activated` is
already declared, so *"when somebody's account goes live, give them the induction
guides"* is a rule an organization writes on day one, with no coupling between the two
modules beyond a published trigger.

**Open — the automation subject.** `AutomationSubject` binds a module to one record
class, and Training has guides, tests and attempts. To be settled against
`ModuleRegistryTest` during implementation, not guessed here. It is the only loose
thread in this document.

`AutomationsController::IMPLEMENTED_ACTIONS` is a **second const in another file**;
an action absent from it publishes as `implemented: false` and is refused. Registering
`training.assign` means both places, or it silently draws as "not built yet".

---

## 8 · Permissions

`ModuleDefinition` key `training`, label `Training`, actions:

| Action | Gates |
|---|---|
| `view` | the Learn tab |
| `monitor` | the Dashboard tab |
| `manage` | the Settings tab — authoring |
| `assign` | giving training to somebody, bounded by reach per §5 |

Four rather than three at the owner's direction (*"more is better"*). `assign` is
separable from `manage` because chasing people and writing content are different jobs.

A tab whose permission the person lacks **is not drawn** — §4's rule that a control which
is offered must be a control that works.

---

## 9 · Personal data and retention

Every one of the five tables needs a policy or a stated exception; two hold personal
data outright.

| Table | §9 |
|---|---|
| `training_guides`, `training_tests` | No personal data — authored content. Retention stated, not exempted: they accumulate. |
| `training_assignments` | Holds `assignee_id` and `assigned_by_user_id`. |
| `training_progress` | Holds `user_id` and what they have read. |
| `training_attempts` | Holds `user_id`, their answers and their score. **This is the sensitive one** — it is a record of a named person being tested and sometimes failing. |

`training_attempts` retention is **forever, with the reason**: the record of who passed
what and when is the entire point of a compliance module, and an age-based window would
delete the oldest pass first — the one a later dispute needs. Erasure anonymises at the
user's one home rather than orphaning rows here, following `EmploymentEvent`.

**No `DB::unprepared` and no `CREATE TRIGGER`.** MySQL refuses both without SUPER while
binary logging is on.

---

## 10 · HR

Training exposes `GET /training/records?user=…`. The HR record screen renders a Training
card from it. Training owns its data; HR reads it. No writes into another module's
tables and no second copy of a result to drift.

HR's own "Expiring certifications" panel is left alone — see decision 8.

---

## 11 · Constraints in force while this is built

From `docs/ACTIVE-CONSTRAINTS.md`, checked 2026-08-28:

- **An App Store reviewer is running a frozen client against live production.**
  Training is net-new, so new tables, new endpoints, new routes and all client work ship
  freely. The moment this module touches a **shared response** it is constrained.
- The shared registries answer for the whole registry, not for one module.
  `ezbizi-a1` verified each against the frozen tree at `8327833`: `module_screens`,
  `GlanceRegistry`, `SettingRegistry` and triggers are **tolerant**; **actions are
  guarded rather than tolerant**, and a guard can be removed by a later commit.
  `training.assign` is therefore the one registration to check before adding.
- Do not set or raise a minimum client version. It is a database row and passes no gate.
- Tests run `DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training`. `DB_DATABASE=`
  alone silently creates a **sqlite file** of that name, and a green suite against
  sqlite looks identical to a green one against MySQL.
- Work happens in `H:/Development/wt/training`, never the shared tree. Never junction
  `vendor` into it.

---

## 12 · Still open

- **The automation subject grain** (§7). Settled during implementation against
  `ModuleRegistryTest`.
- **Whether a test can be retaken after a pass, before it expires.** Currently
  `max_attempts` counts all attempts. Somebody wanting to improve an 81% is a real case
  and the column does not distinguish it. Ask the owner when it comes up rather than
  inventing an answer now.
- **Whether Dashboard should show a person's whole training history** or only current
  state. Layout 6 shows current state; history is a pane away and not designed.

---

## 13 · What gets written

**Backend**

```
database/migrations/<timestamp>_create_training_tables.php          all five, one file
app/Models/Training{Guide,Test,Assignment,Progress,Attempt}.php
app/Policies/Training{Guide,Test}Policy.php
app/Http/Controllers/Api/Training/{GuidesController,TestsController,
    LearnController,ProgressController}.php
app/Support/Training/{TestGrader,PassExpiry,AssignmentReach}.php
app/Console/Commands/ExpireTrainingPasses.php      → outbox rows, never dispatch
tests/Feature/Training/
```

**Client**

```
client/lib/screens/training_screen.dart            three tabs, the Inventory shell
client/lib/screens/training/
    learn_shelves.dart   guide_player.dart   test_player.dart
    dashboard_list.dart  dashboard_pane.dart
    guide_builder.dart   test_builder.dart
client/test/training_screen_test.dart
```

**Shared files, one block each, announced before editing**

`routes/api.php` · `app/Providers/ModuleServiceProvider.php` ·
`client/lib/core/module_screens.dart`

---

## 14 · Acceptance

- A franchise user sees organization guides, their company's General guides, and their
  own franchise's — and never a sibling franchise's.
- A company-view control offering a place does not list sibling companies.
- A learner fetching a test receives no `correct` key anywhere in the response.
- A test with `requires_guide` refuses submission until every screen is seen, with a
  sentence.
- A pass past `valid_for_days` reappears in Learn as *due for retaking*, and
  `training.expired` is in the outbox rather than dispatched.
- Assigning to somebody outside the assigner's reach refuses, with a sentence.
- Every table answers `RetentionTest`.
- Each test above fails when the line it targets is deleted. A test that cannot fail is
  not a test.
