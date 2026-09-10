# Reports — client implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The Reports tab draws. Somebody with `reports.view` opens a saved report and gets its rows; somebody with `reports.build` composes one from a field tree and saves it.

**Architecture:** One screen with two surfaces, split by width. Above 900px it is Jose's **option 1** — a field tree on the left, the report on the right. Below that it is **option 5** — the count first, then each row as a card. A phone runs and does not build, so the tree is absent there rather than shrunk.

**Tech Stack:** Flutter, `ApiClient`, `Session`. No new packages.

**Spec:** `docs/superpowers/specs/2026-09-10-reports-design.md`
**Design:** `docs/previews/reports-builder-layouts.html` (`bc13f121`). Jose chose **1 and 5** on 2026-09-10.
**Backend:** branch `session/reports`, tip `b98c3103`. This plan cannot be executed against `main` until that merges.

## Global Constraints

- **Two surfaces, one screen, split at 900px.** Option 1 above, option 5 below. The number is a starting point, not a ruling — check it against the real tree at 900–1100px and say if it wants moving.
- **A phone runs; it does not build.** UI/UX ruling, accepted. No field tree below the split, and the mobile surface says reports are built on a computer rather than hiding the fact.
- **Build and run are different surfaces, not one surface with a disabled mode.** A greyed builder tells somebody the app is for other people. Without `reports.build` there is no New button at all — a list of what they can run.
- **The two empty states are opposite facts and must not share a sentence.**
  - Nothing built yet: *"No reports built yet."* (plus the way to build one, only if they hold `reports.build`)
  - A run that matched nothing, verbatim from UI/UX: *"No rows matched, for the records you can see. Somebody with a wider reach may get results from the same report."*
  The second must never read as a fault. A person's franchise reach legitimately narrows a report, and the reader is not entitled to conclude anything about rows outside it.
- **The cap line appears only when the cap actually bit.** `capped` is a separate boolean from `count` in the run response precisely so this is never inferred — a report with exactly 5,000 real rows is not truncated. "The first 5,000" of nothing is not a truthful sentence, and a red line under an empty table reads as a fault.
- **No modal dialogs.** Screens or side panels (development rules). Touch and 375px must work.
- **Theme colours only**, never literals, never decorative gradients.
- **`client/lib/core/module_screens.dart` is UI/UX's file.** One `reports` entry, the same way Training took one. Tell them after; do not redesign around it.

## API surface this plan consumes

All already built and tested on `session/reports`:

| Call | Returns |
|---|---|
| `GET /reports` | `{data: [{id, name, description, company_id, franchise_id, definition}]}` |
| `GET /reports/fields` | `{data: {<resource>: {label, columns: {<col>: {label, type}}, relations: {<name>: {to}}}}}` |
| `POST /reports/{id}/run` | `{data: {columns: [{key, label, type}], rows: [{<key>: value}], capped, count, cap}}` |
| `POST /reports` | 201 `{data: …}`; 422 `{errors: {definition: [sentence]}}` |
| `PATCH /reports/{id}` · `DELETE /reports/{id}` | updated `{data: …}` · 204 |

Two shapes that will bite if forgotten:

- **Row keys contain dots** — `jobs.title`, not nested objects. Read `row['jobs.title']`, never `row['jobs']['title']`.
- **A relation's NAME is its key**, not a field: `relations: {"customer": {"to": "customers"}}`. There is no human label, deliberately — there is one relation in the product today, and `ReportMapTest::test_two_relations_to_the_same_target_would_need_labels` goes red the day a label becomes necessary. Render `› customer › Customers`.

---

### Task 1: The tab exists and lists what you can run

The smallest thing that draws. Deliberately the run-only surface first: it is what most people see, it is all a phone gets, and it is the half that must be right for somebody who cannot build.

**Files:**
- Create: `client/lib/screens/reports_screen.dart`
- Modify: `client/lib/core/module_screens.dart` — one `reports` entry
- Test: `client/test/reports_screen_test.dart`

**Interfaces:**
- Consumes: `ApiClient.get('/reports')`, `Session` for permissions.
- Produces: `ReportsScreen({required ApiClient api, required Session session})`, and `SavedReportSummary` with fields `id`, `name`, `description` parsed from `/reports`.

- [ ] **Step 1: Write the failing test**

