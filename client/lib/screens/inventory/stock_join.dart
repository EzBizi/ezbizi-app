import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import 'item_pane.dart';

/// Stock one row per item, as the server groups it (`GET
/// /inventory/stock/items`, Inventory 51ab95c7): each item's places, what is
/// on order and in a draft, and its status by the TOTAL across the places the
/// caller can see. Permission and money are the server's: `on_order` and
/// friends are null without Orders, `last_cost_minor` absent without
/// financials. UI queue 43, Jose's 1 and 4.
class StockJoin {
  StockJoin(this.rows, {this.openOrders});

  /// One per item, held anywhere or nowhere.
  final List<Map<String, dynamic>> rows;

  /// Open purchase orders in view (`meta.tiles.open_orders`); null without
  /// Orders.
  final int? openOrders;

  late final List<Map<String, dynamic>> items = [
    for (final row in rows) ?inventoryMap(row['item']),
  ];

  late final Map<String, Map<String, dynamic>> _byId = {
    for (final row in rows)
      if (inventoryMap(row['item'])?['id'] case final String id) id: row,
  };

  /// Each item's places, as stock rows: `{location, qty_on_hand}`.
  late final Map<String, List<Map<String, dynamic>>> byItem = {
    for (final entry in _byId.entries)
      entry.key: inventoryMaps(entry.value['places']),
  };

  /// The places that hold anything, by id, in the order the rows name them.
  late final Map<String, Map<String, dynamic>> locations = {
    for (final places in byItem.values)
      for (final place in places)
        if (inventoryMap(place['location']) case final l?)
          if (l['id'] is String) l['id'] as String: l,
  };

  double qty(Map<String, dynamic> place) =>
      inventoryQtyNumber(place['qty_on_hand']) ?? 0;

  /// On hand across every place, or at one.
  double onHand(String itemId, {String? at}) => (byItem[itemId] ?? const [])
      .where((p) => at == null || inventoryMap(p['location'])?['id'] == at)
      .fold(0, (sum, p) => sum + qty(p));

  /// Still to arrive on open orders; 0 where the caller may not see orders
  /// (the screen does not claim it then).
  double onOrder(String itemId) =>
      inventoryQtyNumber(_byId[itemId]?['on_order']) ?? 0;

  /// On orders not sent yet.
  double inDraft(String itemId) =>
      inventoryQtyNumber(_byId[itemId]?['in_draft']) ?? 0;

  /// The open orders an item is on, by number.
  List<String> orderNumbers(String itemId) => [
    for (final number in _byId[itemId]?['order_numbers'] as List? ?? const [])
      '$number',
  ];

  /// Last cost in cents; null without `inventory.financials` (the server
  /// leaves it out) or when nothing has been bought yet.
  int? costMinor(Map<String, dynamic> item) {
    final cost = item['last_cost_minor'];
    return cost is num ? cost.toInt() : null;
  }

  /// "Out of stock", "Below reorder" or "In stock", as the server judged it by
  /// the item's total: one van under the reorder point is not the item low.
  String status(Map<String, dynamic> item) =>
      switch (_byId[item['id']]?['status']) {
        'out_of_stock' => 'Out of stock',
        'below_reorder' => 'Below reorder',
        _ => 'In stock',
      };
}

/// Every page of the grouped read. ponytail: 25 pages of 200; the read pages
/// properly when a catalogue outgrows that.
Future<(StockJoin?, String?)> loadStockItems(ApiClient api) async {
  final rows = <Map<String, dynamic>>[];
  Map<String, dynamic>? tiles;
  for (var page = 1; page <= 25; page++) {
    final response = await api.get('/inventory/stock/items', {
      'page': '$page',
      'per_page': '200',
    });
    if (!response.ok) return (null, response.message);
    rows.addAll(inventoryRows(response.data));
    tiles ??= inventoryMap(inventoryMap(response.body['meta'])?['tiles']);
    if (page >= inventoryLastPage(response.body)) break;
  }
  final open = tiles?['open_orders'];
  return (StockJoin(rows, openOrders: open is num ? open.toInt() : null), null);
}

/// Cents as "$5,057.00": a table of values reads by its thousands.
String inventoryMoney(int cents) {
  final whole = (cents.abs() ~/ 100).toString().replaceAllMapped(
    RegExp(r'\B(?=(\d{3})+(?!\d))'),
    (_) => ',',
  );
  final part = (cents.abs() % 100).toString().padLeft(2, '0');
  return '${cents < 0 ? '-' : ''}\$$whole.$part';
}

/// One row of an inventory table: cells by flex over a rule. The heading
/// row is the same, built from [inventoryHeading]s.
Widget inventoryTableRow(
  BuildContext context,
  List<(int, Widget)> cells, {
  Key? key,
  VoidCallback? onTap,
  bool selected = false,
}) {
  final theme = Theme.of(context);
  return Material(
    key: key,
    color: selected ? theme.colorScheme.primaryContainer : Colors.transparent,
    child: InkWell(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        decoration: BoxDecoration(
          border: Border(bottom: BorderSide(color: EzBiziTheme.edge(context))),
        ),
        child: Row(
          children: [
            for (final (flex, cell) in cells) Expanded(flex: flex, child: cell),
          ],
        ),
      ),
    ),
  );
}

Widget inventoryHeading(
  BuildContext context,
  String text, {
  TextAlign? align,
}) => Text(
  text.toUpperCase(),
  textAlign: align,
  style: Theme.of(context).textTheme.labelSmall?.copyWith(
    letterSpacing: 0.8,
    fontWeight: FontWeight.w700,
    color: Theme.of(context).colorScheme.onSurfaceVariant,
  ),
);
