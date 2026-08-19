import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/jobs_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Open, search, filter, and a customer-search failure that is not "no such
/// customer". The API already knew how to edit and delete a job.
void main() {
  testWidgets('searching asks the list, and a job-number miss says so', (
    tester,
  ) async {
    final searches = <String>[];

    await _pump(
      tester,
      onRequest: (request) {
        if (request.url.path.endsWith('/jobs')) {
          searches.add(request.url.queryParameters['search'] ?? '');
          return _json({
            'data': <dynamic>[],
            'meta': {'total': 0, 'last_page': 1},
          });
        }
        return null;
      },
    );

    await tester.enterText(find.byType(TextField).first, 'J-1042');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();

    expect(searches, contains('J-1042'));
    expect(find.text('Job not found.'), findsOneWidget);
  });

  testWidgets('opening a row loads the job and can delete it', (tester) async {
    final paths = <String>[];

    await _pump(
      tester,
      jobs: [_crown],
      onRequest: (request) {
        paths.add('${request.method} ${request.url.path}');

        if (request.method == 'DELETE' &&
            request.url.path.endsWith('/jobs/j-1')) {
          return _json({'message': 'Job deleted.'});
        }

        return null;
      },
    );

    await tester.tap(find.text('Crown inspection'));
    await tester.pumpAndSettle();

    expect(find.textContaining('J-1001'), findsWidgets);
    expect(find.text('Scheduled for'), findsOneWidget);
    expect(find.text('Delete'), findsOneWidget);

    await tester.ensureVisible(find.text('Delete'));
    await tester.tap(find.text('Delete'));
    await tester.pumpAndSettle();

    expect(paths.any((p) => p == 'DELETE /api/v1/jobs/j-1'), isTrue);
  });

  testWidgets('a failed customer search is not an empty result', (tester) async {
    await _pump(tester);

    await tester.tap(find.text('Add job'));
    await tester.pumpAndSettle();

    final customerField = find.widgetWithText(TextField, 'Customer');
    expect(customerField, findsOneWidget);

    await tester.enterText(customerField, 'smith');
    await tester.pumpAndSettle();

    expect(
      find.textContaining('Could not search customers'),
      findsOneWidget,
      reason:
          'An empty suggestion list reads as "no such customer". The '
          'request failed — that has to be said.',
    );
  });
}

const _crown = {
  'id': 'j-1',
  'number': 'J-1001',
  'title': 'Crown inspection',
  'status': 'scheduled',
  'scheduled_for': '2026-03-08T09:00:00',
  'franchise': {'id': 'fr-1', 'name': 'Connecticut'},
  'customer': {
    'id': 'c-1',
    'name': 'Alice Roofing',
    'number': 'C-1001',
    'owned_by_this_franchise': true,
  },
  'amount': {'minor': 19999, 'currency': 'USD', 'display': '199.99'},
};

const _crownDetail = {
  ..._crown,
  'description': 'Check the crown.',
  'duration_minutes': 60,
  'allowed_statuses': ['scheduled', 'completed'],
};

Future<void> _pump(
  WidgetTester tester, {
  List<String> permissions = const [
    'jobs.view',
    'jobs.create',
    'jobs.edit',
    'jobs.delete',
  ],
  List<Map<String, dynamic>> jobs = const [],
  http.Response? Function(http.Request request)? onRequest,
}) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final override = onRequest?.call(request);
      if (override != null) return override;

      final path = request.url.path;

      if (path.endsWith('/me')) {
        return _json({
          'data': {..._me, 'permissions': permissions},
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

      if (path.endsWith('/jobs/j-1')) {
        return _json({'data': _crownDetail});
      }

      if (path.endsWith('/jobs')) {
        return _json({
          'data': jobs,
          'meta': {'total': jobs.length, 'last_page': 1},
        });
      }

      if (path.endsWith('/customers') &&
          (request.url.queryParameters['search'] ?? '').isNotEmpty) {
        return _json({'message': 'The mail service stopped answering.'}, 502);
      }

      if (path.endsWith('/customers')) {
        return _json({
          'data': <dynamic>[],
          'meta': {'total': 0, 'last_page': 1},
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
