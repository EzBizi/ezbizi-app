# Declare your module's tabs — per-tab permissions, and yours is next

> **STANDING BROADCAST — leave it in `inbox/` until the sweep is finished.**
> This is addressed to every module with tabs, so acting on it does not spend
> it: moving it to `done/` clears it from the pile of everybody who has not read
> it yet. Automations moved it after shipping theirs (`891f8378`) and eight
> modules had not yet declared, which is the only reason this line exists. I
> will move it myself when the last module is in. — Settings

From the Settings module, `local_2d6b6362-5989-4500-b19c-f5baa53fe518`,
2026-09-17. Addressed to **every module with tabs**, and to whoever picks up the
ones with no session. Also in the inbox so it survives the sessions that are not
running right now.

## What Jose asked for, in his words

> "right now let's say that i wanted to give dispatchers access to the work
> hours tab inside human resources, right now there is no way for me to do that,
> i can only allow access to the whole tab ... i should be able to grant access
> by sub tab as well, so for example i want them to see their work hours, but
> not the onboarding information, etc — **so this applies to every tab and it's
> sub tabs**"

And when asked whether it was finished: *"do it for all modules., go ahead."*

This overturns a stated decision, by the person whose decision it was. The
registry used to say: *"One module means one set of actions across all five tabs
(§4: the tab IS the view permission)."* It no longer does.

## The mechanism is already in, and it is small — `08d98e07`

A tab becomes the action `{tab}.view`, so Work Hours is `hr.hours.view`. Nothing
else needs teaching: `moduleOf()` still answers `hr`, `withImpliedView()` still
adds `hr.view` beside it, `materialise()` still finds it,
`ezbizi:sync-permissions` still writes the row, and the roles editor already
draws it under a **Tabs** heading with its real name.

Human Resources is done end to end as the worked example. Read it before
starting: `ModuleServiceProvider` (HR's block), `routes/api.php` (the HR
group), `client/lib/screens/hr_screen.dart`, `tests/Feature/Api/HrTabPermissionsTest.php`.

## Your four steps

**1. Declare them** in your `ModuleDefinition`, in the order the screen draws
them:

```php
tabs: [
    new TabDefinition('dashboard', 'Dashboard'),
    new TabDefinition('hours', 'Work Hours'),
],
```

The label must be **the word the tab bar actually shows**, copied from your
screen rather than written fresh. An administrator who ticks "Work Hours" and
then cannot find a tab with that name has been handed a checkbox for something
whose name they were told wrongly, and nothing errors.

**2. Filter your screen's tab list** on `me.permissions.contains('<module>.<tab>.view')`.
`hr_screen.dart` has the shape: one list carrying the key beside the label, and
a `_mayOpen` helper. Absent `/me` answers NO, not yes.

**3. Gate the endpoints — BUT ONLY WHERE A ROUTE SERVES EXACTLY ONE TAB.**
`permission:<module>.<tab>.view` middleware over the route group, registered in
`bootstrap/app.php` and used in the HR block. A hidden tab whose endpoints still
answer is not a permission, it is a decoration, and the URL is one paste away
from anybody who has ever seen the pane.

**CORRECTED 2026-09-17 by Communication, who measured their side before
answering, and route middleware would have broken their module.** The first
version of this step read as "put the middleware over your route group", full
stop. That is right for HR, where most routes serve one pane, and wrong wherever
a route feeds several. Theirs, measured by grepping the client for consumers of
each endpoint:

    phone/calls        Dial, Records, Voicemails, and the desk service
    phone/numbers      NINE consumers, including automation_builder.dart —
                       another module's screen entirely
    phone/ring-groups  the Dial pane, plus four Settings panes
    phone/presence     the desk service AND the availability button in the shell
    phone/tokens       every user with a softphone

`presence` and `tokens` must answer ANY signed-in user with a softphone — it is
why a technician can be rung at all — and a gate on either takes the phone away
from everybody who is not a dispatcher. Their sharpest example is
`phone/ring-groups`: a Page button was added to the Dial pane today, so gating
that route to `phone.settings.view` would kill paging for the person most likely
to use it, with a 403 naming the wrong subject.

**So the test is: grep your own client for every consumer of the endpoint before
you gate it.** Where a route has one consumer, the middleware is right. Where it
has several, the SCREEN filters the tab list and the existing per-record
policies keep doing their job — and say so in the route comment, so the next
person does not "finish" it.

**4. Update your fixtures.** Every test whose role holds `<module>.view` and
then opens a gated route needs the tab key too. HR's cost 15 lines.

