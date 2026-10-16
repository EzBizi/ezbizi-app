import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/financials/financial_cells.dart';
import 'package:ezbizi_client/screens/financials/financial_panels.dart';
import 'package:ezbizi_client/screens/financials_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  screenTests();
  final fields = <Map>[
    {'id': 'n', 'name': 'Name', 'type': 'text', 'is_primary': true},
    {
      'id': 'b',
      'name': 'Budgeted',
      'type': 'currency',
      'options': {'symbol': r'$'},
    },
    {
      'id': 'a',
      'name': 'Actual',
      'type': 'currency',
      'options': {'symbol': r'$'},
    },
    {
      'id': 'r',
      'name': 'Remaining',
      'type': 'formula',
      'options': {'formula': '({Budgeted} - {Actual}) * 2'},
    },
  ];

  test('formulas do arithmetic, joins and refuse nonsense', () {
    final v = {'n': 'Fuel', 'b': 100, 'a': 40};
    expect(evaluateFormula('({Budgeted} - {Actual}) * 2', v, fields), 120.0);
    expect(evaluateFormula('{Name} & " left"', v, fields), 'Fuel left');
    expect(evaluateFormula('{Budgeted} / 0', v, fields), '#ERROR');
    expect(evaluateFormula('{Nope} + 1', v, fields), '#ERROR');
    expect(evaluateFormula('{Remaining}', v, fields), '#ERROR');
    expect(formatCell(fields[1], 1234567.5), r'$1,234,567.50');
  });

  test('a view filters, searches and sorts, formulas included', () {
    final records = <Map>[
      {
        'id': '1',
        'values': {'n': 'Fuel', 'b': 100, 'a': 40},
      },
      {
        'id': '2',
        'values': {'n': 'Rent', 'b': 1000, 'a': 1000},
      },
      {
        'id': '3',
        'values': {'n': 'Tape', 'b': 10},
      },
    ];
    final sorted = applyView(records, fields, {
      'sorts': [
        {'field': 'r', 'dir': 'desc'},
      ],
    }, '');
    expect(sorted.map((r) => r['id']), ['1', '3', '2']);

    final filtered = applyView(records, fields, {
      'filters': [
        {'field': 'b', 'op': 'gt', 'value': '50'},
      ],
    }, '');
    expect(filtered.map((r) => r['id']), ['1', '2']);

    expect(applyView(records, fields, const {}, 'ren').map((r) => r['id']), [
      '2',
    ]);
  });
}

// ── The screen, against a stubbed API ──────────────────────────────

const _table = {
  'id': 't1',
  'base_id': 'b1',
  'name': 'Transactions',
  'fields': [
    {
      'id': 'n',
      'name': 'Name',
      'type': 'text',
      'options': {},
      'is_primary': true,
    },
    {
      'id': 'c',
      'name': 'Category',
      'type': 'single_select',
      'options': {
        'choices': [
          {'name': 'Fuel'},
          {'name': 'Rent'},
        ],
      },
      'is_primary': false,
    },
    {
      'id': 'a',
      'name': 'Amount',
      'type': 'currency',
      'options': {'symbol': r'$', 'precision': 2},
      'is_primary': false,
    },
  ],
  'views': [
    {'id': 'v1', 'name': 'Grid view', 'type': 'grid', 'config': {}},
    {
      'id': 'v2',
      'name': 'By category',
      'type': 'kanban',
      'config': {'group_field': 'c'},
    },
  ],
  'records': [
    {
      'id': 'r1',
      'values': {'n': 'Diesel', 'c': 'Fuel', 'a': 42.5},
    },
    {
      'id': 'r2',
      'values': {'n': 'Office', 'c': 'Rent', 'a': 1200},
    },
  ],
};

