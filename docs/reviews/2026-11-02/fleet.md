# Fleet Management — review, 2026-11-02

Measured on production on 2026-11-03, release `20261103160531-7ade29adc67f`. Org: Triplex Group,
which is the renamed A-1 workspace and holds Eve's 24 vehicles. I called the controllers as a user
holding `fleet_management.financials`. All of this was read-only; nothing was written.

I measured the server endpoints and the data. I did not open the screens: I did not drive the app
UI in this pass. The client was last confirmed by its tests (fleet_screen_test, General dashboard tests).

## 1. Working

- Vehicles list: 200, 24 rows, 280 ms (24 vehicles).
- One state at a time: `meta.places` = Connecticut 6, DMV 3, Illinois 2, New Jersey 7, Ohio 2, Texas 4. Every vehicle has a state (0 untagged).
- General summary: 200, 119 ms. health (24 active, 24 owned), warnings, dates, spending (financials only).
- Filters, all 200: uninsured=1 (14), insurance_expiring=1 (0), due_soon=1 (0), unassigned=1 (24), status=sold (0).
- Insurance: 10 policies on file. The "No insurance" warning (14) links to the uninsured filter.
- Missing-field counters, money stripping without financials, and the Fleet glance: covered by green tests (FleetSummaryTest, FleetGlanceTest, VehicleListApiTest). No production error was found.

## 2. Broken

No code fault was found. These are data gaps that leave features empty on production:

- **Nobody is assigned to any vehicle** (0 open assignments, 24 "in use with nobody holding the keys").
  Why: the A1 Tools import carried vehicles, not drivers (Jose: the A1-TEST assignments were a test).
- **No service schedule on any vehicle** (24 of 24 have neither a next-service date nor mileage),
  21 have no registration expiry, and 15 have no plate. Why: A1 Tools held none of these.
  Effect: the summary's `dates` list is empty, Due soon is 0, and the General tab shows no "Coming up".
- **Spending is empty**: 0 payments and 0 service visits have been recorded.

Fix SHAs this pass: none needed. Fleet work since the last review: `081b7e9a` (one state at a time),
`698a9d88` (summary dates, spending vs last period, top vehicles, uninsured filter),
`6a2de3c0` (insurance_expiring filter).

## 3. Cohesiveness (for UI-UX)

- The state buttons show counts for the whole register, not for the active filter. With
  "No insurance" on, Connecticut still reads 6 when only some of the 6 are uninsured. Other modules'
  chips are not count-bearing, so this one is unique; either drop the numbers or count them under the filter.
- "General" is used both as the dashboard tab and as the state for untagged vehicles (today 0, so it is never drawn).

## 4. Suggestions (ranked)

1. Assign drivers to the 24 vehicles. This turns on the dashboard, the "who has the keys" warnings and per-person views.
2. Enter a next-service date or mileage, the registration expiry and the plate (15 missing). This fills Coming up and Due soon. The amber "Not filled in" marks already show where.
3. Record payments as they happen (fuel, maintenance, insurance), so Spending, vs last month and the top vehicles have numbers.
4. Count the state buttons under the active filter (server: compute `places` after the filters; a small change).
5. Bulk edit of one field across the vehicles in a state (for example plate state or the insurer) would cut the data entry in items 1–3.

## 5. Needs Jose

- Who should enter drivers, service dates and plates for Eve's 24 vehicles: Eve in EzBizi, or should we import them from somewhere else?
- Should the state buttons count only the vehicles that match the active filter (suggestion 4)?