```dart
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/screens/reports_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/testing.dart';
import 'package:http/http.dart' as http;

void main() {
  testWidgets('a saved report is listed by name', (tester) async {
    await _pumpReports(tester, reports: [_report]);

    expect(find.text('Open jobs'), findsOneWidget);
  });

  testWidgets('with no reports it says none are built, not that none matched', (
    tester,
  ) async {
    await _pumpReports(tester, reports: []);

    expect(find.textContaining('No reports built yet'), findsOneWidget);
    // The other empty state belongs to a RUN, and the two mean opposite things.
    expect(find.textContaining('No rows matched'), findsNothing);
  });

  testWidgets('without reports.build there is no New report control at all', (
    tester,
  ) async {
    await _pumpReports(tester, reports: [_report], canBuild: false);

    expect(find.text('New report'), findsNothing);
  });

  testWidgets('with reports.build the New report control is offered', (
    tester,
  ) async {
    await _pumpReports(tester, reports: [_report], canBuild: true);

    expect(find.text('New report'), findsOneWidget);
  });
}
```

The last two are a pair on purpose. The negative alone would pass on a screen that renders no controls at all.

`_pumpReports` follows `client/test/services_screen_test.dart` exactly — `MockClient` returning `jsonEncode({'data': …})` for `/api/v1/reports`, wrapped in `MaterialApp` with `ezbiziTheme`. Read that file and copy its helper rather than inventing one; it already handles the `Session` construction this screen needs.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd client && flutter test test/reports_screen_test.dart`
Expected: FAIL — `reports_screen.dart` does not exist.

- [ ] **Step 3: Write minimal implementation**

The screen loads `/reports` on mount, renders a list, and offers New only when the session holds `reports.build`. No builder yet — that is Task 3, and this task must be shippable without it.

Key decisions to encode, each with a comment saying why:

```dart
/// Reports (spec 2026-09-10, design docs/previews/reports-builder-layouts.html).
///
/// Two surfaces rather than one that adapts: above [buildAbove] the builder is
/// a field tree beside the report (Jose's option 1); below it, a phone RUNS what
/// a desk built (option 5). That is not a size threshold on one design, it is
/// two designs — an unlimited-hop picker at 375px would be a worse copy of a
/// control that already works, and offering it invites somebody to start a
/// report they cannot finish.
static const double buildAbove = 900;
```

The New control is absent, never disabled:

```dart
// Absent rather than greyed. A disabled builder tells somebody the app is for
// other people; a list of what they can run tells them what they have.
if (session.can('reports.build')) ... // the New report control
```

`session.can(String)` was checked against `client/lib/core/session.dart:637` while writing this plan — `bool can(String permission) => permissions.contains(permission);`, over a `Set<String>` populated from `/me`. It is the real method; do not add a wrapper.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd client && flutter test test/reports_screen_test.dart`
Expected: PASS, 4 tests.

- [ ] **Step 5: Add the module entry and confirm the tab draws**

One line in `client/lib/core/module_screens.dart`, beside the others:

```dart
'reports': ModuleScreen(
  Icons.assessment_outlined,
  (api, session) => ReportsScreen(api: api, session: session),
),
```

The icon matches the `'reports'` row already in `dashboard_panels.dart` — same glyph in the rail and on the dashboard, which is the point of both maps agreeing.

Then: `cd client && flutter analyze && flutter test`
Expected: analysis clean, whole client suite green. `ClientModuleScreensTest` on the PHP side also reads this file; run it too:
`DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_reports php -d memory_limit=1G vendor/bin/phpunit tests/Feature/ClientModuleScreensTest.php`

- [ ] **Step 6: Commit**

```bash
git add client/lib/screens/reports_screen.dart client/lib/core/module_screens.dart client/test/reports_screen_test.dart
git commit -m "Reports: the tab draws, and lists what you can run"
```

---

### Task 2: Running a report

Where the two surfaces diverge and where the empty states earn their keep.

**Files:**
- Create: `client/lib/screens/reports/report_run_view.dart`
- Modify: `client/lib/screens/reports_screen.dart` — open a report into the run view
- Test: `client/test/reports_run_test.dart`

**Interfaces:**
- Consumes: `ApiClient.post('/reports/$id/run', {})`.
- Produces: `ReportRunView({required ApiClient api, required String reportId, required String name})`, and `ReportRun` parsed from the response with `columns` (list of `(key, label, type)`), `rows`, `capped`, `count`, `cap`.

- [ ] **Step 1: Write the failing test**

