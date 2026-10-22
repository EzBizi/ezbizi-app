import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/screens/reports/meta_ads_report.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Reports › Meta: the search narrows the campaigns.
void main() {
  testWidgets('the search narrows the campaigns', (tester) async {
    Map<String, dynamic> row(String id, String name, String state) => {
      'id': id,
      'name': name,
      'state': state,
      'category': null,
      'branch': null,
      'spend_minor': 10050,
      'leads': 4,
      'cost_per_lead_minor': 2512,
      'clicks': 10,
      'impressions': 100,
    };
    final api = ApiClient(
      client: MockClient(
        (request) async => http.Response(
          jsonEncode({
            'data': {
              'rows': [
                row('c1', 'NJ Chimney', 'NJ'),
                row('c2', 'CT Dryer Vent', 'CT'),
              ],
              'states': ['NJ', 'CT'],
              'categories': <dynamic>[],
              'regions': <dynamic>[],
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        ),
      ),
    );
    tester.view.physicalSize = const Size(2000, 1600);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: MetaAdsReport(
            api: api,
            canEdit: false,
            now: () => DateTime(2026, 10, 14),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    final table = find.byKey(const ValueKey('meta-table'));
    Finder inTable(String text) =>
        find.descendant(of: table, matching: find.text(text));
    expect(inTable('NJ Chimney'), findsWidgets);
    expect(inTable('CT Dryer Vent'), findsWidgets);

    await tester.enterText(
      find.byKey(const Key('meta-ads-report-search')),
      'dryer',
    );
    await tester.pump();
    expect(inTable('CT Dryer Vent'), findsWidgets);
    expect(inTable('NJ Chimney'), findsNothing);

    await tester.enterText(
      find.byKey(const Key('meta-ads-report-search')),
      'zzz',
    );
    await tester.pump();
    expect(find.text('No campaigns match "zzz".'), findsOneWidget);
    expect(table, findsNothing);
  });
}
