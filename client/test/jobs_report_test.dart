import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/screens/reports/jobs_report.dart';
import 'package:ezbizi_client/widgets/date_range_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Reports › Jobs (Jose, 2026-11-11): every job created, both ids, the
/// number dialed, filtered and counted by the server, a page at a time.
void main() {
  Map<String, dynamic> row(String workiz, {String? ezbizi, String? dialed}) => {
    'ezbizi_number': ezbizi,
    'ezbizi_syncing': ezbizi == null,
    'workiz_id': workiz,
    'workiz_number': '6000$workiz',
    'created_at': '2026-11-10 09:05:00',
    'client': 'Ann Lee',
    'phone': '+12125550101',
    'branch': 'Ohio',
    'service_area': 'Cleveland',
    'source': 'LS Ads',
    'dialed': dialed,
    'dialed_name': dialed == null ? null : 'Ace Fireplace · Akron',
    'called_at': dialed == null ? null : '2026-11-10 08:55:00',
    'job_type': 'Chimney Sweep',
    'status': 'Submitted',
    'total': 300.5,
    'created_by': 'Dov',
  };

  testWidgets('both ids, the number dialed, the count and total, then more', (
    tester,
  ) async {
    final asked = <Map<String, String>>[];
    final api = ApiClient(
      client: MockClient((request) async {
        final q = request.url.queryParameters;
        asked.add(q);
        final next = q['offset'] != '0';
        return http.Response(
          jsonEncode({
            'data': {
              'rows': next
                  ? [row('J2')]
                  : [row('J1', ezbizi: '1042', dialed: '+13305550000')],
              'count': 2,
              'total': 601,
              'sources': [
                {'value': 'LS Ads', 'count': 2},
              ],
              'areas': [
                {'value': 'Cleveland', 'count': 2},
              ],
              'dialed': [
                {
                  'value': '+13305550000',
                  'count': 1,
                  'name': 'Ace Fireplace · Akron',
                },
                {'value': 'none', 'count': 1, 'name': null},
              ],
              'calls_read': true,
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );
    tester.view.physicalSize = const Size(2400, 1400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: JobsReport(
            api: api,
            span: DateSpan(DateTime(2026, 11, 9), DateTime(2026, 11, 10)),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(asked.single['from'], '2026-11-09');
    expect(asked.single['to'], '2026-11-10');
    expect(find.text('1042'), findsOneWidget);
    expect(find.text('6000J1'), findsOneWidget);
    expect(find.textContaining('Ace Fireplace · Akron'), findsWidgets);
    expect(find.text('\$601.00'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('jobs-more')));
    await tester.pumpAndSettle();

    expect(asked.last['offset'], '1');
    expect(find.text('6000J2'), findsOneWidget);
    expect(find.text('6000J1'), findsOneWidget);
    // Called at beside Number dialed (Jose 11-12); a job just in from Workiz says Syncing.
    expect(find.text('Called at'), findsOneWidget);
    expect(find.text('2026-11-10 08:55'), findsOneWidget);
    expect(find.text('Syncing'), findsOneWidget);
    expect(find.byKey(const ValueKey('jobs-more')), findsNothing);
  });

  testWidgets(
    'Number dialed takes several, found by typing, with No call found '
    '(Jose 11-12: "Multiselect", "search for the sources and lines too")',
    (tester) async {
      final asked = <Map<String, List<String>>>[];
      final api = ApiClient(
        client: MockClient((request) async {
          asked.add(request.url.queryParametersAll);
          return http.Response(
            jsonEncode({
              'data': {
                'rows': [row('J1', ezbizi: '1042', dialed: '+12163077555')],
                'count': 1,
                'total': 300.5,
                'sources': [
                  {'value': 'LS Ads', 'count': 1},
                ],
                'areas': const [],
                'dialed': [
                  {
                    'value': '+12163077555',
                    'count': 11,
                    'name': 'CLE - LS Ads',
                  },
                  {'value': '+12162290000', 'count': 2, 'name': 'CLE - PPC'},
                  {'value': '+18562908555', 'count': 3, 'name': 'NJ - PPC'},
                  {'value': 'none', 'count': 62, 'name': null},
                ],
                'calls_read': true,
              },
            }),
            200,
            headers: {'content-type': 'application/json'},
          );
        }),
      );
      tester.view.physicalSize = const Size(2400, 1400);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: JobsReport(
              api: api,
              span: DateSpan(DateTime(2026, 11, 12), DateTime(2026, 11, 12)),
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(asked.single['dialed[]'], isNull);

      await tester.tap(find.byKey(const ValueKey('jobs-dialed')));
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextField).last, 'cle');
      await tester.pumpAndSettle();
      expect(find.textContaining('NJ - PPC'), findsNothing);
      await tester.tap(find.byKey(const ValueKey('search-multi-select-shown')));
      await tester.enterText(find.byType(TextField).last, '');
      await tester.pumpAndSettle();
      await tester.tap(find.textContaining('No call found'));
      await tester.tap(find.text('Done'));
      await tester.pumpAndSettle();

      expect(
        asked.last['dialed[]'],
        unorderedEquals(['+12163077555', '+12162290000', 'none']),
      );
      expect(asked.last['source[]'], isNull);
    },
  );
}
