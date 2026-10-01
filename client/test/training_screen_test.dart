import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/training_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Learn is layout 1 (docs/previews/training-layouts.html): two shelves,
/// assigned to you above library, and the assigned shelf can never be
/// filtered away.
void main() {
  testWidgets(
    'the assigned shelf is drawn above the library and cannot be filtered away',
    (tester) async {
      await _pumpScreen(
        tester,
        assigned: [_assign(_guide(id: 'g-1', title: 'Ladder Safety', screenCount: 8))],
        library: [_guide(id: 'g-2', title: 'Van Load-Out Checklist', screenCount: 6)],
      );

      final assigned = tester.getTopLeft(find.text('Assigned to you'));
      final library = tester.getTopLeft(find.text('Library'));

      expect(assigned.dy, lessThan(library.dy));
      expect(_inside('learn-assigned', find.text('Ladder Safety')), findsOneWidget);
    },
  );

  // ── §3: the scope tag is a label lookup, proven live in both directions ──

  testWidgets('a company view names its own company and never a sibling', (
    tester,
  ) async {
    // The §3 leak that shipped in Inventory, Fleet and Phone. /me carries a
    // sibling; standing in one company it must not be offered. Round 2 of
    // this test: the entry now carries a REAL company_id (co-a1, in view)
    // rather than null/null, so the lookup branch this test exists to guard
    // actually runs — a poison literal in `_scopeLabel` (replacing
    // `return company.name;`/`return franchise.name;` with a fixed string)
    // must turn this red, which a null-only fixture could never detect.
    await _pumpScreen(
      tester,
      assigned: [
        _assign(_guide(id: 'g-1', title: 'Ladder Safety', screenCount: 8, companyId: 'co-a1')),
      ],
      me: {
        'scope': {
          'type': 'company',
          'id': 'co-a1',
          'label': 'A-1 Chimney',
          'path': ['A-1 Chimney'],
        },
        'companies': [
          {'id': 'co-a1', 'name': 'A-1 Chimney', 'parent_company_id': null},
          {'id': 'co-ever', 'name': 'Everdoors', 'parent_company_id': null},
        ],
        'franchises': [
          {'id': 'fr-a1', 'name': 'Riverside', 'company_id': 'co-a1'},
        ],
      },
    );

    // The branch is proven LIVE: an in-view row renders its real name.
    expect(find.text('A-1 Chimney'), findsOneWidget);
    // The sibling /me can reach but this view does not cover must never leak.
    expect(find.text('Everdoors'), findsNothing);
  });

  testWidgets('the empty state is a sentence, not a blank', (tester) async {
    await _pumpScreen(tester);

    expect(find.text('Assigned to you'), findsOneWidget);
    expect(find.text('Library'), findsOneWidget);
    expect(find.textContaining('Nothing is assigned to you'), findsOneWidget);
    expect(find.textContaining('nothing in your library'), findsOneWidget);
    // The subtitle slot is a count ("3 to do · 1 overdue"), never a SECOND
    // sentence saying the shelf is empty — that would say the same thing
    // twice, once above the rule and once below it.
    expect(find.text('Nothing outstanding right now.'), findsNothing);
  });

  testWidgets('a failed load is the request failed, not an empty shelf', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      onRequest: (request) {
        if (request.url.path.endsWith('/training/learn')) {
          return _json({'message': 'the request failed'}, 500);
        }
        return null;
      },
    );

    expect(find.text('the request failed'), findsOneWidget);
    expect(find.text('There is nothing in your library yet.'), findsNothing);
    expect(find.text('Try again'), findsOneWidget);
  });

  // Settings (task 15) is no longer a placeholder either — its own tab, its
  // own permission gate (`training.manage`), and its own storyboard editors
  // are covered in `training_builder_test.dart`, not here. This is only the
  // shell-level smoke check: granted the permission, the chip opens the real
  // screen, never the retired `ComingSoonScreen` text.
  testWidgets('Settings is a real screen, not a placeholder', (tester) async {
    await _pumpScreen(tester, me: {'permissions': const ['training.view', 'training.manage']});

    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();
    expect(find.text('TODO'), findsNothing);
    expect(find.textContaining('Coming next'), findsNothing);
    expect(find.text('Guides'), findsOneWidget);
  });

  // Dashboard (task 14) is no longer a placeholder — its own tab, its own
  // permission gate (`training.monitor`), and its own list/pane behaviour
  // are covered in `training_dashboard_tab_test.dart`, not here. The
  // fixture below grants `training.manage` (so Settings still appears —
  // that gate is `training_builder_test.dart`'s own to prove) but not
  // `training.monitor`, so the Dashboard chip must never appear at all —
  // see that gate proven directly.
  testWidgets('a viewer without training.monitor sees Learn and Settings, never Dashboard', (
    tester,
  ) async {
    await _pumpScreen(tester, me: {'permissions': const ['training.view', 'training.manage']});

    expect(find.text('Learn'), findsOneWidget);
    expect(find.text('Settings'), findsOneWidget);
    expect(find.text('Dashboard'), findsNothing);
  });

  // ── 375px: the convention every other screen's tests already follow ──

  testWidgets('the shelf grid collapses to a single column at 375px', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      assigned: [
        _assign(_guide(id: 'g-1', title: 'Ladder Safety', screenCount: 8)),
        _assign(_test(id: 't-1', title: 'Carbon Monoxide Awareness')),
      ],
      size: const Size(375, 900),
    );

    final ladder = tester.getTopLeft(_inside('learn-library', find.text('Ladder Safety')));
    final carbon = tester.getTopLeft(
      _inside('learn-library', find.text('Carbon Monoxide Awareness')),
    );

    // Single column: the second card sits strictly below the first, never
    // beside it — and nothing overflowed getting there.
    expect(carbon.dy, greaterThan(ladder.dy));
    expect(tester.takeException(), isNull);
  });

  // ── Tests (`'type': 'test'`) — question_count, due-for-retaking, overdue ──

  testWidgets('a test entry carries its question count', (tester) async {
    await _pumpScreen(
      tester,
      library: [_test(id: 't-1', title: 'Reading a Flue Liner Report', questionCount: 10)],
    );

    expect(find.textContaining('10 questions'), findsOneWidget);
  });

  testWidgets('a due-for-retaking test carries the tag, with no due date of its own', (
    tester,
  ) async {
    // The synthetic shape LearnController::assigned adds for a lapsed pass —
    // no assignment_id, no due_on (this class's own docblock, "A lapsed
    // pass reappears here").
    await _pumpScreen(
      tester,
      assigned: [
        _lapsed(_test(id: 't-1', title: 'Customer Greeting & Uniform', questionCount: 10)),
      ],
    );

    expect(_inside('learn-assigned', find.text('Due for retaking')), findsOneWidget);
    expect(find.textContaining('Was due'), findsNothing);
  });

  testWidgets('an overdue assigned entry draws an error-tinted border and a "Was due" tag', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      assigned: [
        _assign(_guide(id: 'g-1', title: 'Ladder Safety', screenCount: 8), dueOn: _pastDate),
      ],
    );

    expect(_inside('learn-assigned', find.textContaining('Was due')), findsOneWidget);
    expect(find.textContaining('Due '), findsNothing);

    // The library card carries it too, in the error tint.
    final container = tester.widget<Container>(find.byKey(const Key('pair-g-1')));
    final decoration = container.decoration! as BoxDecoration;
    final border = decoration.border! as Border;

    expect(
      border.top.color,
      EzBiziTheme.light().colorScheme.error.withValues(alpha: 0.5),
      reason: 'An overdue card must draw with the error-tinted border, not the plain edge.',
    );
  });

  testWidgets('a not-yet-due assigned entry shows a plain "Due" tag, never "Was due"', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      assigned: [
        _assign(_guide(id: 'g-1', title: 'Ladder Safety', screenCount: 8), dueOn: _futureDate),
      ],
    );

    expect(_inside('learn-assigned', find.textContaining('Due ')), findsOneWidget);
    expect(find.textContaining('Was due'), findsNothing);
  });

  testWidgets('the assigned subtitle counts items and, separately, overdue items', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      assigned: [
        _assign(
          _guide(id: 'g-1', title: 'Ladder Safety', screenCount: 8),
          dueOn: _pastDate,
          assignmentId: 'ta-1',
        ),
        _assign(
          _test(id: 't-1', title: 'Carbon Monoxide Awareness'),
          dueOn: _futureDate,
          assignmentId: 'ta-2',
        ),
        // Due for retaking, no due_on — must count toward "to do" but never
        // toward "overdue" (there is no date to compare).
        _lapsed(_test(id: 't-2', title: 'Customer Greeting & Uniform')),
      ],
    );

    expect(find.text('3 to do · 1 overdue'), findsOneWidget);
  });

  // ── The big one: progress data the approved design shows ──────────

  testWidgets('a library guide in progress shows its reading position and a progress bar', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      library: [_guide(id: 'g-1', title: 'Ladder Safety', screenCount: 8, lastScreen: 2)],
    );

    expect(find.text('3 of 8'), findsOneWidget);
    expect(_inside('learn-library', find.byType(LinearProgressIndicator)), findsOneWidget);
  });

  testWidgets('a finished library guide says Completed and the date, not a fraction', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      library: [
        _guide(
          id: 'g-1',
          title: 'Ladder Safety',
          screenCount: 8,
          lastScreen: 7,
          completedAt: '2026-08-11T10:00:00Z',
        ),
      ],
    );

    expect(find.textContaining('Completed '), findsOneWidget);
    expect(find.text('8 of 8'), findsNothing);
  });

  testWidgets('an untouched library guide says Not started', (tester) async {
    await _pumpScreen(tester, library: [_guide(id: 'g-1', title: 'Ladder Safety', screenCount: 8)]);

    expect(_inside('guide-g-1', find.text('Not started')), findsOneWidget);
  });

  testWidgets('one learner never sees a different card carry no progress at all when it has some', (
    tester,
  ) async {
    // Two guides in the same shelf, only one touched — the batching this
    // proves is on the server (LearnController::guideProgress); this is the
    // client half, that the untouched card's own fields stay null rather
    // than picking up the touched one's.
    await _pumpScreen(
      tester,
      library: [
        _guide(id: 'g-touched', title: 'Touched Guide', screenCount: 4, lastScreen: 1),
        _guide(id: 'g-untouched', title: 'Untouched Guide', screenCount: 4),
      ],
    );

    expect(find.text('2 of 4'), findsOneWidget);
    expect(_inside('guide-g-untouched', find.text('Not started')), findsOneWidget);
    expect(_inside('guide-g-touched', find.text('Not started')), findsNothing);
  });

  testWidgets('a library test states its last outcome as Passed or Not passed', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      library: [
        _test(
          id: 't-pass',
          title: 'Customer Greeting & Uniform',
          lastPassed: true,
          lastTakenAt: '2025-08-01T00:00:00Z',
        ),
        _test(
          id: 't-fail',
          title: 'Carbon Monoxide Awareness',
          lastPassed: false,
          lastTakenAt: '2026-08-20T00:00:00Z',
        ),
      ],
    );

    expect(_inside('test-t-pass', find.text('Passed')), findsOneWidget);
    expect(_inside('test-t-fail', find.text('Not passed')), findsOneWidget);
  });

  testWidgets('a never-taken test says Not started, and draws no progress bar', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      library: [_test(id: 't-1', title: 'Reading a Flue Liner Report')],
    );

    expect(_inside('test-t-1', find.text('Not started')), findsOneWidget);
    // A test has no "screens seen" to draw a fraction of — the bar is
    // guide-only, never invented for a test (this class's own docblock).
    expect(find.byType(LinearProgressIndicator), findsNothing);
  });

  testWidgets(
    'a guide and the test that follows it share one card; a test with no guide says so',
    (tester) async {
      await _pumpScreen(
        tester,
        library: [
          _guide(id: 'g-1', title: 'Ladder Safety', screenCount: 8),
          _test(id: 't-1', title: 'Ladder Safety Check', guideId: 'g-1', questionCount: 5),
          _test(id: 't-2', title: 'Reading a Flue Liner Report', questionCount: 10),
        ],
      );

      // UI queue 41: the pair reads as two steps of one thing.
      expect(_inside('pair-g-1', find.text('Ladder Safety Check')), findsOneWidget);
      expect(_inside('pair-g-1', find.text('Guide · 8 topics')), findsOneWidget);
      expect(
        _inside('pair-g-1', find.text('Test · 5 questions · pass at 60%')),
        findsOneWidget,
      );
      expect(
        _inside('pair-t-2', find.text('No guide comes before this test')),
        findsOneWidget,
      );
      expect(
        find.text('Each guide with the test that follows it · 1 guide, 2 tests'),
        findsOneWidget,
      );
    },
  );
  // ── UI queue 41 (Jose's 1 and 4): continue first, then guide + test ──

  testWidgets('where you left off comes first: the next topic by name, how far, '
      'and its test', (tester) async {
    await _pumpScreen(
      tester,
      library: [
        {
          ..._guide(id: 'g-1', title: 'Technician Training Guide', screenCount: 4, lastScreen: 1),
          'screens': [
            {'chapter': 'Basics', 'title': 'Safety first'},
            {'chapter': 'Basics', 'title': 'Tools'},
            {
              'chapter': 'The smoke chamber',
              'title': 'Parging the smoke chamber',
              'blocks': [
                {'type': 'video', 'url': 'https://video.example/1'},
              ],
            },
            {'chapter': 'The smoke chamber', 'title': 'Cleaning up'},
          ],
        },
        _test(id: 't-1', title: 'Technician Training', guideId: 'g-1', questionCount: 60),
      ],
    );

    final card = find.byKey(const Key('learn-continue'));
    expect(
      find.descendant(of: card, matching: find.text('Next: Parging the smoke chamber · topic 3')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: card, matching: find.text('2 of 4 topics · chapter 2 of 2')),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: card,
        matching: find.text(
          'Its test, Technician Training · 60 questions · pass at 60% · open now',
        ),
      ),
      findsOneWidget,
    );
    // The guide's card counts its chapters and videos from the same topics.
    expect(
      _inside('pair-g-1', find.text('Guide · 2 chapters · 4 topics · 1 video')),
      findsOneWidget,
    );
  });

  testWidgets('Continue takes the guide read most recently, not the first '
      'in the list', (tester) async {
    await _pumpScreen(
      tester,
      library: [
        {
          ..._guide(id: 'g-old', title: 'Ladder Safety', screenCount: 4, lastScreen: 1),
          'last_read_at': '2026-09-01T10:00:00Z',
        },
        {
          ..._guide(id: 'g-new', title: 'Van Load-Out', screenCount: 4, lastScreen: 2),
          'last_read_at': '2026-09-30T10:00:00Z',
        },
      ],
    );

    expect(_inside('learn-continue', find.text('Van Load-Out')), findsOneWidget);
  });

  testWidgets('nothing half-read says so instead of an empty card', (tester) async {
    await _pumpScreen(tester, library: [_guide(id: 'g-1', title: 'Ladder Safety')]);

    expect(
      _inside('learn-continue', find.textContaining('Nothing half-read')),
      findsOneWidget,
    );
  });

  testWidgets('the status chips and the search narrow the library, never the '
      'assigned box', (tester) async {
    await _pumpScreen(
      tester,
      assigned: [_assign(_guide(id: 'g-a', title: 'Ladder Safety'))],
      library: [
        _guide(id: 'g-done', title: 'Dispatcher Guide', completedAt: '2026-08-12T10:00:00Z'),
        _test(id: 't-gas', title: 'Gas Appliance Safety', lastPassed: false, lastTakenAt: '2026-09-01T10:00:00Z'),
      ],
    );

    expect(find.byKey(const Key('pair-g-done')), findsOneWidget);
    expect(find.byKey(const Key('pair-t-gas')), findsOneWidget);

    await tester.tap(find.byKey(const Key('learn-chip-Done')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('pair-g-done')), findsOneWidget);
    expect(find.byKey(const Key('pair-t-gas')), findsNothing);
    expect(find.byKey(const Key('pair-g-a')), findsNothing);
    // Assigned to you is never filtered away.
    expect(_inside('learn-assigned', find.text('Ladder Safety')), findsOneWidget);

    await tester.tap(find.text('All'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('learn-search')), 'gas');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('pair-t-gas')), findsOneWidget);
    expect(find.byKey(const Key('pair-g-done')), findsNothing);
  });

  testWidgets('an assigned row says what to do: Start, Continue or Retake', (tester) async {
    await _pumpScreen(
      tester,
      assigned: [
        _assign(_guide(id: 'g-1', title: 'Ladder Safety')),
        _assign(_guide(id: 'g-2', title: 'Van Load-Out', lastScreen: 0), assignmentId: 'ta-2'),
        _lapsed(_test(id: 't-1', title: 'Carbon Monoxide Awareness')),
      ],
    );

    for (final verb in ['Start', 'Continue', 'Retake']) {
      expect(
        _inside('learn-assigned', find.widgetWithText(OutlinedButton, verb)),
        findsOneWidget,
      );
    }
  });

}

