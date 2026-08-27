# Task 17A report — typed cells and atomic bulk editing

## Delivered

- Added `task_column_cells.dart`: typed custom-column cells for Text, Number,
  Checkbox, Date, People, Label, Link and File. File remains visibly
  unavailable until the Task 10A restore gate. Unknown definitions and malformed
  known values remain visible, preserve their raw JSON, and are read-only.
- Added server-scoped participant, custom-column and bulk API commands. Candidate
  People/owner lists come only from `tasks/boards/{board}/participants` with the
  appropriate server-declared purpose; no `/me` eligibility or permission logic
  is reconstructed by the client.
- Updated the dense table with typed core/custom cells, versioned selection,
  and status/priority/archive bulk actions. A bulk request updates the displayed
  rows only after one successful server response, preserving atomic client
  behavior.
- Scalar core edits render optimistically and roll back to the original row on a
  validation failure or conflict. Custom column updates and archive/bulk actions
  wait for the server response. Every mutation carries the current task version.
- Added a screen-reader live region for save, refusal, conflict, selection and
  bulk-status announcements.

## TDD evidence

- `task_cells_and_bulk_test.dart` was added first and initially failed because
  `task_column_cells.dart` and its contracts did not exist. It now covers all
  eight cell types, raw read-only fallback, sort restrictions, selected version
  payloads, scalar rollback and structural waiting.
- Extended `task_api_test.dart` first for purpose-scoped candidates, a versioned
  column mutation and one atomic bulk payload; it initially failed for missing
  API/model contracts and passed after implementation.

## Verification

- `flutter test test/tasks/task_cells_and_bulk_test.dart test/tasks/task_api_test.dart test/tasks/task_models_test.dart test/tasks/task_table_test.dart test/tasks/tasks_screen_test.dart` — 25 passing.
- Targeted `flutter analyze` of Tasks model/API/cells/table/screen — no errors;
  existing informational style/context diagnostics remain.

## Constraints observed

- Server refusal/eligibility sentences are surfaced unchanged; the client does
  not turn an empty candidate list into inferred eligibility.
- No raw reach picker, modal, hard-coded colors, upload path, or production
  change was introduced.

## Fix round 1/5

- Partial scalar and bulk task projections now merge into the existing displayed
  row, retaining custom values and compact detail while accepting the server's
  changed core fields and versions.
- Per-task mutation queues acquire the latest displayed version only when each
  dependent request begins. Candidate lists are cleared while a board changes.
- Number accepts the API's canonical decimal-string representation; invalid
  nonempty entry remains local validation rather than becoming a null clear.
  Existing File values remain visible beside the restore-gate explanation.
- Status/priority menus omit archived options, and operational fields no longer
  exclude screen-reader semantics.

Verification: focused Task17A/API/model/table/screen Flutter run passed 26
tests; targeted analyze completed with no errors (informational diagnostics
remain).
