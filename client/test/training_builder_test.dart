import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/training/guide_builder.dart';
import 'package:ezbizi_client/screens/training/test_builder.dart';
import 'package:ezbizi_client/screens/training_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Settings — layout 8 of `docs/previews/training-layouts.html` (Jose's
/// approved pick, by number): a storyboard, the module's one novel pattern.
/// [GuideBuilder] draws the strip and the screen editor; [TestBuilder] draws
/// the four options Jose named and the question editor, standalone or
/// embedded beside a guide's own strip.
void main() {
  // ── The four options, editable ──────────────────────────────────────

  testWidgets('the four test options are all editable in the builder', (tester) async {
    await _pumpTestBuilder(tester, test: _test(id: 't1', title: 'Ladder Safety Quiz'));

    expect(find.text('Must finish the guide before the test'), findsOneWidget);
    expect(find.text('Pass mark'), findsOneWidget);
    expect(find.text('Retakes allowed'), findsOneWidget);
    expect(find.text('A pass runs out after'), findsOneWidget);

    // Each is a real, live control — not just a label — bound to the
    // field it edits.
    expect(find.byKey(const Key('training.option-requires-guide')), findsOneWidget);
    expect(find.byKey(const Key('training.option-pass-mark')), findsOneWidget);
    expect(find.byKey(const Key('training.option-max-attempts')), findsOneWidget);
    expect(find.byKey(const Key('training.option-valid-for-days')), findsOneWidget);
  });

  testWidgets('editing all four options and saving sends exactly what was set', (tester) async {
    Map<String, dynamic>? sentBody;

    await _pumpTestBuilder(
      tester,
      test: _test(id: 't1', title: 'Ladder Safety Quiz', passMark: 80),
      onRequest: (request) {
        if (request.method == 'PATCH' && request.url.path.endsWith('/training/tests/t1')) {
          sentBody = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({'data': _test(id: 't1', title: 'Ladder Safety Quiz')});
        }
        return null;
      },
    );

    await tester.tap(find.byKey(const Key('training.option-requires-guide')));
    await tester.enterText(find.byKey(const Key('training.option-pass-mark')), '70');
    await tester.enterText(find.byKey(const Key('training.option-max-attempts')), '3');
    await tester.enterText(find.byKey(const Key('training.option-valid-for-days')), '365');
    await tester.tap(find.byKey(const Key('training.save-test')));
    await tester.pumpAndSettle();

    expect(sentBody, isNotNull);
    expect(sentBody!['requires_guide'], isTrue);
    expect(sentBody!['pass_mark'], 70);
    expect(sentBody!['max_attempts'], 3);
    expect(sentBody!['valid_for_days'], 365);
  });

  // ── No modals, anywhere ───────────────────────────────────────────────

  testWidgets('adding a screen adds a card to the strip and never opens a dialog', (tester) async {
    await _pumpGuideBuilder(tester, guide: _guide(id: 'g1', title: 'Ladder Safety'));

    expect(find.byKey(const ValueKey('training.screen-card-0')), findsNothing);

    await tester.tap(find.text('Add screen'));
    await tester.pumpAndSettle();

    expect(find.byType(Dialog), findsNothing);
    expect(find.byType(AlertDialog), findsNothing);
    expect(find.byKey(const Key('training.screen-editor')), findsOneWidget);
    expect(find.byKey(const ValueKey('training.screen-card-0')), findsOneWidget);
  });

  testWidgets('adding a question never opens a dialog either', (tester) async {
    await _pumpTestBuilder(tester, test: _test(id: 't1', title: 'Ladder Safety Quiz'));

    await tester.tap(find.byKey(const Key('training.add-question')));
    await tester.pumpAndSettle();

    expect(find.byType(Dialog), findsNothing);
    expect(find.byType(AlertDialog), findsNothing);
    expect(find.byKey(const ValueKey('training.question-0')), findsOneWidget);
  });

  // ── The server's own sentence, shown verbatim ─────────────────────────

  testWidgets(
    'the server refusal sentence is shown verbatim when a question has no right answer',
    (tester) async {
      await _pumpTestBuilder(
        tester,
        test: _test(
          id: 't1',
          title: 'Ladder Safety Quiz',
          questions: [
            {
              'prompt': 'How far out does the base go?',
              'choices': [
                {'text': 'Two feet', 'correct': false},
                {'text': 'Four feet', 'correct': false},
              ],
            },
          ],
        ),
        onRequest: (request) {
          if (request.method == 'PATCH' && request.url.path.endsWith('/training/tests/t1')) {
            return _json({
              'message': 'The given data was invalid.',
              'errors': {
                'questions': [
                  'Every question needs exactly one right answer. Question 1 has none.',
                ],
              },
            }, 422);
          }
          return null;
        },
      );

      await tester.tap(find.byKey(const Key('training.save-test')));
      await tester.pumpAndSettle();

      // The server's own sentence (`TestsController::assertExactlyOneCorrectPerQuestion`),
      // shown character for character — never a client-invented paraphrase.
      expect(
        find.text('Every question needs exactly one right answer. Question 1 has none.'),
        findsOneWidget,
      );
    },
  );

  testWidgets(
    'the server refusal sentence is shown verbatim when the guide gate has no target',
    (tester) async {
      await _pumpTestBuilder(
        tester,
        test: _test(id: 't1', title: 'Ladder Safety Quiz'),
        onRequest: (request) {
          if (request.method == 'PATCH' && request.url.path.endsWith('/training/tests/t1')) {
            return _json({
              'message': 'The given data was invalid.',
              'errors': {
                'requires_guide': [
                  'This test requires a guide, but none is attached. Pick a guide, '
                      'or turn off requiring one.',
                ],
              },
            }, 422);
          }
          return null;
        },
      );

      await tester.tap(find.byKey(const Key('training.option-requires-guide')));
      await tester.tap(find.byKey(const Key('training.save-test')));
      await tester.pumpAndSettle();

      // `TestsController::assertGuideGateHasATarget`'s exact sentence.
      expect(
        find.text(
          'This test requires a guide, but none is attached. Pick a guide, or turn off '
          'requiring one.',
        ),
        findsOneWidget,
      );
    },
  );

  // ── 375px: the strip scrolls inside itself; the page never scrolls sideways ──

  testWidgets(
    'at 375px the strip scrolls inside its own container while the page never scrolls sideways',
    (tester) async {
      await _pumpGuideBuilder(
        tester,
        guide: _guide(
          id: 'g1',
          title: 'Ladder Safety',
          screens: [
            _screen('Why this guide exists'),
            _screen('Checking a ladder', kind: 'image', url: 'https://imgur.com/a'),
            _screen('The four-to-one rule', kind: 'video', url: 'https://youtube.com/watch?v=1'),
            _screen('Tying off at the top'),
            _screen('Ground conditions', kind: 'image', url: 'https://imgur.com/b'),
          ],
        ),
        size: const Size(375, 900),
      );

      // No overflow anywhere on the page at 375px.
      expect(tester.takeException(), isNull);

      // Exactly one horizontally-scrolling thing on the whole screen: the
      // strip. The screen editor and "Its test" column stack vertically
      // instead (the brief's own "at 375px … the editors stack").
      final horizontal = find.byWidgetPredicate(
        (w) => w is ScrollView && w.scrollDirection == Axis.horizontal,
      );
      expect(horizontal, findsOneWidget);
      expect(tester.widget(horizontal), isA<ListView>());

      // And it is the strip specifically.
      final stripBox = tester.widget<SizedBox>(find.byKey(const Key('training.screen-strip')));
      expect(stripBox.child, isA<ListView>());
    },
  );

  // RED/GREEN, recorded manually in the task-15 report: swapping the
  // strip's `ListView(scrollDirection: Axis.horizontal)` for a bare `Row`
  // (guide_builder.dart, `_ScreenStrip.build`) turns the assertion above
  // red with a `RenderFlex overflowed` exception, because a `Row` never
  // scrolls and never clips its children — restoring the `ListView` turns
  // it green again. Not re-asserted here as a permanent test (there is
  // nothing else to assert against a temporary edit); see the report for
  // what was observed.

  // ── The permission gate ────────────────────────────────────────────────

  testWidgets('a viewer without training.manage does not reach the Settings tab', (tester) async {
    await _pumpScreen(tester, permissions: const ['training.view']);

    expect(find.text('Learn'), findsOneWidget);
    expect(find.text('Settings'), findsNothing);
  });

  testWidgets('a viewer with training.manage reaches Settings, and it is not a placeholder', (
    tester,
  ) async {
    await _pumpScreen(tester, permissions: const ['training.view', 'training.manage']);

    expect(find.text('Settings'), findsOneWidget);
    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();

    expect(find.text('TODO'), findsNothing);
    expect(find.textContaining('Coming next'), findsNothing);
    expect(find.text('Guides'), findsOneWidget);
    expect(find.text('Tests'), findsOneWidget);
  });

  // ── Through the real route, never constructed directly ────────────────
  //
  // Task 13's postmortem: a test that constructs the destination widget
  // straight inside its own borrowed `Scaffold` can pass while the app's
  // REAL route to that widget is broken, because the harness's own wrapper
  // supplies whatever ancestor (`Material`, in that case) the real route
  // forgot. These two reach both builders the way `TrainingSettingsTab`
  // itself does — a tap, then a real `Navigator.push` — so a future regression
  // in that wiring shows up here rather than only in the field.

  testWidgets('tapping a guide in Settings opens its real builder, through the real route', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      permissions: const ['training.view', 'training.manage'],
      guides: [_guide(id: 'g1', title: 'Ladder Safety', screens: [_screen('Intro')])],
    );

    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Ladder Safety'));
    await tester.pumpAndSettle();

    // Reached GuideBuilder for real — its screen editor is on screen, and
    // nothing about the trip there was a dialog.
    expect(find.byKey(const Key('training.screen-editor')), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);
  });

  testWidgets('tapping a test in Settings opens its real builder, through the real route', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      permissions: const ['training.view', 'training.manage'],
      tests: [_test(id: 't1', title: 'Carbon Monoxide Awareness')],
    );

    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Carbon Monoxide Awareness'));
    await tester.pumpAndSettle();

    // Reached TestBuilder for real — the four options are on screen.
    expect(find.text('Must finish the guide before the test'), findsOneWidget);
    expect(find.text('Pass mark'), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);
  });
}

