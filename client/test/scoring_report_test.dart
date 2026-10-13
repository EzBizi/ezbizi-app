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
/// with AI's call score, a ranking after ten scored calls, and the person's
/// checks and notes when a card is opened. Built from Performance's
/// `GET /reports/experimental/calls`. Every name here is invented.
void main() {
  Map<String, dynamic> call(
    String who,
    num? score, {
    String day = '2026-10-13',
    bool booked = false,
    bool priced = true,
    String? improve,
    String? strength,
  }) => {
    'id': 'c-${who.hashCode}-${score.hashCode}-$day-$improve',
    'answered_by': who,
    'started_at': '${day}T15:00:00Z',
    'booked': booked,
    'score': score == null
        ? null
        : {
            'score': score,
            'checks': {
              'greeted': true,
              'got_name': true,
              'got_address': true,
              'found_need': true,
              'offered_time': true,
              'gave_price': priced,
              'asked_for_booking': true,
            },
            'strength': strength,
            'improve': improve,
          },
  };

  List<Map<String, dynamic>> now() => [
    for (var i = 0; i < 12; i++)
      call(
        'Maya Ortiz',
        i.isEven ? 9 : 8,
        day: '2026-10-${10 + i % 3}',
        booked: i < 7,
        improve: i == 0 ? 'Say the visit fee before the time slots.' : null,
        strength: i == 1 ? 'Opens with the company name.' : null,
      ),
    for (var i = 0; i < 11; i++)
      call('Tom Becker', 7, priced: i < 3, booked: i < 4),
    for (var i = 0; i < 4; i++) call('Sam Wu', 6),
    // Answered, not scored: counts toward booked, not toward the score.
    call('Tom Becker', null, booked: true),
  ];

  late int reads;

  Future<void> pump(
    WidgetTester tester, {
    List<Map<String, dynamic>>? calls,
    double width = 1440,
  }) async {
    tester.view.physicalSize = Size(width, 1400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    reads = 0;

    http.Response json(Object body) => http.Response(
      jsonEncode(body),
      200,
      headers: const {'content-type': 'application/json'},
    );

    final api = ApiClient(
      client: MockClient((request) async {
        if (!request.url.path.endsWith('/reports/experimental/calls')) {
          return json({'data': null});
        }
        reads++;
        final from = request.url.queryParameters['from'];
        // The 30 days before: Tom scored lower then, so he is "most improved".
        if (from == '2026-08-15') {
          return json({
            'calls': [for (var i = 0; i < 10; i++) call('Tom Becker', 5)],
            'people': const [],
            'workiz_scores': const {},
          });
        }
        return json({
          'calls': calls ?? now(),
          'people': const [],
          'workiz_scores': {
            'Maya Ortiz': {'calls': 30, 'score': 82},
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

  testWidgets('a card per person, ranked after ten scored calls', (
    tester,
  ) async {
    await pump(tester);

    expect(reads, 2, reason: 'these 30 days and the 30 before, for the trend');
    for (final name in ['Maya Ortiz', 'Tom Becker', 'Sam Wu']) {
      expect(find.byKey(ValueKey('scoring-card-$name')), findsOneWidget);
    }
    // Maya 8.5 over 12, Tom 7.0 over 11; Sam's 4 calls rank nobody.
    expect(find.text('#1'), findsOneWidget);
    expect(find.text('#2'), findsOneWidget);
    expect(find.text('4 of 10 calls'), findsOneWidget);
    expect(find.text('8.5'), findsWidgets);
    expect(find.text('2 of 3'), findsOneWidget);
    expect(find.text('Sam Wu needs 6 more calls'), findsOneWidget);
    // Tom went from 5.0 to 7.0.
    expect(find.text('Most improved'), findsWidgets);
    expect(find.text('5.0 → 7.0'), findsOneWidget);
    // The price is the team's weakest check: Tom gave it on 3 of 11.
    expect(find.text('Explained the price'), findsOneWidget);
    // Booked counts every answered call, scored or not: Tom 5 of 12.
    expect(find.text('11 scored calls · 42% booked'), findsOneWidget);
    expect(
      find.textContaining('A guide for coaching, not a verdict'),
      findsOneWidget,
    );

    // The ranking reads top down.
    final maya = tester.getTopLeft(
      find.byKey(const ValueKey('scoring-rank-Maya Ortiz')),
    );
    final tom = tester.getTopLeft(
      find.byKey(const ValueKey('scoring-rank-Tom Becker')),
    );
    expect(maya.dy, lessThan(tom.dy));
  });

  testWidgets('a card opens the person in the tab: checks, notes, way back', (
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
    expect(find.text("Workiz's score 82 / 100"), findsOneWidget);
    expect(find.text('Got the address'), findsOneWidget);
    expect(
      find.text('“Say the visit fee before the time slots.”'),
      findsOneWidget,
    );
    expect(find.text('“Opens with the company name.”'), findsOneWidget);
    // The ranking stays beside it, so the next person is one click away.
    expect(find.byKey(const Key('scoring-ranking')), findsOneWidget);
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

  testWidgets('no scored calls says so', (tester) async {
    await pump(tester, calls: [call('Sam Wu', null)]);

    expect(find.byKey(const Key('scoring-empty')), findsOneWidget);
  });
}