/// A finder held to one keyed region: the assigned box, the library, a card.
Finder _inside(String key, Finder finder) =>
    find.descendant(of: find.byKey(Key(key)), matching: finder);

// ── Fixtures ──────────────────────────────────────────────────────────

/// Relative to "now" rather than a fixed calendar date, so an overdue/
/// not-yet-due assertion stays true for as long as this suite runs — a
/// hardcoded date is a moving target the moment overdue is asserted against
/// it (`DateTime.now()`, inside `_EntryCard.build`).
final String _pastDate = _isoDate(DateTime.now().subtract(const Duration(days: 15)));
final String _futureDate = _isoDate(DateTime.now().add(const Duration(days: 15)));

String _isoDate(DateTime d) =>
    '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

/// The shape `LearnController::presentGuide` produces.
Map<String, dynamic> _guide({
  required String id,
  required String title,
  int screenCount = 4,
  String? companyId,
  String? franchiseId,
  int? lastScreen,
  String? completedAt,
}) => {
  'type': 'guide',
  'id': id,
  'title': title,
  'summary': 'Summary.',
  'company_id': companyId,
  'franchise_id': franchiseId,
  'screen_count': screenCount,
  'screens': const [],
  'last_screen': lastScreen,
  'completed_at': completedAt,
};

