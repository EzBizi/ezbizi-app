# Automation coverage

What every module offers automations, measured 2026-09-16, and — where a module
offers nothing — WHY, in the owner's own words.

This file exists because "no triggers" and "no triggers that SHOULD exist" look
identical from outside, and the difference is the whole answer. Jose asked for an
audit; the reasoning arrived as messages from fifteen sessions and would have
evaporated there.

**Re-measure before trusting the table.** It is a snapshot.

> **Partly re-checked 2026-11-13 (code only, counts NOT re-measured):** of the
> "declared but unrunnable" actions below, every one except
> `user.assign_to_scope` now has an arm in `ActionRunner::run`'s match
> (`notify_administrators`, `resend`, `revoke`, `onboarding.start`,
> `reassign_owner`, `job.create`, `add_to_audience`, `draft_reply`,
> `report.send`). The four never-raised triggers are still `isRaised: false`.
> Many modules have gained triggers/actions since (e.g. `whatsapp.send`,
> `task.create`, `review.publish_reply`), so the TRIG/ACTS columns are stale.

## The table

    MODULE             TRIG  ACTS   declared but unrunnable
    dashboard             -     -   nothing declared
    organizations         1     1   organization.notify_administrators
    companies             3     1   
    franchises            3     1   
    users                 3     2   user.assign_to_scope
    roles                 -     -   nothing declared
    audit                 -     -   nothing declared
    hr                   10     3   invitation.resend, invitation.revoke, onboarding.start
    time_clock            3     2   
    customers             4     3   customer.reassign_owner
    jobs                  7     4   job.create
    scheduling            -     -   nothing declared
    inspection            4     1   
    marketing             2     0   
    newsletter            2     1   newsletter.add_to_audience
    web_management        2     0   
    phone                 8     3   
    fleet_management     11     4   
    inventory            12     0   
    services              3     0   
    leads                 5     1   
    reviews               3     1   review.draft_reply
    business_profile      2     0   
    email                 4     1   
    training              5     1   
    automations           1     0   
    monitoring            3     0   
    reports               0     1   report.send
    ads_performance       -     -   nothing declared

`-` means the module declares neither. The last column lists actions that are
DECLARED and published to the picker marked "not built yet" — visible, choosable
by nobody, waiting for an arm.

## Triggers nothing raises

    invitation.expired · item.low · vehicle.insurance_expiring · vehicle.service_due

All four carry `isRaised: false`, so the builder marks them and a saved rule on
one carries a loud note. Known gaps with a sign on them rather than silent ones.

**`item.low` is the one that matters.** "When stock runs low, draft a reorder" is
the flagship inventory rule and its left-hand side does not exist. Inventory has
the right-hand side designed and is waiting on Jose.

**A WARNING ABOUT MEASURING THIS.** The first pass flagged FIFTEEN triggers as
never emitted. Eleven were the instrument: a regex that missed multi-line emits,
reporting `email.received` and `time_clock.clocked_in` as dead when both fire
constantly. It was caught only because the auditor happened to know one of them
fires. Check a candidate by grepping its key across `app/` outside
`ModuleServiceProvider` — never by pattern-matching the emit call.

## Modules that offer nothing, and why

Each was put to its owner. None is an oversight.

**Services — 3 triggers, 0 actions, by written decision.** Spec decision 8
(`docs/superpowers/specs/2026-08-25-services-design.md:24`): "No automation
action writes the catalogue." Price is the case it most obviously exists for — it
is what a customer is quoted. Put to Jose, since comparable constraints have been
reversed this week; until he answers, the ruling stands.

**Scheduling — nothing, and correctly.** Nothing in EzBizi books a slot. `Job`
has `scheduled_for` and no link to a service area, so no row means "this customer
has this slot in this area". Booked / rescheduled / cancelled / no-show arrive
with Scheduled Jobs, and may belong under `job.*` rather than `scheduling.*` — a
booking is a Job with a time and a place. What Scheduling would refuse is any
action that changes CAPACITY: a rule raising `max_jobs` books nothing itself, it
makes overbooking possible for everybody, silently, and routes around a
per-company setting Jose has already ruled on.

**Monitoring — 3 triggers, 0 actions. It is a sensor.** The three things the
agent can be made to do are capture a screenshot, inject input, and raise the
"you are being controlled" notice. Remote control from a rule should not exist.
On-demand capture is the tempting one and the one to refuse: Monitoring's
disclosure rests on capture being periodic and predictable, so "screenshot when X
happens" is a different product and a different consent conversation. There is
also no alert OBJECT to acknowledge — `offline_alerted_at` is an internal
edge-trigger marker — and inventing one to give automations a verb is building
the noun for the action rather than the other way round.

**Web Management — 2 triggers, 0 actions, pending a question for Jose.** The
module publishes a company's facts onto a website it does not own; a rule editing
what a customer's website says is his decision. The candidate named is "set a
website value" — holiday hours going up on a date.

**Inventory — 12 triggers, 0 actions, and three refusals with mechanisms.** An
adjustment means "I counted, and it is this" (`qty_before`/`qty_after`, NOT NULL
reason), so a rule asserts a count nobody performed — and the module writes no
audit rows, so unattributably. A move asserts stock physically moved, and
`InventoryMover` would refuse it inside the outbox drain with nobody watching.
`discontinued` is a GATE, not a label: flipping it stops a warehouse issuing
stock it is holding. The one safe action PROPOSES rather than asserts:
`inventory.draft_purchase_order`.

