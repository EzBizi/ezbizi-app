# Reports — design

**2026-09-10.** Jose asked for a tab called Reports and put one session on it
(`local_a0b9e184-04b2-4a70-84f0-361cd88bba1d`). This is what was agreed with him
before any code, question by question. Nothing below is built yet.

## What it is

A **report builder**. A person composes a report — pick a starting record, pull in
columns from tables related to it, filter, group — saves it by name, and other
people run it. Jose chose this over a fixed catalogue of reports EzBizi ships,
knowing it is the largest of the four options he was offered.

## What it is not, and why the name still stands

Three things in this codebase already answer to "report", and none of them is this:

| Already exists | What it is |
|---|---|
| Inspection's **Generated Reports** chip, `InspectionReportsController`, `InspectionReportPolicy` | A finished inspection, frozen at publish. A document, not a query. |
| Dashboard's **`ReportsOverAPeriod`** | A shared base class for dashboard widgets. Not user-facing at all. |
| `Support/Data/Exporter.php` | Whole-table CSV export of one resource. No joins, no grouping, no saving. |

The deploy desk verified all three and **ruled on 2026-09-10 that the module key
stays `reports`** (registered at `640b4fd7`): a key that disagrees with the tab's
name costs every future reader a translation step forever, to save one reader a
confusing grep once. The collision is a documentation problem and it has a
documentation fix — the disambiguating sentence now in `docs/SESSIONS.md`.

## The join engine — declared relations, unlimited hops

Jose asked for "anything joined to anything". This is how that is delivered
without the server ever emitting a join nobody designed.

`Support/Data/ResourceMap.php` already declares, per resource: the model, an
**explicit** column list, the permission to read it, the permission to bulk-export
it, and a `reach_column` naming the franchise scoping. Its own docblock carries the
argument for the explicit list — *"adding a column to a migration never silently
starts exporting it"* — and that argument is worth more here than it is there,
because a report builder is a screen whose whole purpose is reading widely.

Reports extends that map with **relations**: declared edges between resources
(`jobs.customer_id → customers`). The builder starts at any mapped resource and may
pull in any resource reachable through those edges, **any number of hops out**. In
practice that is anything joined to anything; structurally it is a walk over a graph
we wrote down.

What this buys, stated as the properties that matter rather than as "it is safer":

- **No arbitrary join predicate ever reaches the database.** The builder chooses a
  path through declared edges; it does not choose a `ON` clause.
- **Every table on the path re-applies its own scope**, from its own map entry, on
  every run. A report is not scoped once at the root and trusted thereafter.
- **A table with no scope rule cannot be joined in at all**, because the map entry
  is what makes it reachable and the entry is where the rule lives. The failure mode
  is a missing table, which somebody reports, rather than a leaking one, which
  nobody does.

The alternative Jose was shown and did not pick — whitelist the tables, then let
the builder join any column to any column — is recorded here because it is the
strictest reading of his words and someone will re-propose it. It permits a
cartesian join across two large tables, and it permits reaching data through a
table that carries no franchise column. Neither is checkable at build time.

## Placement

A saved report is an owned resource: organization, company or franchise, filed on
the **current view** per the ownership contract, never on reach. `OwnedScope::mayWrite`
guards the write. The id never comes from the caller.

Running re-applies scope per table, so the same saved report returns fewer rows from
a company view than from the organization view. That is the intended behaviour and
needs a test with a sibling company in `/me`, per the standing rule.

## Permissions

Three, and the split follows the one `ResourceMap` already makes between reading a
record and taking the whole set as a file:

| Key | What it allows |
|---|---|
| `reports.view` | Open the tab; run reports you have been granted |
| `reports.build` | Compose and save a report |
| `reports.export` | Download a run as CSV or PDF |

**There is no role named in this spec, deliberately.** `Role.php:22` — *"There are
no preset roles beyond Administrator — the owner's decision."* Roles are created per
organization, and Administrator implicitly holds every permission including ones
added later. So `reports.build` ships as an ordinary grantable key, appears on the
Roles screen, and each organization grants it to whichever role it has made. Jose
asked for "administrator plus a role you name"; this is that, with the naming done
in the app by the person who owns the org rather than hardcoded here.

