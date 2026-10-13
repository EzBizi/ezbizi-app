import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/screens/reports/meta_ads_report.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('spend per campaign, a filter re-asks, and the AI category '
      'suggestion is one tap', (tester) async {
    final asked = <Map<String, String>>[];
    final put = <String>[];
    final api = ApiClient(
      client: MockClient((request) async {
        Map<String, dynamic> body;
        if (request.method == 'PUT') {
          put.add('${request.url.path} ${request.body}');
          body = {'data': {}};
        } else if (request.url.path.endsWith('/campaigns')) {
          body = {
            'data': [
              {
                'id': 'c1',
                'name': 'NJ Chimney',
                'state': 'NJ',
                'service_group_id': null,
                'suggested_service_group_id': 'g1',
              },
            ],
          };
        } else {
          asked.add(request.url.queryParameters);
          body = {
            'data': {
              'rows': [
                {
                  'id': 'c1',
                  'name': 'NJ Chimney',
                  'state': 'NJ',
                  'category': null,
                  'branch': null,
                  'spend_minor': 10050,
                  'leads': 4,
                  'cost_per_lead_minor': 2512,
                  'clicks': 10,
                  'impressions': 100,
                },
              ],
              'states': ['NJ'],
              'categories': [
                {'id': 'g1', 'name': 'Chimney'},
              ],
              'regions': ['New Jersey'],
            },
          };
        }
        return http.Response(
          jsonEncode(body),
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );
    tester.view.physicalSize = const Size(2000, 1600);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: MetaAdsReport(
            api: api,
            canEdit: true,
            now: () => DateTime(2026, 10, 14),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(asked.single, {'from': '2026-09-01', 'to': '2026-09-30'});
    expect(find.text('\$100.50'), findsOneWidget);
    expect(find.text('\$25.12'), findsOneWidget);
    expect(
      find.text('\$100.50 spent · 4 leads · \$25.12 per lead'),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const ValueKey('meta-region')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('New Jersey').last);
    await tester.pumpAndSettle();
    expect(asked.last['region'], 'New Jersey');

    await tester.tap(find.byKey(const ValueKey('meta-edit-campaigns')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('meta-use-suggestion-c1')));
    await tester.pumpAndSettle();
    expect(
      put.single,
      '/api/v1/reports/meta/campaigns/c1 {"service_group_id":"g1"}',
    );
  });

  testWidgets('without Build there is no campaign editor', (tester) async {
    final api = ApiClient(
      client: MockClient(
        (_) async => http.Response(
          jsonEncode({
            'data': {'rows': [], 'states': [], 'categories': [], 'regions': []},
          }),
          200,
          headers: {'content-type': 'application/json'},
        ),
      ),
    );
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(body: MetaAdsReport(api: api)),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('meta-edit-campaigns')), findsNothing);
  });
}