```dart
void main() {
  testWidgets('a wide screen draws the rows as a table', (tester) async {
    await _pumpRun(tester, width: 1200, run: _oneRow);

    expect(find.byType(DataTable), findsOneWidget);
    expect(find.text('Sweep'), findsOneWidget);
  });

  testWidgets('a narrow screen leads with the count, then cards', (tester) async {
    await _pumpRun(tester, width: 375, run: _oneRow);

    expect(find.byType(DataTable), findsNothing);
    expect(find.textContaining('1 row'), findsOneWidget);
    expect(find.text('Sweep'), findsOneWidget);
  });

  testWidgets('a run that matched nothing does not read as a fault', (tester) async {
    await _pumpRun(tester, width: 1200, run: _noRows);

    expect(
      find.textContaining('No rows matched, for the records you can see'),
      findsOneWidget,
    );
    expect(
      find.textContaining('Somebody with a wider reach'),
      findsOneWidget,
    );
    // The opposite fact, which belongs to the LIST and not to a run.
    expect(find.textContaining('No reports built yet'), findsNothing);
  });

  testWidgets('the cap line is absent when the cap did not bite', (tester) async {
    await _pumpRun(tester, width: 1200, run: _oneRow);

    expect(find.textContaining('5,000'), findsNothing);
  });

  testWidgets('the cap line appears when the cap did bite', (tester) async {
    await _pumpRun(tester, width: 1200, run: _capped);

    expect(find.textContaining('first 5,000'), findsOneWidget);
  });

  testWidgets('an empty run shows no cap line even if capped were true', (
    tester,
  ) async {
    // Defensive: "the first 5,000" of nothing is not a truthful sentence, and a
    // red line under an empty table reads as a fault.
    await _pumpRun(tester, width: 1200, run: _cappedButEmpty);

    expect(find.textContaining('5,000'), findsNothing);
  });

  testWidgets('a dotted column key is read as a key, not as a path', (
    tester,
  ) async {
    // rows are {'jobs.title': 'Sweep'} — flat, with dots IN the key. A reader
    // that splits on the dot finds nothing and renders a blank table.
    await _pumpRun(tester, width: 1200, run: _oneRow);

    expect(find.text('Sweep'), findsOneWidget);
  });
}

const _oneRow = {
  'columns': [
    {'key': 'jobs.title', 'label': 'Title', 'type': 'string'},
  ],
  'rows': [
    {'jobs.title': 'Sweep'},
  ],
  'capped': false,
  'count': 1,
  'cap': 5000,
};
```

Write `_noRows`, `_capped` and `_cappedButEmpty` as the same shape with `rows: []` / `capped: true` / both. Repeat them literally in the file; do not build them with a helper that could paper over a parsing bug.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd client && flutter test test/reports_run_test.dart`
Expected: FAIL — `report_run_view.dart` does not exist.

- [ ] **Step 3: Write minimal implementation**

Sizing comes from `LayoutBuilder`, not `MediaQuery`, so the view is testable at a given width and correct inside a split pane.

The three sentences, exactly:

```dart
// UI/UX's wording, used verbatim and deliberately. The runner returns zero
// rows identically whether the franchise is genuinely empty or merely outside
// this person's reach — it cannot tell them apart and should not try. Only
// this sentence can decline to let the reader conclude which.
static const String noRows =
    'No rows matched, for the records you can see. '
    'Somebody with a wider reach may get results from the same report.';