`reports.build` is the large disclosure — a person composing a report sees every
mapped column, including costs and pay. That is why building and running are
separate keys rather than one.

## Output

- **A table**, with the row cap below applied.
- **One chart** above it when the report has a grouping — bar or line, from the
  dashboard's existing chart work. Not a new dependency.
- **CSV and PDF export**, gated on `reports.export`. PDF goes through
  `Support/Files/HardenedPdf.php`, which Inspection already ships.
- **A dashboard glance**, showing a chosen report's headline number. Not optional
  even if it were unwanted: `GlanceRegistryTest` asserts sidebar modules and glance
  keys as a sorted-set **equality**, so declaring the module without a glance turns
  the gate red. Training hit this and had to add a `COMING_SOON` entry it had not
  planned.

## Automations

**Reports ships no scheduler and no schedule UI.** Jose's ruling, 2026-09-10: *"the
schedule stuff belongs to automations though ... should only be doable from there."*

Reports declares one action:

```php
new ActionDefinition(
    key: 'report.send',
    label: 'Email a report',
    description: 'Runs a saved report and emails the result to the people you '
        .'name. The report is run with this rule\'s own scope, so a rule that '
        .'belongs to one franchise cannot mail a report covering the whole '
        .'organization.',
    executionType: 'report.send',   // spelled out — see below
    subjectBound: false,
)
```

Three specifics, each confirmed against the code rather than taken on relay:

1. **`executionType` is spelled out and it is load-bearing.**
   `ActionDefinition.php:32` computes `$executionType ?? substr($key, strpos($key, '.') + 1)`,
   so `report.send` left alone reduces to the bare word `send`.

   *Correction to the advice as it was given to this session.* The Automations
   session raised this and was right about the mechanism, but the collision it
   named does not exist: it said `newsletter.send` already reduces to `send`. It
   does not, because **there is no `newsletter.send` automation action**. The
   `'send'` at `ModuleServiceProvider.php:1142` is a *permission* in the module's
   `actions:` array, which is a different list entirely; newsletter's only
   `ActionDefinition` is `newsletter.add_to_audience`. Derived types were computed
   for all 28 declared actions and **none reduces to bare `send`** —
   `phone.sms.send` reduces to `sms.send`, having two dots.

   So spelling it out prevents a *future* collision rather than a present one, and
   is still right for two reasons: `send` is a generic word that any later module's
   `x.send` would also claim silently, and `phone.sms.send` is the house style for a
   module's own behaviour. Bare types are correct for genuinely generic actions —
   four modules share `update_field` on purpose — and wrong for a module's own.

   One asymmetry found while checking, worth knowing before someone tries to
   "simplify" this: the constructor validates the dotted shape **only when
   `executionType` is passed explicitly**. A derived bare type bypasses the regex
   entirely. So the unsafe form is the one that is never checked, and the safe form
   is the one that would throw if you got it wrong.
2. **`subjectBound: false`**, like `phone.sms.send`. The report id and the
   recipients come from the rule's own configuration; "email the weekly job report
   when a month closes" has no subject to read them from.
3. **The entry in `AutomationsController::IMPLEMENTED_ACTIONS` lands in the same
   commit as the runner arm, not before.** The failure is asymmetric:
   declared-without-const publishes `implemented: false`, draws as "Not built yet"
   and cannot be selected — honest. Const-without-arm publishes `implemented: true`,
   lets a tenant build and save the rule, then throws on every run and auto-disables
   the automation.

### A rule must not mail a report its own scope cannot read

The sharp edge, raised by the Automations session and adopted here. `report.send`
mails business data to addresses chosen by whoever wrote the rule. A
franchise-scoped rule naming an organization-wide report would otherwise export the
whole business to an address of its author's choosing, and nothing about that looks
wrong in the builder.

The precedent is `ActionRunner::sendSms`, which refuses when the chosen line does not
belong to the company the rule belongs to. The analogue: **the report's scope is
checked against the rule's scope before a single row is read.**
`Automation::coversScope($type, $id, $companyChain)` is the helper — and the chain is
the third argument, `array $companyChain = []`, verified at
`app/Models/Automation.php:66`. Omitting it silently answers "does not cover" for
every company-scoped rule.