Future<List<http.Request>> _pump(
  WidgetTester tester, {
  Map<String, dynamic> table = _table,
  Map<String, dynamic> scope = const {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Everdoors',
    'path': ['Everdoors'],
  },
}) async {
  tester.view.physicalSize = const Size(1400, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  final seen = <http.Request>[];
  http.Response json(Object body, [int status = 200]) => http.Response(
    jsonEncode(body),
    status,
    headers: const {'content-type': 'application/json'},
  );

  final api = ApiClient(
    client: MockClient((request) async {
      seen.add(request);
      final path = request.url.path;
      if (path.endsWith('/me')) {
        return json({
          'data': {
            'id': 'u-1',
            'name': 'Jose',
            'email': 'jose@example.com',
            'role': {'name': 'Administrator'},
            'has_avatar': false,
            'preferences': <String, dynamic>{},
            'organization': {
              'id': 'org-1',
              'name': 'Everdoors',
              'franchise_label': 'Franchise',
              'is_read_only': false,
            },
            'scope': scope,
            'companies': [
              {'id': 'co-1', 'name': 'Everdoors', 'parent_company_id': null},
            ],
            'franchises': <Map>[],
            'modules': [
              {
                'key': 'financials',
                'label': 'Financials',
                'available': true,
                'reachable': true,
              },
            ],
            'permissions': [
              'financials.view',
              'financials.create',
              'financials.edit',
              'financials.delete',
            ],
          },
        });
      }
      if (path.endsWith('/financials/bases') && request.method == 'GET') {
        return json({
          'data': [
            {
              'id': 'b1',
              'name': 'Books',
              'place': null,
              'tables': [
                {'id': 't1', 'name': 'Transactions'},
              ],
            },
          ],
        });
      }
      if (path.endsWith('/financials/bases') && request.method == 'POST') {
        return json({
          'data': {'id': 'b1'},
        }, 201);
      }
      if (path.endsWith('/financials/sources')) {
        return json({
          'data': [
            {
              'id': 's1',
              'base_id': 'appR',
              'table_id': 'tblNJ',
              'role': 'repair',
              'company_id': null,
              'franchise_id': null,
              'place': null,
              'field_map': {'team': 'Team'},
            },
          ],
          'roles': {'repair': 'Repair team sheet', 'pnl': 'Profit & loss'},
          'field_keys': ['amount', 'team'],
          'airtable_tables': [
            {
              'base_id': 'appR',
              'table_id': 'tblNJ',
              'table_name': 'Repair NJ',
              'rows': 12,
            },
          ],
        });
      }
      if (path.endsWith('/financials/tables/t1')) return json({'data': table});
      return json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: FinancialsScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
  return seen;
}

void screenTests() {
  testWidgets('settings list which Airtable table is which part of the books', (
    tester,
  ) async {
    await _pump(tester);
    await tester.tap(find.byTooltip('Financials settings'));
    await tester.pumpAndSettle();
    expect(find.text('Repair team sheet · Repair NJ'), findsOneWidget);
    expect(find.text('Whole organization · 1 column mapped'), findsOneWidget);
    expect(find.text('Add source'), findsOneWidget);
  });

  testWidgets('a table that mirrors Airtable says so and offers no writing', (
    tester,
  ) async {
    await _pump(
      tester,
      table: {
        ..._table,
        'mirror': {
          'base_id': 'appX',
          'table_id': 'tblR',
          'synced_at': DateTime.now().toUtc().toIso8601String(),
        },
      },
    );
    expect(
      find.textContaining('Mirrors Airtable, read-only here'),
      findsOneWidget,
    );
    expect(find.text('Refresh now'), findsOneWidget);
    expect(find.text('Add record'), findsNothing);
    expect(find.text('Diesel'), findsOneWidget);
  });

  testWidgets('the grid shows records with a total, and kanban stacks them', (
    tester,
  ) async {
    await _pump(tester);
    expect(find.text('Diesel'), findsOneWidget);
    expect(find.text(r'Sum $1,242.50'), findsOneWidget);

    await tester.tap(find.text('By category'));
    await tester.pumpAndSettle();
    expect(find.text('Uncategorized'), findsOneWidget);
    expect(find.text('Diesel'), findsOneWidget);
  });

  testWidgets('a new base is filed on the company the view stands on', (
    tester,
  ) async {
    final seen = await _pump(
      tester,
      scope: {
        'type': 'company',
        'id': 'co-1',
        'label': 'Everdoors',
        'path': ['Everdoors'],
      },
    );
    await tester.tap(find.text('New base'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Create base'));
    await tester.pumpAndSettle();
    final post = seen.lastWhere((r) => r.method == 'POST');
    expect(jsonDecode(post.body)['company_id'], 'co-1');
    expect(jsonDecode(post.body)['template'], 'bookkeeping');
  });
}
