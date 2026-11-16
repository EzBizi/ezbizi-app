import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/training/guide_player.dart';
import 'package:ezbizi_client/screens/training/test_player.dart';
import 'package:ezbizi_client/widgets/list_table.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The guide reader — laid out and locked as A1 Tools' study guide was: a
/// progress card and a chapter › section › topic outline beside the topic,
/// topics unlocking one at a time as each is completed.
void main() {
  // ── The outline ──────────────────────────────────────────────────────

  testWidgets('wide: the progress card counts completed topics and the outline groups them', (tester) async {
    await _pumpGuide(tester, guide: _guide(lastScreen: 2));

    expect(find.byKey(const Key('training.screen-rail')), findsOneWidget);
    expect(find.text('3 / 8 topics completed'), findsOneWidget);
    expect(find.text('38%'), findsOneWidget);
    // Chapter and section from the topics themselves.
    final rail = find.byKey(const Key('training.screen-rail'));
    expect(find.descendant(of: rail, matching: find.text('Before you climb')), findsOneWidget);
    expect(find.descendant(of: rail, matching: find.text('On the roof')), findsOneWidget);
    expect(find.descendant(of: rail, matching: find.text('The ladder')), findsOneWidget);
  });

  testWidgets('topics after the next one are locked, and a locked one cannot be opened', (tester) async {
    await _pumpGuide(tester, guide: _guide(lastScreen: 2));

    // Resumes at the first topic not yet completed.
    expect(find.text('Tying off at the top'), findsWidgets);

    ListTile row(int i) => tester.widget<ListTile>(find.byKey(ValueKey('training.topic-row-$i')));
    expect(row(2).enabled, isTrue, reason: 'Completed.');
    expect(row(3).enabled, isTrue, reason: 'The next one is open.');
    expect(row(4).enabled, isFalse, reason: 'Two ahead is locked.');
  });

  testWidgets('Complete & Continue records the topic, then opens the next', (tester) async {
    await _pumpGuide(tester, guide: _guide());

    expect(find.text('0 / 8 topics completed'), findsOneWidget);
    await tester.tap(find.byKey(const Key('training.complete-topic')));
    await tester.pumpAndSettle();

    expect(find.text('1 / 8 topics completed'), findsOneWidget);
    expect(find.byKey(const ValueKey('training.topic-1')), findsOneWidget, reason: 'Moved on to the next topic.');
  });

  testWidgets('a completed topic offers Next Topic, and the last one says All done', (tester) async {
    await _pumpGuide(tester, guide: _guide(lastScreen: 7, completedAt: '2026-08-01T00:00:00Z'));

    // Finished: resumes on the last topic.
    expect(find.byKey(const Key('training.all-done')), findsOneWidget);
    expect(find.byKey(const Key('training.complete-topic')), findsNothing);

    await tester.tap(find.byKey(const Key('training.nav-back')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('training.nav-next')), findsOneWidget);
  });

  testWidgets('a topic draws its breadcrumb and every block in order', (tester) async {
    await _pumpGuide(tester, guide: _guide());

    expect(find.text('Before you climb › The ladder'), findsOneWidget);
    expect(find.text('Body for Why this guide exists.'), findsOneWidget);
    expect(find.text('Second paragraph.'), findsOneWidget);
    expect(find.byType(ListTable), findsOneWidget);
    expect(find.byKey(const Key('training.play-video')), findsOneWidget);
  });

  testWidgets('below 600px the outline and the topic are shown one at a time', (tester) async {
    await _pumpGuide(tester, guide: _guide(lastScreen: 2), size: const Size(375, 800));

    expect(find.byKey(const Key('training.screen-rail')), findsNothing);
    expect(find.text('3 / 8 topics completed'), findsNothing);

    await tester.tap(find.byKey(const Key('training.toggle-outline')));
    await tester.pumpAndSettle();
    expect(find.text('3 / 8 topics completed'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('training.topic-row-2')));
    await tester.pumpAndSettle();
    expect(find.text('3 / 8 topics completed'), findsNothing);
    expect(find.text('The four-to-one rule'), findsOneWidget);
  });

  // ── The guide's test ────────────────────────────────────────────────

  testWidgets('a test that requires the guide stays locked until every topic is complete', (tester) async {
    await _pumpGuide(tester, guide: _guide(), test: _testEntry(guideId: 'g1', requiresGuide: true));

    expect(find.text('Unlocks when every topic is complete'), findsOneWidget);
    expect(tester.widget<ListTile>(find.byKey(const Key('training.start-test'))).onTap, isNull);
  });

  testWidgets('a test that does not require the guide is open from the start, as in A1 Tools', (tester) async {
    await _pumpGuide(tester, guide: _guide(), test: _testEntry(guideId: 'g1'));

    expect(find.text('Ready — tap to begin'), findsOneWidget);
    expect(tester.widget<ListTile>(find.byKey(const Key('training.start-test'))).onTap, isNotNull);
  });

  // ── Submitting an attempt ────────────────────────────────────────────

  testWidgets('submitting an attempt shows the returned score', (
    tester,
  ) async {
    await _pumpTest(tester, test: _testEntry(id: 't1'));

    await tester.tap(find.text('One foot out for every four feet'));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('training.submit-test')));
    await tester.pumpAndSettle();

    expect(find.text('Score: 100%'), findsOneWidget);
    expect(find.text('Passed'), findsOneWidget);
  });

  // ── Through the real interaction, not the test harness's own shortcut ──
  //
  // `_pumpTest` (below) builds `TestPlayer` directly inside `Scaffold(body:
  // ...)`, which is NOT how the app reaches it from here — `GuidePlayer`
  // swaps it in for the reader via `setState` (`_openTest`/`_testOpen`), the
  // same in-body pattern `LearnShelves._selected` uses; no route is pushed,
  // so there is nothing left to wrap in a `Scaffold` of its own. This test
  // still taps the real "start test" control on the real reader — not a
  // direct `TestPlayer` construction — so a future regression that stops
  // wiring `TestPlayer` up to that tap shows up here, the same guarantee
  // this test held when the wiring was a pushed route.
  testWidgets(
    'opening the test from the guide reader, through the real interaction, renders '
    'the choices and Submit',
    (tester) async {
      await _pumpGuide(
        tester,
        guide: _guide(lastScreen: 7, completedAt: '2026-08-01T00:00:00Z'),
        test: _testEntry(guideId: 'g1', requiresGuide: true),
      );

      final navigator = tester.state<NavigatorState>(find.byType(Navigator));
      expect(navigator.canPop(), isFalse);

      await tester.tap(find.byKey(const Key('training.start-test')));
      await tester.pumpAndSettle();

      expect(find.text('One foot out for every four feet'), findsOneWidget);
      expect(find.text('Four rungs per minute'), findsOneWidget);
      expect(find.byKey(const Key('training.submit-test')), findsOneWidget);

      // Bug 2's third instance (`GuidePlayer._openTest`): reaching the test
      // never pushes a route — still nothing to pop, still the harness's
      // one Scaffold, same as `LearnShelves`' own swap.
      expect(navigator.canPop(), isFalse);
      expect(find.byType(Scaffold), findsOneWidget);
    },
  );

  // ── The retake cap: an exact count, not an under-count ───────────────

  testWidgets(
    'attempts_toward_cap from an earlier sitting caps Submit exactly, where '
    'last_taken_at alone would only under-count it',
    (tester) async {
      // last_taken_at only proves ONE prior attempt; attempts_toward_cap
      // says three, which is the real number
      // LearnController::attemptsTowardCap now sends. A client still gating
      // on last_taken_at alone would compute 1 >= 3 == false and offer an
      // enabled Submit the server would 403.
      await _pumpTest(
        tester,
        test: _testEntry(
          maxAttempts: 3,
          lastTakenAt: '2026-01-01T00:00:00Z',
          attemptsTaken: 3,
          attemptsTowardCap: 3,
        ),
      );

      final button = tester.widget<ElevatedButton>(
        find.byKey(const Key('training.submit-test')),
      );
      expect(
        button.onPressed,
        isNull,
        reason: 'attempts_toward_cap (3) already meets max_attempts (3) — '
            'Submit must not be offered.',
      );
      expect(find.textContaining('This test allows at most 3'), findsOneWidget);
    },
  );

  // ── The fifth option: a free retake never gates Submit, even after a
  //    raw attempts_taken that alone would already meet the cap ─────────

  testWidgets(
    'a passer whose retake is free is never capped by attempts_taken alone',
    (tester) async {
      // The exact regression this option exists to fix, one layer up in the
      // client: with max_attempts 1 and one prior PASS, attempts_taken is
      // already 1 (it genuinely happened) — but attempts_toward_cap is 0,
      // because count_attempts_after_pass is off and that pass still
      // shelters this retake. Gating on attempts_taken here (the bug this
      // test guards against) would grey out Submit for exactly the person
      // the fifth option exists to free.
      await _pumpTest(
        tester,
        test: _testEntry(
          maxAttempts: 1,
          lastTakenAt: '2026-01-01T00:00:00Z',
          attemptsTaken: 1,
          attemptsTowardCap: 0,
        ),
      );

      final button = tester.widget<ElevatedButton>(
        find.byKey(const Key('training.submit-test')),
      );
      expect(
        button.onPressed,
        isNotNull,
        reason: 'attempts_toward_cap (0) is well under max_attempts (1) — '
            'Submit must still be offered.',
      );
    },
  );

  // ── The retake cap: latch the disable on a real refusal ──────────────

  testWidgets(
    'a 403 refusal on submit latches Submit disabled rather than leaving it '
    'to be hammered',
    (tester) async {
      await _pumpTest(
        tester,
        test: _testEntry(id: 't1'),
        attemptStatus: 403,
        attemptBody: {
          'message':
              'This test allows at most 1 attempts, and that many have '
              'already been made.',
        },
      );

      await tester.tap(find.text('One foot out for every four feet'));
      await tester.pumpAndSettle();

      var button = tester.widget<ElevatedButton>(
        find.byKey(const Key('training.submit-test')),
      );
      expect(button.onPressed, isNotNull, reason: 'Not refused yet.');

      await tester.tap(find.byKey(const Key('training.submit-test')));
      await tester.pumpAndSettle();

      expect(
        find.text(
          'This test allows at most 1 attempts, and that many have already '
          'been made.',
        ),
        findsOneWidget,
      );

      button = tester.widget<ElevatedButton>(
        find.byKey(const Key('training.submit-test')),
      );
      expect(
        button.onPressed,
        isNull,
        reason: 'The server just refused this submission with a 403; the '
            'client must not keep offering a Submit that would 403 again.',
      );
    },
  );

  // ── No modals anywhere in this module — pin it (task-13 review) ──────
  //
  // The pattern `theme_conventions_test.dart:9-14` describes: a rule
  // nothing checks is a preference, and this module genuinely draws none —
  // through the guide reader, the real route into the test, answering a
  // question, and the graded result panel.
  testWidgets(
    'the guide player and the test player never draw a Dialog or AlertDialog',
    (tester) async {
      await _pumpGuide(
        tester,
        guide: _guide(lastScreen: 7, completedAt: '2026-08-01T00:00:00Z'),
        test: _testEntry(guideId: 'g1', requiresGuide: true),
      );
      expect(find.byType(Dialog), findsNothing);
      expect(find.byType(AlertDialog), findsNothing);

      await tester.tap(find.byKey(const Key('training.start-test')));
      await tester.pumpAndSettle();
      expect(find.byType(Dialog), findsNothing);
      expect(find.byType(AlertDialog), findsNothing);

      await tester.tap(find.text('One foot out for every four feet'));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('training.submit-test')));
      await tester.pumpAndSettle();
      expect(find.byType(Dialog), findsNothing);
      expect(find.byType(AlertDialog), findsNothing);
    },
  );

  // ── A screen's media_url must be http/https before it reaches an iframe
  //    or launchUrl (task-13 review) ────────────────────────────────────
  //
  // Pure and exported for the same reason `snippetApiBase` (seo_tab.dart)
  // is — worth asserting directly rather than only through a widget that
  // happens to call it. `_ScreenMedia` gates the video iframe on this
  // (guide_player.dart), and `_openExternal` refuses to `launchUrl` a URL
  // this returns false for.
  group('isHttpUrl refuses anything that is not http/https', () {
    test('an ordinary https link is allowed', () {
      expect(isHttpUrl('https://www.youtube.com/watch?v=abc'), isTrue);
    });

    test('an ordinary http link is allowed', () {
      expect(isHttpUrl('http://example.com/video'), isTrue);
    });

    test('a javascript: URL is refused', () {
      // The actual attack this closes: `allow-scripts` and
      // `allow-same-origin` are granted together on the video iframe
      // (video_embed_web.dart), so a javascript: URL handed to it verbatim
      // would run in THIS app's own origin, with access to `parent` and the
      // session token.
      expect(isHttpUrl('javascript:alert(document.cookie)'), isFalse);
    });

    test('a data: URL is refused', () {
      expect(isHttpUrl('data:text/html,<script>alert(1)</script>'), isFalse);
    });

    test('an unparseable string is refused', () {
      expect(isHttpUrl('not a url at all'), isFalse);
    });
  });
}

