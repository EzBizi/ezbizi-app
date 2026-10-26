import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/inventory/general_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Inventory › General in design 149's shape (Jose, 2026-10-26: "the
/// inventory one as well"): Stock status bar, conditional Needs attention
/// with fixes, Movement bars with a period switch, two columns of equal
/// height on a desktop and one on a phone.
void main() {
  Map<String, dynamic> summary({
    int inStock = 6,
    int low = 2,
    int out = 1,
    int orders = 3,
    bool money = true,
    List<Map<String, dynamic>> warnings = const [],
    List<double> received = const [4.0, 0.0],
    Map<String, dynamic>? previous,
    List<Map<String, dynamic>>? byLocation,
    List<Map<String, dynamic>>? topItems,
    Map<String, dynamic>? purchaseOrders,
  }) => {
    'by_location': ?byLocation,
    'top_items': ?topItems,
    'purchase_orders': ?purchaseOrders,
    'health': {
      'empty': false,
      'in_stock': inStock,
      'low': low,
      'out': out,
      'items': inStock + low + out,
      'locations': 2,
      'open_purchase_orders': orders,
      'links': {
        'low': 'stock?low_items=1',
        'items': 'items',
        'locations': 'locations',
        'open_purchase_orders': 'orders',
      },
      if (money) 'value': {'currency': 'USD', 'amount_minor': 164450},
    },
    'warnings': warnings,
    'movement': {
      'period': 'month',
      'buckets': ['2026-10-04', '2026-10-11'],
      'lines': [
        {
          'key': 'in',
          'label': 'Received',
          'internal': false,
          'points': received,
        },
        {
          'key': 'out',
          'label': 'Issued and sold',
          'internal': false,
          'points': [0.0, 0.0],
        },
        {
          'key': 'adjusted',
          'label': 'Adjusted',
          'internal': false,
          'points': [0.0, 0.0],
        },
        {
          'key': 'transferred',
          'label': 'Transferred',
          'internal': true,
          'points': [0.0, 0.0],
        },
      ],
      'previous': ?previous,
    },
  };

  Future<List<String>> pump(
    WidgetTester tester,
    Map<String, dynamic> Function(String period) body, {
    Size size = const Size(1280, 900),
  }) async {
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final opened = <String>[];
    final api = ApiClient(
      client: MockClient((request) async {
        final period = request.url.queryParameters['period'] ?? 'month';
        return http.Response(
          jsonEncode(body(period)),
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: InventoryGeneral(api: api, visible: true, onOpen: opened.add),
        ),
      ),
    );
    await tester.pumpAndSettle();
    return opened;
  }

  testWidgets('the status bar paints each part in proportion to its count', (
    tester,
  ) async {
    await pump(tester, (_) => summary());

    final bar = tester.getSize(find.byKey(const Key('inventory-status-bar')));
    final inStock = tester.getSize(
      find.byKey(const Key('inventory-status-bar-in_stock')),
    );
    final low = tester.getSize(
      find.byKey(const Key('inventory-status-bar-low')),
    );
    final out = tester.getSize(
      find.byKey(const Key('inventory-status-bar-out')),
    );

    // Painted, not only laid out: each part is as tall as the bar.
    expect(bar.height, greaterThan(0));
    expect({inStock.height, low.height, out.height}, {bar.height});

    // 6 : 2 : 1 of the bar's width.
    expect(inStock.width / bar.width, closeTo(6 / 9, 0.01));
    expect(low.width / bar.width, closeTo(2 / 9, 0.01));
    expect(out.width / bar.width, closeTo(1 / 9, 0.01));

    final paints = {
      for (final key in ['in_stock', 'low', 'out'])
        tester
            .widget<ColoredBox>(
              find.descendant(
                of: find.byKey(Key('inventory-status-bar-$key')),
                matching: find.byType(ColoredBox),
              ),
            )
            .color,
    };
    expect(paints, hasLength(3));

    expect(
      find.text('Stock value \$1,644.50 at last cost · 2 locations'),
      findsOneWidget,
    );
  });

  testWidgets('a zero part is not painted, and no money means no value', (
    tester,
  ) async {
    await pump(tester, (_) => summary(low: 0, out: 0, money: false));

    expect(
      find.byKey(const Key('inventory-status-bar-in_stock')),
      findsOneWidget,
    );
    expect(find.byKey(const Key('inventory-status-bar-low')), findsNothing);
    expect(find.byKey(const Key('inventory-status-bar-out')), findsNothing);
    expect(find.text('2 locations'), findsOneWidget);
    expect(find.textContaining('Stock value'), findsNothing);
  });

  testWidgets('each attention row opens the place that fixes it', (
    tester,
  ) async {
    final opened = await pump(
      tester,
      (_) => summary(
        warnings: [
          {
            'key': 'purchase_order_open_a_long_time',
            'count': 1,
            'sentence': '1 purchase order has been open more than 30 days.',
            'link': 'orders',
          },
        ],
      ),
    );

    await tester.tap(find.text('Create purchase order'));
    await tester.tap(find.text('Reorder'));
    await tester.tap(find.text('Receive'));
    await tester.tap(find.text('Open'));

    expect(opened, ['stock?low_items=1', 'orders', 'orders', 'orders']);
    expect(find.byKey(const Key('inventory-attention-clear')), findsNothing);
  });

  testWidgets('with nothing to fix, Needs attention is one green line', (
    tester,
  ) async {
    await pump(tester, (_) => summary(low: 0, out: 0, orders: 0));

    expect(find.byKey(const Key('inventory-attention-low')), findsNothing);
    expect(find.byKey(const Key('inventory-attention-out')), findsNothing);
    expect(find.byKey(const Key('inventory-attention-receive')), findsNothing);
    expect(find.text('Nothing needs attention.'), findsOneWidget);
    expect(find.byType(OutlinedButton), findsNothing);
  });

  testWidgets('the period switch reloads Movement, and a quiet one says so', (
    tester,
  ) async {
    final asked = <String>[];
    await pump(tester, (period) {
      asked.add(period);
      return summary(
        received: period == 'week' ? const [0.0, 0.0] : const [4.0, 6.0],
      );
    });

    expect(find.byKey(const Key('inventory-movement-in')), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const Key('inventory-movement-in')),
        matching: find.text('10'),
      ),
      findsOneWidget,
    );
    expect(find.byKey(const Key('inventory-movement-empty')), findsNothing);
    final fill = tester.getSize(
      find
          .descendant(
            of: find.byKey(const Key('inventory-movement-in')),
            matching: find.byType(ColoredBox),
          )
          .first,
    );
    expect(fill.height, 8);

    await tester.tap(find.text('Week'));
    await tester.pumpAndSettle();

    expect(asked.last, 'week');
    expect(find.byKey(const Key('inventory-movement-in')), findsNothing);
    expect(
      find.text(
        'Nothing was received, issued, adjusted or moved in this period.',
      ),
      findsOneWidget,
    );
  });

  // Both ways round: a quiet Needs attention (Movement is the taller
  // column) and a long one (it is the shorter), so each column's stretch is
  // the one under test once.
  for (final (name, fixture) in [
    ('a quiet one', () => summary(low: 0, out: 0, orders: 0, money: false)),
    (
      'every card on the right',
      () => summary(
        low: 0,
        out: 0,
        orders: 0,
        byLocation: [
          for (var i = 1; i <= 4; i++)
            {
              'location': {'id': i, 'name': 'Place $i'},
              'items': i,
              'value_minor': i * 100,
            },
        ],
        topItems: [
          for (var i = 1; i <= 5; i++)
            {
              'item': {'id': i, 'name': 'Item $i', 'number': 'N$i'},
              'qty': '$i.000',
              'value_minor': i * 100,
            },
        ],
      ),
    ),
    (
      'a long one',
      () => summary(
        warnings: [
          for (final key in ['a', 'b', 'c', 'd', 'e'])
            {'key': key, 'count': 1, 'sentence': 'Row $key.', 'link': 'items'},
        ],
      ),
    ),
  ]) {
    testWidgets('on a desktop the columns end on one line, with $name', (
      tester,
    ) async {
      await pump(tester, (_) => fixture());

      final left = tester.getRect(
        find.byKey(const Key('inventory-general-left')),
      );
      final right = tester.getRect(
        find.byKey(const Key('inventory-general-right')),
      );
      expect(left.left, lessThan(right.left));

      // The cards themselves stretch to it, not only the columns.
      final status = tester.getRect(
        find.byKey(const ValueKey('inventory-general-items')),
      );
      final attention = tester.getRect(
        find.byKey(const ValueKey('tour-inventory-attention')),
      );
      final movement = tester.getRect(
        find.byKey(const ValueKey('tour-inventory-movement')),
      );
      expect(status.top, movement.top);
      // The last card on the right, whichever cards the server sent.
      final lastRight = [
        movement,
        for (final key in [
          'inventory-general-locations',
          'inventory-general-top',
        ])
          if (find.byKey(ValueKey(key)).evaluate().isNotEmpty)
            tester.getRect(find.byKey(ValueKey(key))),
      ].last;
      expect(attention.bottom, lastRight.bottom);
      expect(lastRight.bottom, left.bottom);
    });
  }

  testWidgets('on a 390x844 phone it is one column with no overflow', (
    tester,
  ) async {
    await pump(
      tester,
      (_) => summary(
        warnings: [
          {
            'key': 'no_cost_recorded',
            'count': 2,
            'sentence': '2 items have no cost recorded.',
            'link': 'items',
          },
        ],
      ),
      size: const Size(390, 844),
    );

    expect(tester.takeException(), isNull);
    expect(
      find.byKey(const Key('inventory-general-one-column')),
      findsOneWidget,
    );
    expect(find.byKey(const Key('inventory-general-left')), findsNothing);

    final status = tester.getRect(
      find.byKey(const ValueKey('inventory-general-items')),
    );
    final attention = tester.getRect(
      find.byKey(const ValueKey('tour-inventory-attention')),
    );
    expect(attention.top, greaterThan(status.bottom));
    expect(status.width, attention.width);

    await tester.scrollUntilVisible(
      find.byKey(const ValueKey('tour-inventory-movement')),
      300,
    );
    expect(tester.takeException(), isNull);
  });

  testWidgets(
    'Stock by location: value, Not priced for null, none when absent',
    (tester) async {
      await pump(
        tester,
        (_) => summary(
          byLocation: [
            {
              'location': {'id': 1, 'name': 'Main shop'},
              'items': 4,
              'value_minor': 164450,
            },
            {
              'location': {'id': 2, 'name': 'Van 3'},
              'items': 2,
              'value_minor': null,
            },
          ],
        ),
      );

      expect(find.text('Stock by location'), findsOneWidget);
      final shop = find.byKey(const Key('inventory-location-1'));
      final van = find.byKey(const Key('inventory-location-2'));
      expect(
        find.descendant(of: shop, matching: find.text('\$1,644.50')),
        findsOneWidget,
      );
      expect(
        find.descendant(of: van, matching: find.text('Not priced')),
        findsOneWidget,
      );
      expect(
        find.descendant(of: van, matching: find.text('\$0.00')),
        findsNothing,
      );

      // Painted: the fill has height, and halves for half the items.
      Size fill(Finder row) => tester.getSize(
        find.descendant(of: row, matching: find.byType(ColoredBox)).first,
      );
      expect(fill(shop).height, 8);
      expect(fill(van).width / fill(shop).width, closeTo(0.5, 0.01));
    },
  );

  testWidgets('without the value key a location shows no value at all', (
    tester,
  ) async {
    await pump(
      tester,
      (_) => summary(
        money: false,
        byLocation: [
          {
            'location': {'id': 1, 'name': 'Main shop'},
            'items': 4,
          },
        ],
      ),
    );

    final shop = find.byKey(const Key('inventory-location-1'));
    expect(
      find.descendant(of: shop, matching: find.text('4 items')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: shop, matching: find.text('Not priced')),
      findsNothing,
    );
    expect(
      find.descendant(of: shop, matching: find.textContaining('\$')),
      findsNothing,
    );
  });

  testWidgets('top items are by value with values, by quantity without', (
    tester,
  ) async {
    await pump(
      tester,
      (_) => summary(
        topItems: [
          {
            'item': {'id': 7, 'name': '6 mil liner', 'number': 'LN-6'},
            'qty': '12.000',
            'value_minor': 90000,
          },
        ],
      ),
    );
    expect(find.text('Top items by value'), findsOneWidget);
    expect(find.text('LN-6 · 6 mil liner'), findsOneWidget);
    expect(find.text('Qty 12'), findsOneWidget);
    expect(find.text('\$900.00'), findsOneWidget);
  });

  testWidgets('top items without values are titled by quantity', (
    tester,
  ) async {
    await pump(
      tester,
      (_) => summary(
        money: false,
        topItems: [
          {
            'item': {'id': 7, 'name': '6 mil liner', 'number': 'LN-6'},
            'qty': '12.500',
          },
        ],
      ),
    );
    expect(find.text('Top items by quantity'), findsOneWidget);
    expect(find.text('Top items by value'), findsNothing);
    expect(find.text('Qty 12.50'), findsOneWidget);
  });

  testWidgets('Movement sets each total against the previous period', (
    tester,
  ) async {
    await pump(
      tester,
      (_) => summary(
        received: const [4.0, 6.0],
        previous: {
          'received': 4.0,
          'issued_sold': 3.0,
          'adjusted': 0.0,
          'moved': 0.0,
        },
      ),
    );

    expect(
      find.byKey(const Key('inventory-movement-in-change')),
      findsOneWidget,
    );
    expect(find.text('▲ 6 vs 4 last period'), findsOneWidget);
    expect(find.text('▼ 3 vs 3 last period'), findsOneWidget);
    expect(find.text('Same as last period'), findsNWidgets(2));

    final now = tester.getSize(
      find
          .descendant(
            of: find.byKey(const Key('inventory-movement-in')),
            matching: find.byType(ColoredBox),
          )
          .first,
    );
    final before = tester.getSize(
      find
          .descendant(
            of: find.byKey(const Key('inventory-movement-in-previous')),
            matching: find.byType(ColoredBox),
          )
          .first,
    );
    expect(before.height, 4);
    expect(before.width / now.width, closeTo(0.4, 0.01));
  });

  testWidgets('Receive counts open orders only, drafts as a note', (
    tester,
  ) async {
    await pump(
      tester,
      (_) => summary(
        low: 0,
        out: 0,
        orders: 3,
        purchaseOrders: {'open': 1, 'draft': 2},
      ),
    );
    expect(
      find.text(
        '1 purchase order is open to receive. 2 drafts are not sent yet.',
      ),
      findsOneWidget,
    );
    expect(find.text('Receive'), findsOneWidget);
  });

  testWidgets('no open orders, only drafts: no Receive row', (tester) async {
    await pump(
      tester,
      (_) => summary(
        low: 0,
        out: 0,
        orders: 2,
        purchaseOrders: {'open': 0, 'draft': 2},
      ),
    );
    expect(find.text('Receive'), findsNothing);
    expect(find.text('Nothing needs attention.'), findsOneWidget);
  });
}