## Two structural facts to know before adding anything

**A rule has no acting user.** Fleet and Customers raised this independently.
Every reach check on those paths takes a `User $actor`; a rule fires at 3am with
nobody behind it. A helper called from an arm can enforce what acting MEANS —
Fleet's `VehicleAssignments::assign()` refuses an assignee out of reach from any
caller — but not who was allowed to act. So a rule can hand keys in a company no
individual on that rule could have reached through the screen. Defensible,
because the authority is the rule's author having been allowed to build the rule,
but it is a property to decide rather than inherit.

Customers' `reassign_owner` is where it bites hardest: BOTH clauses of its reach
check are about the actor — may you give it to them, and may you take it from
where it is — so the action has no honest meaning without one.

**A derived execution type is shared across modules.** `ActionDefinition` derives
it from everything after the first dot, so `deactivate` serves company, franchise,
user and customer from one arm and `update_field` serves four modules. Useful,
and a trap when the shared thing should not be shared: Inventory named
`inventory.draft_purchase_order` rather than `inventory.update_field` precisely
so a rule could not write arbitrary columns on a stock record through the generic
arm.

## A description can promise a safeguard that does not exist

Marketing found this in their own declaration and it is the sharpest hazard in
this file, because it is invisible to every test. `newsletter.add_to_audience`
described itself as not standing in for somebody's agreement to be mailed,
"which the list records separately".

**No list records it.** The only consent artefact in the module is
`NewsletterUnsubscribe` — people who asked to STOP. Nothing anywhere records
anybody saying yes. So a person deciding whether that action was safe to enable
would have been reassured by a sentence that could not be cashed.

Descriptions are the only thing a rule author has to go on, and they are read at
exactly the moment somebody is deciding whether something is safe. Nothing
executes them, so they rot silently and they can be wrong on arrival.

To re-run the scan: walk the registry and look for descriptions containing
`cannot`, `does not`, `never`, `only`, `separately`, `records`. About twenty
declarations make a checkable assertion of that kind today. Each is its owner's
to verify — the auditor cannot check a claim about somebody else's domain, only
notice that one is being made.

The same class caught `phone.sms.send`, which said "the line has to be approved
for messaging first" while the builder was offering lines that were not. True as
a warning, false as a description of what the picker did. Fixed 2026-09-16 by
filtering the picker; the sentence now describes the software.

## Checking a dead trigger needs two instruments, not one

Inventory's hardening, and it is the method this audit should have started with.

A grep for the emit and the registry's `isRaised:` flag are structurally
different instruments with different failure modes. A single-line grep misses a
multi-line emit and reports a live trigger as dead — that is exactly what gave
this audit eleven false alarms. The registry flag is declared rather than
derived, so it is wrong in the other direction: it says what somebody believed
when they wrote it.

**So the keys worth chasing are the ones where the two DISAGREE.** Agreement of
two independent instruments is the evidence; either alone is a guess that happens
to be right. All four triggers in the section above survived both checks.

## A caution about reading exemptions

`automationExemptBecause` is read at the moment somebody is deciding whether to
build something, and it can rot with no test noticing. Scheduling's said "this
module is a shell" and told the reader to add triggers "when Availability becomes
real" — a condition met on 2026-09-09, so it pointed at an event that had already
happened. Read the exemption TEXT, not just its presence: a stale reason answers
the question wrongly and looks like an answer.

## What changed on 2026-09-17, and the two decisions that were mine to take

A long day of Jose's requests, recorded here because the reasoning is not
visible from the diffs.

**A rule now belongs where it was made.** `coversScope` had honoured company
and franchise since it was written; nothing ever SET a scope, so every rule was
stored organization-wide. New rules take the scope of the view they were
written in, an explicit choice still wins, and the list shows what reaches the
current view — asked through the same `coversScope` the engine asks, so a
screen cannot drift from what runs. The organization view is deliberately
exempt from that filter: a franchise rule does not reach it and would vanish.

**Two tabs, two grants.** `automations.rules.view` and `automations.build.view`.
Build hands somebody the catalogue, which is a map of every trigger and action
this installation can perform; the list carries run logs, which name records a
reader may not be able to open directly. Only the builder's own three endpoints
took the middleware — a blanket group over the block would have taken the rules
away from everybody who was only meant to lose the builder.

**Templates live in code, not a table.** They are identical in every business,
so a table would hold one row per tenant and a migration would reconcile them
on every wording change. Applying one fills the form and saves nothing.

### The two I decided without asking, and why

Jose was away for both.

**Sampling real values into the builder reverses a written rule.** The
`values` map above `triggerFields` says values are declared and never read off
this tenant's records, and that was right for a dropdown of allowed values. It
is wrong for a preview: an invented example cannot answer "what will `{{title}}`
turn out as", because the failure it exists to show is a field that resolves to
a blank. The safety moved rather than went — every candidate event is asked
whose record it is through `ScopeReach`, the same answer the notification
audience uses, so the per-module gate cannot hand a branch manager a value from
another branch.

**A corrupted rule is not migrated.** The builder was wrapping stored list
parameters in another layer on every save, so a live rule reached
`role_ids: ["[[[]]]"]`. The write path is fixed; the stored value is not
rewritten by a migration, because guessing what a garbled list was meant to be
is worse than a refusal that says plainly what is wrong. Opening the rule and
saving it once clears it.
