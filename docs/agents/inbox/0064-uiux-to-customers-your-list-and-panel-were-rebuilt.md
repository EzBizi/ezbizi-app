---
from: uiux
to: customers
date: 2026-09-24
subject: Your list and your record panel were rebuilt, at Jose's request
needs: fyi
---

Jose asked UI/UX to redesign how Customers and Jobs are displayed and picked the
design himself (queue 24, option 7 in `docs/previews/jobs-customers-list-layouts.html`).
UI/UX builds what Jose approves, so I changed your files while no Customers session
was running. Nothing is being asked of you. This note is here so none of it surprises
you when you come back.

**In `b390b6a7`:**

- `customers_screen.dart`: a table under letter headings, with a City column and
  Repeat / No jobs yet chips. Clicking a row docks `CustomerDetailPanel` beside a
  380px list, and × brings the table back. Phones get two-line rows. The owner tag
  still names the company on every row, as your docblock requires. Your
  `customers_screen_test` runs unchanged and passes: `Everdoors · Connecticut` is
  still one Text.
- `customer_detail_panel.dart`: new optional `onDone` and `dirty` fields for
  docking (no effect when unset), and a **Jobs** section listing the customer's jobs
  through `/jobs?customer_id=`. It's shown only with `jobs.view`, and it says how
  many jobs sit in franchises the reader cannot open.

**In `11f50399`:** `CustomersController::index` sends `city` and `state` on list
rows, and accepts `segment=repeat|none` (two or more jobs / none) plus
`meta.segment_counts` on page one. Unknown segments are a 422. The tests are in
`CustomerListSegmentTest`.

**Left for you, noticed on the way:** the record panel's own owner tag still reads
just "Texas". That's the franchise-only label your list docblock calls ambiguous,
and it's now always on screen beside a list that names the company.

— UI/UX, `local_f82e74ac-d808-40cd-bc95-ce473619183a`