/// The shape `LearnController::presentTest` produces.
Map<String, dynamic> _test({
  required String id,
  required String title,
  int questionCount = 10,
  String? guideId,
  String? companyId,
  String? franchiseId,
  bool dueForRetaking = false,
  int? lastScore,
  bool? lastPassed,
  String? lastTakenAt,
}) => {
  'type': 'test',
  'id': id,
  'title': title,
  'company_id': companyId,
  'franchise_id': franchiseId,
  'guide_id': guideId,
  'requires_guide': false,
  'pass_mark': 60,
  'max_attempts': null,
  'valid_for_days': null,
  'due_for_retaking': dueForRetaking,
  'question_count': questionCount,
  'questions': const [],
  'last_score': lastScore,
  'last_passed': lastPassed,
  'last_taken_at': lastTakenAt,
};

/// A real assignment row wrapping a guide/test entry — `assigned`'s own
/// extra fields (`LearnController::presentAssignment`).
Map<String, dynamic> _assign(
  Map<String, dynamic> entry, {
  String assignmentId = 'ta-1',
  String? dueOn,
}) => {'assignment_id': assignmentId, 'due_on': dueOn, 'assignee_type': 'user', ...entry};

/// The synthetic "lapsed pass reappears" row `LearnController::assigned`
/// adds — no `assignment_id`, no `due_on` (LearnController's own docblock),
/// and `due_for_retaking` forced true regardless of what `testEntry` set:
/// that field is the entire reason this shape exists.
Map<String, dynamic> _lapsed(Map<String, dynamic> testEntry) => {
  'assignment_id': null,
  'due_on': null,
  'assignee_type': null,
  ...testEntry,
  'due_for_retaking': true,
};

