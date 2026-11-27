import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/training/dashboard_list.dart';
import 'package:ezbizi_client/screens/training_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Dashboard is layout 6 (`docs/previews/training-layouts.html`, Jose's
/// approved pick): one row per guide or test with a completion bar,
/// selecting one opens a PANE of the people behind it — never a matrix, and
/// never a modal.
///
/// `GET /training/dashboard` (`DashboardController::index`) answers
/// `{data: [...], summary: {...}}`. Each row: `{id, title, kind, assigned,
/// passed, in_progress, overdue, retaking}`. `summary`:
/// `{people_assigned, people_overdue, people_due_for_retaking}` — counts of
/// DISTINCT PEOPLE, never a sum of the rows (the controller's own docblock,
/// "The summary counts PEOPLE, never assignments").
///
/// `GET /training/dashboard/{id}/people` answers `{data: [...]}`, one row
/// per person: `{user_id, name, state, due_on, ...detail}`.
///
/// `kind` (`'guide'` or `'test'`) is what `DashboardPane`'s "＋ Assign to
/// more people" control (audit-fixes-report.md, closing the missing-control
/// finding) reads to know which of `guide_id`/`test_id` to post to
/// `/training/assignments` — see `DashboardController::row`'s own docblock.
void main() {
  // ── The headline numbers ─────────────────────────────────────────

  testWidgets(
    'overdue and due-for-retaking render as two different numbers, from the summary',
    (tester) async {
      await _pumpDashboard(
        tester,
        rows: [_row(id: 't-1', title: 'Ladder Safety Test', assigned: 5, passed: 2, overdue: 1, retaking: 1)],
        summary: _summary(assigned: 5, overdue: 2, retaking: 3),
      );

      expect(find.text('2 overdue'), findsOneWidget);
      expect(find.text('3 due for retaking'), findsOneWidget);
      expect(find.textContaining('certification'), findsNothing);
    },
  );

  // ── The summary is not a row sum ─────────────────────────────────

  testWidgets(
    'the summary is the distinct-people count, never a sum of the per-row counts',
    (tester) async {
      // Two rows, each naming 2 overdue ASSIGNMENTS — a client that summed
      // the rows would print "4 overdue". The real answer is 1 DISTINCT
      // person, overdue on both, which is what the fixed `summary` says.
      await _pumpDashboard(
        tester,
        rows: [
          _row(id: 'g-1', title: 'Guide A', assigned: 3, passed: 0, overdue: 2, retaking: 0),
          _row(id: 'g-2', title: 'Guide B', assigned: 3, passed: 0, overdue: 2, retaking: 0),
        ],
        summary: _summary(assigned: 3, overdue: 1, retaking: 0),
      );

      expect(find.text('1 overdue'), findsOneWidget);
      expect(find.text('4 overdue'), findsNothing);
    },
  );

  // ── Selecting a row opens the pane ───────────────────────────────

  testWidgets('selecting a row opens the pane, listing its people', (tester) async {
    await _pumpDashboard(
      tester,
      rows: [_row(id: 't-1', title: 'Ladder Safety Test', assigned: 2, passed: 1, overdue: 1, retaking: 0)],
      summary: _summary(assigned: 2, overdue: 1, retaking: 0),
      people: {
        't-1': [
          _person(id: 'u-dave', name: 'Dave Turner', state: 'overdue', dueOn: '2020-01-01'),
          _person(id: 'u-priya', name: 'Priya Kaur', state: 'passed', score: 100),
        ],
      },
    );

    expect(find.text('Dave Turner'), findsNothing);

    await tester.tap(find.text('Ladder Safety Test'));
    await tester.pumpAndSettle();

    expect(find.text('Dave Turner'), findsOneWidget);
    expect(find.text('Priya Kaur'), findsOneWidget);

    // Every list has search (phone audit 2026-11-04).
    await tester.enterText(
      find.descendant(
        of: find.byKey(const ValueKey('training-people-search')),
        matching: find.byType(TextField),
      ),
      'priya',
    );
    await tester.pumpAndSettle();
    expect(find.text('Dave Turner'), findsNothing);
    expect(find.text('Priya Kaur'), findsOneWidget);
  });

  // ── 375px: pane is the whole body, Back returns to the list ─────

  // -- The whole history, owner's ruling of 2026-09-09 ("all") --

  testWidgets('the pane lists every attempt, newest first', (tester) async {
    await _pumpDashboard(
      tester,
      rows: [_row(id: 't-1', title: 'Ladder Safety Test', assigned: 1, passed: 1, overdue: 0, retaking: 0)],
      summary: _summary(assigned: 1, overdue: 0, retaking: 0),
      people: {
        't-1': [
          _person(
            id: 'u-priya',
            name: 'Priya Kaur',
            state: 'passed',
            score: 90,
            attempts: [
              _attempt(takenAt: '2026-09-08T10:00:00Z', score: 90, passed: true),
              _attempt(takenAt: '2026-09-01T10:00:00Z', score: 40, passed: false),
            ],
          ),
        ],
      },
    );

    await tester.tap(find.text('Ladder Safety Test'));
    await tester.pumpAndSettle();

    expect(find.text('Attempts'), findsOneWidget);
    expect(find.textContaining('90%  -  Passed'), findsOneWidget);
    expect(find.textContaining('40%  -  Failed'), findsOneWidget);
  });

  testWidgets('more than three attempts show three, and the affordance names the total', (tester) async {
    await _pumpDashboard(
      tester,
      rows: [_row(id: 't-1', title: 'Ladder Safety Test', assigned: 1, passed: 1, overdue: 0, retaking: 0)],
      summary: _summary(assigned: 1, overdue: 0, retaking: 0),
      people: {
        't-1': [
          _person(
            id: 'u-priya',
            name: 'Priya Kaur',
            state: 'passed',
            score: 90,
            attempts: [
              _attempt(takenAt: '2026-09-08T10:00:00Z', score: 90, passed: true),
              _attempt(takenAt: '2026-09-07T10:00:00Z', score: 70, passed: false),
              _attempt(takenAt: '2026-09-06T10:00:00Z', score: 60, passed: false),
              _attempt(takenAt: '2026-09-05T10:00:00Z', score: 50, passed: false),
              _attempt(takenAt: '2026-09-04T10:00:00Z', score: 40, passed: false),
            ],
          ),
        ],
      },
    );

    await tester.tap(find.text('Ladder Safety Test'));
    await tester.pumpAndSettle();

    // The three newest are drawn, the two oldest are not.
    expect(find.textContaining('90%'), findsWidgets);
    expect(find.textContaining('70%  -  Failed'), findsOneWidget);
    expect(find.textContaining('60%  -  Failed'), findsOneWidget);
    expect(find.textContaining('50%  -  Failed'), findsNothing);
    expect(find.textContaining('40%  -  Failed'), findsNothing);

    // The affordance names the total, never a bare "Show all".
    expect(find.text('Show all 5 attempts'), findsOneWidget);

    await tester.tap(find.byKey(const Key('training.person-toggle-attempts')));
    await tester.pumpAndSettle();

    expect(find.textContaining('50%  -  Failed'), findsOneWidget);
    expect(find.textContaining('40%  -  Failed'), findsOneWidget);
    expect(find.text('Show all 5 attempts'), findsNothing);

    // And back again. A one-way disclosure would let the reader recreate the
    // very row-swallows-the-pane problem the cap exists to prevent, with no
    // way out short of reloading.
    expect(find.text('Show fewer'), findsOneWidget);

    await tester.tap(find.byKey(const Key('training.person-toggle-attempts')));
    await tester.pumpAndSettle();

    expect(find.textContaining('50%  -  Failed'), findsNothing);
    expect(find.text('Show all 5 attempts'), findsOneWidget);
  });

  testWidgets('exactly three attempts hide nothing, so no affordance claims they do', (tester) async {
    await _pumpDashboard(
      tester,
      rows: [_row(id: 't-1', title: 'Ladder Safety Test', assigned: 1, passed: 1, overdue: 0, retaking: 0)],
      summary: _summary(assigned: 1, overdue: 0, retaking: 0),
      people: {
        't-1': [
          _person(
            id: 'u-priya',
            name: 'Priya Kaur',
            state: 'passed',
            score: 90,
            attempts: [
              _attempt(takenAt: '2026-09-08T10:00:00Z', score: 90, passed: true),
              _attempt(takenAt: '2026-09-07T10:00:00Z', score: 70, passed: false),
              _attempt(takenAt: '2026-09-06T10:00:00Z', score: 60, passed: false),
            ],
          ),
        ],
      },
    );

    await tester.tap(find.text('Ladder Safety Test'));
    await tester.pumpAndSettle();

    expect(find.textContaining('60%  -  Failed'), findsOneWidget);
    expect(find.byKey(const Key('training.person-toggle-attempts')), findsNothing);
  });

  testWidgets('an empty pane says what showed up, never that nobody is assigned', (tester) async {
    // This pane is bounded by reach over the PERSON, so it cannot know whether
    // anybody is assigned -- only whether anybody it may show is. The sentence
    // it replaces ("Nobody is assigned to, or has attempted, this yet.") was
    // FALSE to a franchise manager whose colleagues are assigned and simply
    // not theirs to see.
    await _pumpDashboard(
      tester,
      rows: [_row(id: 't-1', title: 'Ladder Safety Test', assigned: 0, passed: 0, overdue: 0, retaking: 0)],
      summary: _summary(assigned: 0, overdue: 0, retaking: 0),
      people: {'t-1': const []},
    );

    await tester.tap(find.text('Ladder Safety Test'));
    await tester.pumpAndSettle();

    expect(find.text('Nobody to show for this one.'), findsOneWidget);
    expect(find.textContaining('is assigned'), findsNothing);
  });

  testWidgets('one attempt draws no history block, because the score tag already is one', (tester) async {
    await _pumpDashboard(
      tester,
      rows: [_row(id: 't-1', title: 'Ladder Safety Test', assigned: 1, passed: 1, overdue: 0, retaking: 0)],
      summary: _summary(assigned: 1, overdue: 0, retaking: 0),
      people: {
        't-1': [
          _person(
            id: 'u-priya',
            name: 'Priya Kaur',
            state: 'passed',
            score: 90,
            attempts: [_attempt(takenAt: '2026-09-08T10:00:00Z', score: 90, passed: true)],
          ),
        ],
      },
    );

    await tester.tap(find.text('Ladder Safety Test'));
    await tester.pumpAndSettle();

    // The person is drawn, and the score is still shown as a tag.
    expect(find.text('Priya Kaur'), findsOneWidget);
    expect(find.text('90%'), findsOneWidget);
    expect(find.text('Attempts'), findsNothing);
  });

  testWidgets('a guide row carries no attempts and draws no history block', (tester) async {
    await _pumpDashboard(
      tester,
      rows: [_row(id: 'g-1', title: 'Ladder Safety Guide', assigned: 1, passed: 0, overdue: 0, retaking: 0)],
      summary: _summary(assigned: 1, overdue: 0, retaking: 0),
      people: {
        'g-1': [
          _person(id: 'u-dave', name: 'Dave Turner', state: 'in_progress', lastScreen: 1, screenCount: 4),
        ],
      },
    );

    await tester.tap(find.text('Ladder Safety Guide'));
    await tester.pumpAndSettle();

    expect(find.text('Dave Turner'), findsOneWidget);
    expect(find.text('Attempts'), findsNothing);
  });

  testWidgets('at 375px the pane is the whole body, and Back returns to the list', (tester) async {
    await _pumpDashboard(
      tester,
      rows: [_row(id: 't-1', title: 'Ladder Safety Test', assigned: 2, passed: 1, overdue: 1, retaking: 0)],
      summary: _summary(assigned: 2, overdue: 1, retaking: 0),
      people: {
        't-1': [
          _person(
            id: 'u-dave',
            name: 'Dave Turner',
            state: 'overdue',
            dueOn: '2020-01-01',
            lastScreen: 2,
            screenCount: 8,
          ),
        ],
      },
      size: const Size(375, 800),
    );

    await tester.tap(find.text('Ladder Safety Test'));
    await tester.pumpAndSettle();

    // The pane is the WHOLE body — the list is gone, and Back is offered.
    expect(find.text('Ladder Safety Test'), findsOneWidget);
    expect(find.text('Dave Turner'), findsOneWidget);
    expect(find.byTooltip('Back to the list'), findsOneWidget);
    expect(find.byType(LinearProgressIndicator), findsWidgets);

    await tester.tap(find.byTooltip('Back to the list'));
    await tester.pumpAndSettle();

    expect(find.text('Dave Turner'), findsNothing);
    expect(find.byTooltip('Back to the list'), findsNothing);
    expect(find.text('Ladder Safety Test'), findsOneWidget);
  });

  // ── The word this module must never say ──────────────────────────

  testWidgets('the rendered widget tree never says "certification", in any case', (tester) async {
    await _pumpDashboard(
      tester,
      rows: [_row(id: 't-1', title: 'Ladder Safety Test', assigned: 2, passed: 1, overdue: 1, retaking: 2)],
      summary: _summary(assigned: 2, overdue: 1, retaking: 2),
      people: {
        't-1': [
          _person(id: 'u-dave', name: 'Dave Turner', state: 'overdue', dueOn: '2020-01-01'),
          _person(id: 'u-retake', name: 'Retta King', state: 'retaking'),
        ],
      },
    );

    await tester.tap(find.text('Ladder Safety Test'));
    await tester.pumpAndSettle();

    for (final text in tester.widgetList<Text>(find.byType(Text))) {
      final data = (text.data ?? text.textSpan?.toPlainText() ?? '').toLowerCase();
      expect(
        data.contains('certification'),
        isFalse,
        reason: 'Found "certification" in a rendered Text: "${text.data}"',
      );
    }
  });

  // ── A control that is offered must work: the tab itself is gated ──

  testWidgets('a viewer without training.monitor never sees the Dashboard tab', (tester) async {
    await _pumpScreenTab(tester, permissions: const ['training.view']);

    expect(find.text('Learn'), findsOneWidget);
    expect(find.text('Dashboard'), findsNothing);
  });

  testWidgets('a monitor sees the real Dashboard, not the Coming Soon placeholder', (tester) async {
    await _pumpScreenTab(tester, permissions: const ['training.view', 'training.monitor']);

    await tester.tap(find.text('Dashboard'));
    await tester.pumpAndSettle();

    expect(find.textContaining('Coming'), findsNothing);
  });

  // ── "＋ Assign to more people" — the missing-control finding's fix ──

  testWidgets('the assign control is absent without training.assign', (tester) async {
    await _pumpDashboard(
      tester,
      rows: [_row(id: 't-1', title: 'Ladder Safety Test', assigned: 2, passed: 1, overdue: 1, retaking: 0)],
      summary: _summary(assigned: 2, overdue: 1, retaking: 0),
      permissions: const ['training.monitor'],
    );

    await tester.tap(find.text('Ladder Safety Test'));
    await tester.pumpAndSettle();

    expect(find.text('Assign to more people'), findsNothing);
  });

  testWidgets(
    'tapping Assign from the Dashboard tab, picking a person and submitting posts the real body',
    (tester) async {
      Map<String, dynamic>? posted;

      await _pumpDashboard(
        tester,
        rows: [_row(id: 'g-1', title: 'Ladder Safety', kind: 'guide', assigned: 2, passed: 1, overdue: 0, retaking: 0)],
        summary: _summary(assigned: 2, overdue: 0, retaking: 0),
        permissions: const ['training.monitor', 'training.assign'],
        assignableUsers: [
          {'id': 'u-newhire', 'name': 'Nadia Cole', 'email': 'nadia@example.test'},
        ],
        onAssignmentPosted: (body) => posted = body,
      );

      // Through the real route: the Dashboard tab's own row tap, not the
      // widget constructed directly (task's own requirement — the same
      // guarantee `training_builder_test.dart` already holds itself to).
      await tester.tap(find.text('Ladder Safety'));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Assign to more people'));
      await tester.pumpAndSettle();

      expect(find.text('Nadia Cole'), findsOneWidget);
      await tester.tap(find.text('Nadia Cole'));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Assign'));
      await tester.pumpAndSettle();

      expect(posted, isNotNull);
      expect(posted!['guide_id'], 'g-1');
      expect(posted!.containsKey('test_id'), isFalse);
      expect(posted!['assignee_type'], 'user');
      expect(posted!['assignee_id'], 'u-newhire');
      expect(posted!.containsKey('due_on'), isFalse, reason: 'Left blank, never sent as an explicit null.');

      // The pane closes back to the audience list on success.
      expect(find.text('Assign to more people'), findsOneWidget);
    },
  );

  testWidgets('picking a role posts test_id and assignee_type role', (tester) async {
    Map<String, dynamic>? posted;

    await _pumpDashboard(
      tester,
      rows: [_row(id: 't-9', title: 'CO Awareness', kind: 'test', assigned: 1, passed: 0, overdue: 0, retaking: 0)],
      summary: _summary(assigned: 1, overdue: 0, retaking: 0),
      permissions: const ['training.monitor', 'training.assign'],
      assignableRoles: [
        {'id': 'role-crew', 'name': 'Crew', 'assignable': true},
        {'id': 'role-manager', 'name': 'Manager', 'assignable': false},
      ],
      onAssignmentPosted: (body) => posted = body,
    );

    await tester.tap(find.text('CO Awareness'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Assign to more people'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('A role'));
    await tester.pumpAndSettle();

    // Shown and marked, not hidden — the disabled row still renders.
    expect(find.text('Manager'), findsOneWidget);
    expect(find.text('Not yours to give — needs organization-wide reach.'), findsOneWidget);

    await tester.tap(find.text('Crew'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Assign'));
    await tester.pumpAndSettle();

    expect(posted!['test_id'], 't-9');
    expect(posted!.containsKey('guide_id'), isFalse);
    expect(posted!['assignee_type'], 'role');
    expect(posted!['assignee_id'], 'role-crew');
  });

  testWidgets('a failed role fetch shows only the error, never the empty-roles text too', (tester) async {
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) {
          return _json({
            'data': {..._me, 'permissions': const ['training.monitor', 'training.assign']},
          });
        }
        if (path.endsWith('/training/dashboard')) {
          return _json({
            'data': [_row(id: 't-9', title: 'CO Awareness', kind: 'test', assigned: 1, passed: 0, overdue: 0, retaking: 0)],
            'summary': _summary(assigned: 1, overdue: 0, retaking: 0),
          });
        }
        if (path.endsWith('/training/assignees')) {
          final type = request.url.queryParameters['type'];
          if (type == 'role') {
            return _json({'message': 'Something went wrong loading roles.'}, 500);
          }

          return _json({'data': const []});
        }
        if (path.endsWith('/training/dashboard/t-9/people')) {
          return _json({'data': const []});
        }
        return _json({'message': 'Nothing stubbed for $path.'}, 500);
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(body: TrainingDashboard(api: api, session: session)),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.text('CO Awareness'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Assign to more people'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('A role'));
    await tester.pumpAndSettle();

    expect(find.text('Something went wrong loading roles.'), findsOneWidget);
    expect(find.text('This organization has no roles yet.'), findsNothing);
  });

  testWidgets('a truncated candidate list shows the refine-your-search hint', (tester) async {
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) {
          return _json({
            'data': {..._me, 'permissions': const ['training.monitor', 'training.assign']},
          });
        }
        if (path.endsWith('/training/dashboard')) {
          return _json({
            'data': [_row(id: 't-9', title: 'CO Awareness', kind: 'test', assigned: 1, passed: 0, overdue: 0, retaking: 0)],
            'summary': _summary(assigned: 1, overdue: 0, retaking: 0),
          });
        }
        if (path.endsWith('/training/assignees')) {
          final type = request.url.queryParameters['type'];
          if (type == 'role') return _json({'data': const []});

          // `AssignmentsController::userCandidates`'s own `truncated` flag.
          return _json({
            'data': [
              {'id': 'u-1', 'name': 'Somebody', 'email': 'somebody@example.test'},
            ],
            'truncated': true,
          });
        }
        if (path.endsWith('/training/dashboard/t-9/people')) {
          return _json({'data': const []});
        }
        return _json({'message': 'Nothing stubbed for $path.'}, 500);
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(body: TrainingDashboard(api: api, session: session)),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.text('CO Awareness'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Assign to more people'));
    await tester.pumpAndSettle();

    expect(find.text('More people match — refine your search to narrow the list.'), findsOneWidget);
  });

  testWidgets('a refusal from the server is shown verbatim, never a generic failure', (tester) async {
    await _pumpDashboard(
      tester,
      rows: [_row(id: 'g-2', title: 'Ladder Safety', assigned: 1, passed: 0, overdue: 0, retaking: 0)],
      summary: _summary(assigned: 1, overdue: 0, retaking: 0),
      permissions: const ['training.monitor', 'training.assign'],
      assignableUsers: [
        {'id': 'u-outside', 'name': 'Outside Person', 'email': 'outside@example.test'},
      ],
      onAssignmentPosted: (_) {},
    );

    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) {
          return _json({
            'data': {..._me, 'permissions': const ['training.monitor', 'training.assign']},
          });
        }
        if (path.endsWith('/training/dashboard')) {
          return _json({
            'data': [_row(id: 'g-2', title: 'Ladder Safety', assigned: 1, passed: 0, overdue: 0, retaking: 0)],
            'summary': _summary(assigned: 1, overdue: 0, retaking: 0),
          });
        }
        if (path.endsWith('/training/assignees')) {
          return _json({
            'data': [
              {'id': 'u-outside', 'name': 'Outside Person', 'email': 'outside@example.test'},
            ],
          });
        }
        if (path.endsWith('/training/dashboard/g-2/people')) {
          return _json({'data': const []});
        }
        if (path.endsWith('/training/assignments') && request.method == 'POST') {
          return _json({
            'message': 'Outside Person is outside where you may act — you may act on Riverside.',
          }, 403);
        }
        return _json({'message': 'Nothing stubbed for $path.'}, 500);
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(body: TrainingDashboard(api: api, session: session)),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.text('Ladder Safety'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Assign to more people'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Outside Person'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Assign'));
    await tester.pumpAndSettle();

    expect(
      find.text('Outside Person is outside where you may act — you may act on Riverside.'),
      findsOneWidget,
    );
  });

  // ── 375px: the assign form does not overflow ─────────────────────

  testWidgets('at 375px the assign form renders without overflow', (tester) async {
    await _pumpDashboard(
      tester,
      rows: [_row(id: 'g-3', title: 'Ladder Safety', assigned: 1, passed: 0, overdue: 0, retaking: 0)],
      summary: _summary(assigned: 1, overdue: 0, retaking: 0),
      permissions: const ['training.monitor', 'training.assign'],
      assignableUsers: [
        {'id': 'u-1', 'name': 'Nadia Cole', 'email': 'nadia@example.test'},
      ],
      size: const Size(375, 800),
    );

    await tester.tap(find.text('Ladder Safety'));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);

    await tester.tap(find.text('Assign to more people'));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);

    await tester.tap(find.text('A role'));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
  });
}

// ── Fixtures ──────────────────────────────────────────────────────────

Map<String, dynamic> _row({
  required String id,
  required String title,
  required int assigned,
  required int passed,
  int inProgress = 0,
  required int overdue,
  required int retaking,
  String kind = 'guide',
}) => {
  'id': id,
  'title': title,
  'kind': kind,
  'assigned': assigned,
  'passed': passed,
  'in_progress': inProgress,
  'overdue': overdue,
  'retaking': retaking,
};

Map<String, dynamic> _summary({required int assigned, required int overdue, required int retaking}) => {
  'people_assigned': assigned,
  'people_overdue': overdue,
  'people_due_for_retaking': retaking,
};

Map<String, dynamic> _person({
  required String id,
  required String name,
  required String state,
  String? dueOn,
  int? score,
  int? lastScreen,
  int? screenCount,
  List<Map<String, dynamic>>? attempts,
}) => {
    'user_id': id,
    'name': name,
    'state': state,
    'due_on': dueOn,
    'score': ?score,
    'last_screen': ?lastScreen,
    'screen_count': ?screenCount,
    'attempts': ?attempts,
  };

Map<String, dynamic> _attempt({required String takenAt, int? score, required bool passed}) => {
  'taken_at': takenAt,
  'score': score,
  'passed': passed,
};

Future<void> _pumpDashboard(
  WidgetTester tester, {
  List<Map<String, dynamic>> rows = const [],
  Map<String, dynamic>? summary,
  Map<String, List<Map<String, dynamic>>> people = const {},
  Size size = const Size(1280, 900),
  List<String> permissions = const ['training.monitor'],
  List<Map<String, dynamic>> assignableUsers = const [],
  List<Map<String, dynamic>> assignableRoles = const [],
  void Function(Map<String, dynamic> body)? onAssignmentPosted,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient(
      _dashboardHandler(
        rows: rows,
        summary: summary,
        people: people,
        permissions: permissions,
        assignableUsers: assignableUsers,
        assignableRoles: assignableRoles,
        onAssignmentPosted: onAssignmentPosted,
      ),
    ),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: TrainingDashboard(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _pumpScreenTab(WidgetTester tester, {required List<String> permissions}) async {
  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) {
        return _json({
          'data': {..._me, 'permissions': permissions},
        });
      }
      if (path.endsWith('/training/learn')) {
        return _json({'assigned': const [], 'library': const []});
      }
      if (path.endsWith('/training/dashboard')) {
        return _json({'data': const [], 'summary': _summary(assigned: 0, overdue: 0, retaking: 0)});
      }
      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: TrainingScreen(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

MockClientHandler _dashboardHandler({
  required List<Map<String, dynamic>> rows,
  Map<String, dynamic>? summary,
  required Map<String, List<Map<String, dynamic>>> people,
  List<String> permissions = const ['training.monitor'],
  List<Map<String, dynamic>> assignableUsers = const [],
  List<Map<String, dynamic>> assignableRoles = const [],
  void Function(Map<String, dynamic> body)? onAssignmentPosted,
}) {
  return (request) async {
    final path = request.url.path;

    if (path.endsWith('/me')) {
      return _json({
        'data': {..._me, 'permissions': permissions},
      });
    }

    if (path.endsWith('/training/dashboard')) {
      return _json({
        'data': rows,
        'summary': summary ?? _summary(assigned: 0, overdue: 0, retaking: 0),
      });
    }

    // `AssignmentsController::assignees` — the "＋ Assign to more people"
    // control's candidate source (see `dashboard_pane.dart`'s own
    // docblock). `?type=user` or `?type=role`.
    if (path.endsWith('/training/assignees')) {
      final type = request.url.queryParameters['type'];
      return _json({'data': type == 'role' ? assignableRoles : assignableUsers});
    }

    if (path.endsWith('/training/assignments') && request.method == 'POST') {
      onAssignmentPosted?.call(jsonDecode(request.body) as Map<String, dynamic>);
      return _json({
        'data': {
          'id': 'assignment-1',
          'guide_id': null,
          'test_id': null,
          'assignee_type': null,
          'assignee_id': null,
          'due_on': null,
          'assigned_by_user_id': null,
        },
      }, 201);
    }

    final peopleMatch = RegExp(r'/training/dashboard/([^/]+)/people').firstMatch(path);
    if (peopleMatch != null) {
      final id = peopleMatch.group(1);
      return _json({'data': people[id] ?? const []});
    }

    return _json({'message': 'Nothing stubbed for $path.'}, 500);
  };
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@example.com',
  'role': {'name': 'Manager'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Everdoors',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Everdoors',
    'path': ['Everdoors'],
  },
  'companies': [
    {'id': 'co-1', 'name': 'Everdoors', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
  ],
  'modules': [
    {
      'key': 'training',
      'label': 'Training',
      'available': true,
      'reachable': true,
    },
  ],
};
