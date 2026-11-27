import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/reviews_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Adeline, approved by Jose 2026-11-19: "fix the counter when multiple filters selected, also
/// change from 1 to 3 stars not 1 to 2". The chip shows the server's count for the filters in play.
void main() {
  testWidgets('the Unanswered count follows 1–3 stars and This week', (
    tester,
  ) async {
    final asked = <Uri>[];
    tester.view.physicalSize = const Size(1400, 1000);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    final api = ApiClient(
      client: MockClient((request) async {
        if (request.url.path.endsWith('/reviews')) {
          asked.add(request.url);
          final q = request.url.queryParameters;
          // Stand-in for the server: 40 unanswered, 12 at 1–3 stars, 3 of those this week.
          final n = q['rating'] == 'negative'
              ? (q['from'] != null ? 3 : 12)
              : 40;
          return http.Response(
            jsonEncode({
              'data': [],
              'meta': {'total': 0, 'unanswered': n},
            }),
            200,
            headers: {'content-type': 'application/json'},
          );
        }
        return http.Response(
          '{}',
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: ReviewsScreen(api: api, session: Session(api)),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Unanswered (40)'), findsOneWidget);

    await tester.tap(find.widgetWithText(FilterChip, '1–3 stars'));
    await tester.pumpAndSettle();
    expect(asked.last.queryParameters['rating'], 'negative');
    expect(find.text('Unanswered (12)'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('reviews-this-week')));
    await tester.pumpAndSettle();
    expect(find.text('Unanswered (3)'), findsOneWidget);
  });
}
