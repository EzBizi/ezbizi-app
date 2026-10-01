import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:ezbizi_client/screens/inventory/stock_join.dart';
import 'package:ezbizi_client/screens/inventory_screen.dart';
import 'package:ezbizi_client/widgets/coming_soon.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Inventory is a register, not a placeholder: chips, 25/75, receive, no modal.
void main() {
  // Every Inventory list, both Services lists and Training's dashboard stand
  // on this scaffold. On a phone its header used to stand over the rows.
  testWidgets('on a phone the scaffold scrolls its header away', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(411, 800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: InventorySplitScaffold(
            hasPane: false,
            loading: false,
            error: null,
            onRetry: () {},
            header: const SizedBox(height: 300, child: Text('The header')),
            list: ListView(
              children: [
                for (var i = 0; i < 40; i++)
                  SizedBox(height: 60, child: Text('Row $i')),
              ],
            ),
            pane: ({required showBack}) => const SizedBox(),
          ),
        ),
      ),
    );

    expect(find.text('The header'), findsOneWidget);

    await tester.drag(find.text('Row 2'), const Offset(0, -500));
    await tester.pumpAndSettle();

    expect(find.text('The header'), findsNothing);
  });

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
    // UI queue 43: judged by the item's total, in the design's words.
    expect(find.text('Below reorder'), findsWidgets);
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
        'inventory.stock.view',
        'inventory.items.view',
        'inventory.orders.view',
        'inventory.locations.view',
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
    // No `inventory.create`, but the Items TAB is granted — otherwise this
    // asserts the absence of Add on a screen with no Items chip to open, which
    // would pass for the wrong reason.
    await _pumpScreen(
      tester,
      permissions: const ['inventory.view', 'inventory.items.view'],
    );

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

  testWidgets('Orders offers a purchase order and an issue, not a modal', (
    tester,
  ) async {
    await _pumpScreen(tester);

    await tester.tap(find.text('Orders'));
    await tester.pumpAndSettle();

    expect(find.text('Add purchase order'), findsOneWidget);
    expect(find.widgetWithText(OutlinedButton, 'Issue'), findsOneWidget);
    expect(find.text('Add sale'), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);
  });

  testWidgets('a purchase order draft posts vendor, item and qty', (
    tester,
  ) async {
    Map<String, dynamic>? posted;

    await _pumpScreen(
      tester,
      vendors: [_vendor],
      items: [_item],
      onRequest: (request) {
        if (request.method == 'POST' &&
            request.url.path.endsWith('/inventory/purchase-orders')) {
          posted = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({
            'data': {..._openPo, 'status': 'draft'},
          }, 201);
        }
        return null;
      },
    );

    await tester.tap(find.text('Orders'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Add purchase order'));
    await tester.pumpAndSettle();

    expect(find.byType(Dialog), findsNothing);
    expect(find.text('New purchase order'), findsOneWidget);
    expect(find.text('New vendor'), findsOneWidget);

    await tester.enterText(
      find.widgetWithText(TextField, 'Qty ordered'),
      '10',
    );
    await tester.pump();
    await tester.tap(find.text('Save draft'));
    await tester.pumpAndSettle();

    expect(posted, isNotNull);
    expect(posted!['vendor_id'], 'ven-1');
    expect(posted!['lines'], isA<List>());
    expect((posted!['lines'] as List).first['item_id'], 'item-1');
    expect((posted!['lines'] as List).first['qty'], '10');
    expect(find.text('PO-00001'), findsWidgets);
  });

  testWidgets('Issue posts from a location with a note', (tester) async {
    Map<String, dynamic>? posted;

    await _pumpScreen(
      tester,
      items: [_item],
      locations: [_shop],
      onRequest: (request) {
        if (request.method == 'POST' &&
            request.url.path.endsWith('/inventory/issues')) {
          posted = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({
            'data': {
              'id': 'iss-1',
              'number': 'IS-00001',
              'note': 'Sold over the counter',
              'location': {'id': 'loc-1', 'name': 'Main shop'},
              'lines': [
                {
                  'qty': '2',
                  'item': {'id': 'item-1', 'name': '6 mil liner'},
                },
              ],
            },
          }, 201);
        }
        return null;
      },
    );

    await tester.tap(find.text('Orders'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(OutlinedButton, 'Issue'));
    await tester.pumpAndSettle();

    expect(find.byType(Dialog), findsNothing);
    expect(find.text('Issue stock'), findsOneWidget);

    await tester.enterText(
      find.widgetWithText(TextField, 'Why it left'),
      'Sold over the counter',
    );
    await tester.enterText(find.widgetWithText(TextField, 'Qty'), '2');
    await tester.pump();
    await tester.tap(find.widgetWithText(FilledButton, 'Issue'));
    await tester.pumpAndSettle();

    expect(posted, isNotNull);
    expect(posted!['location_id'], 'loc-1');
    expect(posted!['note'], 'Sold over the counter');
    expect((posted!['lines'] as List).first['item_id'], 'item-1');
    expect((posted!['lines'] as List).first['qty'], '2');
    expect(find.text('IS-00001'), findsOneWidget);
  });

  testWidgets('Transfer posts from one location to another', (tester) async {
    Map<String, dynamic>? posted;

    await _pumpScreen(
      tester,
      items: [_item],
      locations: [_shop, _cage],
      onRequest: (request) {
        if (request.method == 'POST' &&
            request.url.path.endsWith('/inventory/transfers')) {
          posted = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({
            'data': {
              'id': 'tr-1',
              'number': 'T-00001',
              'from_location': {'id': 'loc-1', 'name': 'Main shop'},
              'to_location': {'id': 'loc-2', 'name': 'Cage'},
            },
          }, 201);
        }
        return null;
      },
    );

    await tester.tap(find.widgetWithText(FilledButton, 'Transfer'));
    await tester.pumpAndSettle();

    expect(find.byType(Dialog), findsNothing);
    expect(find.text('From'), findsOneWidget);

    await tester.tap(find.widgetWithText(InputDecorator, 'To'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Cage · Everdoors · Connecticut').last);
    await tester.pumpAndSettle();

    await tester.enterText(find.widgetWithText(TextField, 'Qty'), '1');
    await tester.pump();
    await tester.tap(find.widgetWithText(FilledButton, 'Transfer').last);
    await tester.pumpAndSettle();

    expect(posted, isNotNull);
    expect(posted!['from_location_id'], 'loc-1');
    expect(posted!['to_location_id'], 'loc-2');
    expect((posted!['lines'] as List).first['item_id'], 'item-1');
    expect((posted!['lines'] as List).first['qty'], '1');
  });

  testWidgets(
    'a company view does not offer sibling companies on New location',
    (tester) async {
      await _pumpScreen(
        tester,
        me: {
          'organization': {
            'id': 'org-1',
            'name': 'EzBizi Group',
            'franchise_label': 'Franchise',
            'is_read_only': false,
          },
          'scope': {
            'type': 'company',
            'id': 'co-a1',
            'label': 'A-1 Chimney',
            'path': ['A-1 Chimney'],
          },
          'companies': [
            {'id': 'co-a1', 'name': 'A-1 Chimney', 'parent_company_id': null},
            {'id': 'co-ever', 'name': 'Everdoors', 'parent_company_id': null},
            {
              'id': 'co-premier',
              'name': 'Premier Garage Repair',
              'parent_company_id': 'co-ever',
            },
            {
              'id': 'co-speedy',
              'name': 'Speedy Garage Doors',
              'parent_company_id': 'co-ever',
            },
            {'id': 'co-ez', 'name': 'EzBizi', 'parent_company_id': null},
          ],
          'franchises': [
            {'id': 'fr-a1', 'name': 'Connecticut', 'company_id': 'co-a1'},
            {'id': 'fr-ever', 'name': 'Ohio', 'company_id': 'co-ever'},
          ],
        },
      );

      await tester.tap(find.text('Locations'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Add location'));
      await tester.pumpAndSettle();

      expect(find.text('Everdoors'), findsNothing);
      expect(find.text('Premier Garage Repair'), findsNothing);
      expect(find.text('Speedy Garage Doors'), findsNothing);
      expect(find.text('EzBizi'), findsNothing);
      expect(find.text('Company'), findsNothing);
      expect(find.text('Franchise'), findsOneWidget);
    },
  );

  testWidgets('a sale draft posts customer, item and qty', (tester) async {
    Map<String, dynamic>? posted;

    await _pumpScreen(
      tester,
      customers: [_customer],
      items: [_item],
      onRequest: (request) {
        if (request.method == 'POST' &&
            request.url.path.endsWith('/inventory/sales')) {
          posted = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({
            'data': {..._openSale, 'status': 'draft'},
          }, 201);
        }
        return null;
      },
    );

    await tester.tap(find.text('Orders'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Add sale'));
    await tester.pumpAndSettle();

    expect(find.byType(Dialog), findsNothing);
    expect(find.text('New sale'), findsOneWidget);

    await tester.enterText(
      find.widgetWithText(TextField, 'Qty ordered'),
      '5',
    );
    await tester.pump();
    await tester.tap(find.text('Save draft'));
    await tester.pumpAndSettle();

    expect(posted, isNotNull);
    expect(posted!['customer_id'], 'cust-1');
    expect(posted!.containsKey('note'), isFalse);
    expect((posted!['lines'] as List).first['item_id'], 'item-1');
    expect((posted!['lines'] as List).first['qty'], '5');
    expect(find.text('SO-00001'), findsWidgets);
  });

  testWidgets('a counter sale posts a note and no customer', (tester) async {
    Map<String, dynamic>? posted;

    await _pumpScreen(
      tester,
      items: [_item],
      onRequest: (request) {
        if (request.method == 'POST' &&
            request.url.path.endsWith('/inventory/sales')) {
          posted = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({
            'data': {
              ..._openSale,
              'status': 'draft',
              'customer': null,
              'note': 'Walk-in',
            },
          }, 201);
        }
        return null;
      },
    );

    await tester.tap(find.text('Orders'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Add sale'));
    await tester.pumpAndSettle();

    expect(find.text('Counter sale'), findsWidgets);

    await tester.enterText(find.widgetWithText(TextField, 'Note'), 'Walk-in');
    await tester.enterText(
      find.widgetWithText(TextField, 'Qty ordered'),
      '1',
    );
    await tester.pump();
    await tester.tap(find.text('Save draft'));
    await tester.pumpAndSettle();

    expect(posted, isNotNull);
    expect(posted!.containsKey('customer_id'), isFalse);
    expect(posted!['note'], 'Walk-in');
    expect((posted!['lines'] as List).first['qty'], '1');
  });

  testWidgets('Fulfill posts from a location', (tester) async {
    Map<String, dynamic>? posted;

    await _pumpScreen(
      tester,
      sales: [_openSale],
      locations: [_shop],
      onRequest: (request) {
        if (request.method == 'POST' &&
            request.url.path.endsWith('/inventory/sales/so-1/fulfillments')) {
          posted = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({
            'data': {
              'id': 'ful-1',
              'location': {'id': 'loc-1', 'name': 'Main shop'},
              'sale': {'id': 'so-1', 'number': 'SO-00001', 'status': 'partial'},
            },
          }, 201);
        }
        return null;
      },
    );

    await tester.tap(find.text('Orders'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('SO-00001'));
    await tester.pumpAndSettle();

    expect(find.byType(Dialog), findsNothing);
    expect(find.text('Fulfill'), findsOneWidget);

    await tester.enterText(
      find.widgetWithText(TextField, 'Qty this time'),
      '2',
    );
    await tester.pump();
    await tester.tap(find.text('Fulfill'));
    await tester.pumpAndSettle();

    expect(posted, isNotNull);
    expect(posted!['location_id'], 'loc-1');
    expect((posted!['lines'] as List).first['qty'], '2');
    expect((posted!['lines'] as List).first['sale_line_id'], 'sol-1');
  });

  testWidgets('Locations without the Stock tab does not claim what a place holds', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      permissions: const ['inventory.view', 'inventory.locations.view'],
      stock: [_balanceOf('4.000')],
      locations: [_shop],
    );

    // Locations is this role's only chip. The server answers `holds: null`
    // for a role that was never shown quantities (Inventory 8f2d23be).
    final row = find.byKey(const Key('location-row-loc-1'));
    expect(row, findsOneWidget);
    expect(find.descendant(of: row, matching: find.text('—')), findsOneWidget);
    expect(find.descendant(of: row, matching: find.textContaining('item')), findsNothing);
  });

  test('a table of values reads by its thousands', () {
    expect(inventoryMoney(505700), r'$5,057.00');
    expect(inventoryMoney(100000000), r'$1,000,000.00');
    expect(inventoryMoney(7), r'$0.07');
    expect(inventoryMoney(-1234), r'-$12.34');
  });

  // ── UI queue 43 (Jose's 1 and 4): one row per item, places inside ──

  testWidgets('Stock is one row per item, its places inside it', (tester) async {
    await _pumpScreen(
      tester,
      stock: [
        _balanceOf('4.000'),
        {
          ..._balanceOf('6.000'),
          'id': 'bal-2',
          'location': {'id': 'loc-2', 'name': 'Cage', 'kind': 'cage', 'status': 'active'},
        },
      ],
    );

    // The item's total across both places, once.
    final row = find.byKey(const Key('stock-item-item-1'));
    expect(row, findsOneWidget);
    expect(find.descendant(of: row, matching: find.text('10 each')), findsOneWidget);
    expect(find.descendant(of: row, matching: find.textContaining('2 places')), findsOneWidget);
    expect(find.byKey(const Key('stock-place-item-1-loc-2')), findsNothing);

    await tester.tap(find.byKey(const Key('stock-unfold-item-1')));
    await tester.pumpAndSettle();
    final cage = find.byKey(const Key('stock-place-item-1-loc-2'));
    expect(cage, findsOneWidget);

    // Transfer on a place's own line opens the docked pane, not a dialog.
    await tester.tap(find.descendant(of: cage, matching: find.text('Transfer')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('inventory-pane')), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);
  });

  testWidgets('the tiles filter Stock, and judge an item by its total', (tester) async {
    await _pumpScreen(
      tester,
      stock: [
        _balanceOf('4.000'),
        {
          ..._balanceOf('0.000'),
          'id': 'bal-2',
          'item': {..._item, 'id': 'item-2', 'name': 'Crown sealant', 'reorder_at': null},
        },
        {
          ..._balanceOf('40.000'),
          'id': 'bal-3',
          'item': {..._item, 'id': 'item-3', 'name': 'Firebrick'},
        },
      ],
    );

    expect(find.byKey(const Key('stock-item-item-3')), findsOneWidget);

    await tester.tap(find.byKey(const Key('stock-tile-low')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('stock-item-item-1')), findsOneWidget);
    expect(find.byKey(const Key('stock-item-item-2')), findsNothing);
    expect(find.byKey(const Key('stock-item-item-3')), findsNothing);

    await tester.tap(find.byKey(const Key('stock-tile-out')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('stock-item-item-2')), findsOneWidget);
    expect(find.byKey(const Key('stock-item-item-1')), findsNothing);
  });

  testWidgets('Stock without the Orders tab makes no claim about orders', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      permissions: const ['inventory.view', 'inventory.stock.view'],
      stock: [_balance], // below its reorder point
      orders: [_openPo],
    );

    // Positive control: the Stock tab drew its tiles.
    expect(find.text('Below reorder'), findsOneWidget);

    // The role cannot see purchase orders, so it was never shown whether a
    // low item is already on order. "none covered by an order" would tell a
    // stock clerk to raise one when it may already be coming.
    expect(find.text('none covered by an order'), findsNothing);
    expect(find.textContaining('on 0 orders'), findsNothing);
  });

  testWidgets('Items without the Stock tab does not claim 0 on hand', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      permissions: const ['inventory.view', 'inventory.items.view'],
      stock: [_balanceOf('4.000')],
      // What the server answers this role: `/inventory/stock` is gated on
      // `inventory.stock.view`. The mock serves it to anyone unless told, so
      // without this the test cannot see the bug it is here to catch.
      onRequest: (request) {
        if (request.url.path.endsWith('/inventory/stock')) {
          return _json({
            'message': 'You do not have permission to open this tab.',
          }, 403);
        }
        return null;
      },
    );

    // Items is this role's only chip, so it opens first.
    final row = find.byKey(const Key('item-row-item-1'));
    expect(row, findsOneWidget);

    // The role was never shown quantities, so the cell says it does not know
    // rather than that the item holds nothing. A "0" here is a fact invented
    // out of a refusal — the item really holds 4.
    expect(find.descendant(of: row, matching: find.text('—')), findsOneWidget);
    expect(
      find.descendant(of: row, matching: find.text('0')),
      findsNothing,
    );
  });

  testWidgets('Items, Orders and Locations are tables with their totals', (tester) async {
    await _pumpScreen(
      tester,
      stock: [_balanceOf('4.000')],
      orders: [_openPo],
      locations: [_shop],
    );

    await tester.tap(find.text('Items'));
    await tester.pumpAndSettle();
    expect(
      find.descendant(
        of: find.byKey(const Key('item-row-item-1')),
        matching: find.text('4 each'),
      ),
      findsOneWidget,
    );

    await tester.tap(find.text('Orders'));
    await tester.pumpAndSettle();
    expect(
      find.descendant(
        of: find.byKey(const Key('order-row-po-po-1')),
        matching: find.text('0/10'),
      ),
      findsOneWidget,
    );

    await tester.tap(find.text('Locations'));
    await tester.pumpAndSettle();
    expect(
      find.descendant(
        of: find.byKey(const Key('location-row-loc-1')),
        matching: find.text('1 item'),
      ),
      findsOneWidget,
    );
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

const _vendor = {'id': 'ven-1', 'name': 'Supply Co'};

const _customer = {'id': 'cust-1', 'name': 'Pat Buyer', 'number': 'C-00001'};

const _cage = {
  'id': 'loc-2',
  'name': 'Cage',
  'kind': 'cage',
  'status': 'active',
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

const _openSale = {
  'id': 'so-1',
  'number': 'SO-00001',
  'status': 'open',
  'payment_status': 'unpaid',
  'note': null,
  'customer': {'id': 'cust-1', 'name': 'Pat Buyer', 'number': 'C-00001'},
  'company': {'id': 'co-1', 'name': 'Everdoors'},
  'franchise': {'id': 'fr-1', 'name': 'Connecticut'},
  'lines': [
    {
      'id': 'sol-1',
      'qty_ordered': '5.000',
      'qty_fulfilled': '0.000',
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
    // The four tab grants (Jose, 2026-09-17). A role holding `inventory.view`
    // and none of these draws no chips at all, so every fixture that opens a
    // tab needs them — the migration grants them to existing roles for the
    // same reason.
    'inventory.stock.view',
    'inventory.items.view',
    'inventory.orders.view',
    'inventory.locations.view',
  ],
  Map<String, dynamic>? me,
  List<Map<String, dynamic>> stock = const [],
  List<Map<String, dynamic>> items = const [],
  List<Map<String, dynamic>> orders = const [],
  List<Map<String, dynamic>> issues = const [],
  List<Map<String, dynamic>> locations = const [],
  List<Map<String, dynamic>> vendors = const [],
  List<Map<String, dynamic>> customers = const [],
  List<Map<String, dynamic>> sales = const [],
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
        me: me,
        stock: stock,
        items: items,
        orders: orders,
        issues: issues,
        locations: locations,
        vendors: vendors,
        customers: customers,
        sales: sales,
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
    // The four tab grants (Jose, 2026-09-17). A role holding `inventory.view`
    // and none of these draws no chips at all, so every fixture that opens a
    // tab needs them — the migration grants them to existing roles for the
    // same reason.
    'inventory.stock.view',
    'inventory.items.view',
    'inventory.orders.view',
    'inventory.locations.view',
  ],
  Map<String, dynamic>? me,
  List<Map<String, dynamic>> stock = const [],
  List<Map<String, dynamic>> items = const [],
  List<Map<String, dynamic>> orders = const [],
  List<Map<String, dynamic>> issues = const [],
  List<Map<String, dynamic>> locations = const [],
  List<Map<String, dynamic>> vendors = const [],
  List<Map<String, dynamic>> customers = const [],
  List<Map<String, dynamic>> sales = const [],
  http.Response? Function(http.Request request)? onRequest,
}) {
  late final MockClientHandler self;

  /// What /inventory/stock answers THIS test, its overrides included, so the
  /// server's per-item figures below follow a test that changes stock.
  Future<List<Map<String, dynamic>>> balances() async {
    final answer = await self(
      http.Request('GET', Uri.parse('http://localhost/api/v1/inventory/stock')),
    );
    if (answer.statusCode != 200) return const [];
    return [
      for (final row in (jsonDecode(answer.body)['data'] as List? ?? const []))
        Map<String, dynamic>.from(row as Map),
    ];
  }

  double qtyOf(Object? raw) => double.tryParse('$raw') ?? 0;
  final mayStock = permissions.contains('inventory.stock.view');
  final mayOrders = permissions.contains('inventory.orders.view');
  final mayMoney = permissions.contains('inventory.financials');

  return self = (request) async {
    final override = onRequest?.call(request);
    if (override != null) return override;

    final path = request.url.path;

    // GET /inventory/stock/items (Inventory 51ab95c7), built the way the
    // server builds it: one row per item, its status by the TOTAL, what is on
    // order only for a role that may see orders.
    if (path.endsWith('/inventory/stock/items')) {
      // A test that fails the stock read fails this one too.
      final answer = await self(
        http.Request('GET', Uri.parse('http://localhost/api/v1/inventory/stock')),
      );
      if (answer.statusCode != 200) return answer;
      final rows = [
        for (final row in (jsonDecode(answer.body)['data'] as List? ?? const []))
          Map<String, dynamic>.from(row as Map),
      ];
      final byItem = <String, List<Map<String, dynamic>>>{};
      final itemOf = <String, Map<String, dynamic>>{};
      for (final row in rows) {
        final item = Map<String, dynamic>.from(row['item'] as Map);
        byItem.putIfAbsent(item['id'] as String, () => []).add(row);
        itemOf[item['id'] as String] = item;
      }
      for (final item in items) {
        itemOf.putIfAbsent(item['id'] as String, () => item);
      }

      double ordered(String id, String status) {
        var sum = 0.0;
        for (final order in orders) {
          if (order['status'] != status) continue;
          for (final line in (order['lines'] as List? ?? const [])) {
            if ((line as Map)['item']?['id'] != id) continue;
            sum += qtyOf(line['qty_ordered']) - qtyOf(line['qty_received']);
          }
        }
        return sum;
      }

      final grouped = [
        for (final entry in itemOf.entries)
          () {
            final places = byItem[entry.key] ?? const [];
            final total = places.fold(0.0, (t, r) => t + qtyOf(r['qty_on_hand']));
            final at = double.tryParse('${entry.value['reorder_at']}');
            return {
              // The server leaves the cost out without financials.
              'item': {
                for (final field in entry.value.entries)
                  if (mayMoney || field.key != 'last_cost_minor')
                    field.key: field.value,
              },
              'on_hand': total.toStringAsFixed(3),
              'status': total <= 0
                  ? 'out_of_stock'
                  : at != null && total <= at
                  ? 'below_reorder'
                  : 'in_stock',
              'places': [
                for (final r in places)
                  {'location': r['location'], 'qty_on_hand': r['qty_on_hand']},
              ],
              'on_order': mayOrders
                  ? ordered(entry.key, 'open').toStringAsFixed(3)
                  : null,
              'in_draft': mayOrders
                  ? ordered(entry.key, 'draft').toStringAsFixed(3)
                  : null,
              'order_numbers': [
                if (mayOrders)
                  for (final order in orders)
                    if (order['status'] == 'open' &&
                        (order['lines'] as List? ?? const []).any(
                          (l) => (l as Map)['item']?['id'] == entry.key,
                        ))
                      order['number'],
              ],
            };
          }(),
      ];

      return _json({
        'data': grouped,
        'meta': {
          'total': grouped.length,
          'last_page': 1,
          'tiles': {
            'items': grouped.length,
            if (mayOrders)
              'open_orders': orders.where((o) => o['status'] == 'open').length,
          },
        },
      });
    }

    if (path.endsWith('/me')) {
      return _json({
        'data': {..._me, 'permissions': permissions, ...?me},
      });
    }

    if (request.method == 'POST' && path.endsWith('/inventory/vendors')) {
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      return _json({
        'data': {
          'id': 'ven-new',
          'name': body['name'],
        },
      }, 201);
    }

    if (path.endsWith('/inventory/vendors')) {
      return _json({
        'data': vendors,
        'meta': {'total': vendors.length, 'last_page': 1},
      });
    }

    if (path.endsWith('/jobs')) {
      return _json({
        'data': <Map<String, dynamic>>[],
        'meta': {'total': 0, 'last_page': 1},
      });
    }

    if (path.endsWith('/inventory/stock')) {
      return _json({
        'data': stock,
        'meta': {'total': stock.length, 'last_page': 1},
      });
    }

    if (path.endsWith('/inventory/items')) {
      final rows = mayStock ? await balances() : const <Map<String, dynamic>>[];
      double onHand(Object? id) => rows
          .where((r) => (r['item'] as Map)['id'] == id)
          .fold(0.0, (t, r) => t + qtyOf(r['qty_on_hand']));
      return _json({
        'data': [
          for (final item in items.isEmpty && stock.isNotEmpty
              ? [stock.first['item'] as Map<String, dynamic>]
              : items)
            {
              ...item,
              'on_hand': mayStock ? onHand(item['id']).toStringAsFixed(3) : null,
            },
        ],
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
      final rows = mayStock ? await balances() : const <Map<String, dynamic>>[];
      return _json({
        'data': [
          for (final location in locations.isEmpty && stock.isNotEmpty
              ? [_shop]
              : locations)
            {
              ...location,
              'holds': mayStock
                  ? rows
                        .where(
                          (r) =>
                              (r['location'] as Map)['id'] == location['id'] &&
                              qtyOf(r['qty_on_hand']) > 0,
                        )
                        .length
                  : null,
              if (mayMoney) 'value_minor': null,
            },
        ],
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

    if (path.endsWith('/customers')) {
      return _json({
        'data': customers,
        'meta': {'total': customers.length, 'last_page': 1},
      });
    }

    if (path.endsWith('/inventory/sales')) {
      return _json({
        'data': sales,
        'meta': {'total': sales.length, 'last_page': 1},
      });
    }

    if (RegExp(r'/inventory/sales/[^/]+$').hasMatch(path)) {
      final id = path.split('/').last;
      final match = sales.where((row) => row['id'] == id);
      return _json({'data': match.isEmpty ? _openSale : match.first});
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
