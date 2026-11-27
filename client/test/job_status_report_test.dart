import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/screens/reports/job_status_report.dart';
import 'package:ezbizi_client/widgets/date_range_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Reports › Cancelled jobs and Pending jobs (Shoval, 2026-11-18).
void main() {
  Map<String, dynamic> job(String number, {int age = 3}) => {
    'job_id': 'id-$number',
    'number': number,
    'workiz_number': '6001',
    'created_at': '2026-11-09 10:00:00',
    'since': '2026-11-09 18:00:00',
    'age_days': age,
    'client': 'Ann Lee',
    'phone': '+12125550101',
    'branch': 'Alpha · Ohio',
    'source': 'PPC',
    'job_type': 'Sweep',
    'status': 'Canceled',
    'reason': 'Estimate Declined',
    'techs': ['Ron', 'Sam'],
    'value_minor': 15000,
    'created_by': 'Dov',
  };

  Future<List<Map<String, String>>> pump(
    WidgetTester tester, {
    required bool pending,
  }) async {
    tester.view.physicalSize = const Size(2400, 1400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final asked = <Map<String, String>>[];
    final api = ApiClient(
      client: MockClient((request) async {
        asked.add({'path': request.url.path, ...request.url.queryParameters});
        return http.Response(
          jsonEncode({
            'data': {
              'rows': [job('A-1', age: 41), job('B-1')],
              'count': 2,
              'value_minor': 30000,
              'all': 2,
              'created': 8,
              'statuses': [pending ? 'pending' : 'canceled'],
              'reasons': [
                {'value': 'Estimate Declined', 'count': 2},
              ],
              'sources': [
                {'value': 'PPC', 'count': 2},
              ],
              'techs': [
                {'value': 'Ron', 'count': 2},
              ],
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: JobStatusReport(
            api: api,
            pending: pending,
            span: DateSpan(DateTime(2026, 11, 9), DateTime(2026, 11, 10)),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    return asked;
  }

  testWidgets('Cancelled: the dates, the rate of jobs created, each job', (
    tester,
  ) async {
    final asked = await pump(tester, pending: false);

    expect(asked.single['path'], endsWith('/reports/cancelled'));
    expect(asked.single['from'], '2026-11-09');
    expect(asked.single['to'], '2026-11-10');
    // Two of eight created: 25%.
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('cancelled-rate')),
        matching: find.text('25%'),
      ),
      findsOneWidget,
    );
    final table = find.byKey(const ValueKey('cancelled-table'));
    for (final text in ['A-1', 'Estimate Declined', 'Ron, Sam', r'$150.00']) {
      expect(
        find.descendant(of: table, matching: find.text(text)),
        findsWidgets,
        reason: text,
      );
    }
    expect(find.text('Cancelled at'), findsOneWidget);
    expect(find.text('Age (days)'), findsNothing);
  });

  testWidgets('Pending: no dates asked, how long each has waited', (
    tester,
  ) async {
    final asked = await pump(tester, pending: true);

    expect(asked.single['path'], endsWith('/reports/pending'));
    expect(asked.single.containsKey('from'), isFalse);
    final table = find.byKey(const ValueKey('pending-table'));
    expect(find.descendant(of: table, matching: find.text('41')), findsOne);
    expect(find.text('41 days'), findsOneWidget);
    expect(find.text('Pending since'), findsOneWidget);
  });
}