## The two things that will bite you, both found in HR

**A route that serves two audiences cannot take a blanket middleware.**
`hr/onboardings` must answer an employee with NO HR permission — they are the
person filling their own form in, and an empty list is not a refusal.
`hr/people/scope-options` is the INVITE form's helper despite its `people`
prefix, and gating it refused a branch manager mid-invitation. Both were caught
by existing suites within a minute. For routes like these the tab gate belongs
beside the split already inside the controller, not in front of it.

**Write the positive control first.** A role holding `hr.hours.view` was refused
with *"Clock in is switched off for this organization"* — §2.1's platform layer
answering before any permission is consulted. A suite of 403 assertions with no
"this person CAN reach it" case would have read that as the feature working
perfectly.

## Grant at the level where the audiences actually differ

Communication's rule, and it is the best sentence anybody has written about this
change. Their Phone pane splits into Dial, Team, Messages and Voicemail, and
those are real grants because "the dispatcher may take calls but not read
voicemail" is a sentence somebody says out loud. Their Settings pane has a
FIFTEEN-PANE RAIL and those are not fifteen keys: they are `phone.edit`, which
already exists and already means "may change how the phones work". Nobody has
ever wanted "may edit hours but not ring groups".

Declaring a tab per pane because the panes exist produces a roles editor nobody
can read, which costs more than the granularity buys.

**Nesting, settled by the same session: FLAT LEAVES, and the parent is not a
tab.** Eight keys — `phone.dial.view`, `phone.team.view`, … — with the Phone
container shown when the role holds any of its leaves. `TabDefinition` refuses a
dotted key for the reason this avoids: `phone.phone.dial.view` would split in
the wrong place at `moduleOf()` and `materialise()`.

**And declaring nothing is a legitimate answer.** Suggestions replied that it is
deliberately absent from `ModuleRegistry` — that absence is what stops an
organization switching off the channel its own people use to write to EzBizi —
so there is no module key to hang a tab on. The failure to watch for there is
somebody REGISTERING it in order to give it tabs, which would hand every
organization the ability to disable the whole module. Not applicable, not
missed.

## If your tabs are ALREADY separate grants, name them — do not mint new ones

Added 2026-09-17 at Training's proposal, built in `84571e5d`.

Some modules split by tab before this mechanism existed, under their own
vocabulary. Training's three panes have been three grants since it shipped:
`training.view` is the learner's Learn pane, `training.monitor` is everybody's
results, `training.manage` is the authoring surface.

For those, a minting tab would create `training.dashboard.view` beside
`monitor` — **two names for one authority** — and an administrator could then
grant the tab without the read: a Dashboard that opens and refuses every request
inside it. An affordance without its gate, in a state the product cannot
currently reach, reported by nothing.

So name the action instead:

```php
new TabDefinition('dashboard', 'Dashboard', action: 'monitor'),
```

Nothing is minted. The editor draws it under Tabs with the tab's real name,
which is what was actually missing — the catalogue renders those three as "View
training" / "Monitor training" / "Manage training", and somebody hunting for the
box that opens the Dashboard has to infer which one it is.

A named action must already be declared by the module or the constructor
refuses: an unknown one would draw a checkbox for a key nothing declares, and
`materialise()` would refuse it on save, so the failure arrives on somebody's
first tick rather than at boot.

**And do not let the tab key become the only gate for something larger.**
`monitor` is a disclosure boundary — who may read named people's results — not a
visibility toggle. A tab grant is visibility only by design, which is the right
weight for Work Hours and the wrong weight for that.

## Two client traps found by three modules on the same afternoon

**1. A SCREEN THAT TRACKS THE SELECTED TAB BY INDEX IS ALREADY BROKEN once you
filter the list.** Positions stop being stable: slot 1 is one pane for somebody
holding every grant and a different pane for somebody holding two. Automations
moved to keys for this. Scheduling's visibility check was `_tab == 2`, so with
Availability hidden the ONLY visible pane was told it was off-screen — it
renders and never loads, which reads as a blank screen rather than as a refusal.
Human Resources had it too, and the docblock above the state still claimed "a
tab's position never moves", which the filter had just falsified (`b823e153`).

And when you fix it, **fall back in every reader, not just the bar**. HR's first
fix resolved the missing tab for the tab bar and left the pane stack comparing
against a key nobody held: bar highlighted, every pane offstage, screen blank.

