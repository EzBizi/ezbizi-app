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
  submitTest();
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

    for (final heading in ['PAID', 'BALANCE', 'TOTAL']) {
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

void submitTest() {
  testWidgets('three tabs; Submit moves a paid job to Submitted', (
    tester,
  ) async {
    Map<String, Object?> job(String id, String who) => {
      'id': id,
      'job_number': id,
      'customer': who,
      'branch': 'Ohio',
      'status': 'Done, pending approval',
      'tags': <String>[],
      'address': '1 Main St',
      'created': '2026-10-01',
      'modified': '2026-10-02',
    };
    var submitted = false;
    final posts = <String>[];
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
                'jobs.edit',
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
        if (request.method == 'POST') {
          posts.add(path);
          submitted = true;
          return _json({'data': job('j-2', 'Paid Pat')});
        }
        final tab = request.url.queryParameters['tab'];
        final rows = switch (tab) {
          'ready' => submitted ? [] : [job('j-2', 'Paid Pat')],
          'submitted' => submitted ? [job('j-2', 'Paid Pat')] : [],
          _ => [
            job('j-1', 'Unpaid Una'),
            if (!submitted) job('j-2', 'Paid Pat'),
          ],
        };
        return _json({
          'data': rows,
          'meta': {
            'total': rows.length,
            'last_page': 1,
            'counts': {
              'pending': submitted ? 1 : 2,
              'ready': submitted ? 0 : 1,
              'submitted': submitted ? 1 : 0,
            },
          },
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

    expect(find.text('Pending · 2'), findsOneWidget);
    expect(find.text('Unpaid Una'), findsOneWidget);
    expect(find.byKey(const Key('pm2-submit:j-2')), findsNothing);

    await tester.tap(find.text('Ready for submission · 1'));
    await tester.pumpAndSettle();
    expect(find.text('Unpaid Una'), findsNothing);
    expect(find.text('Paid Pat'), findsOneWidget);

    await tester.tap(find.byKey(const Key('pm2-submit:j-2')));
    await tester.pumpAndSettle();
    expect(find.text('Submit job j-2?'), findsOneWidget);
    await tester.tap(find.byKey(const Key('pm2-submit-confirm')));
    await tester.pumpAndSettle();

    expect(posts, ['/api/v1/project-management-2/jobs/j-2/submit']);
    expect(find.textContaining('is Submitted'), findsOneWidget);
    expect(find.text('Paid Pat'), findsNothing);
    expect(find.text('Submitted · 1'), findsOneWidget);

    await tester.tap(find.text('Submitted · 1'));
    await tester.pumpAndSettle();
    expect(find.text('Paid Pat'), findsOneWidget);
  });
}

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);
