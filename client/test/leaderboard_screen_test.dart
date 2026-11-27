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
    bool nobody = false,
    bool searchDown = false,
    Map<String, dynamic> board = const {},
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
        if (path.endsWith('/leaderboard/weeks')) {
          return json({
            'data': [
              {
                'from': '2026-11-15',
                'to': '2026-11-20',
                'top': [
                  for (final (i, n) in ['Bo', 'Ada', 'Cy'].indexed)
                    {
                      'rank': i + 1,
                      'person': n,
                      'user_id': null,
                      'booking_rate': 70 - i,
                      'score': 8.9 - i,
                      'calls_scored': 10,
                    },
                ],
              },
            ],
          });
        }
        if (path.endsWith('/leaderboard')) {
          return json({
            'data': {
              ...board,
              'from': request.url.queryParameters['from'],
              'to': request.url.queryParameters['to'],
              'min_calls_to_rank': 3,
              'top': [
                if (!nobody)
                  for (final (i, n) in ['Ada', 'Bo', 'Cy'].indexed)
                    {
                      'rank': i + 1,
                      'person': n,
                      'user_id': null,
                      'booking_rate': 75 - 5 * i,
                      'booked': 9 - i,
                      'decided': 12,
                      'answer_rate': 88,
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
                    'booking_rate': 50,
                    'booked': 5,
                    'decided': 10,
                    'answer_rate': 91,
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
              'performance': {
                'person': 'Di',
                'answered': 41,
                'outbound': 12,
                'new_client': 20,
                'booked_new': 11,
                'avg_talk_seconds': 222,
                'jobs': 7,
              },
              'scoring': {
                'person': 'Di',
                'score': 6.2,
                'avg_score': 6.5,
                'calls_scored': 9,
                'checks': {'greeted': 80},
                'conversion': 56,
                'review_weekly': {'calls': 9, 'summary': 'Strong openings.'},
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
          if (searchDown && request.url.queryParameters.containsKey('q')) {
            return http.Response(
              '{"message": "Search is down"}',
              500,
              headers: const {'content-type': 'application/json'},
            );
          }
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
              {
                'call_id': 'c2',
                'started_at': '2026-11-19T15:00:00Z',
                'caller_number': '+12145550002',
                'score': 3,
                'tags': ['worst'],
                'transcript': 'Di: one moment',
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
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('leaderboard-top-1')),
          matching: find.text('Ada'),
        ),
        findsOneWidget,
      );
      // Ranked by booking rate; answer rate and score shown (Trey, 11-27).
      final first = find.byKey(const ValueKey('leaderboard-top-1'));
      expect(find.descendant(of: first, matching: find.text('75%')), findsOne);
      expect(
        find.descendant(of: first, matching: find.text('9 of 12 booked')),
        findsOne,
      );
      // Design 214's podium: answer rate and score under the rate on a desk;
      // a phone keeps the rate and booked only.
      expect(
        find.descendant(
          of: first,
          matching: find.text('Answer 88% · Score 9.0'),
        ),
        width < 600 ? findsNothing : findsOne,
      );
      // You: the rank, of how many, your rates and score.
      final you = find.byKey(const ValueKey('leaderboard-me'));
      expect(
        find.descendant(
          of: you,
          matching: find.text('50% booked · answer rate 91% · 6.2 / 10'),
        ),
        findsOne,
      );
      expect(
        find.descendant(of: you, matching: find.text('#4')),
        findsOneWidget,
      );
      expect(
        find.descendant(of: you, matching: find.text('of 6 ranked')),
        findsOneWidget,
      );
      // Design 214: the podium shows each face, on a phone too.
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('leaderboard-top-1')),
          matching: find.byType(CircleAvatar),
        ),
        findsOneWidget,
      );
      expect(tester.takeException(), isNull);

      // Past weeks: the winner, then 2nd and 3rd; a tap opens that week.
      final past = find.byKey(const ValueKey('leaderboard-week-2026-11-15'));
      await tester.ensureVisible(past);
      await tester.pumpAndSettle();
      expect(
        find.descendant(of: past, matching: find.textContaining('Bo')),
        findsWidgets,
      );
      await tester.tap(past);
      await tester.pumpAndSettle();
      expect(
        asked.lastWhere((u) => u.path.endsWith('/leaderboard')).queryParameters,
        {'week': '2026-11-15'},
      );
      await tester.tap(find.byKey(const ValueKey('leaderboard-week-next')));
      await tester.pumpAndSettle();

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
      expect(find.text('56%'), findsOneWidget, reason: 'booking rate');
      expect(find.text('3:42'), findsOneWidget, reason: 'average talk');
      // Six tiles, one size (Jose: sibling tiles are equal).
      final sizes = {
        for (final t in [
          'Answered',
          'Outbound',
          'New client',
          'Booked new',
          'Booking rate',
          'Avg talk',
        ])
          tester.getSize(find.byKey(ValueKey('leaderboard-tile-$t'))),
      };
      expect(sizes, hasLength(1));
      expect(find.text('• Ask for the booking'), findsOneWidget);
      expect(find.text('Strong openings.'), findsOneWidget);
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
      // Latest 3 shows c9, not the worst; Worst swaps them.
      expect(find.byKey(const ValueKey('leaderboard-scored-c2')), findsNothing);
      await tester.tap(find.byKey(const ValueKey('leaderboard-scored-c9')));
      await tester.pumpAndSettle();
      expect(find.text('Di: thanks for calling'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('leaderboard-pick-worst')));
      await tester.pumpAndSettle();
      expect(
        find.byKey(const ValueKey('leaderboard-scored-c2')),
        findsOneWidget,
      );
      expect(find.byKey(const ValueKey('leaderboard-scored-c9')), findsNothing);
      expect(tester.takeException(), isNull);
    });
  }

  // Audit 2026-11-27: a failed search read as "No scored calls match".
  testWidgets('a failed call search says so, not that nothing matched', (
    tester,
  ) async {
    await pump(tester, 1280, searchDown: true);
    await tester.tap(find.text('My performance'));
    await tester.pumpAndSettle();
    final box = find.descendant(
      of: find.byKey(const ValueKey('leaderboard-mine-search')),
      matching: find.byType(TextField),
    );
    await tester.scrollUntilVisible(
      box,
      200,
      scrollable: find
          .descendant(
            of: find.byKey(const ValueKey('leaderboard-mine')),
            matching: find.byType(Scrollable),
          )
          .first,
    );
    await tester.enterText(box, 'boiler');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();
    expect(find.text('Search is down'), findsOneWidget);
    expect(find.textContaining('No scored calls'), findsNothing);
  });

  // Design 212's empty states, in the You card.
  for (final (why, says) in [
    ('not_new', 'Not on New Client Calls'),
    ('too_few_calls', '2 of 3 new-client calls'),
    ('not_matched', 'Your calls are not linked yet'),
  ]) {
    testWidgets('outside the board, it says why: $why', (tester) async {
      await pump(
        tester,
        1280,
        me: {
          'person': 'Ed',
          'rank': null,
          'of': 6,
          'booking_rate': null,
          'decided': 2,
          'score': 8.1,
          'calls_scored': 9,
          'why': why,
        },
      );
      final you = find.byKey(const ValueKey('leaderboard-me'));
      expect(
        find.descendant(of: you, matching: find.text(says)),
        findsOneWidget,
      );
      if (why == 'too_few_calls') {
        expect(
          find.descendant(
            of: you,
            matching: find.textContaining(
              'Answer 1 more new-client call this week',
            ),
          ),
          findsOneWidget,
        );
      }
    });
  }

  testWidgets('nobody ranked yet says so, and You still shows', (tester) async {
    await pump(tester, 1280, nobody: true);
    expect(find.byKey(const ValueKey('leaderboard-nobody')), findsOneWidget);
    expect(find.byKey(const ValueKey('leaderboard-top-1')), findsNothing);
    expect(find.byKey(const ValueKey('leaderboard-me')), findsOneWidget);
  });

  // Design 214 (Jose 11-27: "more elaborate"): the team's week, a podium,
  // everyone ranked with six weeks and the change, and what moves you up.
  testWidgets('design 214: team strip, podium 2·1·3, everyone ranked, You', (
    tester,
  ) async {
    Map<String, dynamic> row(
      int rank,
      String? who,
      num rate, {
      bool me = false,
    }) => {
      'rank': rank,
      'person': who,
      'user_id': me ? 'u-1' : null,
      'booking_rate': rate,
      'booked': 10,
      'decided': 20,
      'answer_rate': 90,
      'score': 7.0,
      'trend': [50, 55, null, 60, 62, rate],
      'change': {'rank': rank == 4 ? 2 : 0},
    };
    await pump(
      tester,
      1280,
      board: {
        'ranked': [
          row(1, 'Ada', 80),
          row(2, 'Bo', 75),
          row(3, 'Cy', 70),
          row(4, 'Di', 60, me: true),
          row(5, null, 55),
        ],
        'team': {
          'new_calls': 402,
          'booked': 247,
          'booking_rate': 61,
          'answer_rate': 87,
          'score': 7.1,
          'change': {'booking_rate': 2},
        },
      },
      me: {
        'person': 'Di',
        'rank': 4,
        'of': 5,
        'booking_rate': 60,
        'answer_rate': 90,
        'score': 6.2,
        'decided': 20,
        'why': null,
        'to_next': {'rank': 3, 'person': 'Cy', 'bookings': 2},
      },
    );
    // Five team tiles, one size.
    final sizes = {
      for (final t in [
        'New client calls',
        'Booked',
        'Team booking rate',
        'Answer rate',
        'Avg score',
      ])
        tester.getSize(find.byKey(ValueKey('leaderboard-team-$t'))),
    };
    expect(sizes, hasLength(1));
    expect(find.textContaining('▲ 2 pts'), findsOneWidget);
    // The podium reads 2 · 1 · 3 from the left.
    double x(int r) =>
        tester.getTopLeft(find.byKey(ValueKey('leaderboard-top-$r'))).dx;
    expect(x(2), lessThan(x(1)));
    expect(x(1), lessThan(x(3)));
    // Everyone ranked: your row says You, a nameless row its place only.
    final mine = find.byKey(const ValueKey('leaderboard-row-4'));
    expect(find.descendant(of: mine, matching: find.text('You')), findsOne);
    expect(find.descendant(of: mine, matching: find.text('▲ 2')), findsOne);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('leaderboard-row-5')),
        matching: find.text('—'),
      ),
      findsOne,
    );
    expect(find.byType(CustomPaint), findsWidgets);
    // What moves you up.
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('leaderboard-to-next')),
        matching: find.textContaining('past Cy (#3)'),
      ),
      findsOne,
    );
    expect(tester.takeException(), isNull);
  });
}
