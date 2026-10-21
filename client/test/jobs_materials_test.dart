import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/jobs_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Materials section of the job detail panel (spec §9).
///
/// What Inventory issued against this job, read-only and without money.
///
/// **The three states are three different sentences and the panel must not
/// collapse them.** The server sends `materials` as a list when the reader
/// holds `jobs.materials.view`, and omits the key entirely when they do not —
/// so "nothing was used on this job" and "you may not see what was used" arrive
/// as different payloads on purpose, and drawing an empty section for the
/// second would tell somebody a falsehood about the job rather than about their
/// own permissions.
void main() {
  testWidgets('materials issued against the job are listed without money', (
    tester,
  ) async {
    await _pump(tester, detail: _detailWithMaterials);

    await tester.tap(find.text('Chimney sweep call'));
    await tester.pumpAndSettle();

    expect(find.text('Materials'), findsOneWidget);
    expect(find.text('Stainless cap 8in'), findsOneWidget);
    expect(find.textContaining('2.5'), findsOneWidget);

    expect(find.text('2026-09-23'), findsOneWidget);

    // §9's no-money guarantee is NOT asserted here, deliberately, and the
    // reason is worth more than a weak version of it. The obvious client
    // check — no '$' anywhere on the panel — is false for an unrelated
    // reason: the job's own total is on screen and belongs there. Scoping it
    // to these rows instead would only confirm that a fixture I wrote myself
    // has no price in it, which is not evidence about the server. The
    // guarantee is asserted where it can be measured against a real payload,
    // by a subject holding `inventory.financials`: JobMaterialsTest.
  });

  testWidgets('a reader without the tab gets no Materials section at all', (
    tester,
  ) async {
    // No `materials` key — which is what the server sends to somebody holding
    // `jobs.view` and not `jobs.materials.view`.
    await _pump(tester, detail: _detailWithoutMaterialsKey);

    await tester.tap(find.text('Chimney sweep call'));
    await tester.pumpAndSettle();

    expect(find.text('Materials'), findsNothing);

    // The control: the DETAIL PANEL still opened. Without it this test passes
    // just as well against a panel that failed to load at all, which is the
    // failure it would otherwise be blind to.
    //
    // 'Description' rather than the job number, and the two wrong drafts are worth
    // the comment. `find.text('JOB-1042')` finds nothing — the panel's
    // subtitle joins number, customer and franchise with ' · '. And
    // `find.textContaining('JOB-1042')` finds TWO, because the list row
    // behind the panel carries the number as well, so it would have passed
    // with the panel closed and proved nothing. The Description field exists only
    // inside the loaded panel.
    expect(find.widgetWithText(TextField, 'Description'), findsOneWidget);
  });

  testWidgets('a job nothing was issued against says so rather than nothing', (
    tester,
  ) async {
    await _pump(tester, detail: _detailWithEmptyMaterials);

    await tester.tap(find.text('Chimney sweep call'));
    await tester.pumpAndSettle();

    // Drawn, with a sentence. An empty list means the reader MAY see the
    // materials and there are none — the opposite of the case above, and a
    // silent gap would read as the feature being broken.
    expect(find.text('Materials'), findsOneWidget);
    expect(find.text('Nothing has been issued against this job.'), findsOneWidget);
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

const _detailWithMaterials = {
  ..._detailBase,
  'materials': [
    {'item': 'Stainless cap 8in', 'qty': 2.5, 'issued_on': '2026-09-23'},
  ],
};

const _detailWithEmptyMaterials = {
  ..._detailBase,
  'materials': <Map<String, dynamic>>[],
};

const _detailWithoutMaterialsKey = _detailBase;

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
