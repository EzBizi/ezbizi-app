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
/// `{data: [...], summary: {...}}`. Each row: `{id, title, assigned, passed,
/// in_progress, overdue, retaking}`. `summary`:
/// `{people_assigned, people_overdue, people_due_for_retaking}` — counts of
/// DISTINCT PEOPLE, never a sum of the rows (the controller's own docblock,
/// "The summary counts PEOPLE, never assignments").
///
/// `GET /training/dashboard/{id}/people` answers `{data: [...]}`, one row
/// per person: `{user_id, name, state, due_on, ...detail}`.
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
  });

  // ── 375px: pane is the whole body, Back returns to the list ─────

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
}) => {
  'id': id,
  'title': title,
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
}) => {
    'user_id': id,
    'name': name,
    'state': state,
    'due_on': dueOn,
    'score': ?score,
    'last_screen': ?lastScreen,
    'screen_count': ?screenCount,
  };

Future<void> _pumpDashboard(
  WidgetTester tester, {
  List<Map<String, dynamic>> rows = const [],
  Map<String, dynamic>? summary,
  Map<String, List<Map<String, dynamic>>> people = const {},
  Size size = const Size(1280, 900),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(_dashboardHandler(rows: rows, summary: summary, people: people)));
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
}) {
  return (request) async {
    final path = request.url.path;

    if (path.endsWith('/me')) {
      return _json({
        'data': {..._me, 'permissions': const ['training.monitor']},
      });
    }

    if (path.endsWith('/training/dashboard')) {
      return _json({
        'data': rows,
        'summary': summary ?? _summary(assigned: 0, overdue: 0, retaking: 0),
      });
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
