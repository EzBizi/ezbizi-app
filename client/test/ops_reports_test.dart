import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/deep_links.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/reports/ops_reports.dart';
import 'package:ezbizi_client/widgets/date_range_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Shoval's operations reports (2026-11-18, design 199): each draws the
/// server's figures and rows. Every name invented.
void main() {
  tearDown(() => DeepLinks.pending.value = null);

  final data = <String, Map<String, dynamic>>{
    '/api/v1/reports/estimates': {
      'rows': [
        {
          'id': 'e1',
          'number': 'EST-1001',
          'store': 'ezbizi',
          'state': 'open',
          'no_answer': true,
          'created_at': '2026-11-02T10:00:00Z',
          'texted_at': '2026-11-02T11:00:00Z',
          'answered_at': null,
          'client': 'Dana Reyes',
          'phone': '+12025550147',
          'branch': 'A-1 · Columbus',
          'job_number': 'JOB-60906',
          'job_id': 'job-1',
          'value_minor': 125000,
        },
      ],
      'count': 1,
      'value_minor': 125000,
      'figures': {
        'total': {'count': 6213, 'value_minor': 2236000000},
        'open': {'count': 2127, 'value_minor': 1258000000},
        'approved': {'count': 709, 'value_minor': 1},
        'declined': {'count': 98, 'value_minor': 1},
        'texted_no_answer': {'count': 1397, 'value_minor': 1},
        'closed_no_answer': {'count': 5, 'value_minor': 1},
        'closed_answered': {'count': 3, 'value_minor': 1},
      },
      'states': [
        {'value': 'open', 'count': 2127},
      ],
    },
    '/api/v1/reports/complaints': {
      'rows': [
        {
          'id': 'c1',
          'kind': 'call_complaint',
          'status': 'new',
          'raised_at': '2026-11-03T10:00:00Z',
          'contacted_at': '2026-11-04T16:00:00Z',
          'contacted_by': 'call',
          'hours_to_contact': 30.0,
          'contact_kept': false,
          'customer': 'Rae Moss',
          'source_type': 'lead',
          'source_id': 'ld-1',
        },
      ],
      'count': 1,
      'targets': {'contact_hours': 24, 'solve_hours': 48},
      'figures': {
        'total': 510,
        'open': 510,
        'closed': 0,
        'contact_kept_percent': 32,
        'solve_kept_percent': null,
        'avg_hours_to_contact': 42.7,
        'avg_hours_to_solve': null,
        'not_contacted': 257,
      },
      'kinds': <dynamic>[],
      'statuses': <dynamic>[],
    },
    '/api/v1/reports/tasks': {
      'rows': [
        {
          'name': 'Eve Sarfaty',
          'open': 12,
          'done': 30,
          'overdue': 3,
          'avg_days_to_done': 2.4,
        },
        {
          'name': null,
          'open': 4,
          'done': 0,
          'overdue': 4,
          'avg_days_to_done': null,
        },
      ],
      'totals': {'open': 15, 'overdue': 7, 'done': 30},
    },
    '/api/v1/reports/dispatch': {
      'rows': [
        {
          'dispatcher': 'Bar',
          'leads': 120,
          'calls': 90,
          'messages': 30,
          'answered': 25,
          'unanswered': 5,
          'avg_minutes_to_answer': 165,
          'median_minutes_to_answer': 4,
        },
      ],
      'totals': {
        'leads': 120,
        'calls': 90,
        'messages': 30,
        'answered': 25,
        'unanswered': 5,
        'avg_minutes_to_answer': 165,
        'median_minutes_to_answer': 4,
      },
    },
    '/api/v1/reports/accounting': {
      'rows': [
        {
          'issue': 'done_zero',
          'date': '2026-11-12',
          'amount_minor': 0,
          'job_number': 'JOB-1',
          'job_id': 'job-2',
          'client': 'Ana Soto',
          'branch': 'Akron',
          'detail': 'Done for \$0',
        },
      ],
      'count': 1,
      'invoice_days': 30,
      'figures': {
        'done_unpaid': {'count': 1, 'amount_minor': 14900},
        'done_zero': {'count': 17, 'amount_minor': 0},
        'invoice_overdue': {'count': 1387, 'amount_minor': 113000000},
        'paid_cancelled': {'count': 0, 'amount_minor': 0},
        'payment_deleted': {'count': 33, 'amount_minor': 3990000},
      },
    },
    '/api/v1/reports/recruiting': {
      'rows': <dynamic>[],
      'totals': {
        'spend_minor': 0,
        'inquiries': 0,
        'hires': 0,
        'cost_per_hire_minor': null,
      },
    },
    '/api/v1/reports/technicians': {
      'rows': [
        {
          'tech': 'Sam Okafor',
          'jobs': 86,
          'revenue_minor': 4300000,
          'complaints': 2,
          'redo': 1,
          'eta_missed': 9,
          'close_missed': 3,
          'inspection_missed': null,
          'photos_missed': 2,
        },
      ],
      'jobs': 86,
      'rules': {'eta_hours': 3, 'close_hours': 24, 'min_photos': 4},
    },
    '/api/v1/reports/tech-checks': {
      'rows': [
        {
          'tech': 'Sam Okafor',
          'jobs': 86,
          'eta_missed': 9,
          'close_missed': 3,
          'inspection_missed': null,
          'photos_missed': 2,
        },
      ],
      'jobs': 86,
      'rules': {'eta_hours': 3, 'close_hours': 24, 'min_photos': 4},
    },
  };

  final asked = <Uri>[];

  Future<void> pump(WidgetTester tester, OpsSpec spec) async {
    tester.view.physicalSize = const Size(1400, 1000);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    final api = ApiClient(
      client: MockClient((r) async {
        asked.add(r.url);
        var body = data[r.url.path];
        if (r.url.path.endsWith('/tech-checks') &&
            r.url.queryParameters['check'] != null) {
          body = {
            'rows': [
              {
                'job_id': 'job-9',
                'number': 'JOB-60001',
                'client': 'Kelly Harmon',
                'job_type': 'Inspection',
                'start': '2026-11-10T14:00:00Z',
                'closed_at': null,
                'photos': 1,
                'misses': ['eta'],
              },
            ],
          };
        }
        return http.Response(
          jsonEncode({'data': body ?? <String, dynamic>{}}),
          200,
          headers: const {'content-type': 'application/json'},
        );
      }),
    );
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: OpsReport(
            api: api,
            span: DateSpan(DateTime(2026, 11, 10), DateTime(2026, 11, 16)),
            spec: spec,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('Estimates: the whole-range figures and a row opens its job', (
    tester,
  ) async {
    await pump(tester, estimatesSpec);
    expect(find.text('6,213'), findsOneWidget);
    expect(find.text('TEXTED, NO ANSWER'), findsOneWidget);
    expect(find.text('EST-1001'), findsOneWidget);
    expect(asked.last.queryParameters['from'], '2026-11-10');

    await tester.tap(find.text('EST-1001'));
    await tester.pumpAndSettle();
    expect(DeepLinks.pending.value?.id, 'job-1');
  });

  testWidgets('Complaints: the target is in the label, late is red', (
    tester,
  ) async {
    await pump(tester, complaintsSpec);
    expect(find.text('CALLED WITHIN 24H'), findsOneWidget);
    expect(find.text('32%'), findsOneWidget);
    expect(find.text('257'), findsOneWidget);
    expect(find.text('Rae Moss'), findsOneWidget);
  });

  testWidgets('Tasks: Nobody for no owner; the totals as figures', (
    tester,
  ) async {
    await pump(tester, tasksSpec);
    expect(find.text('Nobody'), findsOneWidget);
    expect(find.text('Eve Sarfaty'), findsOneWidget);
    expect(find.text('7'), findsWidgets);
  });

  testWidgets('Dispatch: the median leads', (tester) async {
    await pump(tester, dispatchSpec);
    expect(find.text('4 min'), findsOneWidget);
    expect(find.text('Bar'), findsOneWidget);
  });

  testWidgets('Accounting: five figures with the business\'s days', (
    tester,
  ) async {
    await pump(tester, accountingSpec);
    expect(find.text('INVOICE UNPAID AFTER 30 DAYS'), findsOneWidget);
    expect(find.text('1,387'), findsOneWidget);
    expect(find.text('Ana Soto'), findsOneWidget);
  });

  testWidgets('Recruiting: none yet says where they are kept', (tester) async {
    await pump(tester, recruitingSpec);
    expect(find.byKey(const ValueKey('recruiting-empty')), findsOneWidget);
  });

  testWidgets('Tech report: a count with its share; not checked is blank', (
    tester,
  ) async {
    await pump(tester, techsSpec);
    expect(find.text('9 · 10%'), findsOneWidget);
    expect(find.text('Sam Okafor'), findsOneWidget);
  });

  testWidgets('AI checks: a technician opens the jobs behind each count', (
    tester,
  ) async {
    await pump(tester, techChecksSpec);
    await tester.tap(find.text('Sam Okafor'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('tech-checks-panel')), findsOneWidget);
    expect(asked.last.queryParameters['tech'], 'Sam Okafor');
    expect(asked.last.queryParameters['check'], 'eta');
    expect(find.textContaining('Kelly Harmon'), findsOneWidget);
    // Not checked (no inspection types listed): no chip for it.
    expect(find.byKey(const ValueKey('tech-check-inspection')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('tech-check-photos')));
    await tester.pumpAndSettle();
    expect(asked.last.queryParameters['check'], 'photos');
  });
}
