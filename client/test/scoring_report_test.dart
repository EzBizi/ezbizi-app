import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/reports/scoring_report.dart';
import 'package:ezbizi_client/widgets/date_range_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Reports › Scoring (UI queue 85, desktop 1 and phone 4): a card per person
/// with AI's call score, the server's ranking, and the person's checks,
/// notes and Grok's Daily and Weekly read when a card is opened. Built on
/// Reports' `GET /reports/scoring`. Every name here is invented.
void main() {
  Map<String, dynamic> checks({int price = 60}) => {
    'greeted': 100,
    'got_name': 95,
    'got_address': 90,
    'found_need': 96,
    'offered_time': 88,
    'gave_price': price,
    'asked_for_booking': 84,
  };

  List<Map<String, dynamic>> people() => [
    {
      'person': 'Maya Ortiz',
      'calls_scored': 12,
      'conversion': 25,
      'jobs': 1,
      'new_client': 4,
      'avg_score': 8.5,
      'checks': checks(price: 75),
      'strengths': ['Opens with the company name'],
      'improve': ['Say the visit fee before the time slots'],
      'workiz': {'calls': 30, 'score': 82},
      'review_daily': null,
      'review_weekly': {
        'from': '2026-10-05',
        'to': '2026-10-11',
        'calls': 9,
        'avg_score': 8.4,
        'summary': 'Tidy calls: name, address, then the problem.',
        'strengths': [
          'Greets every caller by the company name',
          'Gets the address first',
        ],
        'work_on': ['The price before the times', 'The gate code'],
        'trend': 'up',
      },
      'rank': 1,
    },
    {
      'person': 'Tom Becker',
      'calls_scored': 11,
      'avg_score': 7.0,
      'checks': checks(price: 27),
      'strengths': <String>[],
      'improve': ['Explain the price'],
      'workiz': null,
      'review_daily': null,
      'review_weekly': null,
      'rank': 2,
    },
    {
      'person': 'Sam Wu',
      'calls_scored': 2,
      'avg_score': 6.0,
      'checks': checks(),
      'strengths': <String>[],
      'improve': <String>[],
      'workiz': null,
      'review_daily': null,
      'review_weekly': null,
      'rank': null,
    },
  ];

  late int reads;
  late List<Map<String, String>> asked;

  Future<void> pump(
    WidgetTester tester, {
    List<Map<String, dynamic>>? data,
    double width = 1440,
  }) async {
    tester.view.physicalSize = Size(width, 1400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    reads = 0;
    asked = [];

    http.Response json(Object body) => http.Response(
      jsonEncode(body),
      200,
      headers: const {'content-type': 'application/json'},
    );

    final api = ApiClient(
      client: MockClient((request) async {
        if (request.url.path.endsWith('/reports/scoring/calls')) {
          asked.add(request.url.queryParameters);
          final q = request.url.queryParameters['q'];
          return json({
            'data': [
              for (final (id, score, tags) in [
                ('c4', 9, ['latest', 'best']),
                ('c3', 7, ['latest']),
                ('c2', 6, ['latest']),
                ('c1', 3, ['worst']),
              ])
                if (q == null || id == 'c3')
                  {
                    'call_id': id,
                    'started_at': '2026-10-12T15:00:00Z',
                    'caller_number': '+1214555000${id[1]}',
                    'recording_id': null,
                    'score': score,
                    'strength': 'Greeted',
                    'improve': 'Price first',
                    'transcript': 'words',
                    'tags': q == null ? tags : ['match'],
                  },
            ],
          });
        }
        if (!request.url.path.endsWith('/reports/scoring')) {
          return json({'data': null});
        }
        asked.add(request.url.queryParameters);
        reads++;
        final q = request.url.queryParameters;
        // The day before yesterday (the default is yesterday, Jose 2026-10-14):
        // Tom scored lower then, so he is "most improved".
        final before = q['from'] == '2026-10-11';
        return json({
          'data': {
            'from': q['from'],
            'to': q['to'],
            'min_calls_to_rank': 3,
            'weights': {'conversion': 40, 'greeted': 60},
            'people': before
                ? [
                    {...people()[1], 'avg_score': 5.0},
                  ]
                : (data ?? people()),
          },
        });
      }),
    );

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: ScoringReport(
            api: api,
            formats: DisplayFormats.fallback,
            now: () => DateTime(2026, 10, 13, 12),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets("a card per person, in the server's ranking", (tester) async {
    await pump(tester);

    expect(reads, 2, reason: 'these 30 days and the 30 before, for the trend');
    for (final name in ['Maya Ortiz', 'Tom Becker', 'Sam Wu']) {
      expect(find.byKey(ValueKey('scoring-card-$name')), findsOneWidget);
    }
    expect(find.text('#1'), findsOneWidget);
    expect(find.text('#2'), findsOneWidget);
    expect(find.text('not enough calls yet'), findsOneWidget);
    expect(find.text('2 of 3'), findsOneWidget);
    expect(find.text('1 with fewer than 3 scored calls'), findsOneWidget);
    // Team: (8.5 × 12 + 7 × 11 + 6 × 2) / 25 = 7.6.
    expect(find.text('7.6'), findsOneWidget);
    expect(find.text('over 25 scored calls'), findsOneWidget);
    expect(find.text('5.0 → 7.0'), findsOneWidget);
    expect(find.text('Explained the price'), findsOneWidget);
    // The week's summary reads on the card.
    expect(
      find.text('Tidy calls: name, address, then the problem.'),
      findsOneWidget,
    );
    expect(
      find.textContaining('A guide for coaching, not a verdict'),
      findsOneWidget,
    );

    final maya = tester.getTopLeft(
      find.byKey(const ValueKey('scoring-rank-Maya Ortiz')),
    );
    final tom = tester.getTopLeft(
      find.byKey(const ValueKey('scoring-rank-Tom Becker')),
    );
    expect(maya.dy, lessThan(tom.dy));
  });

  testWidgets('a card opens the person: checks, notes, AI analysis', (
    tester,
  ) async {
    await pump(tester);

    await tester.tap(find.byKey(const ValueKey('scoring-card-Maya Ortiz')));
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('scoring-detail-Maya Ortiz')),
      findsOneWidget,
    );
    expect(find.text('#1 of 2'), findsOneWidget);
    expect(find.text('12 scored calls in these dates'), findsOneWidget);
    expect(
      find.text('Conversion rate: 25% (1 job from 4 new-client calls)'),
      findsOneWidget,
    );
    expect(
      find.text(
        'The score weighs: Conversion 40% · Greeted with the company name 60%',
      ),
      findsOneWidget,
    );
    expect(
      find.text("Workiz's own score 82 / 100 over 30 calls"),
      findsOneWidget,
    );
    expect(find.text('Got the address'), findsOneWidget);
    expect(
      find.text('• Say the visit fee before the time slots'),
      findsOneWidget,
    );

    // Weekly first.
    expect(find.text('Oct 5 – Oct 11 · from 9 calls'), findsOneWidget);
    expect(find.text('• The price before the times'), findsOneWidget);
    expect(find.text('Better than the period before.'), findsOneWidget);
    await tester.tap(
      find.descendant(
        of: find.byKey(const Key('scoring-review-period')),
        matching: find.text('Daily'),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('scoring-review-none')), findsOneWidget);
    expect(find.text('• The price before the times'), findsNothing);

    // The ranking stays beside it, so the next person is one click away.
    await tester.tap(find.byKey(const ValueKey('scoring-rank-Tom Becker')));
    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('scoring-detail-Tom Becker')),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const Key('scoring-back')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('scoring-card-Sam Wu')), findsOneWidget);
  });

  testWidgets('Most improved puts the biggest rise first', (tester) async {
    await pump(tester);

    await tester.tap(
      find.descendant(
        of: find.byKey(const Key('scoring-sort')),
        matching: find.text('Most improved'),
      ),
    );
    await tester.pumpAndSettle();
    final tom = tester.getTopLeft(
      find.byKey(const ValueKey('scoring-rank-Tom Becker')),
    );
    final maya = tester.getTopLeft(
      find.byKey(const ValueKey('scoring-rank-Maya Ortiz')),
    );
    expect(tom.dy, lessThan(maya.dy));
  });

  testWidgets('on a phone: cards, no ranking card, and the dates open', (
    tester,
  ) async {
    await pump(tester, width: 390);

    expect(find.byKey(const Key('scoring-ranking')), findsNothing);
    expect(
      find.byKey(const ValueKey('scoring-card-Maya Ortiz')),
      findsOneWidget,
    );

    // The date panel inside a scrolling list drew nothing on a phone in
    // Marketing, Meta and Thumbtack; Scoring holds it the same way.
    await tester.tap(find.byType(DateRangeBar));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    expect(find.byType(DateRangePanel), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('scoring-card-Maya Ortiz')));
    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('scoring-detail-Maya Ortiz')),
      findsOneWidget,
    );
  });

  testWidgets("a person's latest calls with their scores, and a search", (
    tester,
  ) async {
    await pump(tester);
    await tester.tap(find.byKey(const ValueKey('scoring-card-Maya Ortiz')));
    await tester.pumpAndSettle();

    final first = asked.lastWhere((q) => q.containsKey('person'));
    expect(first['person'], 'Maya Ortiz');
    expect(first['from'], '2026-10-12');
    expect(find.byKey(const ValueKey('scoring-call-score-c4')), findsOneWidget);
    expect(find.text('9/10'), findsOneWidget);
    expect(find.text('6/10'), findsOneWidget);
    expect(find.text('3/10'), findsOneWidget);
    expect(find.byKey(const ValueKey('scoring-call-best')), findsOneWidget);
    expect(find.byKey(const ValueKey('scoring-call-worst')), findsOneWidget);

    await tester.enterText(
      find.byKey(const ValueKey('scoring-calls-search')),
      'gas',
    );
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    expect(asked.last['q'], 'gas');
    expect(asked.last['person'], 'Maya Ortiz');
    expect(find.text('7/10'), findsOneWidget);
    expect(find.text('9/10'), findsNothing);
  });

  testWidgets('the quick dates: yesterday by default, 1 week asks 7 days', (
    tester,
  ) async {
    await pump(tester);
    expect(asked.first['from'], '2026-10-12');
    expect(asked.first['to'], '2026-10-12');

    await tester.tap(find.byKey(const ValueKey('report-dates-1 week')));
    await tester.pumpAndSettle();
    final week = asked.lastWhere((q) => q['to'] == '2026-10-13');
    expect(week['from'], '2026-10-07');
  });

  testWidgets('no scored calls says so', (tester) async {
    await pump(tester, data: const []);

    expect(find.byKey(const Key('scoring-empty')), findsOneWidget);
  });
}
