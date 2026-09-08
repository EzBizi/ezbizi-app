import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/training/guide_player.dart';
import 'package:ezbizi_client/screens/training/test_player.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The guide player and the test player — layout 4
/// (`docs/previews/training-layouts.html`): a screen rail beside the reader,
/// the guide's own test as the last rail item, gated until every screen has
/// been seen. Below `GuidePlayer.splitAbove` (600, the same constant
/// `InventoryScreen` uses) the rail is gone and the reader is the whole
/// body.
void main() {
  // ── §3/§4: below 600px the rail collapses ──────────────────────────

  testWidgets('below 600px the rail is gone and the reader is the whole body', (
    tester,
  ) async {
    await _pumpGuide(
      tester,
      guide: _guide(lastScreen: 2),
      size: const Size(375, 800),
    );

    expect(find.byKey(const Key('training.screen-rail')), findsNothing);
    expect(find.text('The four-to-one rule'), findsOneWidget);
  });

  // ── §4: a control that is offered must be a control that works ─────

  testWidgets(
    'a gated test says so instead of offering a control that would refuse',
    (tester) async {
      await _pumpGuide(
        tester,
        guide: _guide(),
        test: _testEntry(guideId: 'g1', requiresGuide: true),
      );

      expect(find.text('Unlocks when all 8 are seen'), findsOneWidget);
      final button = tester.widget<ElevatedButton>(
        find.byKey(const Key('training.start-test')),
      );
      expect(button.onPressed, isNull);
    },
  );

  // ── `last_screen` is a high-water mark, never a cursor ──────────────

  testWidgets(
    'marking an earlier screen seen does not un-finish an already-finished guide',
    (tester) async {
      await _pumpGuide(
        tester,
        guide: _guide(lastScreen: 7, completedAt: '2026-08-01T00:00:00Z'),
        test: _testEntry(guideId: 'g1', requiresGuide: true),
      );

      // Starts unlocked: the guide was already finished before the player
      // opened.
      var button = tester.widget<ElevatedButton>(
        find.byKey(const Key('training.start-test')),
      );
      expect(button.onPressed, isNotNull);

      // Page back to an earlier screen — this POSTs `seen` for a screen
      // index BELOW the stored high-water mark. The fake server mirrors
      // ProgressController::seen's own max(): last_screen and completed_at
      // both stay exactly what they already were.
      await tester.tap(find.byKey(const Key('training.nav-back')));
      await tester.pumpAndSettle();

      expect(find.text('Unlocks when all 8 are seen'), findsNothing);
      button = tester.widget<ElevatedButton>(
        find.byKey(const Key('training.start-test')),
      );
      expect(
        button.onPressed,
        isNotNull,
        reason: 'Re-reading an earlier screen of a finished guide must not '
            'take the completion away (ProgressController::seen: a '
            'high-water mark, never a cursor).',
      );
    },
  );

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
    for (final title in _screenTitles)
      {'title': title, 'body': 'Body for $title.', 'media_kind': 'none', 'media_url': null},
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
  'valid_for_days': 365,
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
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(_handler(guides: const [], tests: [test])));
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
      return _json({'score': 100, 'passed': true, 'expires_on': '2027-08-01'});
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
