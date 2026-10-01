import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import 'item_pane.dart';

/// Stock, items and purchase orders joined on the client, so a list can say
/// what an item holds in all, where, what it is worth and what is on order
/// (UI queue 43, Jose's 1 and 4). `/inventory/stock` is one row per item per
/// place and carries no cost; `/inventory/items` has the cost and the items
/// held nowhere; open purchase orders say what is coming.
///
/// ponytail: every page of 200, up to 25 pages. A grouped server read is the
/// upgrade when an organization's stock outgrows that.
class StockJoin {
  StockJoin({
    required this.items,
    required this.balances,
    required this.orders,
  });

  /// Every item this view can see, held anywhere or nowhere.
  final List<Map<String, dynamic>> items;

  /// One row per item per place (`/inventory/stock`).
  final List<Map<String, dynamic>> balances;

  /// Purchase orders, empty when this role cannot read them.
  final List<Map<String, dynamic>> orders;

  late final Map<String, List<Map<String, dynamic>>> byItem = _group('item');
  late final Map<String, List<Map<String, dynamic>>> byLocation = _group(
    'location',
  );

  Map<String, List<Map<String, dynamic>>> _group(String key) {
    final out = <String, List<Map<String, dynamic>>>{};
    for (final row in balances) {
      final id = inventoryMap(row[key])?['id'] as String?;
      if (id != null) out.putIfAbsent(id, () => []).add(row);
    }
    return out;
  }

  /// The places that hold anything, by id, in the order stock names them.
  late final Map<String, Map<String, dynamic>> locations = {
    for (final row in balances)
      if (inventoryMap(row['location']) case final l?)
        if (l['id'] is String) l['id'] as String: l,
  };

  double qty(Map<String, dynamic> row) =>
      inventoryQtyNumber(row['qty_on_hand']) ?? 0;

  /// On hand across every place, or at one.
  double onHand(String itemId, {String? at}) => (byItem[itemId] ?? const [])
      .where((row) => at == null || inventoryMap(row['location'])?['id'] == at)
      .fold(0, (sum, row) => sum + qty(row));

  /// Still to arrive on open orders.
  double onOrder(String itemId) => _ordered(itemId, 'open');

  /// On orders not sent yet.
  double inDraft(String itemId) => _ordered(itemId, 'draft');

  double _ordered(String itemId, String status) {
    var sum = 0.0;
    for (final order in orders) {
      if (order['status'] != status) continue;
      for (final line in inventoryMaps(order['lines'])) {
        if (inventoryMap(line['item'])?['id'] != itemId) continue;
        final ordered = inventoryQtyNumber(line['qty_ordered']) ?? 0;
        final received = inventoryQtyNumber(line['qty_received']) ?? 0;
        sum += (ordered - received).clamp(0, double.infinity);
      }
    }
    return sum;
  }

  /// The open orders an item is on, by number.
  List<String> orderNumbers(String itemId) => [
    for (final order in orders)
      if (order['status'] == 'open' &&
          inventoryMaps(
            order['lines'],
          ).any((l) => inventoryMap(l['item'])?['id'] == itemId))
        '${order['number']}',
  ];

  /// Last cost in cents; null without `inventory.financials` (the server
  /// strips it) or when nothing has been bought yet.
  int? costMinor(Map<String, dynamic> item) {
    final cost = item['last_cost_minor'];
    return cost is num ? cost.toInt() : null;
  }

  /// "Out of stock", "Below reorder" or "In stock", by the item's TOTAL:
  /// one van under the reorder point is not the item running low.
  String status(Map<String, dynamic> item) {
    final total = onHand(item['id'] as String);
    if (total <= 0) return 'Out of stock';
    final at = inventoryQtyNumber(item['reorder_at']);
    if (at != null && total <= at) return 'Below reorder';
    return 'In stock';
  }
}

/// Every page of [path], or the refusal of the first one that failed.
Future<(List<Map<String, dynamic>>?, String?)> inventoryAllPages(
  ApiClient api,
  String path,
) async {
  final out = <Map<String, dynamic>>[];
  for (var page = 1; page <= 25; page++) {
    final response = await api.get(path, {
      'page': '$page',
      'per_page': '200',
    });
    if (!response.ok) return (null, response.message);
    out.addAll(inventoryRows(response.data));
    if (page >= inventoryLastPage(response.body)) break;
  }
  return (out, null);
}

/// Stock is required; items and orders fill in what they can. Items fall
/// back to the ones stock names.
Future<(StockJoin?, String?)> loadStockJoin(
  ApiClient api, {
  required bool withOrders,
}) async {
  final (stock, error) = await inventoryAllPages(api, '/inventory/stock');
  if (stock == null) return (null, error);

  final (items, _) = await inventoryAllPages(api, '/inventory/items');
  final (orders, _) = withOrders
      ? await inventoryAllPages(api, '/inventory/purchase-orders')
      : (null, null);

  final known = <String, Map<String, dynamic>>{
    for (final row in stock)
      if (inventoryMap(row['item']) case final item?)
        if (item['id'] is String) item['id'] as String: item,
    for (final item in items ?? const <Map<String, dynamic>>[])
      if (item['id'] is String) item['id'] as String: item,
  };

  return (
    StockJoin(
      items: known.values.toList()
        ..sort((a, b) => '${a['name']}'.toLowerCase().compareTo(
          '${b['name']}'.toLowerCase(),
        )),
      balances: stock,
      orders: orders ?? const [],
    ),
    null,
  );
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

/// On hand per item id, across every place this view can see.
Future<Map<String, double>> inventoryOnHand(ApiClient api) async {
  final (rows, _) = await inventoryAllPages(api, '/inventory/stock');
  final out = <String, double>{};
  for (final row in rows ?? const <Map<String, dynamic>>[]) {
    final id = inventoryMap(row['item'])?['id'] as String?;
    if (id == null) continue;
    out[id] = (out[id] ?? 0) + (inventoryQtyNumber(row['qty_on_hand']) ?? 0);
  }
  return out;
}
