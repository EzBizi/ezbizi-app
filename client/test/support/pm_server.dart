import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/project_management_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// A fake server for Project Management's screen (design 141). Every name,
/// number and address is invented. Each request is recorded with its method
/// and, for writes, its body.
class PmServer {
  PmServer({
    this.permissions = const [
      'jobs.view',
      'project_management.view',
      'financials.bases.view',
      'financials.approve',
      'financials.edit',
    ],
    Map<String, dynamic>? overview,
    this.jobs,
    this.scope = const {
      'type': 'organization',
      'id': 'org-1',
      'label': 'Brightway',
      'path': ['Brightway'],
    },
  }) : overview = overview ?? pmOverview();

  final List<String> permissions;
  Map<String, dynamic> overview;
  final Map<String, dynamic> scope;

  /// The stage list's answer for a stage; null: [pmJobsPage].
  final Map<String, dynamic> Function(Uri uri)? jobs;

  final requests = <({String method, Uri uri, Object? body})>[];

  /// Settings rows by key, as GET /settings answers them.
  final settings = <String, Map<String, dynamic>>{
    'jobs.statuses': _row('jobs.statuses', [
      {'value': 'in_progress', 'label': 'In progress'},
      {'value': 'parts_ordered', 'label': 'Parts ordered'},
      {'value': 'done', 'label': 'Done'},
      {'value': 'review', 'label': 'Needs review'},
    ]),
    'jobs.pm_stages': _row('jobs.pm_stages', [
      {
        'key': 'in_progress',
        'statuses': ['in_progress'],
        'scheduled': null,
        'due': null,
      },
    ]),
    'jobs.review_reasons': _row('jobs.review_reasons', [
      {'value': 'order_parts', 'label': 'Order parts'},
      {'value': 'measurements', 'label': 'Measurements'},
    ]),
    'jobs.pm_redo_words': _row('jobs.pm_redo_words', ['Redo']),
    'jobs.pm_dispute_words': _row('jobs.pm_dispute_words', ['Dispute']),
    'jobs.pm_warn_days': _row('jobs.pm_warn_days', 10),
    'jobs.pm_warning_email': _row('jobs.pm_warning_email', ''),
    'jobs.pm_stopped_days': _row('jobs.pm_stopped_days', 30),
    'jobs.pm_cost_alert_percent': _row('jobs.pm_cost_alert_percent', 15),
    'jobs.estimate_aging_statuses': _row('jobs.estimate_aging_statuses', [
      'review',
    ]),
  };

  static Map<String, dynamic> _row(String key, Object? value) => {
    'key': key,
    'value': value,
    'may_edit': true,
    'source': {
      'type': 'organization',
      'id': 'org-1',
      'name': 'Brightway',
      'inherited': false,
    },
  };

  List<Uri> get(String path) => [
    for (final r in requests)
      if (r.method == 'GET' && r.uri.path.endsWith(path)) r.uri,
  ];

  List<({String method, Uri uri, Object? body})> writes() => [
    for (final r in requests)
      if (r.method != 'GET') r,
  ];

  /// The repair report's answer; null: [pmRepairWeek].
  Map<String, dynamic>? report;

  /// The table's rows.
  List<Map<String, dynamic>> tableRows = [
    {
      'week_start': '2026-10-12',
      'week_end': '2026-10-18',
      'week_status': 'approved',
      ...pmTech('Sam Ortiz'),
    },
    {
      'week_start': '2026-10-12',
      'week_end': '2026-10-18',
      'week_status': 'approved',
      ...pmTech('Pat Lin', missing: ['paycheck', 'operation']),
    },
  ];

  List<Map<String, dynamic>> production = [
    {
      'month': '2026-10',
      'franchise_id': 'fr-1',
      'place': 'Northgate',
      'team': 'Sam Ortiz',
      'jobs': 9,
      'cost_cents': 1116000,
      'avg_cents': 124000,
    },
    {
      'month': '2026-10',
      'franchise_id': 'fr-1',
      'place': 'Northgate',
      'team': 'Pat Lin',
      'jobs': 3,
      'cost_cents': 300000,
      'avg_cents': 100000,
    },
  ];

  ApiClient client() => ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      final body = request.body.isEmpty ? null : jsonDecode(request.body);
      requests.add((method: request.method, uri: request.url, body: body));

