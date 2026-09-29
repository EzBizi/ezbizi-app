import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/jobs_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Jose, 2026-09-29: money in Jobs is "decided on roles y permissions as a
/// financials toggle". Without `jobs.financials` the server leaves every
/// amount out, and the screens must draw nothing rather than a dash in every
/// row or a "Total $0.00" nobody earned.
void main() {
  testWidgets('without Financials the list has no Amount column', (
    tester,
  ) async {
    await _open(tester, permissions: const ['jobs.view', 'jobs.edit']);

    expect(find.text('Amount'), findsNothing);
    expect(find.text('Chimney Sweep'), findsOneWidget, reason: 'the control');
  });

  testWidgets('with Financials the Amount column is there', (tester) async {
    await _open(
      tester,
      permissions: const ['jobs.view', 'jobs.edit', 'jobs.financials'],
    );

    expect(find.text('Amount'), findsOneWidget);
  });

  testWidgets('without Financials the Work section shows no Total', (
    tester,
  ) async {
    await _open(tester, permissions: const ['jobs.view', 'jobs.edit']);
    await tester.tap(find.text('Chimney Sweep'));
    await tester.pumpAndSettle();

    // The role and the performer are not money, so they stay.
    expect(find.text('Work'), findsOneWidget);
    expect(find.textContaining('Performed by'), findsOneWidget);
    expect(find.text('Total'), findsNothing);
  });
}

String _wall(String clock) =>
    '${formatCalendarDay(DateTime.now())}T$clock:00-05:00';

// What the server sends a person WITHOUT the toggle: no `amount`, no `lines`.
final _jobs = [
  {
    'id': '1',
    'number': 'JOB-1',
    'title': 'Chimney Sweep',
    'status': 'Pending',
    'status_label': 'Pending',
    'scheduled_for': _wall('08:00'),
    'franchise': {'id': 'fr-il', 'name': 'Illinois'},
    'customer': {
      'id': 'c-1',
      'name': 'Maria Delgado',
      'number': 'C-1',
      'owned_by_this_franchise': true,
    },
    'service_area': null,
  },
];

Future<void> _open(
  WidgetTester tester, {
  required List<String> permissions,
}) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/me')) {
        return _json({
          'data': {
            ..._me,
            'franchises': const [
              {'id': 'fr-il', 'name': 'Illinois', 'company_id': 'co-1'},
            ],
            'permissions': permissions,
          },
        });
      }
      if (path.endsWith('/exports')) {
        return _json({
          'resources': <dynamic>[],
          'organization': {'export_refusal': null, 'inventory_refusal': null},
        });
      }
      if (path.endsWith('/settings')) {
        return _json({
          'data': [
            {
              'key': 'jobs.statuses',
              'value': ['Pending', 'Done'],
            },
          ],
        });
      }
      if (path.contains('/jobs/')) {
        return _json({
          'data': {
            ..._jobs.first,
            'description': null,
            'duration_minutes': null,
            'allowed_statuses': ['Pending', 'Done'],
            'allowed_service_areas': <dynamic>[],
          },
        });
      }
      if (path.endsWith('/jobs')) {
        return _json({
          'data': _jobs,
          'meta': {'total': _jobs.length, 'last_page': 1, 'current_page': 1},
        });
      }
      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: JobsScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@example.com',
  'role': {'name': 'Dispatcher'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'A1-Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'A1-Group',
    'path': ['A1-Group'],
  },
  'companies': <dynamic>[],
};
