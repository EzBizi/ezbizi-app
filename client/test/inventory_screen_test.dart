import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:ezbizi_client/screens/inventory_screen.dart';
import 'package:ezbizi_client/widgets/coming_soon.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Inventory is a register, not a placeholder: chips, 25/75, receive, no modal.
void main() {
  testWidgets('a granted Inventory tab shows chips, not Coming soon', (
    tester,
  ) async {
    await _pumpShell(tester);

    expect(find.byType(ComingSoonScreen), findsNothing);
    expect(find.text('Coming soon'), findsNothing);
    expect(find.byType(InventoryScreen), findsOneWidget);
    expect(find.text('Stock'), findsWidgets);
    expect(find.text('Items'), findsOneWidget);
    expect(find.text('Orders'), findsOneWidget);
    expect(find.text('Locations'), findsOneWidget);
  });

  testWidgets('a failed load is the request failed, not an empty list', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      onRequest: (request) {
        if (request.url.path.endsWith('/inventory/stock')) {
          return _json({'message': 'the request failed'}, 500);
        }
        return null;
      },
    );

    expect(find.text('the request failed'), findsOneWidget);
    expect(find.text('There is no stock.'), findsNothing);
    expect(find.text('Try again'), findsOneWidget);
  });

  testWidgets('Stock lists balance rows', (tester) async {
    await _pumpScreen(tester, stock: [_balance]);

    expect(find.text('6 mil liner'), findsOneWidget);
    expect(find.textContaining('Main shop'), findsOneWidget);
    expect(find.text('Low'), findsWidgets);
  });

  testWidgets('Receive posts and Stock qty updates', (tester) async {
    var received = 0;
    Map<String, dynamic>? posted;

    await _pumpScreen(
      tester,
      stock: [_balanceOf('0.000')],
      orders: [_openPo],
      locations: [_shop],
      onRequest: (request) {
        if (request.method == 'POST' &&
            request.url.path.endsWith(
              '/inventory/purchase-orders/po-1/receives',
            )) {
          posted = jsonDecode(request.body) as Map<String, dynamic>;
          received = 4;
          return _json({
            'data': {
              'id': 'recv-1',
              'location': {'id': 'loc-1', 'name': 'Main shop'},
            },
          }, 201);
        }
        if (request.url.path.endsWith('/inventory/stock')) {
          return _json({
            'data': [_balanceOf('$received.000')],
            'meta': {'total': 1, 'last_page': 1},
          });
        }
        return null;
      },
    );

    expect(find.textContaining('0 each'), findsOneWidget);

    await tester.tap(find.text('Orders'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('PO-00001'));
    await tester.pumpAndSettle();

    expect(find.byType(Dialog), findsNothing);
    expect(find.text('Receive'), findsOneWidget);

    await tester.enterText(
      find.widgetWithText(TextField, 'Qty this time'),
      '4',
    );
    await tester.tap(find.text('Receive'));
    await tester.pumpAndSettle();

    expect(posted, isNotNull);
    expect(posted!['location_id'], 'loc-1');
    expect(posted!['lines'], isA<List>());
    expect((posted!['lines'] as List).first['qty'], '4');
    expect((posted!['lines'] as List).first['purchase_order_line_id'], 'pol-1');

    await tester.tap(find.text('Stock'));
    await tester.pumpAndSettle();

    expect(find.textContaining('4 each'), findsOneWidget);
    expect(find.textContaining('0 each'), findsNothing);
  });

  testWidgets('at 1280 a selected row keeps the list at 25%', (tester) async {
    await _pumpScreen(tester, stock: [_balance], size: const Size(1280, 900));

    await tester.tap(find.text('6 mil liner'));
    await tester.pumpAndSettle();

    expect(find.byTooltip('Back to the list'), findsNothing);

    final list = tester.getRect(find.byKey(const Key('inventory-list')));
    final pane = tester.getRect(find.byKey(const Key('inventory-pane')));
    expect(pane.width, closeTo(list.width * 3, 8));
  });

  testWidgets('at 375 the pane is the whole body, with Back', (tester) async {
    await _pumpScreen(tester, size: const Size(375, 800));

    expect(tester.takeException(), isNull);

    await tester.tap(find.text('Items'));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);

    await tester.ensureVisible(find.text('Add item'));
    await tester.tap(find.text('Add item'));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(find.text('New item'), findsOneWidget);
    expect(find.byKey(const Key('inventory-list')), findsNothing);
    expect(find.byTooltip('Back to the list'), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);

    await tester.tap(find.byTooltip('Back to the list'));
    await tester.pumpAndSettle();

    expect(find.text('New item'), findsNothing);
    expect(find.byKey(const Key('inventory-list')), findsOneWidget);
  });

  testWidgets('unit cost and last cost stay off without financials', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      stock: [
        _balanceOf('10.000', lastCost: 1250),
      ],
      orders: [
        {
          ..._openPo,
          'lines': [
            {
              ...(_openPo['lines'] as List).first as Map<String, dynamic>,
              'unit_cost_minor': 1250,
            },
          ],
        },
      ],
      items: [
        {
          ..._item,
          'last_cost_minor': 1250,
        },
      ],
    );

    await tester.tap(find.text('6 mil liner'));
    await tester.pumpAndSettle();

    expect(find.text('Last cost'), findsNothing);
    expect(find.textContaining(r'$12.50'), findsNothing);

    await tester.tap(find.text('Orders'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('PO-00001'));
    await tester.pumpAndSettle();

    expect(find.text('Unit cost'), findsNothing);
    expect(find.textContaining(r'$12.50'), findsNothing);
  });

  testWidgets('financials show last cost and unit cost', (tester) async {
    await _pumpScreen(
      tester,
      permissions: const [
        'inventory.view',
        'inventory.create',
        'inventory.edit',
        'inventory.financials',
      ],
      stock: [
        _balanceOf('10.000', lastCost: 1250),
      ],
      orders: [
        {
          ..._openPo,
          'lines': [
            {
              ...(_openPo['lines'] as List).first as Map<String, dynamic>,
              'unit_cost_minor': 1250,
            },
          ],
        },
      ],
      items: [
        {
          ..._item,
          'last_cost_minor': 1250,
        },
      ],
    );

    await tester.tap(find.text('6 mil liner'));
    await tester.pumpAndSettle();
    expect(find.textContaining(r'$12.50'), findsWidgets);

    await tester.tap(find.text('Orders'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('PO-00001'));
    await tester.pumpAndSettle();
    expect(find.textContaining(r'$12.50'), findsWidgets);
  });

  testWidgets('Add item is not drawn without create', (tester) async {
    await _pumpScreen(tester, permissions: const ['inventory.view']);

    await tester.tap(find.text('Items'));
    await tester.pumpAndSettle();

    expect(find.text('Add item'), findsNothing);
  });

  testWidgets(
    'opening a later-page balance still shows qty in the pane',
    (tester) async {
      final itemIds = <String>[];
      var unfiltered = 0;

      await _pumpScreen(
        tester,
        onRequest: (request) {
          if (!request.url.path.endsWith('/inventory/stock')) return null;

          final itemId = request.url.queryParameters['item_id'];
          if (itemId != null && itemId.isNotEmpty) {
            itemIds.add(itemId);
            return _json({
              'data': [_balanceOf('17.000')],
              'meta': {'total': 1, 'last_page': 1},
            });
          }

          unfiltered++;
          if (unfiltered == 1) {
            return _json({
              'data': [_balanceOf('17.000')],
              'meta': {'total': 1, 'last_page': 1},
            });
          }

          // Later unfiltered GETs are page 1 of a larger index — this row
          // would not be on it.
          return _json({
            'data': <Map<String, dynamic>>[],
            'meta': {'total': 0, 'last_page': 1},
          });
        },
      );

      await tester.tap(find.text('6 mil liner'));
      await tester.pumpAndSettle();

      expect(itemIds, contains('item-1'));
      expect(find.textContaining('17 each'), findsWidgets);
      expect(find.text('None on hand at a visible location.'), findsNothing);
    },
  );

  testWidgets('a failed stock fetch in the pane is the request failed', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      stock: [_balance],
      onRequest: (request) {
        if (!request.url.path.endsWith('/inventory/stock')) return null;
        final itemId = request.url.queryParameters['item_id'];
        if (itemId != null && itemId.isNotEmpty) {
          return _json({'message': 'the request failed'}, 500);
        }
        return null;
      },
    );

    await tester.tap(find.text('6 mil liner'));
    await tester.pumpAndSettle();

    expect(find.text('the request failed'), findsOneWidget);
    expect(find.text('None on hand at a visible location.'), findsNothing);
  });

  testWidgets('van kind is hidden without fleet view', (tester) async {
    await _pumpScreen(tester);

    await tester.tap(find.text('Locations'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Add location'));
    await tester.pumpAndSettle();

    expect(find.widgetWithText(ChoiceChip, 'Van'), findsNothing);
    expect(find.widgetWithText(ChoiceChip, 'Shop'), findsOneWidget);
  });
}

const _item = {
  'id': 'item-1',
  'number': 'I-00001',
  'sku': 'LINER-6',
  'name': '6 mil liner',
  'unit': 'each',
  'status': 'active',
  'reorder_at': '5.000',
  'company': {'id': 'co-1', 'name': 'Everdoors'},
  'franchise': {'id': 'fr-1', 'name': 'Connecticut'},
};

const _shop = {
  'id': 'loc-1',
  'name': 'Main shop',
  'kind': 'shop',
  'status': 'active',
  'company': {'id': 'co-1', 'name': 'Everdoors'},
  'franchise': {'id': 'fr-1', 'name': 'Connecticut'},
};

Map<String, dynamic> _balanceOf(String qty, {int? lastCost}) => {
      'id': 'bal-1',
      'qty_on_hand': qty,
      'item': {
        ..._item,
        'last_cost_minor': ?lastCost,
      },
      'location': {
        'id': 'loc-1',
        'name': 'Main shop',
        'kind': 'shop',
        'status': 'active',
      },
    };

final _balance = _balanceOf('4.000');

const _openPo = {
  'id': 'po-1',
  'number': 'PO-00001',
  'status': 'open',
  'vendor': {'id': 'ven-1', 'name': 'Supply Co'},
  'company': {'id': 'co-1', 'name': 'Everdoors'},
  'franchise': {'id': 'fr-1', 'name': 'Connecticut'},
  'lines': [
    {
      'id': 'pol-1',
      'qty_ordered': '10.000',
      'qty_received': '0.000',
      'item': {'id': 'item-1', 'name': '6 mil liner'},
    },
  ],
};

Future<void> _pumpShell(WidgetTester tester) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(_handler()));
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: ListenableBuilder(
        listenable: session,
        builder: (_, _) => HomeShell(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();

  final inSidebar = find.descendant(
    of: find.byType(NavigationSidebar),
    matching: find.text('Inventory'),
  );
  expect(inSidebar, findsOneWidget);
  await tester.tap(inSidebar);
  await tester.pumpAndSettle();
}

Future<void> _pumpScreen(
  WidgetTester tester, {
  List<String> permissions = const [
    'inventory.view',
    'inventory.create',
    'inventory.edit',
  ],
  List<Map<String, dynamic>> stock = const [],
  List<Map<String, dynamic>> items = const [],
  List<Map<String, dynamic>> orders = const [],
  List<Map<String, dynamic>> issues = const [],
  List<Map<String, dynamic>> locations = const [],
  Size size = const Size(1280, 900),
  http.Response? Function(http.Request request)? onRequest,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient(
      _handler(
        permissions: permissions,
        stock: stock,
        items: items,
        orders: orders,
        issues: issues,
        locations: locations,
        onRequest: onRequest,
      ),
    ),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: InventoryScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

MockClientHandler _handler({
  List<String> permissions = const [
    'inventory.view',
    'inventory.create',
    'inventory.edit',
  ],
  List<Map<String, dynamic>> stock = const [],
  List<Map<String, dynamic>> items = const [],
  List<Map<String, dynamic>> orders = const [],
  List<Map<String, dynamic>> issues = const [],
  List<Map<String, dynamic>> locations = const [],
  http.Response? Function(http.Request request)? onRequest,
}) {
  return (request) async {
    final override = onRequest?.call(request);
    if (override != null) return override;

    final path = request.url.path;

    if (path.endsWith('/me')) {
      return _json({
        'data': {..._me, 'permissions': permissions},
      });
    }

    if (path.endsWith('/inventory/stock')) {
      return _json({
        'data': stock,
        'meta': {'total': stock.length, 'last_page': 1},
      });
    }

    if (path.endsWith('/inventory/items')) {
      return _json({
        'data': items.isEmpty && stock.isNotEmpty
            ? [stock.first['item']]
            : items,
        'meta': {'total': items.length, 'last_page': 1},
      });
    }

    if (RegExp(r'/inventory/items/[^/]+$').hasMatch(path)) {
      final id = path.split('/').last;
      final fromItems = items.where((row) => row['id'] == id);
      if (fromItems.isNotEmpty) return _json({'data': fromItems.first});
      if (stock.isNotEmpty) {
        return _json({'data': stock.first['item']});
      }
      return _json({'data': _item});
    }

    if (path.endsWith('/inventory/locations')) {
      return _json({
        'data': locations.isEmpty && stock.isNotEmpty ? [_shop] : locations,
        'meta': {'total': locations.length, 'last_page': 1},
      });
    }

    if (path.endsWith('/inventory/purchase-orders')) {
      return _json({
        'data': orders,
        'meta': {'total': orders.length, 'last_page': 1},
      });
    }

    if (RegExp(r'/inventory/purchase-orders/[^/]+$').hasMatch(path)) {
      final id = path.split('/').last;
      final match = orders.where((row) => row['id'] == id);
      return _json({'data': match.isEmpty ? _openPo : match.first});
    }

    if (path.endsWith('/inventory/issues')) {
      return _json({
        'data': issues,
        'meta': {'total': issues.length, 'last_page': 1},
      });
    }

    if (path.endsWith('/vehicles')) {
      return _json({
        'data': <Map<String, dynamic>>[],
        'meta': {'total': 0, 'last_page': 1},
      });
    }

    return _json({'message': 'Nothing stubbed for $path.'}, 500);
  };
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
  'companies': [
    {'id': 'co-1', 'name': 'Everdoors', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
  ],
  'modules': [
    {
      'key': 'inventory',
      'label': 'Inventory',
      'available': true,
      'reachable': true,
    },
  ],
};
