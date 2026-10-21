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
  Map<String, dynamic>? homeAfterLink,
  int adjustmentFailures = 0,
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
  var linked = false;
  var failures = adjustmentFailures;
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
            // As the server sends an unset widths preference: [] (empty PHP array).
            'preferences': <String, dynamic>{'financials.column_widths': []},
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
              'kind': 'native',
              'last_copied_at': null,
              'rows': 12,
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
        final served = linked ? homeAfterLink ?? home : home;
        return served == null
            ? json({'message': 'Not found.'}, 404)
            : json({'data': served});
      }
      if (path.endsWith('/financials/airtable-links') &&
          request.method == 'POST') {
        linked = true;
        return json({'data': _table}, 201);
      }
      if (path.contains('/financials/adjustments/')) {
        return request.method == 'DELETE'
            ? http.Response('', 204)
            : json({'data': _adjustments.first});
      }
      if (path.endsWith('/financials/adjustments')) {
        if (failures-- > 0) {
          return json({
            'message': 'The books are busy. Nothing was changed.',
          }, 503);
        }
        final kind = request.url.queryParameters['kind'];
        return json({
          'data': [
            for (final a in _adjustments)
              if (kind == null || a['kind'] == kind) a,
          ],
        });
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
    // A row per part of the books, the All-branches column holding the table.
    expect(find.text('Repair team sheet'), findsOneWidget);
    expect(find.text('Profit & loss'), findsOneWidget);
    expect(find.text('ALL BRANCHES'), findsOneWidget);
    expect(find.text('Repair NJ'), findsOneWidget);
    expect(find.text('12 rows'), findsOneWidget);
    expect(find.text('— · Add'), findsOneWidget);
    expect(find.text('1 mapped · 0 branches'), findsOneWidget);
    expect(find.text('Add source'), findsOneWidget);
  });

  testWidgets(
    'a table that mirrors Airtable says so, edits cells, and keeps its columns to Airtable',
    (tester) async {
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
      expect(find.textContaining('Kept in step with Airtable'), findsOneWidget);
      expect(find.text('Refresh now'), findsOneWidget);
      expect(find.text('Add record'), findsWidgets);
      expect(find.byTooltip('Import CSV'), findsNothing);
      expect(find.text('Diesel'), findsOneWidget);
    },
  );

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
  'icon': 'undo',
  'colour_hint': 1,
  'figure': 'This week: \$120 · 2 open',
  'source': source,
  'refreshed_at': DateTime.now()
      .subtract(const Duration(minutes: 4))
      .toUtc()
      .toIso8601String(),
  'tables': tables,
};

const _adjustments = [
  {
    'id': 'ad1',
    'franchise_id': null,
    'place': 'Lakeside',
    'kind': 'refund',
    'date': '2026-10-03',
    'technician': 'Ollie Brook',
    'from_technician': null,
    'team': null,
    'cents': 12500,
    'job_number': 'J-77',
    'reason': 'Gate jammed again',
  },
  {
    'id': 'ad2',
    'franchise_id': null,
    'place': 'Lakeside',
    'kind': 'repair_move',
    'date': '2026-10-04',
    'technician': 'Mina Vale',
    'from_technician': 'Ollie Brook',
    'team': 'North',
    'cents': null,
    'job_number': 'J-78',
    'reason': null,
  },
];

Map<String, dynamic> _quarrels({
  String? unmirroredId,
  bool nativeOpen = true,
  bool mayMirror = true,
}) => _area(
  'quarrels',
  'Quarrels & Returns',
  source: 'both',
  tables: [
    {
      'key': 'appQ/tblQ',
      'name': 'Quarrels',
      'count': 3,
      'table_id': 't1',
      'route': '/financials/tables/t1',
      'may_open': true,
      'refused': null,
    },
    {
      'key': 'appQ/tblU/fr-9',
      'name': 'Unlinked returns',
      'count': 5,
      'table_id': unmirroredId,
      'route': unmirroredId == null ? null : '/financials/tables/$unmirroredId',
      'may_open': unmirroredId != null,
      'may_mirror': unmirroredId == null && mayMirror,
      'refused': unmirroredId == null ? 'Not mirrored into a base yet.' : null,
    },
    {
      'key': 'adjustments',
      'name': 'Recorded in EzBizi',
      'count': 1,
      'table_id': null,
      'route': '/financials/adjustments',
      'may_open': nativeOpen,
      'refused': nativeOpen ? null : 'Your role does not have this tab.',
    },
    {
      'key': 'repair_moves',
      'name': 'Split changes',
      'count': 1,
      'table_id': null,
      'route': '/financials/adjustments?kind=repair_move',
      'may_open': nativeOpen,
      'refused': nativeOpen ? null : 'Your role does not have this tab.',
    },
    {
      'key': 'bills',
      'name': 'Bills',
      'count': 1,
      'table_id': null,
      'route': '/financials/bills',
      'may_open': nativeOpen,
      'refused': nativeOpen ? null : 'Your role does not have this tab.',
    },
  ],
);