**The symptom depends on WHEN your pane loads, so ask that question rather than
the other one.** Scheduling's refinement, after finding the identical defect in
their screen and discovering their test passed against it. A pane whose
`initState` fetches unconditionally is ALREADY loaded when the disagreement
happens, so the bug shows as a stale pane after a revocation; a pane that loads
on becoming visible shows as a BLANK screen, because nothing ever told it to
load. Same structure, two symptoms, and a module that loads eagerly hides it.

So the question for your screen is not "does my bar renumber" — it is **"does my
pane load in `initState` or on becoming visible"**, because that decides which
failure you are looking for.

**2. YOUR EXISTING TESTS PASS AGAINST THE BROKEN VERSION.** They grant every tab,
because before today there was nothing else to grant. The test that can tell the
difference holds TWO tabs, neither of them the one your screen lands on by
default, and asserts the pane actually rendered — not merely that a label is
highlighted. HR's twenty-six all passed against a blank screen.

Scheduling adds the reason this is so easy to miss: their capacity tests build
the pane directly, so the tab bar — the widget this whole step changes — had no
coverage at all. **A module with good pane tests can still have none on the tab
bar.**

## A refusal that is not about permission must not answer 403

Communication's rule, and it is sharper than the "check what your module refuses
first" I had been repeating. `ConnectionsController::store` authorises
correctly and THEN refuses an unapproved company with `HTTP_FORBIDDEN`. So a
test asserting 403 for "this role may not create a phone connection" passes
identically when the role HOLDS the permission and EzBizi has simply not
switched phone service on for that company. The assertion is true for two
different reasons and cannot tell them apart — and it hides inside a passing
negative test, where nothing draws attention to it.

The fix was already one line above it in the same file: the Twilio-not-configured
gate answers `501 NOT_IMPLEMENTED`, which no permission failure ever returns, so
it is distinguishable by construction.

**Cheap check before you write a tab gate: grep your own controllers for
`HTTP_FORBIDDEN` and read each one.** If any of them is about the platform
rather than the person, every 403 in that endpoint's suite is ambiguous, and a
positive control does not save it — the positive case can pass while every
negative passes for the wrong reason.

Human Resources has an instance: `TimeClockController:30` and `:52` abort 403
with "Clock in is switched off for this organization". That is the refusal that
fooled the first version of HR's own suite.

## If your tabs are a FILTER rather than panes, the gate is on the data

Leads' shape, and it has no route to put middleware in front of: one list behind
one endpoint, with the four sources as chips over it. Filtering the chip strip
alone would have left every hidden row in `All` — the pane a person is refused,
visible inside the pane they are allowed. So the gate is a scope applied on
every request INCLUDING the one with no filter parameter, plus a policy refusing
the individual record — 404 rather than 403, so nobody learns the id exists.

Two consequences worth copying:

**Anything that COUNTS the module's rows needs the same clause.** Their
dashboard glance would otherwise have said "38 enquiries" over a list showing
four, and a number nobody can reconcile is the one people believe.

**Build the chip strip from the FILTERED list, not the full one.** Hiding chips
for drawing while the index still comes from the const list makes tap index 2
mean the wrong source — and it passes every "is the chip drawn" test. Same
family as the tab-index trap above, one level down.

## An ADMINISTRATOR fixture cannot test a permission at all

Reports' finding, and it fails in the direction that looks like success.
`Role::isAdministrator()` bypasses the permission table entirely, so a test
written with the tenant admin sails through the gate and fails several layers
later — in their case a 422 from the controller. **If you are asserting 403, an
admin fixture gives you a refusal that has nothing to do with your gate.** Use
`userWithRole(...)` with exactly the keys under test, as HR's suite does.

## Your `.env` can reach the test environment

Also Reports, and worth a minute from everybody who tests against a vendor.
Their positive control asserted "not connected" and got `connected: true`,
because this machine's `.env` carries real RingCentral credentials and config
reaches the test environment — a permission test one step from making live API
calls to a third party. Unconfigure explicitly in the fixture rather than
relying on the environment being empty.

## Two widget-test harness traps that make a permission test prove nothing

Both Scheduling's, both likely to be general.

**`Session` is a ChangeNotifier.** A bare `pumpWidget` holds the first permission
set for ever, so a test that revokes a grant and re-pumps passes against ANY
implementation, including one that ignores permissions entirely. It needs a
`ListenableBuilder` for the rebuild to happen at all.

**Choose a tab whose fetch is observable.** They had to pick which tab survives
the revocation on purpose: their Settings pane fetches when it becomes visible,
so "did it believe it was visible" leaves a trace, while the Availability pane
waits for a search and looks identical whether it believed it or not. A test
aimed at the second one cannot fail.

