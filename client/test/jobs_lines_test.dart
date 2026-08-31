import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/jobs/job_lines_tab.dart';
import 'package:ezbizi_client/screens/jobs_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Work section of the job detail panel: role, who did it, the service
/// lines, and a total that is summed from them rather than typed.
///
/// `jobs_screen_test.dart` already covers opening the panel and deleting a
/// job; this file is scoped to what Task 8 added underneath the Notes field.
void main() {
  testWidgets(
    'the total is shown as the sum of the lines, not an editable field',
    (tester) async {
      await _pump(
        tester,
        onRequest: (request) {
          if (request.method == 'GET' &&
              request.url.path.endsWith('/jobs/j-1')) {
            return _json({'data': _crownDetail});
          }
          return null;
        },
      );

      await tester.tap(find.text('Chimney sweep call'));
      await tester.pumpAndSettle();

      expect(find.text('Chimney sweep'), findsOneWidget);
      expect(find.text('Level 1 inspection'), findsOneWidget);
      expect(find.text(r'$314.00'), findsOneWidget);
      expect(find.text('Summed from 2 lines.'), findsOneWidget);

      // The old typed-price field must be gone: a total you can type is the
      // bug this module was built to remove.
      expect(find.text('Price'), findsNothing);
    },
  );

  testWidgets(
    'a total that predates any line does not claim to be summed from lines',
    (tester) async {
      // A job priced before it had lines — old data from before the Price
      // field was removed from Create, or a job still waiting on its first
      // line. `lines` is empty but `amount` is not zero: "Summed from 0
      // lines." would be false on this exact number, so it must not appear.
      await _pump(
        tester,
        onRequest: (request) {
          if (request.method == 'GET' &&
              request.url.path.endsWith('/jobs/j-1')) {
            return _json({'data': _crownDetailPriceOnly});
          }
          return null;
        },
      );

      await tester.tap(find.text('Chimney sweep call'));
      await tester.pumpAndSettle();

      expect(find.text(r'$314.00'), findsOneWidget);
      expect(
        find.text('Set before this job had any service lines.'),
        findsOneWidget,
      );
      expect(find.textContaining('Summed from'), findsNothing);
    },
  );

  testWidgets('adding a service does not open a dialog', (tester) async {
    await _pump(
      tester,
      onRequest: (request) {
        if (request.method == 'GET' &&
            request.url.path.endsWith('/jobs/j-1')) {
          return _json({'data': _crownDetail});
        }
        if (request.url.path.endsWith('/services')) {
          return _json({
            'data': _services,
            'meta': {'total': 1, 'last_page': 1},
          });
        }
        return null;
      },
    );

    await tester.tap(find.text('Chimney sweep call'));
    await tester.pumpAndSettle();

    await tester.ensureVisible(find.byKey(const Key('job-add-line')));
    await tester.tap(find.byKey(const Key('job-add-line')));
    await tester.pumpAndSettle();

    expect(find.byType(Dialog), findsNothing);
    expect(find.byType(AlertDialog), findsNothing);
    expect(find.byKey(const Key('job-line-service-picker')), findsOneWidget);
  });

  testWidgets(
    'adding a line posts service_id and qty, then shows the recomputed total',
    (tester) async {
      var added = false;
      final requests = <String>[];

      await _pump(
        tester,
        onRequest: (request) {
          requests.add('${request.method} ${request.url.path}');

          if (request.method == 'GET' &&
              request.url.path.endsWith('/jobs/j-1')) {
            return _json({'data': added ? _crownDetailWithLine3 : _crownDetail});
          }
          if (request.url.path.endsWith('/services')) {
            return _json({
              'data': _services,
              'meta': {'total': 1, 'last_page': 1},
            });
          }
          if (request.method == 'POST' &&
              request.url.path.endsWith('/jobs/j-1/lines')) {
            expect(jsonDecode(request.body), {'service_id': 's3', 'qty': 2});
            added = true;
            return _json({'data': _line3}, 201);
          }
          return null;
        },
      );

      await tester.tap(find.text('Chimney sweep call'));
      await tester.pumpAndSettle();

      await tester.ensureVisible(find.byKey(const Key('job-add-line')));
      await tester.tap(find.byKey(const Key('job-add-line')));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('job-line-service-picker')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Flue liner replacement').last);
      await tester.pumpAndSettle();

      await tester.enterText(find.byKey(const Key('job-line-qty-field')), '2');
      await tester.pumpAndSettle();

      await tester.ensureVisible(find.byKey(const Key('job-line-add-confirm')));
      await tester.tap(find.byKey(const Key('job-line-add-confirm')));
      await tester.pumpAndSettle();

      expect(requests, contains('POST /api/v1/jobs/j-1/lines'));
      expect(find.text('Flue liner replacement'), findsOneWidget);
      expect(find.text(r'$1,214.00'), findsOneWidget);
    },
  );

  testWidgets('the quantity stepper patches the line and refreshes the total', (
    tester,
  ) async {
    var patched = false;
    final requests = <String>[];

    await _pump(
      tester,
      onRequest: (request) {
        requests.add('${request.method} ${request.url.path}');

        if (request.method == 'GET' &&
            request.url.path.endsWith('/jobs/j-1')) {
          return _json({'data': patched ? _crownDetailQtyBumped : _crownDetail});
        }
        if (request.method == 'PATCH' &&
            request.url.path.endsWith('/jobs/j-1/lines/l1')) {
          expect(jsonDecode(request.body), {'qty': 2});
          patched = true;
          return _json({'data': _line1Bumped});
        }
        return null;
      },
    );

    await tester.tap(find.text('Chimney sweep call'));
    await tester.pumpAndSettle();

    await tester.ensureVisible(find.byKey(const Key('job-line-l1-inc')));
    await tester.tap(find.byKey(const Key('job-line-l1-inc')));
    await tester.pumpAndSettle();

    expect(requests, contains('PATCH /api/v1/jobs/j-1/lines/l1'));
    expect(find.text(r'$503.00'), findsOneWidget);
  });

  testWidgets('removing a line deletes it and refreshes the total', (
    tester,
  ) async {
    var removed = false;
    final requests = <String>[];

    await _pump(
      tester,
      onRequest: (request) {
        requests.add('${request.method} ${request.url.path}');

        if (request.method == 'GET' &&
            request.url.path.endsWith('/jobs/j-1')) {
          return _json({'data': removed ? _crownDetailLine2Only : _crownDetail});
        }
        if (request.method == 'DELETE' &&
            request.url.path.endsWith('/jobs/j-1/lines/l1')) {
          removed = true;
          return _json({'message': 'Line removed.'});
        }
        return null;
      },
    );

    await tester.tap(find.text('Chimney sweep call'));
    await tester.pumpAndSettle();

    await tester.ensureVisible(find.byKey(const Key('job-line-l1-remove')));
    await tester.tap(find.byKey(const Key('job-line-l1-remove')));
    await tester.pumpAndSettle();

    expect(requests, contains('DELETE /api/v1/jobs/j-1/lines/l1'));
    expect(find.text('Chimney sweep'), findsNothing);
    expect(find.text(r'$125.00'), findsOneWidget);
  });

  testWidgets(
    'the Work section shows the assigned role and who performed the work',
    (tester) async {
      await _pump(
        tester,
        onRequest: (request) {
          if (request.method == 'GET' &&
              request.url.path.endsWith('/jobs/j-1')) {
            return _json({'data': _crownDetailPerformed});
          }
          return null;
        },
      );

      await tester.tap(find.text('Chimney sweep call'));
      await tester.pumpAndSettle();

      // Scoped to the Work section: the row underneath, still mounted behind
      // the panel, carries its own role tag and would otherwise double the
      // count — the same trap `jobs_screen_test.dart` guards against.
      final work = find.byType(JobLinesTab);
      expect(work, findsOneWidget);
      expect(
        find.descendant(of: work, matching: find.text('Chimney Technician')),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: work,
          matching: find.text('Performed by Sam Diaz'),
        ),
        findsOneWidget,
      );
    },
  );

  testWidgets('the Work section fits at 375px, add form included', (
    tester,
  ) async {
    await _pump(
      tester,
      size: const Size(375, 800),
      onRequest: (request) {
        if (request.method == 'GET' &&
            request.url.path.endsWith('/jobs/j-1')) {
          return _json({'data': _crownDetail});
        }
        if (request.url.path.endsWith('/services')) {
          return _json({
            'data': _services,
            'meta': {'total': 1, 'last_page': 1},
          });
        }
        return null;
      },
    );

    await tester.tap(find.text('Chimney sweep call'));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);

    await tester.ensureVisible(find.byKey(const Key('job-add-line')));
    await tester.tap(find.byKey(const Key('job-add-line')));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
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

const _crownLines = [
  {
    'id': 'l1',
    'service_id': 's1',
    'name': 'Chimney sweep',
    'unit_price_minor': 18900,
    'qty': 1,
    'amount_minor': 18900,
  },
  {
    'id': 'l2',
    'service_id': 's2',
    'name': 'Level 1 inspection',
    'unit_price_minor': 12500,
    'qty': 1,
    'amount_minor': 12500,
  },
];

const _crownDetail = {
  ..._crown,
  'description': null,
  'duration_minutes': null,
  'allowed_statuses': ['scheduled', 'completed'],
  'lines': _crownLines,
  'performed_by': null,
  'earned_at': null,
};

/// A job priced the old way — a typed amount, zero lines. `JobsController`
/// never recomputes the total for a job that has none, so this is not a
/// transient state: it is what every job created before Task 8 removed the
/// Create form's Price field looks like forever, unless a line is added.
final _crownDetailPriceOnly = {
  ..._crown,
  'description': null,
  'duration_minutes': null,
  'allowed_statuses': ['scheduled', 'completed'],
  'lines': const <Map<String, dynamic>>[],
  'performed_by': null,
  'earned_at': null,
};

const _line3 = {
  'id': 'l3',
  'service_id': 's3',
  'name': 'Flue liner replacement',
  'unit_price_minor': 45000,
  'qty': 2,
  'amount_minor': 90000,
  'position': 3,
};

final _crownDetailWithLine3 = {
  ..._crown,
  'amount': {'minor': 121400, 'currency': 'USD', 'display': r'$1,214.00'},
  'description': null,
  'duration_minutes': null,
  'allowed_statuses': ['scheduled', 'completed'],
  'lines': [..._crownLines, _line3],
  'performed_by': null,
  'earned_at': null,
};

const _services = [
  {'id': 's3', 'name': 'Flue liner replacement', 'price_minor': 45000},
];

const _line1Bumped = {
  'id': 'l1',
  'service_id': 's1',
  'name': 'Chimney sweep',
  'unit_price_minor': 18900,
  'qty': 2,
  'amount_minor': 37800,
  'position': 1,
};

final _crownDetailQtyBumped = {
  ..._crown,
  'amount': {'minor': 50300, 'currency': 'USD', 'display': r'$503.00'},
  'description': null,
  'duration_minutes': null,
  'allowed_statuses': ['scheduled', 'completed'],
  'lines': [_line1Bumped, _crownLines[1]],
  'performed_by': null,
  'earned_at': null,
};

final _crownDetailLine2Only = {
  ..._crown,
  'amount': {'minor': 12500, 'currency': 'USD', 'display': r'$125.00'},
  'description': null,
  'duration_minutes': null,
  'allowed_statuses': ['scheduled', 'completed'],
  'lines': [_crownLines[1]],
  'performed_by': null,
  'earned_at': null,
};

final _crownDetailPerformed = {
  ..._crownDetail,
  'performed_by': {'id': 'u-9', 'name': 'Sam Diaz'},
};

Future<void> _pump(
  WidgetTester tester, {
  required http.Response? Function(http.Request request) onRequest,
  Size size = const Size(1280, 900),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final override = onRequest(request);
      if (override != null) return override;

      final path = request.url.path;

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

const _permissions = ['jobs.view', 'jobs.create', 'jobs.edit', 'jobs.delete'];

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
