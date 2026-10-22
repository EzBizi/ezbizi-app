import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/reports/scoring_report.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Reports › Scoring: the search narrows the people. Every name is invented.
void main() {
  Map<String, dynamic> person(String name, double score, int rank) => {
    'person': name,
    'calls_scored': 12,
    'avg_score': score,
    'checks': {
      'greeted': 100,
      'got_name': 95,
      'got_address': 90,
      'found_need': 96,
      'offered_time': 88,
      'gave_price': 60,
      'asked_for_booking': 84,
    },
    'strengths': <String>[],
    'improve': <String>[],
    'workiz': null,
    'review_daily': null,
    'review_weekly': null,
    'rank': rank,
  };

  for (final width in [1440.0, 390.0]) {
    testWidgets('the search narrows the people at $width', (tester) async {
      tester.view.physicalSize = Size(width, 1400);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      final api = ApiClient(
        client: MockClient((request) async {
          final q = request.url.queryParameters;
          return http.Response(
            jsonEncode({
              'data': request.url.path.endsWith('/reports/scoring')
                  ? {
                      'from': q['from'],
                      'to': q['to'],
                      'min_calls_to_rank': 3,
                      'weights': {'conversion': 40, 'greeted': 60},
                      'people': [
                        person('Maya Ortiz', 8.5, 1),
                        person('Tom Becker', 7.0, 2),
                      ],
                    }
                  : null,
            }),
            200,
            headers: const {'content-type': 'application/json'},
          );
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

      const maya = ValueKey('scoring-card-Maya Ortiz');
      const tom = ValueKey('scoring-card-Tom Becker');
      expect(find.byKey(maya), findsOneWidget);
      expect(find.byKey(tom), findsOneWidget);

      await tester.enterText(
        find.byKey(const Key('scoring-report-search')),
        'becker',
      );
      await tester.pump();
      expect(find.byKey(tom), findsOneWidget);
      expect(find.byKey(maya), findsNothing);
      expect(
        find.byKey(const ValueKey('scoring-rank-Maya Ortiz')),
        findsNothing,
      );

      await tester.enterText(
        find.byKey(const Key('scoring-report-search')),
        'zzz',
      );
      await tester.pump();
      expect(find.text('No people match "zzz".'), findsOneWidget);
      expect(find.byKey(tom), findsNothing);
    });
  }
}