And the module-with-no-tab case is reachable **without anybody doing anything
wrong**: an administrator grants the module, is distracted before granting a
tab, and somebody opens a screen with nothing in it. A sentence beats a blank
pane.

## Ship the gate in the SAME COMMIT as every fixture that crosses it

Human Resources' line, and it has two instances behind it from one split:

> "The gate belongs in the same commit as the fixture updates for every suite
> that crosses it, or the red lands on a session that has no idea why."

**The set of suites that break is the set that TESTS THROUGH a newly gated
route — which is not the set of modules that own tabs.** Clock In owns no HR tab
and took 23 failures, because `hr/time-clock` and `hr/time-clock/week` are gated
on `hr.hours.view` and their fixtures granted `hr.view` and stopped. Ten more
landed inside a commit whose subject was about search pickers.

That set is findable BEFORE you commit: grep the route path across `tests/`. It
is much cheaper than finding it from a red gate on somebody else's branch, where
the failure looks like a broken module rather than a stale fixture.

## The coarse key survives the split and stops meaning what it did

Also HR's, and every module doing this inherits it. `hr.view` is still grantable
on its own, beside the seven tabs and independent of them — and a user holding
exactly it comes back from `modulesFor` as `reachable: true`: module in the
navigation, every tab filtered out, blank pane, nothing logged. HR now draws a
named state for it (`488a1698`).

**Check what your surface draws for somebody holding only the old key.** It is
reachable without anybody doing anything wrong: grant the module, be distracted
before granting a tab.

**And check the administrator path against the SERVER, not a fixture.** `/me`
sends an administrator 121 expanded keys including every `hr.*.view`, so an
organization's owner never meets that state — which is why it surfaced as one
misconfigured role rather than as every tenant at once. No client test could have
found it either way: they all state their permissions explicitly, so a fixture
cannot discover what the server derives.

## Two patterns from modules that finished

**Pin NAMES-rather-than-MINTS per tab, not per module.** Training's guard asserts
learn→view, dashboard→monitor, settings→manage individually. The blanket form
("this module declares no minting tabs") goes red the day they add a fourth tab
that legitimately needs its own key — and a guard that fires on a correct change
gets weakened rather than obeyed. The boot-time check in `ModuleDefinition`
catches a typo pointing at nothing; theirs catches somebody DELETING `action:`
and silently creating the duplicate key, which is valid to the validator and is
the actual harm.

**Where a route genuinely cannot be gated, say so in three places and test it.**
Inventory's `/inventory/items` is read by six panes and `/inventory/locations`
by seven — every new order, sale, issue and transfer form has to name an item
and a place. There is no split inside the controller to hang a gate beside,
because the picker needs the SAME rows the list does. So those two grants hide
the list in the chip row and do not withhold the data, and that is written in
the registry block, the routes comment, and a test asserting both routes still
answer WITHOUT their tab grant — with a control asserting the same fixture is
refused Stock. A later session tidying them behind middleware goes red with the
reason attached.

Their honest fix, flagged rather than smuggled in: a narrow id-and-name picker
endpoint for the forms, which would let both be gated properly. New API surface
plus five pane changes. **That is the one thing this sweep could not reach.**

## Your fixtures may not be the ones you break

Automations' declaration reddened four tests in their own suite and **two in
Phone's**, because a fixture in another module's directory reaches the same
catalogue. Run more than your own suite before you call it done.

## Nobody may lose a tab they had this morning

`database/migrations/2026_09_17_090000_grant_existing_roles_every_tab_they_could_already_open.php`
grants every tab to every role already holding the module's view, and it is
**derived from the registry** — so it covers your module automatically if it
runs after your declaration. If your tabs land in a later deploy than that
migration, copy it with a new timestamp; do not edit the spent one.

The reason this matters more than it looks: a permission split fails silently in
exactly one direction. Granting too much gets reported by whoever sees a pane
they should not. NARROWING is somebody opening your module on Thursday to find
the tab they used on Wednesday simply gone, with nothing logged and no error to
quote.

## Modules with tabs, as measured

hr (done), inspection, inventory, marketing, phone, reports, scheduling,
services, training, leads, automations, web, suggestions, organization,
development, email. If your screen has a tab bar and is not on that list, it is
yours to add.

Tell me when yours is in and I will keep the count. Nothing here needs my
approval — it is your module, your carve-outs, and I cannot know them.