### Scheduled triggers are Automations' work, and as of 2026-09-10 they exist

**This section said "run this report every Monday is blocked" for about two hours and
that is no longer true.** It is corrected in place rather than annotated, because a
stale claim with a correction underneath it still reads as the claim.

What was true when this spec was written: the engine had no time-based trigger at
all. `AutomationEngine.php:44-69` said every time value in the class was a duration,
that there was no timezone in it and that this was *"a property to keep rather than a
gap to fill"*, and that §5.5 required DST be settled before schedules were built.
The Automations session confirmed it declared none and planned none.

**Jose assigned the work to Automations the same afternoon, and they shipped the
server half in `a63f78a2`** — verified at HEAD from this session rather than taken on
report: `automation.schedule_reached` is declared at `ModuleServiceProvider.php:2533`,
with `AutomationSchedule`, a `DispatchDueAutomations` command and a per-minute sweep
behind it. A rule carries a schedule — daily, chosen weekdays, or a day of the month
— with the time and the zone it was typed in, and the wall clock is re-resolved in
its own zone on every advance, so 9am New York is 14:00 UTC in January and 13:00 in
July. The builder UI is not drawn yet; a schedule can be set through the API.

So a scheduled report is reachable as soon as `report.send` has a runner arm.
**Reports still builds no scheduler** — that was never about whether one existed, it
is Jose's ruling about where it lives.

One consequence that lands directly on `report.send`: a scheduled event's subject is
**the rule itself**, because a schedule is about no record and a subject-less event
falls through `audienceFor` to every active user. That means a scheduled
`report.send` already carries a scope to check the report against — the rule's own —
so the guard in the section above has something to compare with rather than needing a
special case for scheduled runs.

The DST hazard that motivated the delay is handled by that re-resolution, but the
thing underneath it is not, and it is still Jose's call: the organization timezone
list is a union of twelve real IANA places and `Etc/GMT±N` fixed offsets, and **the
default is `Etc/GMT+5`**, a fixed offset with no daylight saving. Re-resolving a wall
clock in a zone that has no DST cannot produce DST. A tenant who never touched the
setting still gets a schedule an hour off local civil time from March to November.

## Guards

A report is the one screen where reading a great deal of data is the normal case, so
the limits are structural rather than advisory:

- **A row cap** on every run, applied in the query.
- **A statement timeout**, so a badly shaped report fails rather than occupying the
  database.
- **An audit row per run**, naming the report, the person and the row count. Five
  modules in this app write no audit rows at all; Reports will not be the sixth, and
  a report run is exactly the event an audit trail exists for.

## Not in v1, stated so the absence is a decision

- No scheduling of any kind in this tab (Automations owns it).
- No report sharing between organizations.
- No column-level permission filtering. It is unnecessary under the agreed model:
  the person composing already sees everything, and running is granted per report.
  It becomes necessary the day `reports.build` is granted widely, and that is the
  moment to build it — not before.
- No aggregation beyond count / sum / average / min / max on a grouping.

## Landing order

Each commit must **boot on its own**. `GlanceRegistry` and `ModuleRegistry` name
members by `::class`, so a commit referencing a class the tree does not yet have dies
at boot rather than in a test — which has caught four sessions in one week.

`ModuleServiceProvider.php` is shared, has taken 15 commits in three days, and a
change to it runs `tests/Feature/Automations` as part of the gate. The window gets
announced before it is taken, and the block gets verified **at HEAD after the commit**
(`git show HEAD:<path> | grep`) — not with `git log` and not with an ancestor check,
both of which keep answering correctly while content is gone.

## Open

- `report.send`'s parameter contract — how recipients are named, and whether a run
  that returns zero rows still sends. Automations will review the block when it is
  written; they have already said yes to the name.
- Whether the SES sandbox blocks `report.send` in practice. EzBizi's own transport
  delivers only to hand-verified identities and rejects everything else with 554,
  and each rejection lands on the metric AWS reads while judging the pending appeal.
  So a rule mailing a report to a real customer address today fails, and fails in a
  way that argues against us. This does not change the design — it changes what can
  be demonstrated before the sandbox lifts, and it should be said out loud when the
  action ships rather than discovered by a tenant.
