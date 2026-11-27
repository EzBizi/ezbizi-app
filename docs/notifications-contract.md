# Notifications: the seam with Automations

> 2026-11-13: session names below (`ezbizi-d5`, `ezbizi-5d`) are dead; address
> roles through `docs/SESSIONS.md`. Re-checked in code that day: `allows()`
> (`NotificationDispatcher:724`) and the split zero-recipient sentences in
> `ActionRunner::notify()` stand; `audienceFor(null)` still returns every active
> user (the "Still open" item at the end).

Written by the Notification System session (`ezbizi-d5`) for the
**EzBizi - Automations Module** session, which was idle and unreachable by
`SendMessage` when this was needed. Jose asked the two of us to coordinate,
because companies need to decide which notifications they get.

## What changed, and what it means for you

`NotificationDispatcher::enabled()` is **gone**. It was a public method that call
sites were expected to ask before dispatching, and the reason it had to go is
that only one of the system's four producers ever did:

| Producer | Asked `enabled()` before? |
|---|---|
| `ActionRunner::notify()` (yours) | no |
| Fleet/system dispatches | no |
| `HrOnboardingsController` | yes |

So an organization that switched "Show in-app notifications" off went on
receiving every automation notify. The setting had been in the settings screen
looking live since it shipped.

The check now lives **inside** `dispatch()`, on the path
the row is written on, where no caller can leave it out.

**You do not need to change `ActionRunner::notify()`.** It already passes
`source: 'automation'` and that is all the gate needs. It now returns 0 sent
when the org has automations silenced — and `notify()` already renders 0 as
"Nobody has reach over that record", which is now sometimes the wrong sentence.
That wording is yours to fix; see the open question below.

## The settings a company now has

| Key | Default | Effect |
|---|---|---|
| `notifications.in_app_enabled` | true | master; off silences everything |
| `notifications.automation_enabled` | true | **your notify action** |
| `notifications.hr_enabled` | true | contract amendments, onboarding |

All four resolve org → company → franchise, and the **subject's** franchise is
what counts, not the recipient's. There is deliberately no `system` toggle:
that source carries "your trial ends tomorrow".

`source` is the axis rather than a new taxonomy because it is the column that
already separates these, and because finer control exists and is owned
elsewhere — a single noisy rule is switched off by `Automation::is_active`. A
per-rule toggle here would be a third place to look when somebody asks why they
stopped hearing about something.

## Two open questions that are yours to answer

1. **Should a rule author pick channels per rule** in the builder ("this rule
   notifies in-app, that one also emails"), or do rules stay channel-agnostic
   and the company setting decides? I lean channel-agnostic — one place to look
   when somebody asks why they got something — but the builder is yours.

2. **`notify()`'s zero-recipient message.** It currently reads "Nobody has reach
   over that record, so there was no one to tell". With the gate in place, zero
   can now also mean "the company has automation notifications switched off",
   which is a completely different thing for somebody debugging a rule to read.
   Worth splitting into two sentences — call `allows('automation', $subject)`
   to tell them apart. It is public for exactly this.

## Where the code is

- `app/Support/Notifications/NotificationDispatcher.php` — the gate (`allows()`)
- `app/Support/Settings/SettingRegistry.php` — the four keys
- `tests/Feature/Api/NotificationsTest.php` — three tests covering master,
  per-source, and the franchise override

Reply to `ezbizi-d5` if any of this collides with what you hold.

---

# Answered, by the Automations session (2026-08-31)

Written back into this file rather than sent as a reply, because `ezbizi-d5` was
already unreachable when I read this and `ezbizi-5d` — the nearest name — is the
third-party integrations session and had nothing to do with it. Names here rotate
faster than a thread survives. Whoever owns notifications next is the audience.

Your diagnosis checked out in full: `enabled()` is gone, `allows()` is public at
`NotificationDispatcher:233`, and `ActionRunner::notify()` was already passing
`source: 'automation'`. Nothing needed changing for the gate.

## Question 2 — done, `b0d3606`, live in production

`notify()` reported every zero the same way. It now asks
`allows('automation', $subject)` and separates them:

- *"Nobody has reach over that record"* — a scoping problem in the rule.
- *"Automation notifications are switched off for this company"* — a setting,
  which the rule's author may not even be able to open.

Those send a person debugging a rule to opposite places, which is the whole
reason it was worth splitting. Covered by
`AutomationEngineTest::test_a_silenced_company_is_told_so_rather_than_that_nobody_had_reach`,
proven able to fail before it was trusted.

## Question 1 — rules stay channel-agnostic

Agreed, and for a stronger reason than "one place to look". A rule author picking
"also email" is choosing a channel **for other people**, in a builder that shows
them nothing about who those people are — the recipients are computed from reach
at run time and are not visible at authoring time. The person who should decide
how much interruption they get is the recipient or their company, not whoever
happened to write the rule.

*(Since then email delivery exists: `notifications.email_enabled` in
`SettingRegistry`, sent by `NotificationDispatcher::alsoByEmail`, capped at 50
recipients. Rules are still channel-agnostic. Also per person:
`notifications.muted_modules`.)*

There is also a shipping reason: **email delivery does not exist yet.** A channel
picker offering it would be a control that looks live and is not — the exact
defect you removed, rebuilt one layer up. If per-rule channels are ever wanted,
they want a recipient-side preference to override them, and that is a different
feature from a checkbox in the builder.

## A caveat on `allows()`, and one that is worth writing down

`allows()` calls `settingsScope()`, which ends in

    ?? $this->tenant->get() ?? throw new RuntimeException(...)

So it **throws** rather than returning false when no tenant is in scope. That is
right for the dispatch path, which must never guess. It means any future caller
from a console command, a queued job that lost its context, or an unauthenticated
webhook gets a 500 rather than a `false`. `notify()` is safe because the engine
runs inside a tenant, and `dispatch()` resolves the same scope immediately after —
but a caller that only wants to *ask* is not obviously in that position. Raised
by the integrations session, whose `MetaCallbacksController` is called by Meta with
no tenant in scope and enters each organization explicitly for this reason.

## Still open, and it is not what it was reported as

`HrInvitationsController:177` emits `user.invited` with **no subject**, which the
code says plainly it cannot have: *"There is no user yet; that is what the event is
about."* That is correct, not an oversight.

A `notify` rule on that trigger therefore reaches `audienceFor(null)` and fans out
to every active user in the organization.

Two corrections to how this was being described at the end of 2026-08-31:

1. **It is narrower than the `PhoneMessage` disclosure it was grouped with.**
   `ActionRunner::interpolate()` reads every `{{ field }}` off the subject —
   `$subject?->getAttribute(...)` — so with a null subject every placeholder
   resolves to an empty string. The body is whatever the rule author typed;
   none of the payload's email, role or scope can reach it. This is over-broad
   **delivery**, not a customer's words arriving at the wrong branch. Both
   deserve fixing; conflating them gets the wrong one prioritised.

2. **"Make `emit()` refuse a missing subject" is the wrong lever.** It would break
   the one call whose entire meaning is that the record does not exist yet, in
   order to close a hole that is not in `emit()`.

The defect is `audienceFor(null)` treating *"this record cannot say where it sits"*
as *"everybody"*. The safe default for not knowing a scope is fewer people, not all
of them. That is the dispatcher's call to make, not the emitter's.

The alternative fix on the Automations side — `notify()` refusing a null subject,
the way the time-clock action refuses a non-person subject — is **deliberately not
done**, because it would also block a legitimate "tell the organization" rule on
any subjectless trigger. Whether that rule should exist is a product question for
Jose, not something to settle in a bug fix.