      if (path.endsWith('/me')) {
        return _json({
          'data': {..._me, 'scope': scope, 'permissions': permissions},
        });
      }
      if (path.endsWith('/jobs/project-management/overview')) {
        return _json({'data': overview});
      }
      if (path.endsWith('/jobs/project-management/jobs')) {
        return _json((jobs ?? pmJobsPage)(request.url));
      }
      if (path.endsWith('/jobs/project-management')) {
        final id = request.url.queryParametersAll['ids[]']?.first;
        return _json({
          'data': [
            {
              'id': id,
              'number': '#4102',
              'title': 'Chimney rebuild',
              'customer': {'id': 'c-1', 'name': 'Sam Ortiz'},
              'franchise': {'id': 'fr-1', 'name': 'Northgate'},
              'timeline': [
                {
                  'kind': 'payment',
                  'at': '2026-10-14T14:00:00Z',
                  'marks': 'partial',
                },
              ],
            },
          ],
        });
      }
      if (path.endsWith('/financials/repair-report')) {
        return _json({
          'data': [report ?? pmRepairWeek()],
        });
      }
      if (path.endsWith('/financials/repair-report/approve')) {
        return _json({
          'data': {
            ...pmRepairWeek(),
            'week_status': 'approved',
            'approved_by': 'Rhea Tollan',
            'approved_at': '2026-10-22T15:00:00Z',
          },
        });
      }
      if (path.endsWith('/financials/repair-report/table')) {
        return _json({'data': tableRows, 'editable': pmEditable});
      }
      if (path.endsWith('/financials/repair-report/value')) {
        final week = pmRepairWeek(paycheck: 160000);
        return _json({
          'data': {
            ...week,
            'from': (body as Map)['week_start'],
            'week_status': 'draft',
          },
        });
      }
      if (path.endsWith('/financials/production-cost')) {
        return _json({'data': production});
      }
      if (request.method == 'PATCH' && path.contains('/jobs/')) {
        return _json({
          'data': {'id': path.split('/').last, ...(body as Map)},
        });
      }
      if (path.contains('/settings/')) {
        final key = path.split('/').last;
        final row = request.method == 'DELETE'
            ? {
                ...settings[key]!,
                'source': {
                  'type': 'organization',
                  'id': 'org-1',
                  'name': 'Brightway',
                  'inherited': true,
                },
              }
            : {...settings[key]!, 'value': (body as Map)['value']};
        settings[key] = row;
        return _json({'data': row});
      }
      if (path.endsWith('/settings')) {
        return _json({'data': settings.values.toList()});
      }
      if (path.endsWith('/jobs')) {
        return _json({
          'data': <Object>[],
          'meta': {
            'total': 0,
            'last_page': 1,
            'service_areas': [
              {'id': 'sa-1', 'name': 'Columbus', 'franchise_id': 'fr-1'},
              {'id': 'sa-2', 'name': 'Cleveland', 'franchise_id': 'fr-1'},
            ],
          },
        });
      }
      return _json({'message': 'Nothing stubbed for $path.'}, 404);
    }),
  );

  /// Pumps the screen at [size], signed in.
  Future<void> pump(
    WidgetTester tester, {
    Size size = const Size(1400, 1000),
    DateTime? now,
  }) async {
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    final api = client();
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));
    expect(session.signedIn, isTrue);
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: ProjectManagementScreen(
            api: api,
            session: session,
            now: () => now ?? DateTime(2026, 10, 22, 12),
            zone: () => null,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }
}

/// The overview with every stage mapped but Scheduled, and money when
/// [money].
Map<String, dynamic> pmOverview({bool money = true}) => {
  'mapped': [
    'in_progress',
    'waiting_review',
    'ready_to_schedule',
    'done_waiting_payment',
    'completed',
    'cancelled',
  ],
  'cost_alert_percent': 15,
  'waiting_review': {
    'count': 23,
    'by_reason': [
      {'value': 'order_parts', 'label': 'Order parts', 'count': 9},
      {'value': 'measurements', 'label': 'Measurements', 'count': 7},
      {'value': null, 'label': 'No reason', 'count': 7},
    ],
    'by_location': [
      {
        'name': 'Columbus',
        'count': 11,
        'by_reason': [
          {'value': 'order_parts', 'label': 'Order parts', 'count': 5},
          {'value': 'measurements', 'label': 'Measurements', 'count': 4},
          {'value': null, 'label': 'No reason', 'count': 2},
        ],
      },
      {
        'name': 'Cleveland',
        'count': 4,
        'by_reason': [
          {'value': 'order_parts', 'label': 'Order parts', 'count': 4},
          {'value': 'measurements', 'label': 'Measurements', 'count': 0},
          {'value': null, 'label': 'No reason', 'count': 0},
        ],
      },
    ],
  },
  'in_progress': {
    'count': 41,
    'ours': 29,
    'external': 12,
    'second_visit': 6,
    'redo': 4,
    'new': 37,
  },
  'ready_to_schedule': {'count': 14, 'oldest_days': 9},
  'scheduled': {'count': 0, 'next_7_days': 0},
  'done_waiting_payment': {
    'count': 19,
    if (money) 'total_minor': 4820000,
    if (money) 'due_minor': 2170000,
  },
  'stopped_payments': 5,
  'per_team': [
    {'name': 'Repair NJ', 'count': 32},
    {'name': 'Repair OH', 'count': 22},
  ],
  'waiting_to_start': [
    {
      'job': {'id': 'j-3990', 'number': '#3990', 'customer': 'Moss'},
      'paid_at': '2026-10-10',
      'days': 12,
      'limit': 10,
    },
    {
      'job': {'id': 'j-4102', 'number': '#4102', 'customer': 'Ortiz'},
      'paid_at': '2026-10-14',
      'days': 8,
      'limit': 10,
    },
    {
      'job': {'id': 'j-4188', 'number': '#4188', 'customer': 'Lin'},
      'paid_at': '2026-10-19',
      'days': 3,
      'limit': 10,
    },
  ],
  if (money) 'pipeline_minor': 18600000,
  if (money) 'avg_production_cost_minor': null,
};

