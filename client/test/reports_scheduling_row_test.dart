import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/reports/overbooking_report_panel.dart';
import 'package:ezbizi_client/screens/reports_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Scheduling's Overbooking warning in the library (Jose, 2026-10-01: "it
/// should show there"). Its row comes from Scheduling's own list, under its
/// own grant, and opens UI/UX's panel beside the table.
void main() {
  testWidgets('the overbooking warning is listed and opens beside the list', (
    tester,
  ) async {
    final asked = await _pump(tester);

    expect(asked, contains('/api/v1/scheduling/reports'));
    expect(find.text('Overbooking warning'), findsOneWidget);
    expect(find.text('Every service area'), findsOneWidget);
    expect(find.text('Scheduling'), findsWidgets); // the pill and the chip

    await tester.tap(find.text('Overbooking warning'));
    await tester.pumpAndSettle();

    expect(find.byType(OverbookingReportPanel), findsOneWidget);
  });

  testWidgets(
    'without the Overbooking grant it is neither asked for nor drawn',
    (tester) async {
      final asked = await _pump(
        tester,
        permissions: const ['reports.view', 'reports.library.view'],
      );

      expect(asked, isNot(contains('/api/v1/scheduling/reports')));
      expect(find.text('Overbooking warning'), findsNothing);
    },
  );

  testWidgets('a refused Scheduling list leaves the Builder reports drawn', (
    tester,
  ) async {
    await _pump(tester, refuseScheduling: true);

    expect(find.text('Open jobs'), findsOneWidget);
    expect(find.text('No, not Scheduling.'), findsOneWidget);
  });

  testWidgets(
    'only the warning counts as a report: the empty state stays away',
    (tester) async {
      await _pump(tester, built: const []);

      expect(find.text('Overbooking warning'), findsOneWidget);
      expect(find.textContaining('No reports built yet'), findsNothing);
    },
  );
}

Future<List<String>> _pump(
  WidgetTester tester, {
  List<String> permissions = const [
    'reports.view',
    'reports.library.view',
    'scheduling.rescheduling.view',
  ],
  List<Map<String, dynamic>> built = const [_built],
  bool refuseScheduling = false,
}) async {
  tester.view.physicalSize = const Size(1400, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final asked = <String>[];

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) {
        return _json({
          'data': {
            ..._me,
            'permissions': permissions,
            'modules': <Object>[],
            'display': {'date_format': 'M/D/YYYY', 'time_format': '12h'},
          },
        });
      }
      asked.add(path);

      if (path == '/api/v1/scheduling/reports') {
        return refuseScheduling
            ? _json({'message': 'No, not Scheduling.'}, 403)
            : _json({
                'data': const [_warning],
              });
      }
      if (path == '/api/v1/reports') return _json({'data': built});
      if (path.endsWith('/scheduling/overbooking')) {
        return _json({
          'data': {'overbooked': const [], 'fully_booked': const []},
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
        body: ReportsScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();

  return asked;
}

const _built = {
  'id': 'rep-1',
  'name': 'Open jobs',
  'description': 'Everything still open.',
  'company_id': 'co-1',
  'franchise_id': null,
  'definition': {'root': 'jobs', 'columns': [], 'filters': []},
  'root_label': 'Jobs',
  'sent_automatically': false,
  'sent_by': null,
};

const _warning = {
  'id': 'overbooking',
  'name': 'Overbooking warning',
  'description': 'Service areas overbooked or fully booked in the next 7 days',
  'covers': 'Every service area',
  'sent_automatically': true,
  'sent_by': null,
};

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@example.com',
  'role': {'name': 'Manager'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Everdoors',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Everdoors',
    'path': ['Everdoors'],
  },
};

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
