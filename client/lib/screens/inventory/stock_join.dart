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
  StockJoin(this.rows, {this.openOrders, this.valueMinor});

  /// One per item, held anywhere or nowhere.
  final List<Map<String, dynamic>> rows;

  /// Open purchase orders in view (`meta.tiles.open_orders`); null without
  /// Orders.
  final int? openOrders;

  /// The inventory value the server computed (`meta.tiles.value_minor`), the
  /// same figure General and Locations show; null without financials or when
  /// nothing held is priced. Never re-added here: a client sum drifted a
  /// dollar from the other tabs (UI/UX audit, 2026-11-13).
  final int? valueMinor;

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
  final value = tiles?['value_minor'];
  return (
    StockJoin(
      rows,
      openOrders: open is num ? open.toInt() : null,
      valueMinor: value is num ? value.toInt() : null,
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

/// One of a tab's summary tiles (UI queue 43): a count, what it counts, a
/// line under it, and a tap that narrows the list to it. Null [key] is the
/// tile for everything.
class InventoryTile {
  const InventoryTile({
    required this.key,
    required this.label,
    required this.value,
    required this.note,
    this.tone,
    this.enabled = true,
  });

  final String? key;
  final String label;
  final String value;
  final String note;
  final Tone? tone;

  /// False where the role cannot see the fact: dashed, and no filter.
  final bool enabled;
}

/// A tab's tiles in one row, or two by two where the row is narrow; siblings
/// are one size. Keyed `<prefix>-tile-<key>`.
Widget inventoryTiles(
  BuildContext context, {
  required String prefix,
  required List<InventoryTile> tiles,
  required String? picked,
  required ValueChanged<String?> onPick,
  required bool twoByTwo,
}) {
  Widget card(InventoryTile tile) =>
      _tileCard(context, prefix, tile, picked == tile.key, onPick);
  Widget row(Iterable<InventoryTile> some, double gap) => IntrinsicHeight(
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final (i, tile) in some.indexed) ...[
          if (i > 0) SizedBox(width: gap),
          Expanded(child: card(tile)),
        ],
      ],
    ),
  );

  if (!twoByTwo) return row(tiles, 10);
  return Column(
    children: [
      for (var i = 0; i < tiles.length; i += 2) ...[
        if (i > 0) const SizedBox(height: 8),
        row(tiles.skip(i).take(2), 8),
      ],
    ],
  );
}

Widget _tileCard(
  BuildContext context,
  String prefix,
  InventoryTile tile,
  bool picked,
  ValueChanged<String?> onPick,
) {
  final theme = Theme.of(context);
  final ink = tile.tone == null
      ? null
      : ToneColours.of(context, tile.tone!).ink;
  final radius = BorderRadius.circular(EzBiziTheme.controlRadius);

  return Material(
    key: Key('$prefix-tile-${tile.key ?? 'all'}'),
    color: picked
        ? theme.colorScheme.primaryContainer
        : theme.colorScheme.surface,
    shape: RoundedRectangleBorder(
      borderRadius: radius,
      side: BorderSide(
        color: picked ? theme.colorScheme.primary : EzBiziTheme.edge(context),
      ),
    ),
    child: InkWell(
      borderRadius: radius,
      onTap: tile.enabled ? () => onPick(picked ? null : tile.key) : null,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              tile.label.toUpperCase(),
              style: theme.textTheme.labelSmall?.copyWith(
                letterSpacing: 0.8,
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(height: 4),
            Text(
              tile.value,
              style: theme.textTheme.headlineSmall?.copyWith(
                fontWeight: FontWeight.w800,
                color: ink,
              ),
            ),
            Text(
              tile.note,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodySmall,
            ),
          ],
        ),
      ),
    ),
  );
}
