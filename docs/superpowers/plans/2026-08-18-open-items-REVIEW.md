# Review of `2026-08-18-open-items.md`

**Reviewer:** Claude (Opus 5) · **Date:** 2026-08-18 · **Repo state:** `main` @ `80acccc`

**Verdict: approve with five fixes.** The architecture calls are right — copy the existing
registries instead of inventing parallel ones, wall-clock time for scheduled jobs, dated
leaving events rather than a bare boolean, OAuth rather than IMAP for Outlook, i18n as data
rather than compiled ARB. The phase ordering respects real dependencies. Everything below is
only what I found wrong; I checked each factual claim against the code rather than against
the docs, because two of the docs turned out to be stale.

---

## What I verified as correct

| Claim in the plan | Checked | Result |
|---|---|---|
| Three mailbox providers, `forProvider` is the match to extend | `app/Support/Email/MailboxProvider.php:25,40,54`; `MailboxReaders.php:122` | correct |
| Workspace code is already on `main` | `GoogleWorkspaceAuthController.php`, `2026_08_17_120000_create_google_workspace_tables.php`, `tests/Feature/Api/GoogleWorkspaceTest.php` | correct |
| Backup remote is the websites box | `deploy/backup.sh:59` — `ubuntu@32.194.222.151` | correct |
| `franchises.timezone` stored, read by almost nothing | `2026_08_05_000001_create_foundation_tables.php:98`, default `America/New_York` | correct |
| `scheduled_for` is cast as an instant | `app/Models/Job.php:48` | correct |
| phpunit already forces MySQL `ezbizi_testing` | `phpunit.xml:26-27` | correct |
| `TestCase` refuses a database without `testing` | `tests/TestCase.php:39` | correct |
| TODO #8 is closable | `AuthApiTest::test_an_account_behind_an_authenticator_is_not_silently_skipped` exists | correct |
| TODO #25 is closable | `Invitation` model, `HrInvitationsController`, `InvitationAcceptanceController`, `RolesController` all exist | correct |
| No local MySQL, i18n is greenfield | no `lang/`, no `client/lib/l10n`, no `intl` in `client/pubspec.yaml` | correct |

---

## Fix 1 — Phase 7 rests on a test that does not exist

`SignupTest::test_the_account_can_read_and_write_immediately_with_no_verification_step` is
**not in the codebase**. `tests/Feature/Api/SignupTest.php` has ten tests and not one of them
concerns email verification.

This matters beyond a bad file reference. TODO #22 states that the absence of a verification
gate is "pinned by a test … so adding the gate early fails the suite rather than a customer."
That safety net does not exist. The plan repeats the claim twice (Phase 0 table, Phase 7),
and Phase 7's instruction to *replace* that test is a no-op — there is nothing to replace.

**Do instead:** either write the pin now as its own small task before Phase 7, or delete the
claim from the plan and from TODO #22. Do not leave a plan telling a future agent that a
guard rail is in place when it is not. This is the only load-bearing error I found.

## Fix 2 — `GET /up` already exists, and it does not mean what Phase 4 needs

`bootstrap/app.php` already registers `health: '/up'`. Phase 4 item 1 is done work.

The larger point: Laravel's health route returns 200 once the framework boots, and nothing in
this repo listens on `DiagnosingHealth`. **`/up` answers 200 with MySQL stopped.** A peer
watchdog built on it proves the box has a working nginx and PHP-FPM, not that EzBizi works —
which is precisely the failure the phase exists to catch.

**Do instead:** either register a cheap DB ping on `DiagnosingHealth`, or state plainly in
`docs/DEPLOY.md` and in the watch script's comment that `/up` is a liveness probe only and
the app can be 200-and-broken. Either is fine. Silently treating 200 as "healthy" is not.

## Fix 3 — Phase 6's file list is missing three of five call sites

The plan lists `Job.php`, the write path, and the dashboard widget. `scheduled_for` also
appears in:

- `app/Http/Controllers/Api/AutomationsController.php`
- `app/Support/Automations/ActionRunner.php` — automations **write** this field
- `app/Support/Data/ResourceMap.php` — filters and conditions read it
- `app/Support/Dashboard/Widgets/UpcomingJobs.php`
- `client/lib/screens/jobs_screen.dart`, `client/lib/core/automation_catalogue.dart`

Changing the cast without touching the automations path changes automation semantics
silently — an automation that sets a job time would write a different instant than the one a
technician sees. That is the same bug class the plan's own Global Constraints warn about
under "a forgotten `match` arm is a shipping bug."

**Do instead:** add those files to Phase 6's expected list, and add a test that an automation
which sets `scheduled_for` produces the same wall time the API returns.

## Fix 4 — Phase 13 is numbered last and ordered fourth

The Recommended Order puts local MySQL at position 4; the document puts it at Phase 13. The
plan's own Final Verification says not to claim the deploy gate is green from sqlite — which
means every phase executed before MySQL exists has verification you have agreed not to trust.

A subagent walking phases in file order will do this last and will have "passed" nine phases
on an approximation.

**Do instead:** renumber it Phase 2 (it blocks nothing and unblocks honest verification), or
put a one-line stop at the head of every intervening phase saying which gate is being
deferred. Renumbering is cheaper.

## Fix 5 — Phase 11 has no backfill story

`employment_events` does not exist yet, so the design is free — good. But every user who is
already `is_active = false` has no `left` row. On the day this ships, "who left this month"
returns nothing for them, and the leavers panel will not reconcile against the current
inactive count. The plan covers seeding `started` from invitation-accepted / `users.created_at`
and says nothing about the leaving side.

**Do instead:** pick one and write it down — backfill a `left` event from `users.updated_at`
with a comment saying the date is approximate, or state that leaving history begins at the
migration and have the widget say so on screen rather than implying nobody ever left.

---

## Smaller notes, no action required to proceed

- **Phase 10, "seventh property."** `FieldDefinition` already takes eight constructor
  arguments (`key, label, type, options, filledBy, required, sensitive, retired`), so
  `is_expiry` is the ninth property and the fourth boolean. The wording is inherited from
  TODO #26 and does not change the work — just do not let it become a count someone checks.
- **Phase 8 omits token refresh.** The scope list correctly requests `offline_access`, so
  refresh tokens will arrive and will need handling. `GoogleMailTokenRefreshTest` is the
  shape to copy; the phase should name it.
- **Phase 12 is fully greenfield.** No `lang/` directory, no `client/lib/l10n`, no `intl`
  dependency. "Plumbing plus English and Spanish for the shell" is still by a wide margin the
  largest item in this plan. Worth splitting into a server slice (catalogue table, resolution
  order, API, tests) and a client slice (load at session start, fallback), so the first can
  land and be verified without the second.
- **Phase 3's production check is workable.** The `production` remote exists
  (`app:/var/repos/ezbizi.git`), so `git log production/main` will answer the question the
  phase asks.

---

## Recommended execution order after these fixes

```
1  Local MySQL ezbizi_testing        (was 13 — do it first, it makes everything else honest)
2  Record decisions in TODO/STATUS   (and correct the #22 test claim while there)
3  Signup verification pin test      (the missing guard rail from Fix 1)
4  SES + Workspace console work      (Mia, in parallel with the above)
5  Mutual box alerts                 (with the /up caveat from Fix 2 written down)
6  Platform mail live
7  Job wall-clock scheduled_for      (with the full call-site list from Fix 3)
8  Signup confirmation email
9  Outlook provider
10 Automations leftover audit
11 Contract is_expiry
12 Dated leaving events              (with the backfill decision from Fix 5)
13 i18n foundation
```

Same dependencies as the original. The only moves are MySQL to the front and the missing pin
test ahead of the phase that assumes it.

---

Nothing here requires rewriting the plan's design. Four of the five fixes are a sentence or a
file-list line each; the fifth (the signup pin) is a small test. Everything else in the plan
I would execute as written.