// ── Fixtures ────────────────────────────────────────────────────────────

Map<String, dynamic> _screen(String title, {String kind = 'none', String? url}) => {
  'title': title,
  'body': 'Body for $title.',
  'media_kind': kind,
  'media_url': url,
};

/// `GuidesController::present`'s own shape.
Map<String, dynamic> _guide({
  required String id,
  required String title,
  String status = 'draft',
  List<Map<String, dynamic>> screens = const [],
  String? companyId,
  String? franchiseId,
}) => {
  'id': id,
  'title': title,
  'summary': null,
  'status': status,
  'company_id': companyId,
  'franchise_id': franchiseId,
  'screen_count': screens.length,
  'screens': screens,
};

/// `TestsController::present`'s own shape.
Map<String, dynamic> _test({
  required String id,
  required String title,
  String status = 'draft',
  String? guideId,
  bool requiresGuide = false,
  int passMark = 80,
  int? maxAttempts,
  int? validForDays,
  List<Map<String, dynamic>> questions = const [],
  String? companyId,
  String? franchiseId,
}) => {
  'id': id,
  'title': title,
  'status': status,
  'company_id': companyId,
  'franchise_id': franchiseId,
  'guide_id': guideId,
  'requires_guide': requiresGuide,
  'pass_mark': passMark,
  'max_attempts': maxAttempts,
  'valid_for_days': validForDays,
  'question_count': questions.length,
  'questions': questions,
};

