# What the demo tenant is missing

Measured 2026-09-04 against production, organization **EzBizi Demo**
(`01a03e4f-f9a1-7012-9515-96bc9260d2f8`). Counts are `withoutGlobalScopes()`
row counts filtered on `organization_id`, not estimates.

Jose asked on 2026-09-04 for the demo tenant to be filled out across every
module, because store screenshots are captured from it and half of them landed
on empty states. This file is the gap list that work is against. **Re-measure
before acting on it** — a count here is true for the hour it was taken.

## Why this file exists rather than a seeder run

`inspection:seed-demo`, `inventory:seed-demo`, `leads:seed-demo` and
`review:seed-organization` all refuse to run here, by design:

    if ($database === 'ezbizi' || ! str_contains($database, 'testing')) { refuse }

That guard was added after `php artisan db:wipe --env=testing --force` fell
through to `.env` on 2026-08-06 and named the live schema. **Do not weaken it.**
The demo tenant lives on the production database and always will, because the
screenshot run signs into `https://app.ezbizi.com`.

Anything that fills this tenant therefore needs a different safety model:
scoped to one organization by id, insert-only, idempotent, and never a
truncate. That is a different risk class from a database-wide seeder and should
be built as such rather than by relaxing the line above.

## Has enough to photograph

| Model | Rows |
|---|---|
| Customer | 13 |
| Job | 24 |
| Service / ServiceGroup | 8 / 1 |
| InventoryItem / Balance / Location / Vendor | 4 / 5 / 2 / 1 |
| Vehicle | 5 |
| User | 8 |
| Lead / Review / Automation | 8 / 5 / 2 |
| Franchise / BusinessLocation | 4 / 2 |
| InspectionDefinition | 1 |

## Empty, and what each one costs

Ordered by what it would buy on a store page.

| Module | Zero rows | Screen today |
|---|---|---|
| **Scheduling** | `ServiceArea`, `ServiceAreaZip` | "Search a zip code or an area name to see its week" — nothing else on screen |
| **Inspection** | `InspectionReport`, `InspectionRun` | New Inspection is a search box over nothing; Generated Reports correctly shows none. The definition exists, so only filed work is missing |
| **HR** | ~~`Contract`~~ (done, see below), `ContractSignature`, `Onboarding`, `TimeClockShift`, `TrainingGuide`, `Invitation` | Eight people on the books and every other line reads 0 |
| **Fleet** | `VehicleAssignment`, `VehicleServiceVisit` | Five real vehicles, all five captioned "Nobody has the keys" |
| **Phone** | `PhoneNumber` and everything below it | "No phone lines yet. Add one in Settings." |
| **Marketing** | `Newsletter`, `NewsletterAudience`, `SocialPost` | Newsletter still carries a "Not built yet" badge, which is a build gap, not a data one |
| **Web / Email** | `WebSite`, `Mailbox` | Both are connection-backed |

## The three that are not just data

Filling these is not the same kind of act as filling Scheduling, and they
should not be swept in with it.

- **Phone.** A `PhoneNumber` row is the app's handle on a real Twilio number.
  Inventing one risks a demo that tries to dial, bill or receive against a
  number nobody owns. Wants a decision and probably a real number parked on the
  demo tenant, not a fabricated row.
- **Web and Email.** `WebSite` and `Mailbox` represent live connections to a
  customer's WordPress and mailbox. A fabricated row is a connection that will
  fail on first use, and may attempt a sync against somebody else's host.
- **Marketing.** `Newsletter` is labelled Not built yet in the product. Rows
  would not remove the badge, so screenshots stay honest only if the module is
  left out until it ships.

Scheduling, Inspection, HR and Fleet have no such edge: their rows describe the
tenant's own invented business and touch nothing outside it.

## Not to be confused with

`inspection:seed-demo` seeds 10 customers and 10 jobs and files **zero**
reports — `InspectionReport` appears nowhere in it. So even where that command
runs, it does not fix the Inspection screen. Verified by grep, with the pattern
shown to match three other files.

## Done since this was measured

**Contracts, 2026-09-04.** Eleven templates with eleven versions, in the demo
tenant, live. Not written by hand and not by a new command: `hr:publish-
onboarding-catalog "EzBizi Demo"` already existed, is intended to run against a
real organization, is audit-logged, and was already deployed. Reaching for it
beat inventing `contract_versions.fields`, which is the json shape this file
originally said not to guess at.

Verified where it landed rather than assumed: A1-Group's newest contract is
dated 2026-08-21 with **zero** created that day, and all eleven of the demo
tenant's are from that day. A count alone could not have told those apart —
there was no before-reading of A1-Group to compare against.

Still open in HR: `Onboarding` and `TrainingGuide`. Onboarding is a workflow
somebody walks through rather than a row to plant, and `training_guides.screens`
is still an unread json shape. `demo:fill` (committed, not yet deployed) covers
Scheduling, Fleet assignments and the time clock.