Future<void> _pumpScreen(
  WidgetTester tester, {
  List<Map<String, dynamic>> assigned = const [],
  List<Map<String, dynamic>> library = const [],
  Map<String, dynamic>? me,
  Size size = const Size(1280, 900),
  http.Response? Function(http.Request request)? onRequest,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient(
      _handler(assigned: assigned, library: library, me: me, onRequest: onRequest),
    ),
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

MockClientHandler _handler({
  List<Map<String, dynamic>> assigned = const [],
  List<Map<String, dynamic>> library = const [],
  Map<String, dynamic>? me,
  http.Response? Function(http.Request request)? onRequest,
}) {
  return (request) async {
    final override = onRequest?.call(request);
    if (override != null) return override;

    final path = request.url.path;

    if (path.endsWith('/me')) {
      return _json({
        'data': {..._me, 'permissions': const ['training.view'], ...?me},
      });
    }

    if (path.endsWith('/training/learn')) {
      return _json({'assigned': assigned, 'library': library});
    }

    // Settings (task 15) fetches its own guide/test catalogue whenever the
    // tab is reached — stubbed empty here so tests in this file that merely
    // grant `training.manage` and open the tab (never assert its contents;
    // see `training_builder_test.dart` for that) do not fall through to the
    // 500 below.
    if (path.endsWith('/training/guides') || path.endsWith('/training/tests')) {
      return _json({
        'data': const [],
        'meta': {'total': 0},
      });
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
