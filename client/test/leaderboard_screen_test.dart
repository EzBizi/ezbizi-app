import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/leaderboard_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Leaderboard (Trey, 2026-11-25): three names, the reader's own rank, and
/// the reader's own calls; a new period asks again for its dates.
void main() {
  Future<List<Uri>> pump(
    WidgetTester tester,
    double width, {
    Map<String, dynamic>? me,
  }) async {
    tester.view.physicalSize = Size(width, 1400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    final asked = <Uri>[];

    http.Response json(Object body) => http.Response(
      jsonEncode(body),
      200,
      headers: const {'content-type': 'application/json'},
    );

    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        asked.add(request.url);
        if (path.endsWith('/me')) {
          return json({
            'data': {
              'id': 'u-1',
              'name': 'Di',
              'email': 'di@example.com',
              'role': {'name': 'Dispatcher'},
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
              'permissions': ['leaderboard.view', 'leaderboard.mine.view'],
              'modules': <dynamic>[],
            },
          });
        }
        if (path.endsWith('/leaderboard')) {
          return json({
            'data': {
              'from': request.url.queryParameters['from'],
              'to': request.url.queryParameters['to'],
              'min_calls_to_rank': 3,
              'top': [
                for (final (i, n) in ['Ada', 'Bo', 'Cy'].indexed)
                  {
                    'rank': i + 1,
                    'person': n,
                    'user_id': null,
                    'score': 9.0 - i,
                    'calls_scored': 12,
                  },
              ],
              'me':
                  me ??
                  {
                    'person': 'Di',
                    'rank': 4,
                    'of': 6,
                    'score': 6.2,
                    'calls_scored': 9,
                    'why': null,
                  },
            },
          });
        }
        if (path.endsWith('/leaderboard/mine')) {
          return json({
            'data': {
              'person': 'Di',
              'min_calls_to_rank': 3,
              'performance': {'person': 'Di', 'answered': 41, 'jobs': 7},
              'scoring': {
                'person': 'Di',
                'score': 6.2,
                'avg_score': 6.5,
                'calls_scored': 9,
                'checks': {'greeted': 80},
                'strengths': ['Warm greeting'],
                'improve': ['Ask for the booking'],
              },
              'calls': [
                {
                  'id': 'k1',
                  'direction': 'Inbound',
                  'started_at': '2026-11-20T15:00:00Z',
                  'from': '+12145550001',
                  'answered_by': 'Di',
                },
              ],
            },
          });
        }
        if (path.endsWith('/leaderboard/mine/calls')) {
          return json({
            'data': [
              {
                'call_id': 'c9',
                'started_at': '2026-11-20T15:00:00Z',
                'caller_number': '+12145550001',
                'score': 8,
                'tags': ['latest', 'best'],
                'transcript': 'Di: thanks for calling',
              },
            ],
          });
        }
        return http.Response('{"message": "Nothing stubbed for $path"}', 500);
      }),
    );

    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: LeaderboardScreen(
            api: api,
            session: session,
            now: () => DateTime(2026, 11, 25),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    return asked;
  }

  for (final width in [1280.0, 390.0]) {
    testWidgets('the top three, your rank and your own calls at $width', (
      tester,
    ) async {
      final asked = await pump(tester, width);

      // This week, Sunday to Friday, by default.
      final board = asked.lastWhere((u) => u.path.endsWith('/leaderboard'));
      expect(board.queryParameters, {'week': '2026-11-22'});
      for (final rank in [1, 2, 3]) {
        expect(find.byKey(ValueKey('leaderboard-top-$rank')), findsOneWidget);
      }
      expect(find.text('Ada'), findsOneWidget);
      expect(find.text('Your rank: #4 of 6'), findsOneWidget);
      expect(tester.takeException(), isNull);

      await tester.tap(find.byKey(const ValueKey('leaderboard-week-previous')));
      await tester.pumpAndSettle();
      expect(
        asked.lastWhere((u) => u.path.endsWith('/leaderboard')).queryParameters,
        {'week': '2026-11-15'},
      );

      await tester.tap(find.text('My performance'));
      await tester.pumpAndSettle();
      expect(
        asked
            .lastWhere((u) => u.path.endsWith('/leaderboard/mine'))
            .queryParameters,
        {'from': '2026-11-15', 'to': '2026-11-20'},
        reason: 'the same week',
      );
      expect(find.text('41'), findsOneWidget, reason: 'answered');
      expect(find.text('• Ask for the booking'), findsOneWidget);
      await tester.scrollUntilVisible(
        find.byKey(const ValueKey('leaderboard-scored-c9')),
        200,
        scrollable: find
            .descendant(
              of: find.byKey(const ValueKey('leaderboard-mine')),
              matching: find.byType(Scrollable),
            )
            .first,
      );
      await tester.tap(find.byKey(const ValueKey('leaderboard-scored-c9')));
      await tester.pumpAndSettle();
      expect(find.text('Di: thanks for calling'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('outside the board, it says why', (tester) async {
    await pump(
      tester,
      1280,
      me: {
        'person': 'Ed',
        'rank': null,
        'of': 6,
        'score': 8.1,
        'calls_scored': 20,
        'why': 'not_new',
      },
    );
    expect(find.text('You are not on this board'), findsOneWidget);
    expect(
      find.text('Only the people marked for New Client Calls are ranked here.'),
      findsOneWidget,
    );
  });
}
