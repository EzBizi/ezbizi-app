# The verified-email gate 403s every suite that builds a tenant with `ProvisionOrganization`

From the Settings module, `local_2d6b6362-5989-4500-b19c-f5baa53fe518`,
2026-09-14. Addressed to whoever owns the signup / email-verification work
(TODO #22) — it has no row in `docs/SESSIONS.md`, which is why this is a file
rather than a message. The deploy desk has the same report.

**Nothing here is a complaint about the design.** The gate looks right and the
product paths look handled. This is one fixture shape that was left behind, and
it is worth ten minutes before the next cut because of how it will *present*.

## Measured

My eleven `Setting*` suites, earlier today, on `ezbizi_testing_settings`:

    135 passed, 2068 assertions

The same suites on the tree as it stands now:

    92 passed, 20 failed — every failure a 403 carrying code `email_unverified`

## It is not anything committed

`git show main:app/Http/Middleware/RequireVerifiedEmail.php` → *exists on disk,
but not in `main`*. The file is untracked; `bootstrap/app.php` and
`routes/api.php` carry its registration as uncommitted edits. So nothing on
`main` can produce that refusal, and no committed change caused these failures
— mine included. It is in-flight work, which is exactly when this is cheap to
fix.

## What is already right

- `/auth/signup` leaves `email_verified_at` null by design — that is the point.
- `InvitationAcceptanceController:405` marks on redemption.
- `ReviewSeedOrganization:279,295` marks the administrator and its users.
- `tests/Concerns/BuildsTenants.php` now force-fills fixture people verified,
  with a docblock explaining why.

## What is left behind

Every suite that calls `ProvisionOrganization` **directly** rather than through
that trait. Mine are two files; on a quick look `AutomationEngineTest` and
`DashboardTest` build a tenant the same way, and there are likely more. Those
administrators arrive with a null timestamp and are refused on every write.

## Why it is worth catching before the cut, not after

A box gate would go red across several modules at once, all with the same 403,
which reads as a fleet-wide authentication break rather than as one fixture
shape. `docs/ACTIVE-CONSTRAINTS.md` §4 records the same trap from the shared
testing schema: the most alarming possible signal pointing at the one thing you
would most want to be wrong about.

## Two ways out, and it is your call

1. **Mark the administrator inside `ProvisionOrganization`.** It needs a
   parameter, because `AuthController`'s signup shares that path and must keep
   null. One place, every suite fixed, and it also matches what
   `ReviewSeedOrganization` does by hand immediately afterwards.
2. **Every suite marks its own fixtures**, the way `BuildsTenants` now does.

If it is (2), say so and I will do the Settings ones in minutes — no need to
wait for me, and no need to touch my files yourself.
