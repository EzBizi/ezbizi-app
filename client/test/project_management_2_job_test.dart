import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/project_management_2_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Project Management - 2 (Jose, 2026-10-27): Pending, Paid and Total in
/// place of "money pending" and "money owed", and a row opens the job with
/// its timeline.
void main() {
  testWidgets('a row opens the job: its money and its timeline', (
    tester,
  ) async {
    final row = {
      'id': 'j-1',
      'job_number': '4471',
      'customer': 'Omar Castillo',
      'branch': 'Ohio',
      'company': 'Brightside',
      'status': 'Done pending approval',
      'tags': ['DEPOSIT'],
      'address': '12 Elm St, Akron OH',
      'created': '2026-10-01',
      'modified': '2026-10-02',
      'pending': 80000,
      'paid': 40000,
      'total': 120000,
      'currency': 'USD',
    };
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) {
          return _json({
            'data': {
              'id': 'u-1',
              'name': 'Dana',
              'email': 'dana@brightside.example',
              'permissions': [
                'project_management_2.view',
                'jobs.view',
                'jobs.financials',
              ],
              'modules': <dynamic>[],
              'organization': {'id': 'org-1', 'name': 'Brightside'},
              'scope': {
                'type': 'organization',
                'id': null,
                'label': 'Brightside',
                'path': ['Brightside'],
              },
            },
          });
        }
        if (path.endsWith('/project-management-2/jobs/j-1')) {
          return _json({
            'data': {
              ...row,
              'timeline': [
                {
                  'kind': 'created',
                  'at': '2026-10-01T10:00:00Z',
                  'what': 'Job created',
                },
                {
                  'kind': 'payment',
                  'at': '2026-10-05T10:00:00Z',
                  'what': 'Payment',
                  'amount': 40000,
                },
                {
                  'kind': 'tags',
                  'at': '2026-10-06T10:00:00Z',
                  'what': 'Tags +DEPOSIT',
                  'by': 'Ada',
                },
              ],
            },
          });
        }
        return _json({
          'data': [row],
          'meta': {'total': 1, 'last_page': 1},
        });
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    tester.view.physicalSize = const Size(1400, 1000);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: ProjectManagement2Screen(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();

    for (final heading in ['PENDING', 'PAID', 'TOTAL']) {
      expect(
        find.textContaining(RegExp(heading, caseSensitive: false)),
        findsWidgets,
      );
    }
    expect(find.text(r'$800.00'), findsOneWidget);
    expect(find.textContaining('MONEY OWED'), findsNothing);

    await tester.tap(find.text('Omar Castillo'));
    await tester.pumpAndSettle();

    expect(find.text('Timeline'), findsOneWidget);
    expect(find.text('Job created'), findsOneWidget);
    expect(find.text('Payment'), findsOneWidget);
    expect(find.textContaining(r'$400.00'), findsWidgets);
    expect(find.text('Tags +DEPOSIT'), findsOneWidget);
    expect(find.textContaining('Ada'), findsOneWidget);
    expect(find.byKey(const Key('pm2-open-job')), findsOneWidget);
  });
}

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);