/// One page of a stage, with every stage counted.
Map<String, dynamic> pmJobsPage(Uri uri) {
  final stage = uri.queryParameters['stage'];
  final page = int.parse(uri.queryParameters['page'] ?? '1');
  return {
    'data': stage == 'scheduled'
        ? <Object>[]
        : [
            pmJobRow('j-4102', '#4102', 'Sam Ortiz', days: 8, second: true),
            pmJobRow(
              'j-4230',
              '#4230',
              'Morgan Kay',
              days: 2,
              external: 'Northwind Masonry',
              tags: ['Redo'],
            ),
          ],
    'meta': {
      'counts': {
        'in_progress': 41,
        'waiting_review': 23,
        'ready_to_schedule': 14,
        'scheduled': 0,
        'done_waiting_payment': 19,
        'completed': 812,
        'cancelled': 64,
      },
      'mapped': [
        'in_progress',
        'waiting_review',
        'ready_to_schedule',
        'done_waiting_payment',
        'completed',
        'cancelled',
      ],
      'page': page,
      'last_page': 3,
      'total': 245,
    },
  };
}

Map<String, dynamic> pmJobRow(
  String id,
  String number,
  String customer, {
  int? days,
  bool second = false,
  String? external,
  List<String> tags = const [],
}) => {
  'id': id,
  'number': number,
  'title': 'Chimney rebuild',
  'status': 'in_progress',
  'tags': tags,
  'customer': {'id': 'c-$id', 'name': customer},
  'franchise': {'id': 'fr-1', 'name': 'Northgate'},
  'service_area': 'Columbus',
  'technician': 'Pat Lin',
  'scheduled_for': null,
  'review_reason': null,
  'external_company': external,
  'needs_second_visit': second,
  'paid_at': '2026-10-14',
  'days_since_payment': days,
};

const pmEditable = [
  'hours',
  'paycheck',
  'helper',
  'operation',
  'gas',
  'parts',
  'tech_share',
  'external_share',
  'contracting',
  'jobs_amount',
];

/// One technician's figures, as Financials sends them; a missing field is
/// null and named in `missing`.
Map<String, dynamic> pmTech(
  String name, {
  num? gross = 33.4,
  int paycheck = 154000,
  List<String> missing = const [],
}) => {
  'technician': name,
  'hours': 38.5,
  'paycheck_cents': missing.contains('paycheck') ? null : paycheck,
  'helper_cents': 42000,
  'operation_cents': missing.contains('operation') ? null : 0,
  'gas_cents': 9600,
  'parts_cents': 31000,
  'total_cost_cents': 236600,
  'tech_share': {'cents': 154000, 'pct': 21.8},
  'external_share': {'cents': null, 'pct': null},
  'contracting_profit': {'cents': null, 'pct': null},
  'company_share': {'cents': 317400, 'pct': 44.8},
  'jobs': 9,
  'jobs_amount_cents': 708000,
  'cost_from_gross_pct': gross,
  'missing': missing,
};

Map<String, dynamic> pmRepairWeek({int paycheck = 154000}) => {
  'place': 'Northgate',
  'company': 'Brightway Chimney',
  'franchise_id': 'fr-1',
  'from': '2026-10-12',
  'to': '2026-10-18',
  'status': 'calculated',
  'week_status': 'draft',
  'approved_by': null,
  'approved_at': null,
  'technicians': [
    pmTech('Sam Ortiz', paycheck: paycheck),
    pmTech('Pat Lin', gross: 12.5),
  ],
  'combined': {...pmTech('x', gross: 12.5), 'count': 2}..remove('technician'),
  'text': '*Northgate · Weekly 2026-10-12 to 2026-10-18* · UNAPPROVED DRAFT',
};

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
  'companies': [
    {'id': 'co-1', 'name': 'Brightway Chimney', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-1', 'name': 'Northgate', 'company_id': 'co-1'},
  ],
};
