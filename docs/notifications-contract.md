# Notifications: the seam with Automations

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
| Task pipeline | no |
| Fleet/system dispatches | no |
| `HrOnboardingsController` | yes |

So an organization that switched "Show in-app notifications" off went on
receiving every automation notify. The setting had been in the settings screen
looking live since it shipped.

The check now lives **inside** `dispatch()` and `dispatchUnique()`, on the path
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
| `notifications.task_enabled` | true | assignments, mentions, reminders |
| `notifications.hr_enabled` | true | contract amendments, onboarding |

All four resolve org → company → franchise, and the **subject's** franchise is
what counts, not the recipient's. There is deliberately no `system` toggle:
that source carries "your trial ends tomorrow".

`source` is the axis rather than a new taxonomy because it is the column that
already separates these, and because finer control exists and is owned
elsewhere — a single noisy rule is switched off by `Automation::is_active`, a
single noisy task by a `TaskWatchPreference`. A per-rule toggle here would be a
third place to look when somebody asks why they stopped hearing about something.

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
