import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/scheduling/rescheduling_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Scheduling › Re-Scheduling: the search bar keeps the jobs whose words
/// match. Every name here is invented.
void main() {
  Map<String, dynamic> row(String id, String number, String customer) => {
    'area_id': 'a-mg',
    'area_name': 'Maple Grove',
    'job': {
      'kind': 'job',
      'job_id': 'ez-$id',
      'workiz_job_id': id,
      'lead_id': null,
      'number': number,
      'status': 'Scheduled',
      'time': '08:00',
      'end_time': '10:00',
      'service': 'Chimney sweep',
      'technicians': ['Sam Ortiz'],
      'notes': null,
      'customer': {
        'name': customer,
        'phone': '555-0142',
        'email': null,
        'address': '1180 Larkspur Ln',
      },
      'date': '2026-10-14',
      'latitude': 41.9,
      'longitude': -87.6,
    },
    'current_nearest_miles': 14.2,
    'suggested': {
      'date': '2026-10-16',
      'window': {'start_time': '10:00', 'end_time': '12:00', 'remaining': 2},
      'window_index': 1,
      'day': 'Thursday',
      'nearby': 3,
      'nearest_miles': 0.8,
      'technicians': ['Sam Ortiz'],
      'jobs': [
        {
          'latitude': 41.91,
          'longitude': -87.6,
          'miles': 0.8,
          'nearby': true,
          'customer': 'Leo Brandt',
          'time': '08:00',
        },
      ],
    },
  };

  Future<void> pump(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1280, 1000);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = ApiClient(
      client: MockClient((request) async {
        final body = request.url.path.endsWith('/scheduling/reschedules')
            ? {
                'data': [
                  row('job-1', '4127', 'Ana Ruiz'),
                  row('job-2', '4133', 'Ben Okafor'),
                ],
              }
            : {'data': null};
        return http.Response(
          jsonEncode(body),
          200,
          headers: const {'content-type': 'application/json'},
        );
      }),
    );

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: ReschedulingPane(
            api: api,
            session: Session(api),
            visible: true,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  const one = ValueKey('rescheduling-row-job-1');
  const two = ValueKey('rescheduling-row-job-2');

  testWidgets('typing keeps the matching job and hides the other', (
    tester,
  ) async {
    await pump(tester);
    expect(find.byKey(one), findsOneWidget);
    expect(find.byKey(two), findsOneWidget);

    await tester.enterText(
      find.byKey(const Key('rescheduling-search')),
      'okafor',
    );
    await tester.pumpAndSettle();

    expect(find.byKey(one), findsNothing);
    expect(find.byKey(two), findsOneWidget);
  });

  testWidgets('a term matching nothing says so in one sentence', (
    tester,
  ) async {
    await pump(tester);

    await tester.enterText(
      find.byKey(const Key('rescheduling-search')),
      'zebra',
    );
    await tester.pumpAndSettle();

    expect(find.text('No jobs match "zebra".'), findsOneWidget);
    expect(find.byKey(one), findsNothing);
    expect(find.byKey(two), findsNothing);
  });
}
