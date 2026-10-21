import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/jobs_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Inspections section of the job detail panel (spec §10).
///
/// The reports submitted on this job, read-only. The server sends
/// `inspections` only to somebody holding Inspection's own `inspection.view` —
/// there is no Jobs tab key for it, because a job must not become a way past
/// InspectionReportPolicy — and omits the key for everybody else. So, as with
/// Materials, three states are three different sentences: listed, "none have
/// been submitted", and absent because the reader may not see reports at all.
void main() {
  testWidgets('the inspection reports on the job are listed', (tester) async {
    await _pump(tester, detail: _detailWithInspections);

    await tester.tap(find.text('Chimney sweep call'));
    await tester.pumpAndSettle();

    expect(find.text('Inspections'), findsOneWidget);
    expect(find.text('Level 2 chimney inspection'), findsOneWidget);
    // The date the report was submitted, as the API sends it, date part only.
    expect(find.text('2026-09-20'), findsOneWidget);
  });

  testWidgets('a reader Inspection refuses gets no Inspections section', (
    tester,
  ) async {
    await _pump(tester, detail: _detailWithoutInspectionsKey);

    await tester.tap(find.text('Chimney sweep call'));
    await tester.pumpAndSettle();

    expect(find.text('Inspections'), findsNothing);
    // The control, and the one that works: only the loaded panel has a
    // Description field. See jobs_materials_test.dart for the two drafts that did not.
    expect(find.widgetWithText(TextField, 'Description'), findsOneWidget);
  });

  testWidgets('a job with no inspection says so rather than nothing', (
    tester,
  ) async {
    await _pump(tester, detail: _detailWithEmptyInspections);

    await tester.tap(find.text('Chimney sweep call'));
    await tester.pumpAndSettle();

    expect(find.text('Inspections'), findsOneWidget);
    expect(
      find.text('No inspection has been submitted on this job.'),
      findsOneWidget,
    );
  });
}

const _crown = {
  'id': 'j-1',
  'number': 'JOB-1042',
  'title': 'Chimney sweep call',
  'status': 'scheduled',
  'franchise': {'id': 'fr-1', 'name': 'Connecticut'},
  'customer': {
    'id': 'c-1',
    'name': 'Alice Roofing',
    'number': 'C-1001',
    'owned_by_this_franchise': true,
  },
  'amount': {'minor': 31400, 'currency': 'USD', 'display': r'$314.00'},
  'assigned_role': {'id': 'r-1', 'name': 'Chimney Technician'},
};

const _detailBase = {
  ..._crown,
  'description': null,
  'duration_minutes': null,
  'allowed_statuses': ['scheduled', 'completed'],
  'lines': <Map<String, dynamic>>[],
  'performed_by': null,
  'earned_at': null,
};

const _detailWithInspections = {
  ..._detailBase,
  'inspections': [
    {
      'id': 'ir-1',
      'name': 'Level 2 chimney inspection',
      'submitted_at': '2026-09-20T14:00:00-04:00',
    },
  ],
};

const _detailWithEmptyInspections = {
  ..._detailBase,
  'inspections': <Map<String, dynamic>>[],
};

const _detailWithoutInspectionsKey = _detailBase;

Future<void> _pump(
  WidgetTester tester, {
  required Map<String, dynamic> detail,
  Size size = const Size(1280, 900),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (request.method == 'GET' && path.endsWith('/jobs/j-1')) {
        return _json({'data': detail});
      }

      if (path.endsWith('/me')) {
        return _json({
          'data': {..._me, 'permissions': _permissions},
        });
      }

      if (path.endsWith('/exports')) {
        return _json({
          'resources': [
            {
              'key': 'jobs',
              'label': 'Jobs',
              'columns': ['number', 'title'],
              'importable': false,
            },
          ],
          'organization': {
            'export_refusal': null,
            'inventory_refusal': null,
          },
        });
      }

      if (path.endsWith('/settings')) {
        return _json({
          'data': [
            {
              'key': 'jobs.statuses',
              'value': ['scheduled', 'completed'],
            },
          ],
        });
      }

      if (path.endsWith('/jobs')) {
        return _json({
          'data': [_crown],
          'meta': {'total': 1, 'last_page': 1},
        });
      }

      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: JobsScreen(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _permissions = [
  'jobs.view',
  'jobs.create',
  'jobs.edit',
  'jobs.delete',
  'inspection.view',
];

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
  'companies': <dynamic>[],
  'franchises': [
    {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
  ],
};