```

```dart
// Only when the cap actually bit AND there are rows to have been cut.
if (run.capped && run.rows.isNotEmpty) ... 'Showing the first ${run.cap} rows.'
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd client && flutter test test/reports_run_test.dart`
Expected: PASS, 7 tests.

- [ ] **Step 5: Prove the empty-state sentences cannot be swapped**

Swap the two sentences in the source, re-run, and confirm **both** the list test from Task 1 and the run test here go red. Then restore.

This is the one pair in the client that a copy edit could quietly invert, and inverting them tells somebody their data is missing when it is merely out of reach. Restore by editing back, not `git checkout --`, which would discard the rest of the working tree.

- [ ] **Step 6: Commit**

```bash
git add client/lib/screens/reports/report_run_view.dart client/lib/screens/reports_screen.dart client/test/reports_run_test.dart
git commit -m "Reports: running one, and the two empty states that mean opposite things"
```

---

### Task 3: The builder (option 1)

Desktop only. The field tree on the left, the report on the right.

**Files:**
- Create: `client/lib/screens/reports/report_builder.dart`
- Create: `client/lib/screens/reports/field_tree.dart`
- Modify: `client/lib/screens/reports_screen.dart` — New report opens the builder
- Test: `client/test/reports_builder_test.dart`

**Interfaces:**
- Consumes: `ApiClient.get('/reports/fields')`, `ApiClient.post('/reports', …)`, `ApiClient.patch('/reports/$id', …)`.
- Produces: `ReportBuilder({required ApiClient api, required Session session, SavedReportSummary? editing})`; emits a definition of the shape `{root, columns: [{resource, column}], filters: [{resource, column, op, value}]}`.

- [ ] **Step 1: Write the failing test**

```dart
void main() {
  testWidgets('the tree lists resources from the fields endpoint', (tester) async {
    await _pumpBuilder(tester, fields: _fields);

    expect(find.text('Jobs'), findsOneWidget);
    expect(find.text('Customers'), findsOneWidget);
  });

  testWidgets('a hop is drawn with its relation name, not just the target', (
    tester,
  ) async {
    await _pumpBuilder(tester, fields: _fields);
    await tester.tap(find.text('Jobs'));
    await tester.pumpAndSettle();

    // The relation's NAME is its key in the payload; there is no human label
    // and deliberately so while one relation exists in the product.
    expect(find.textContaining('customer'), findsWidgets);
  });

  testWidgets('choosing columns and saving posts the definition', (tester) async {
    Map<String, dynamic>? posted;

    await _pumpBuilder(
      tester,
      fields: _fields,
      onPost: (body) => posted = body,
    );

    // …choose Jobs › Title, name it, save…

    expect(posted!['definition']['root'], 'jobs');
    expect(
      (posted!['definition']['columns'] as List).first,
      {'resource': 'jobs', 'column': 'title'},
    );
  });

  testWidgets('a refusal from the server is shown as its own sentence', (
    tester,
  ) async {
    // The server validates a definition by RUNNING it, so its complaint is the
    // real one and is worth showing verbatim rather than replacing with "invalid".
    await _pumpBuilder(
      tester,
      fields: _fields,
      postStatus: 422,
      postBody: {
        'errors': {
          'definition': ["'Jobs' does not report a column called 'secret'."],
        },
      },
    );

    // …attempt a save…

    expect(
      find.textContaining('does not report a column called'),
      findsOneWidget,
    );
  });

  testWidgets('the builder is not offered below the split', (tester) async {
    await _pumpReports(tester, width: 375, reports: [], canBuild: true);

    expect(find.text('New report'), findsNothing);
    expect(find.textContaining('built on a computer'), findsOneWidget);
  });
}
```

The last one is the ruling made checkable: a phone holding `reports.build` still gets no builder, and is told why rather than left wondering.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd client && flutter test test/reports_builder_test.dart`
Expected: FAIL — `report_builder.dart` does not exist.

- [ ] **Step 3: Write minimal implementation**

A left pane that lists resources, expands to columns, and offers reachable resources as a nested level; a right pane that shows the chosen columns and, once there is at least one, a live run. Filters are a row per filter: resource + column + operator + value, where the operators are exactly `eq ne lt lte gt gte contains` and the labels are plain words ("is", "is not", "before", "on or before", "after", "on or after", "contains").

Do not build a client-side validator. The server validates by running the definition, and a second copy of those rules on this side is the thing that drifts — show the server's sentence.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd client && flutter test test/reports_builder_test.dart`
Expected: PASS, 5 tests.

- [ ] **Step 5: Whole client suite and analysis**

```bash
cd client && flutter analyze && flutter test
```

Expected: clean analysis, whole suite green. Report the counts, not the word "green".

- [ ] **Step 6: Commit**

```bash
git add client/lib/screens/reports/ client/lib/screens/reports_screen.dart client/test/reports_builder_test.dart
git commit -m "Reports: the builder, a field tree beside the report"
```

---

## Not in this plan

| Not here | Why |
|---|---|
| CSV and PDF export | Its own plan; the backend does not have it either yet |
| Grouping and the chart | Deliberately after the flat table works — a wrong number is worse than no number |
| `report.send` | Needs the runner arm and the rule-vs-report scope check |
| More reportable resources | Backend work. Three resources and one edge make the tree look thin; Inventory, Fleet, Services and HR are each a map entry plus edges, and Jose has been asked for a priority order |

## The thing most likely to be wrong

**The 900px split.** It is a guess made from a static gallery, not from the tree with real resources in it. Check it at 900, 1000 and 1100 with the field tree open and a report of six columns beside it — if the right pane is cramped at 900, move the split up and tell UI/UX rather than shrinking the tree.