// ── Fixtures ──────────────────────────────────────────────────────────

/// Eight screens — the same guide layout 4's own mockup draws, so
/// "Unlocks when all 8 are seen" and "The four-to-one rule" (screen index 2)
/// match what the fixture actually holds.
const _screenTitles = [
  'Why this guide exists',
  'Checking a ladder before you climb',
  'The four-to-one rule',
  'Tying off at the top',
  'Ground conditions and footing',
  'Carrying tools up',
  'Getting on and off the roof',
  'What to do after a near miss',
];

/// The shape `LearnController::presentGuide` produces.
Map<String, dynamic> _guide({int? lastScreen, String? completedAt}) => {
  'type': 'guide',
  'id': 'g1',
  'title': 'Ladder Safety',
  'summary': 'Summary.',
  'company_id': null,
  'franchise_id': null,
  'screen_count': _screenTitles.length,
  'screens': [
    for (final (i, title) in _screenTitles.indexed)
      {
        'title': title,
        'chapter': i < 5 ? 'Before you climb' : 'On the roof',
        'section': i < 2 ? 'The ladder' : null,
        'blocks': [
          {'type': 'text', 'text': 'Body for $title.'},
          if (i == 0) ...[
            {'type': 'text', 'text': 'Second paragraph.'},
            {
              'type': 'table',
              'rows': [
                ['Level', 'When'],
                ['1', 'Annually'],
              ],
            },
            {'type': 'video', 'url': 'https://vimeo.com/123456', 'embed_url': 'https://api.test/api/v1/training/video/vimeo/123456'},
          ],
        ],
      },
  ],
  'last_screen': lastScreen,
  'completed_at': completedAt,
};