Map<String, dynamic> _homeWith({
  List<Map<String, dynamic>>? areas,
  List<Map<String, dynamic>>? bases,
  bool mayCreate = true,
  bool nativeOpen = true,
  bool mayMirror = true,
}) => {
  'areas':
      areas ??
      [
        _quarrels(nativeOpen: nativeOpen, mayMirror: mayMirror),
        _area('pebbles', 'Pebble Deposits', source: 'airtable'),
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

  ChoiceChip chip(WidgetTester tester, String key) => tester.widget<ChoiceChip>(
    find.byKey(ValueKey('financials-area-table-$key')),
  );

  Future<void> openQuarrels(WidgetTester tester) async {
    await tester.tap(find.text('Quarrels & Returns'));
    await tester.pumpAndSettle();
  }

  testWidgets('an area opens in place on its mirrored table, and goes back', (
    tester,
  ) async {
    final seen = await _pump(tester, home: _homeWith());
    await openQuarrels(tester);
    expect(chip(tester, 'appQ/tblQ').selected, isTrue);
    expect(find.text('Quarrels · 3'), findsOneWidget);
    expect(find.text('Unlinked returns · 5'), findsOneWidget);
    expect(find.text('Recorded in EzBizi · 1'), findsOneWidget);
    expect(find.text('Split changes · 1'), findsOneWidget);
    expect(find.text('Bills · 1'), findsOneWidget);
    // The existing table screen, opened by the table_id the home sent.
    expect(find.text('Diesel'), findsOneWidget);
    expect(find.text('Grid view'), findsOneWidget);
    // Only ever a Financials table id after /financials/tables/.
    expect(
      seen
          .where((r) => r.url.path.contains('/financials/tables/'))
          .map((r) => r.url.path.split('/').last),
      everyElement('t1'),
    );

    await tester.tap(find.byKey(const ValueKey('financials-back')));
    await tester.pumpAndSettle();
    expect(find.text('Your areas'), findsOneWidget);
  });

  testWidgets(
    'an unmirrored source mirrors where the server picks, then opens',
    (tester) async {
      final seen = await _pump(
        tester,
        home: _homeWith(),
        homeAfterLink: _homeWith(areas: [_quarrels(unmirroredId: 't1')]),
      );
      await openQuarrels(tester);
      await tester.tap(find.text('Unlinked returns · 5'));
      await tester.pumpAndSettle();
      expect(
        find.textContaining('not mirrored into a base yet'),
        findsOneWidget,
      );
      await tester.tap(find.text('Mirror it'));
      await tester.pumpAndSettle();

      final post = seen.singleWhere((r) => r.method == 'POST');
      expect(post.url.path, endsWith('/financials/airtable-links'));
      expect(post.url.path, isNot(contains('/bases/')));
      expect(jsonDecode(post.body), {'base_id': 'appQ', 'table_id': 'tblU'});
      final after = seen.sublist(seen.indexOf(post));
      expect(after.any((r) => r.url.path.endsWith('/financials/home')), isTrue);
      expect(chip(tester, 'appQ/tblU/fr-9').selected, isTrue);
      expect(find.text('Diesel'), findsOneWidget);
    },
  );

  testWidgets('without may_mirror, the chip says why', (tester) async {
    await _pump(
      tester,
      // may_mirror false while the role holds every grant: the server's
      // flag alone decides.
      home: _homeWith(nativeOpen: false, mayMirror: false),
    );
    await openQuarrels(tester);
    expect(
      find.text('Unlinked returns · 5 · Not mirrored into a base yet.'),
      findsOneWidget,
    );
    expect(chip(tester, 'appQ/tblU/fr-9').onSelected, isNull);
    // The server's own sentence, word for word.
    expect(
      find.text('Bills · 1 · Your role does not have this tab.'),
      findsOneWidget,
    );
    expect(chip(tester, 'bills').onSelected, isNull);
    expect(chip(tester, 'adjustments').onSelected, isNull);
    // The mirrored source still opens.
    expect(find.text('Diesel'), findsOneWidget);
    expect(find.text('Mirror it'), findsNothing);
  });

  testWidgets('refunds recorded in EzBizi list this month in place', (
    tester,
  ) async {
    final seen = await _pump(tester, home: _homeWith());
    await openQuarrels(tester);
    await tester.tap(find.text('Recorded in EzBizi · 1'));
    await tester.pumpAndSettle();
    final get = seen.lastWhere(
      (r) => r.url.path.endsWith('/financials/adjustments'),
    );
    final now = DateTime.now();
    final month = '${now.year}-${now.month.toString().padLeft(2, '0')}';
    expect(get.url.queryParameters['from'], '$month-01');
    expect(get.url.queryParameters['to'], startsWith(month));
    expect(get.url.queryParameters.containsKey('kind'), isFalse);
    expect(find.text('2026-10-03 · Refund · Lakeside'), findsOneWidget);
    expect(
      find.text('Ollie Brook · Job J-77 · Gate jammed again'),
      findsOneWidget,
    );
    expect(find.text(r'$125.00'), findsOneWidget);
    // Split changes belong to Repair Teams, not here.
    expect(find.textContaining('Split change ·'), findsNothing);

    await tester.tap(find.text('Last month'));
    await tester.pumpAndSettle();
    final last = DateTime(now.year, now.month - 1);
    expect(
      seen
          .lastWhere((r) => r.url.path.endsWith('/financials/adjustments'))
          .url
          .queryParameters['from'],
      '${last.year}-${last.month.toString().padLeft(2, '0')}-01',
    );
  });

  testWidgets('split changes ask for repair moves and show who moved', (
    tester,
  ) async {
    final seen = await _pump(tester, home: _homeWith());
    await openQuarrels(tester);
    await tester.tap(find.text('Split changes · 1'));
    await tester.pumpAndSettle();
    expect(
      seen
          .lastWhere((r) => r.url.path.endsWith('/financials/adjustments'))
          .url
          .queryParameters['kind'],
      'repair_move',
    );
    expect(find.text('2026-10-04 · Split change · Lakeside'), findsOneWidget);
    expect(find.text('Ollie Brook → Team North · Job J-78'), findsOneWidget);
  });

  testWidgets('an adjustments list that fails offers Try again', (
    tester,
  ) async {
    await _pump(tester, home: _homeWith(), adjustmentFailures: 1);
    await openQuarrels(tester);
    await tester.tap(find.text('Recorded in EzBizi · 1'));
    await tester.pumpAndSettle();
    expect(
      find.text('The books are busy. Nothing was changed.'),
      findsOneWidget,
    );
    await tester.tap(find.text('Try again'));
    await tester.pumpAndSettle();
    expect(find.text('2026-10-03 · Refund · Lakeside'), findsOneWidget);
  });

  testWidgets(
    'a recorded row corrects its amount, and deletes on a second press',
    (tester) async {
      final seen = await _pump(tester, home: _homeWith());
      await openQuarrels(tester);
      await tester.tap(find.text('Recorded in EzBizi · 1'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('2026-10-03 · Refund · Lakeside'));
      await tester.pumpAndSettle();
      await tester.enterText(find.widgetWithText(TextField, 'Amount'), '99.50');
      await tester.tap(find.text('Save'));
      await tester.pumpAndSettle();
      final patch = seen.lastWhere((r) => r.method == 'PATCH');
      expect(patch.url.path, endsWith('/financials/adjustments/ad1'));
      expect(jsonDecode(patch.body), {
        'amount': '99.50',
        'job_number': 'J-77',
        'reason': 'Gate jammed again',
      });

      await tester.tap(find.text('2026-10-03 · Refund · Lakeside'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Delete'));
      await tester.pumpAndSettle();
      expect(seen.where((r) => r.method == 'DELETE'), isEmpty);
      await tester.tap(find.text('Press again to delete'));
      await tester.pumpAndSettle();
      expect(
        seen.lastWhere((r) => r.method == 'DELETE').url.path,
        endsWith('/financials/adjustments/ad1'),
      );
    },
  );

  testWidgets('a role without edit sees recorded rows read-only', (
    tester,
  ) async {
    await _pump(
      tester,
      home: _homeWith(),
      permissions: const ['financials.view', 'financials.bases.view'],
    );
    await openQuarrels(tester);
    await tester.tap(find.text('Recorded in EzBizi · 1'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('2026-10-03 · Refund · Lakeside'));
    await tester.pumpAndSettle();
    expect(find.text('Save'), findsNothing);
  });

  testWidgets('bills in an area open the Bills list in place', (tester) async {
    await _pump(tester, home: _homeWith());
    await openQuarrels(tester);
    await tester.tap(find.text('Bills · 1'));
    await tester.pumpAndSettle();
    expect(find.text('New Jersey · 2026-09-01 to 2026-09-30'), findsOneWidget);
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
