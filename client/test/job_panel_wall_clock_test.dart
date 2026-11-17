import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/jobs_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Saving a dated job from its panel sends back the date it was given.
///
/// ── The bug this pins, measured by UI/UX on 2026-09-24 ───────────
///
/// `_wallClock` in job_detail_panel.dart split the API's timestamp on
/// `[Z+-]` ANYWHERE in the string, so it stopped at the first hyphen of the
/// DATE: '2026-09-24T08:00:00-05:00' became "2026". Every shape did. So:
///   - "Scheduled for" was empty on every job's panel (nothing parses "2026");
///   - an untouched Save sent `scheduled_for: "2026"`;
///   - the server refused it, "must be a valid date".
/// No job with a date could be saved from its panel -- 8,162 of 8,185 on
/// production -- for any edit, status or notes included, unless somebody
/// retyped the date. It had been so since 38955ed6 (2026-08-19).
///
/// Every existing panel test used a job with NO date, so `_wallClock(null)`
/// returned '' and none of them could see it. The fixture below is dated, in
/// the exact shape the API sends.
void main() {
  testWidgets('an untouched save sends back the date the job already had', (
    tester,
  ) async {
    final patches = <Map<String, dynamic>>[];
    await _pump(tester, detail: _datedDetail, patches: patches);

    await tester.tap(find.text('Chimney sweep call'));
    await tester.pumpAndSettle();

    // Save waits for a change (design 196): change another field, so the
    // date rides along untouched.
    await tester.enterText(find.widgetWithText(TextField, 'Description'), 'Two flues');
    await tester.pumpAndSettle();
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    expect(patches, hasLength(1), reason: 'The control: Save really sent a PATCH.');
    final sent = patches.single['scheduled_for'] as String?;
    expect(sent, isNot('2026'), reason: 'The defect: the date truncated to its year.');
    expect(sent, startsWith('2026-09-24 08:00'),
        reason: 'The wall clock the job holds, offset stripped, digits kept.');
  });

  testWidgets('design 196: the record on a phone, status once, facts grid', (
    tester,
  ) async {
    await _pump(
      tester,
      detail: _datedDetail,
      patches: [],
      size: const Size(360, 800),
    );
    await tester.tap(find.text('Chimney sweep call'));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull, reason: 'No overflow at 360.');
    expect(find.byKey(const Key('job-status')), findsOneWidget);
    expect(find.widgetWithText(InputDecorator, 'Status'), findsOneWidget,
        reason: 'The status picker once; the Job tab no longer repeats it.');
    expect(find.byKey(const ValueKey('fact-Job #')), findsOneWidget);
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

const _datedDetail = {
  ..._detailBase,
  // Exactly the shape JobsController::present() sends: FranchiseWallTime,
  // toIso8601String(), with the franchise's offset on the end.
  'scheduled_for': '2026-09-24T08:00:00-05:00',
};

Future<void> _pump(
  WidgetTester tester, {
  required Map<String, dynamic> detail,
  required List<Map<String, dynamic>> patches,
  Size size = const Size(1280, 900),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/jobs/j-1')) {
        if (request.method == 'PATCH') {
          patches.add(jsonDecode(request.body) as Map<String, dynamic>);
        }
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
  'jobs.materials.view',
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