Future<void> _pumpTestBuilder(
  WidgetTester tester, {
  required Map<String, dynamic> test,
  http.Response? Function(http.Request request)? onRequest,
}) async {
  // Tall enough that every option and the Save button sit within the test
  // surface without needing a scroll gesture first — the panel's body is a
  // `SingleChildScrollView` (test_builder.dart), and the default 800×600
  // test window clips well above the questions/options/Save on a test with
  // even one question.
  tester.view.physicalSize = const Size(900, 2200);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final override = onRequest?.call(request);
      if (override != null) return override;

      final path = request.url.path;
      if (path.endsWith('/training/tests/${test['id']}')) return _json({'data': test});
      if (path.endsWith('/training/guides')) {
        return _json({
          'data': const [],
          'meta': {'total': 0},
        });
      }
      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: TestBuilder(api: api, session: session, testId: test['id'] as String),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _pumpGuideBuilder(
  WidgetTester tester, {
  required Map<String, dynamic> guide,
  Size size = const Size(1280, 1400),
  http.Response? Function(http.Request request)? onRequest,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final override = onRequest?.call(request);
      if (override != null) return override;

      final path = request.url.path;
      if (path.endsWith('/training/guides/${guide['id']}')) return _json({'data': guide});
      if (path.endsWith('/training/tests')) {
        return _json({
          'data': const [],
          'meta': {'total': 0},
        });
      }
      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: GuideBuilder(api: api, session: session, guideId: guide['id'] as String),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _pumpScreen(
  WidgetTester tester, {
  required List<String> permissions,
  List<Map<String, dynamic>> guides = const [],
  List<Map<String, dynamic>> tests = const [],
}) async {
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
      if (path.endsWith('/training/guides') && request.method == 'GET') {
        return _json({
          'data': guides,
          'meta': {'total': guides.length},
        });
      }
      if (path.endsWith('/training/tests') && request.method == 'GET') {
        return _json({
          'data': tests,
          'meta': {'total': tests.length},
        });
      }
      for (final guide in guides) {
        if (path.endsWith('/training/guides/${guide['id']}')) return _json({'data': guide});
      }
      for (final test in tests) {
        if (path.endsWith('/training/tests/${test['id']}')) return _json({'data': test});
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
