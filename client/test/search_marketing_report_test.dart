import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/screens/reports/marketing_report.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Reports › Marketing: the search narrows the rows by branch and source; the
/// Total row stays the whole report's.
void main() {
  testWidgets('the search narrows the marketing rows', (tester) async {
    final api = ApiClient(
      client: MockClient(
        (request) async => http.Response(
          jsonEncode({
            'data': {
              'rows': [
                {
                  'branch': 'Connecticut',
                  'source': 'LS Ads',
                  'cost_minor': 3000000,
                  'leads': 10,
                },
                {
                  'branch': 'New Jersey',
                  'source': 'PPC',
                  'cost_minor': 100000,
                  'leads': 2,
                },
              ],
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        ),
      ),
    );
    tester.view.physicalSize = const Size(2400, 1200);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: MarketingReport(api: api, now: () => DateTime(2026, 10, 14)),
        ),
      ),
    );
    await tester.pumpAndSettle();

    final table = find.byKey(const Key('marketing-table'));
    Finder inTable(String text) =>
        find.descendant(of: table, matching: find.text(text));
    expect(inTable('Connecticut'), findsWidgets);
    expect(inTable('New Jersey'), findsWidgets);

    await tester.enterText(
      find.byKey(const Key('marketing-report-search')),
      'jersey',
    );
    await tester.pump();
    expect(inTable('New Jersey'), findsWidgets);
    expect(inTable('Connecticut'), findsNothing);
    // The Total row is still both rows'.
    expect(inTable(r'$31,000.00'), findsOneWidget);

    await tester.enterText(
      find.byKey(const Key('marketing-report-search')),
      'zzz',
    );
    await tester.pump();
    expect(find.text('No rows match "zzz".'), findsOneWidget);
    expect(table, findsNothing);
  });
}
