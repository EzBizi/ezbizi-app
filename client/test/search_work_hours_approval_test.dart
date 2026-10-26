import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/hr/work_hours_approval_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Work Hours › Daily approval: the search bar keeps the people whose name
/// or role matches. Every name and time invented.
void main() {
  Map<String, dynamic> person(String id, String name, String role) => {
    'person': {'id': id, 'name': name, 'role': role},
    'shifts': [
      {
        'id': 's-$id',
        'clocked_in': '8:00',
        'clocked_out': '16:00',
        'clocked_in_local': '2026-10-21 08:00',
        'clocked_out_local': '2026-10-21 16:00',
        'ended_by': 'person',
        'edited': null,
      },
    ],
    'worked_seconds': 8 * 3600,
    'scheduled': {'start': '08:00', 'end': '16:00'},
    'late_seconds': 0,
    'early_seconds': 0,
    'no_show': false,
    'status': 'waiting',
    'approved_by': null,
    'approved_at': null,
    'adjustments': <Object>[],
  };

  Future<void> pump(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1280, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = ApiClient(
      client: MockClient((request) async {
        final body = request.url.path.endsWith('/hr/time-clock/approvals')
            ? {
                'data': {
                  'date': '2026-10-21',
                  'label': 'Tuesday, Oct 21',
                  'counts': {'waiting': 2, 'approved': 0, 'no_shows': 0},
                  'rows': [
                    person('u-1', 'Pat Lin', 'Technician'),
                    person('u-2', 'Morgan Kay', 'Helper'),
                  ],
                },
              }
            : {'data': <Object>[]};
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
          body: WorkHoursApprovalTab(api: api, session: Session(api)),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  const field = Key('work-hours-approval-search');

  testWidgets('typing keeps the matching person and hides the other', (
    tester,
  ) async {
    await pump(tester);
    expect(find.text('Pat Lin'), findsOneWidget);
    expect(find.text('Morgan Kay'), findsOneWidget);

    await tester.enterText(find.byKey(field), 'morgan');
    await tester.pumpAndSettle();

    expect(find.text('Morgan Kay'), findsOneWidget);
    expect(find.text('Pat Lin'), findsNothing);
  });

  testWidgets('a term matching nothing says so in one sentence', (
    tester,
  ) async {
    await pump(tester);

    await tester.enterText(find.byKey(field), 'zebra');
    await tester.pumpAndSettle();

    expect(find.text('No people match "zebra".'), findsOneWidget);
    expect(find.text('Pat Lin'), findsNothing);
    expect(find.text('Morgan Kay'), findsNothing);
  });
}
