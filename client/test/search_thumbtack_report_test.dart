import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/screens/reports/thumbtack_report.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Reports › Thumbtack: the search narrows the rows by category, state and
/// branch; the Total row stays the whole report's.
void main() {
  testWidgets('the search narrows the Thumbtack rows', (tester) async {
    Map<String, dynamic> row(String category, String branch) => {
      'category': category,
      'branch': branch,
      'leads': 3,
      'charged': 2,
      'credited': 1,
      'spend_minor': 4000,
      'cost_per_lead_minor': 2000,
      'booked': 1,
      'cost_per_booked_minor': 4000,
    };
    final api = ApiClient(
      client: MockClient(
        (request) async => http.Response(
          jsonEncode({
            'data': {
              'rows': [
                row('Chimney Sweep', 'Connecticut'),
                row('Dryer Vent', 'New Jersey'),
              ],
              'categories': <dynamic>[],
              'branches': <dynamic>[],
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        ),
      ),
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

    final table = find.byKey(const ValueKey('thumbtack-table'));
    Finder inTable(String text) =>
        find.descendant(of: table, matching: find.text(text));
    expect(inTable('Chimney Sweep'), findsWidgets);
    expect(inTable('Dryer Vent'), findsWidgets);

    await tester.enterText(
      find.byKey(const Key('thumbtack-report-search')),
      'jersey',
    );
    await tester.pump();
    expect(inTable('Dryer Vent'), findsWidgets);
    expect(inTable('Chimney Sweep'), findsNothing);
    // The Total row's spend is still both rows'.
    expect(inTable(r'$80.00'), findsOneWidget);

    await tester.enterText(
      find.byKey(const Key('thumbtack-report-search')),
      'zzz',
    );
    await tester.pump();
    expect(find.text('No rows match "zzz".'), findsOneWidget);
    expect(table, findsNothing);
  });
}
