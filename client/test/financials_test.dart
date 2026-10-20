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
  homeTests();
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
  Map<String, dynamic>? home,
  List<String> permissions = const [
    'financials.view',
    'financials.bases.view',
    'financials.bills.view',
    'financials.settings.view',
    'financials.create',
    'financials.edit',
    'financials.delete',
  ],
  bool openBooks = false,
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
            'permissions': permissions,
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
      if (path.endsWith('/financials/bills')) {
        return json({
          'data': [
            {
              'id': 'bl1',
              'place': 'New Jersey',
              'period_from': '2026-09-01',
              'period_to': '2026-09-30',
              'lines': [
                {'name': 'Office rent', 'cents': 100000},
                {'name': 'Internet', 'cents': 25010},
              ],
              'total_cents': 125010,
              'has_image': false,
              'source': 'whatsapp',
            },
          ],
        });
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
      if (path.endsWith('/financials/home')) {
        return home == null
            ? json({'message': 'Not found.'}, 404)
            : json({'data': home});
      }
      if (path.contains('/financials/tables/')) return json({'data': table});
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
  if (openBooks) {
    await tester.tap(find.text('Books'));
    await tester.pumpAndSettle();
  }
  return seen;
}

void screenTests() {
  testWidgets(
    'bills list each period with its total, and open to their lines',
    (tester) async {
      await _pump(tester);
      await tester.tap(find.text('Bills').first);
      await tester.pumpAndSettle();
      expect(
        find.text('New Jersey · 2026-09-01 to 2026-09-30'),
        findsOneWidget,
      );
      expect(find.text(r'$1,250.10'), findsOneWidget);
      expect(find.text('2 lines · from WhatsApp'), findsOneWidget);
      await tester.tap(find.text('New Jersey · 2026-09-01 to 2026-09-30'));
      await tester.pumpAndSettle();
      expect(find.text('Office rent'), findsOneWidget);
    },
  );

  testWidgets('settings list which Airtable table is which part of the books', (
    tester,
  ) async {
    await _pump(tester);
    await tester.tap(find.text('Settings'));
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
      openBooks: true,
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
    await _pump(tester, openBooks: true);
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

// ── The home (UI queue 121) ────────────────────────────────────────

Map<String, dynamic> _area(
  String key,
  String name, {
  String source = 'native',
  List<Map<String, dynamic>> tables = const [],
}) => {
  'key': key,
  'name': name,
  'icon': 'refund',
  'colour_hint': 'teal',
  'figure': 'This week: \$120 · 2 open',
  'source': source,
  'refreshed_at': DateTime.now()
      .subtract(const Duration(minutes: 4))
      .toUtc()
      .toIso8601String(),
  'tables': tables,
};

Map<String, dynamic> _homeWith({
  List<Map<String, dynamic>>? areas,
  List<Map<String, dynamic>>? bases,
  bool mayCreate = true,
}) => {
  'areas':
      areas ??
      [
        _area(
          'quarrels',
          'Quarrels & Returns',
          source: 'airtable',
          tables: [
            {'key': 'tq1', 'name': 'Quarrels', 'count': 3},
            {'key': 'tq2', 'name': 'Returns', 'count': 41},
          ],
        ),
        _area('pebbles', 'Pebble Deposits', source: 'both'),
      ],
  'bases':
      bases ??
      [
        {
          'id': 'b1',
          'name': 'Books',
          'figure': '1 table · 2 records',
          'source': 'native',
          'refreshed_at': null,
        },
      ],
  'may_create_base': mayCreate,
};

void homeTests() {
  testWidgets('the home draws areas then bases, every card one size', (
    tester,
  ) async {
    await _pump(tester, home: _homeWith());
    expect(find.text('Your areas'), findsOneWidget);
    expect(find.text('Your bases'), findsOneWidget);
    expect(
      tester.getTopLeft(find.text('Your areas')).dy,
      lessThan(tester.getTopLeft(find.text('Your bases')).dy),
    );
    final sizes = {
      for (final key in [
        'financials-area-quarrels',
        'financials-area-pebbles',
        'financials-base-b1',
        'financials-new-base-card',
      ])
        tester.getSize(find.byKey(ValueKey(key))),
    };
    expect(sizes, hasLength(1));
    expect(find.text('Airtable mirror'), findsOneWidget);
    expect(find.text('Native + Airtable'), findsOneWidget);
    expect(find.text('Native'), findsOneWidget);
    expect(find.text('refreshed 4 min ago'), findsNWidgets(2));
    expect(find.text('1 table · 2 records'), findsOneWidget);
  });

  testWidgets('a business without Airtable reads no Airtable on its home', (
    tester,
  ) async {
    await _pump(
      tester,
      home: _homeWith(
        areas: [
          _area('pebbles', 'Pebble Deposits'),
          _area('ledgers', 'Ledgers'),
        ],
      ),
    );
    expect(find.text('Pebble Deposits'), findsOneWidget);
    expect(find.textContaining('Airtable'), findsNothing);
  });

  List<Map<String, dynamic>> areas(int n) => [
    for (var i = 0; i < n; i++) _area('a$i', 'Area $i'),
  ];

  testWidgets('seven cards have no search box', (tester) async {
    await _pump(tester, home: _homeWith(areas: areas(6)));
    expect(find.text('Area 5'), findsOneWidget);
    expect(find.byKey(const ValueKey('financials-home-search')), findsNothing);
  });

  testWidgets('a search box appears from eight cards and narrows them', (
    tester,
  ) async {
    await _pump(tester, home: _homeWith(areas: areas(7)));
    final search = find.byKey(const ValueKey('financials-home-search'));
    expect(search, findsOneWidget);
    await tester.enterText(search, 'area 3');
    await tester.pumpAndSettle();
    expect(find.text('Area 3'), findsOneWidget);
    expect(find.text('Area 4'), findsNothing);
    expect(find.text('Books'), findsNothing);
  });

  testWidgets('an area opens in place with its tables, and goes back', (
    tester,
  ) async {
    final seen = await _pump(tester, home: _homeWith());
    await tester.tap(find.text('Quarrels & Returns'));
    await tester.pumpAndSettle();
    expect(find.text('Quarrels · 3'), findsOneWidget);
    expect(find.text('Returns · 41'), findsOneWidget);
    expect(find.text('Diesel'), findsOneWidget);
    expect(seen.last.url.path, endsWith('/financials/tables/tq1'));
    expect(find.text('Bills'), findsOneWidget);

    await tester.tap(find.text('Returns · 41'));
    await tester.pumpAndSettle();
    expect(seen.last.url.path, endsWith('/financials/tables/tq2'));

    await tester.tap(find.byKey(const ValueKey('financials-back')));
    await tester.pumpAndSettle();
    expect(find.text('Your areas'), findsOneWidget);
  });

  testWidgets('no + New base without the create permission', (tester) async {
    await _pump(
      tester,
      permissions: const ['financials.view', 'financials.bases.view'],
    );
    expect(find.text('Books'), findsOneWidget);
    expect(find.text('New base'), findsNothing);
  });

  testWidgets('no + New base when the home says it may not', (tester) async {
    await _pump(tester, home: _homeWith(mayCreate: false));
    expect(find.text('Books'), findsOneWidget);
    expect(find.text('New base'), findsNothing);
  });

  testWidgets('+ New base ends the bases when the home allows it', (
    tester,
  ) async {
    await _pump(tester, home: _homeWith());
    expect(find.text('New base'), findsOneWidget);
  });

  testWidgets('with no home endpoint the bases draw it alone', (tester) async {
    final seen = await _pump(tester);
    expect(seen.any((r) => r.url.path.endsWith('/financials/home')), isTrue);
    expect(find.text('Your areas'), findsNothing);
    expect(find.text('Your bases'), findsOneWidget);
    expect(find.text('Books'), findsOneWidget);
    expect(find.text('1 table'), findsOneWidget);
    expect(find.text('—'), findsNothing);
    expect(find.textContaining('Airtable'), findsNothing);
    expect(find.textContaining('refreshed'), findsNothing);
    expect(find.text('Diesel'), findsNothing);

    await tester.tap(find.text('Books'));
    await tester.pumpAndSettle();
    expect(find.text('Diesel'), findsOneWidget);
    expect(find.text('Transactions'), findsOneWidget);
  });

  testWidgets('a home with nothing in it says so', (tester) async {
    await _pump(
      tester,
      home: _homeWith(areas: const [], bases: const []),
    );
    expect(find.textContaining('No bases yet'), findsOneWidget);
    expect(find.byKey(const ValueKey('financials-new-base')), findsOneWidget);
  });

  testWidgets('a phone lays the cards two across', (tester) async {
    await _pump(tester, home: _homeWith());
    tester.view.physicalSize = const Size(390, 844);
    await tester.pumpAndSettle();
    final a = tester.getTopLeft(
      find.byKey(const ValueKey('financials-area-quarrels')),
    );
    final b = tester.getTopLeft(
      find.byKey(const ValueKey('financials-area-pebbles')),
    );
    expect(a.dy, b.dy);
    expect(b.dx, greaterThan(a.dx));
    expect(tester.takeException(), isNull);
  });
}
