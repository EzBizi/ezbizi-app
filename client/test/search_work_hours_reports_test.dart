import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/hr/work_hours_reports_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Work Hours › Reports: the search bar keeps the saved reports whose words
/// match. Every name invented.
void main() {
  Future<void> pump(
    WidgetTester tester, {
    Size size = const Size(1280, 900),
  }) async {
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = ApiClient(
      client: MockClient(
        (request) async => http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'rep-1',
                'name': 'Technicians weekly',
                'role_ids': ['r-1'],
                'include_user_ids': <String>[],
                'exclude_user_ids': <String>[],
                'recipients': ['boss@example.com'],
              },
              {
                'id': 'rep-2',
                'name': 'Office hours',
                'role_ids': <String>[],
                'include_user_ids': <String>[],
                'exclude_user_ids': <String>[],
                'recipients': <String>[],
              },
            ],
            'roles': [
              {'id': 'r-1', 'name': 'Technician'},
            ],
            'people': <Object>[],
          }),
          200,
          headers: const {'content-type': 'application/json'},
        ),
      ),
    );

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(body: WorkHoursReportsTab(api: api, canManage: true)),
      ),
    );
    await tester.pumpAndSettle();
  }

  const field = Key('work-hours-reports-search');

  testWidgets('typing keeps the matching report and hides the other', (
    tester,
  ) async {
    await pump(tester);
    expect(find.text('Technicians weekly'), findsOneWidget);
    expect(find.text('Office hours'), findsOneWidget);

    await tester.enterText(find.byKey(field), 'office');
    await tester.pumpAndSettle();

    expect(find.text('Office hours'), findsOneWidget);
    expect(find.text('Technicians weekly'), findsNothing);
  });

  testWidgets('a recipient is searchable too', (tester) async {
    await pump(tester);

    await tester.enterText(find.byKey(field), 'boss@');
    await tester.pumpAndSettle();

    expect(find.text('Technicians weekly'), findsOneWidget);
    expect(find.text('Office hours'), findsNothing);
  });

  testWidgets('a term matching nothing says so in one sentence', (
    tester,
  ) async {
    await pump(tester);

    await tester.enterText(find.byKey(field), 'zebra');
    await tester.pumpAndSettle();

    expect(find.text('No reports match "zebra".'), findsOneWidget);
    expect(find.text('Technicians weekly'), findsNothing);
    expect(find.text('Office hours'), findsNothing);
  });

  testWidgets('on a phone the field sits under the header without overflow', (
    tester,
  ) async {
    await pump(tester, size: const Size(390, 844));

    expect(find.byKey(field), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
