import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/screens/reports/thumbtack_report.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('Thumbtack spend, cost per lead and per booked, last month', (
    tester,
  ) async {
    final asked = <Map<String, String>>[];
    final api = ApiClient(
      client: MockClient((request) async {
        asked.add(request.url.queryParameters);
        return http.Response(
          jsonEncode({
            'data': {
              'rows': [
                {
                  'category': 'Chimney Sweep',
                  'branch': 'Connecticut',
                  'leads': 3,
                  'charged': 2,
                  'credited': 1,
                  'spend_minor': 4000,
                  'cost_per_lead_minor': 2000,
                  'booked': 1,
                  'cost_per_booked_minor': 4000,
                },
              ],
              'categories': ['Chimney Sweep'],
              'branches': [
                {'id': 'f1', 'name': 'Connecticut'},
              ],
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );
    tester.view.physicalSize = const Size(2000, 1400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: ThumbtackReport(api: api, now: () => DateTime(2026, 10, 14)),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(asked.single, {'from': '2026-09-01', 'to': '2026-09-30'});
    // The figures, then the one table with its Total row (UI queue 87).
    final figures = find.byKey(const ValueKey('thumbtack-totals'));
    expect(
      find.descendant(of: figures, matching: find.text('1 refunded')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: figures, matching: find.text(r'$40.00 each')),
      findsOneWidget,
    );
    // Spend, and cost per booked (one booked lead), on the row and in Total.
    final table = find.byKey(const ValueKey('thumbtack-table'));
    expect(
      find.descendant(of: table, matching: find.text(r'$40.00')),
      findsNWidgets(4),
    );
    expect(
      find.descendant(of: table, matching: find.text(r'$20.00')),
      findsNWidgets(2),
    );
    expect(
      find.descendant(of: table, matching: find.text('Total')),
      findsOneWidget,
    );
  });
}