/// The shape `LearnController::presentTest` produces — the stripped shape
/// (`LearnerView::stripAnswerKey`), so a choice never carries `correct`.
Map<String, dynamic> _testEntry({
  String id = 't1',
  String? guideId,
  bool requiresGuide = false,
  int? maxAttempts,
  String? lastTakenAt,
  int attemptsTaken = 0,
  // Defaults to attemptsTaken — most callers do not care about the
  // fifth-option distinction, and only ever set count_attempts_after_pass
  // off (its own default), where "nothing has been excluded yet" means the
  // two numbers agree. A caller exercising the fifth option passes both
  // explicitly.
  int? attemptsTowardCap,
  bool countAttemptsAfterPass = false,
}) => {
  'type': 'test',
  'id': id,
  'title': 'Ladder Safety Quiz',
  'company_id': null,
  'franchise_id': null,
  'guide_id': guideId,
  'requires_guide': requiresGuide,
  'pass_mark': 60,
  'max_attempts': maxAttempts,
  'attempts_taken': attemptsTaken,
  'attempts_toward_cap': attemptsTowardCap ?? attemptsTaken,
  'valid_for_days': 365,
  'count_attempts_after_pass': countAttemptsAfterPass,
  'due_for_retaking': false,
  'question_count': 1,
  'questions': [
    {
      'prompt': 'What is the four-to-one rule?',
      'choices': [
        {'text': 'One foot out for every four feet'},
        {'text': 'Four rungs per minute'},
      ],
    },
  ],
  'last_score': null,
  'last_passed': null,
  'last_taken_at': lastTakenAt,
};

