import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/reports/report_run_view.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Reports › a run's result table: the search narrows the rows by any cell.
void main() {
  Future<void> pump(WidgetTester tester, double width) async {
    tester.view.physicalSize = Size(width, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = ApiClient(
      client: MockClient(
        (request) async => http.Response(
          jsonEncode({
            'data': {
              'columns': [
                {'key': 'jobs.title', 'label': 'Title', 'type': 'string'},
                {'key': 'customers.phone', 'label': 'Phone', 'type': 'string'},
              ],
              'rows': [
                {'jobs.title': 'Sweep', 'customers.phone': '7326748312'},
                {'jobs.title': 'Cap install', 'customers.phone': '2035550142'},
              ],
              'capped': false,
              'count': 2,
              'cap': 5000,
            },
          }),
          200,
          headers: const {'content-type': 'application/json'},
        ),
      ),
    );

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: ReportRunView(api: api, reportId: 'rep-1', name: 'Open jobs'),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  // Jose, 2026-11-16: "give format to numbers"; the digits still find it.
  testWidgets('a phone column is written as a number and found by digits', (
    tester,
  ) async {
    await pump(tester, 1200);
    expect(find.text('+1 (732) 674-8312'), findsOneWidget);
    expect(find.text('7326748312'), findsNothing);

    await tester.enterText(
      find.byKey(const Key('report-run-search')),
      '732674',
    );
    await tester.pumpAndSettle();
    expect(find.text('Sweep'), findsOneWidget);
    expect(find.text('Cap install'), findsNothing);
  });

  for (final width in [1200.0, 375.0]) {
    testWidgets('the search narrows the rows at $width', (tester) async {
      await pump(tester, width);
      expect(find.text('Sweep'), findsOneWidget);
      expect(find.text('Cap install'), findsOneWidget);

      await tester.enterText(
        find.byKey(const Key('report-run-search')),
        ' CAP ',
      );
      await tester.pump();
      expect(find.text('Cap install'), findsOneWidget);
      expect(find.text('Sweep'), findsNothing);
      // The count stays the run's.
      expect(find.text('2 rows'), findsOneWidget);

      await tester.enterText(find.byKey(const Key('report-run-search')), 'zzz');
      await tester.pump();
      expect(find.text('No rows match "zzz".'), findsOneWidget);
      expect(find.text('Cap install'), findsNothing);
    });
  }
}
