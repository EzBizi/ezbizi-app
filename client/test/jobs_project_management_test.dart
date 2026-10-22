import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/jobs/pm_stages.dart';
import 'package:ezbizi_client/screens/jobs_screen.dart';
import 'package:ezbizi_client/widgets/status_pill.dart';
import 'package:ezbizi_client/widgets/tab_bar_like.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import 'support/pm_server.dart';

/// Project Management › Jobs (design 141), which was Pending Jobs: the stage
/// tabs and their counts, the filters as the server's query, the After
/// payment bar, paging, and the three job fields PATCHed from the pane.
/// Every name invented.
void main() {
  Future<PmServer> open(
    WidgetTester tester, {
    Size size = const Size(1400, 1000),
    Map<String, dynamic> Function(Uri)? jobs,
  }) async {
    final server = PmServer(jobs: jobs);
    await server.pump(tester, size: size);
    await tester.tap(find.text('Jobs'));
    await tester.pumpAndSettle();
    return server;
  }

  String label(WidgetTester tester, String stage) {
    final text = tester.widget<Text>(
      find.byKey(ValueKey('pm-stage-$stage-label')),
    );
    return text.textSpan!.toPlainText();
  }

  testWidgets('stage tabs in order, each with its count', (tester) async {
    final server = await open(tester);
    expect(server.get('/project-management/jobs').single.queryParameters, {
      'stage': 'in_progress',
    });
    expect(label(tester, 'in_progress'), 'In progress · 41');
    expect(label(tester, 'done_waiting_payment'), 'Done, waiting payment · 19');
    expect(label(tester, 'completed'), 'Completed · 812');
    expect(label(tester, 'cancelled'), 'Cancelled · 64');
    // Scheduled is not mapped: no count.
    expect(label(tester, 'scheduled'), 'Scheduled');

    final xs = [
      for (final s in pmStageKeys)
        tester.getTopLeft(find.byKey(ValueKey('pm-stage-$s'))).dx,
    ];
    expect([...xs]..sort(), xs);
    final divider = tester
        .getTopLeft(find.byKey(const ValueKey('pm-stage-divider')))
        .dx;
    expect(divider, greaterThan(xs[4]));
    expect(divider, lessThan(xs[5]));
  });

  testWidgets('an unmapped stage says not set up, with the way to Settings', (
    tester,
  ) async {
    await open(tester);
    await tester.tap(find.byKey(const ValueKey('pm-stage-scheduled')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('pm-jobs-unmapped')), findsOneWidget);
    expect(find.text('Scheduled is not set up.'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('pm-jobs-setup')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('pm-settings')), findsOneWidget);
  });

  testWidgets('the filters are the query, 2nd visit only on In progress', (
    tester,
  ) async {
    final server = await open(tester);
    Map<String, String> last() =>
        server.get('/project-management/jobs').last.queryParameters;

    await tester.tap(find.byKey(const ValueKey('pm-area-sa-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('pm-flag-second_visit')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('pm-flag-redo')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('pm-flag-stopped')));
    await tester.pumpAndSettle();
    expect(last(), {
      'stage': 'in_progress',
      'service_area_id': 'sa-1',
      'redo': '1',
      'stopped': '1',
      'second_visit': '1',
    });

    // One go replaces Redo; External and Disputes add.
    await tester.tap(find.byKey(const ValueKey('pm-flag-one_go')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('pm-flag-external')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('pm-flag-dispute')));
    await tester.pumpAndSettle();
    expect(last()['redo'], '0');
    expect(last()['external'], '1');
    expect(last()['dispute'], '1');

    await tester.enterText(find.byKey(const ValueKey('pm-jobs-search')), 'Kay');
    await tester.pump(const Duration(milliseconds: 450));
    await tester.pumpAndSettle();
    expect(last()['q'], 'Kay');

    // Off In progress, the chip goes and the filter is not sent.
    await tester.tap(find.byKey(const ValueKey('pm-stage-waiting_review')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('pm-flag-second_visit')), findsNothing);
    expect(last()['stage'], 'waiting_review');
    expect(last().containsKey('second_visit'), isFalse);
    expect(last()['q'], 'Kay');
  });

  testWidgets('rows: team or external chip, flags, the After payment bar', (
    tester,
  ) async {
    await open(tester);
    for (final h in [
      'JOB',
      'CUSTOMER',
      'AREA',
      'TEAM',
      'FLAGS',
      'AFTER PAYMENT',
    ]) {
      expect(find.text(h), findsOneWidget);
    }
    expect(find.byKey(const ValueKey('pm-flag-2nd-j-4102')), findsOneWidget);
    expect(find.byKey(const ValueKey('pm-flag-redo-j-4230')), findsOneWidget);
    expect(
      tester
          .widget<StatusPill>(
            find.byKey(const ValueKey('pm-team-external-j-4230')),
          )
          .label,
      'Northwind Masonry',
    );
    final bar = tester.widget<PmCountdownBar>(
      find.byKey(const ValueKey('pm-after-j-4102')),
    );
    expect((bar.days, bar.limit), (8, 10));
    expect(find.text('8 days · 2 left'), findsOneWidget);
  });

  testWidgets('Older and Newer turn the page', (tester) async {
    final server = await open(tester);
    expect(find.text('Page 1 of 3 · 245 jobs'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('pm-jobs-older')));
    await tester.pumpAndSettle();
    expect(
      server.get('/project-management/jobs').last.queryParameters['page'],
      '2',
    );
    await tester.tap(find.byKey(const ValueKey('pm-jobs-newer')));
    await tester.pumpAndSettle();
    expect(
      server
          .get('/project-management/jobs')
          .last
          .queryParameters
          .containsKey('page'),
      isFalse,
    );
  });

  testWidgets('a row opens its timeline; the three fields PATCH the job', (
    tester,
  ) async {
    final server = await open(tester);
    await tester.tap(find.byKey(const ValueKey('pm-row-j-4102')));
    await tester.pumpAndSettle();
    expect(
      server.get('/jobs/project-management').last.queryParametersAll['ids[]'],
      ['j-4102'],
    );
    expect(find.byKey(const ValueKey('pm-timeline')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('pm-field-reason')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Measurements').last);
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const ValueKey('pm-field-external')),
      '  Northwind Masonry ',
    );
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('pm-field-second-visit')));
    await tester.pumpAndSettle();

    await tester.enterText(find.byKey(const ValueKey('pm-field-external')), '');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();

    expect(
      [
        for (final w in server.writes())
          [w.method, w.uri.path.split('/v1').last, w.body],
      ],
      [
        [
          'PATCH',
          '/jobs/j-4102',
          {'review_reason': 'measurements'},
        ],
        [
          'PATCH',
          '/jobs/j-4102',
          {'external_company': 'Northwind Masonry'},
        ],
        [
          'PATCH',
          '/jobs/j-4102',
          {'needs_second_visit': false},
        ],
        [
          'PATCH',
          '/jobs/j-4102',
          {'external_company': null},
        ],
      ],
    );
    // The row took the saved answer.
    expect(find.byKey(const ValueKey('pm-flag-2nd-j-4102')), findsNothing);
    expect(find.byKey(const ValueKey('pm-flag-reason-j-4102')), findsOneWidget);
  });

  testWidgets('Jobs no longer draws it as a tab', (tester) async {
    // Project Management left Jobs for the sidebar (2026-10-21).
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) {
          return _json({
            'data': {
              ..._me,
              'permissions': const [
                'jobs.view',
                'jobs.financials',
                'estimates.view',
                'project_management.view',
              ],
            },
          });
        }
        return _json({
          'data': <Object>[],
          'meta': {'total': 0, 'last_page': 1},
        });
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));
    tester.view.physicalSize = const Size(1280, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: JobsScreen(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();
    final labels = [
      for (final l
          in tester.widget<TabBarLike>(find.byType(TabBarLike).first).labels)
        l.$1,
    ];
    expect(labels, ['Jobs', 'Estimates']);
    expect(find.text('Project Management'), findsNothing);
  });
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Rhea Tollan',
  'email': 'rhea@example.com',
  'role': {'name': 'Manager'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Brightway',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Brightway',
    'path': ['Brightway'],
  },
  'companies': [
    {'id': 'co-1', 'name': 'Brightway', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-1', 'name': 'Northgate', 'company_id': 'co-1'},
  ],
};