Future<void> _pumpGuide(
  WidgetTester tester, {
  required Map<String, dynamic> guide,
  Map<String, dynamic>? test,
  Size size = const Size(1280, 900),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient(_handler(guides: [guide], tests: test == null ? [] : [test])),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    _wrap(
      GuidePlayer(
        api: api,
        session: session,
        guideId: guide['id'] as String,
        onLeave: () {},
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _pumpTest(
  WidgetTester tester, {
  required Map<String, dynamic> test,
  Size size = const Size(1280, 900),
  int attemptStatus = 200,
  Map<String, dynamic>? attemptBody,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient(
      _handler(
        guides: const [],
        tests: [test],
        attemptStatus: attemptStatus,
        attemptBody: attemptBody,
      ),
    ),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    _wrap(
      TestPlayer(
        api: api,
        session: session,
        testId: test['id'] as String,
        onLeave: () {},
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Widget _wrap(Widget child) =>
    MaterialApp(theme: EzBiziTheme.light(), home: Scaffold(body: child));

MockClientHandler _handler({
  required List<Map<String, dynamic>> guides,
  required List<Map<String, dynamic>> tests,
  int attemptStatus = 200,
  Map<String, dynamic>? attemptBody,
}) {
  // Mutable so `seen` can mirror ProgressController's own max() across
  // repeated calls in one test, the same way the real server would.
  final guideById = {for (final g in guides) g['id'] as String: Map<String, dynamic>.from(g)};

  return (request) async {
    final path = request.url.path;
    final method = request.method;

    if (path.endsWith('/me')) {
      return _json({
        'data': {..._me, 'permissions': const ['training.view']},
      });
    }

    if (method == 'GET' && path.endsWith('/training/learn')) {
      return _json({
        'assigned': const [],
        'library': [...guideById.values, ...tests],
      });
    }

    final seenMatch = RegExp(r'/training/learn/guides/([^/]+)/seen$').firstMatch(path);
    if (method == 'POST' && seenMatch != null) {
      final guide = guideById[seenMatch.group(1)];
      if (guide == null) return _json({'message': 'not found'}, 404);

      final body = jsonDecode(request.body) as Map<String, dynamic>;
      final requested = (body['screen'] as num).toInt();
      final screenCount = (guide['screen_count'] as num).toInt();

      final currentLast = guide['last_screen'] as int? ?? -1;

      // ProgressController's lock: nothing past the next topic.
      if (requested > currentLast + 1) {
        return _json({'message': 'This topic is locked. Complete the topics before it first.'}, 422);
      }

      final newLast = requested > currentLast ? requested : currentLast;
      guide['last_screen'] = newLast;

      // The server never un-sets a completion (ProgressController::seen's
      // own docblock: `completed_at`, once set, is never cleared).
      if (guide['completed_at'] == null && newLast >= screenCount - 1) {
        guide['completed_at'] = '2026-08-01T00:00:00Z';
      }

      return _json({
        'last_screen': guide['last_screen'],
        'completed_at': guide['completed_at'],
        'screen_count': screenCount,
      });
    }

    final testMatch = RegExp(r'/training/learn/tests/([^/]+)$').firstMatch(path);
    if (method == 'GET' && testMatch != null) {
      final test = tests.where((t) => t['id'] == testMatch.group(1)).firstOrNull;
      if (test == null) return _json({'message': 'not found'}, 404);
      return _json({'data': test});
    }

    final attemptMatch = RegExp(
      r'/training/learn/tests/([^/]+)/attempts$',
    ).firstMatch(path);
    if (method == 'POST' && attemptMatch != null) {
      return _json(
        attemptBody ?? {'score': 100, 'passed': true, 'expires_on': '2027-08-01'},
        attemptStatus,
      );
    }

    return _json({'message': 'Nothing stubbed for $method $path.'}, 500);
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
