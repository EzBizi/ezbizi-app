import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/refused.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/status_pill.dart';
import '../inventory_screen.dart';
import 'add_purchase_order_pane.dart';
import 'add_stock_move_pane.dart';
import 'item_pane.dart';
import 'stock_join.dart';

/// Stock (UI queue 43, Jose's 1 and 4): four tiles that filter, then one row
/// per item with its total, reorder point, what is on order and its value;
/// open a row for each place, with Transfer and Count on the place's line.
/// Transfer, Count and a purchase order open in the docked pane.
class StockList extends StatefulWidget {
  const StockList({
    super.key,
    required this.api,
    required this.session,
    required this.visible,
    this.tileRequest,
  });

  final ApiClient api;
  final Session session;
  final bool visible;

  /// A tile another tab asked for (General's "Below reorder"); a new serial
  /// applies it again.
  final ({String? tile, int serial})? tileRequest;

  @override
  State<StockList> createState() => _StockListState();
}

class _StockListState extends State<StockList> {
  bool _loading = true;
  String? _error;
  StockJoin? _join;
  int _request = 0;

  /// The tile picked: low, out, ordered; null is every item.
  String? _tile;
  final _search = TextEditingController();
  String? _place;
  final Set<String> _unfolded = {};

  bool _transferring = false;
  String? _transferFrom;
  String? _transferItem;
  bool _ordering = false;
  Map<String, dynamic>? _open;

  @override
  void initState() {
    super.initState();
    _tile = widget.tileRequest?.tile;
    _load();
  }

  @override
  void didUpdateWidget(covariant StockList oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.tileRequest != oldWidget.tileRequest &&
        widget.tileRequest != null) {
      _tile = widget.tileRequest!.tile;
    }
    if (widget.visible && !oldWidget.visible) _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  bool get _hasPane => _transferring || _ordering || _open != null;

  bool _may(String permission) =>
      widget.session.me?.permissions.contains(permission) ?? false;

  Future<void> _load() async {
    final request = ++_request;
    setState(() {
      _loading = _join == null;
      _error = null;
    });

    final (join, error) = await loadStockItems(widget.api);

    if (!mounted || request != _request) return;
    setState(() {
      _loading = false;
      _error = error;
      if (join != null) _join = join;
    });
  }

  void _clearPane() => setState(() {
    _transferring = false;
    _ordering = false;
    _open = null;
  });

  void _openItem(Map<String, dynamic> item) => setState(() {
    _transferring = false;
    _ordering = false;
    _open = item;
  });

  void _transfer({String? from, String? item}) => setState(() {
    _open = null;
    _ordering = false;
    _transferring = true;
    _transferFrom = from;
    _transferItem = item;
  });

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;
    final canEdit = me.can('inventory.edit');
    final nowhere = (me.companies.isEmpty && me.franchises.isEmpty)
        ? me.nowhereToAct('transfer stock')
        : null;
    final refused = canEdit ? nowhere : null;
    final join = _join;

    return InventorySplitScaffold(
      hasPane: _hasPane,
      loading: _loading,
      error: _error,
      onRetry: _load,
      header: PanelHeader(
        note: join == null
            ? 'What you hold, where it is, and what it is worth.'
            : '${_count(join.items.length, 'item')} in '
                  '${_count(join.locations.length, 'place')}.',
        action: Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            if (me.can('inventory.create') && _may('inventory.orders.view'))
              OutlinedButton.icon(
                key: const ValueKey('tour-inventory-stock-order'),
                onPressed: () => setState(() {
                  _open = null;
                  _transferring = false;
                  _ordering = true;
                }),
                icon: const Icon(Icons.add, size: 18),
                label: const Text('Purchase order'),
              ),
            if (canEdit)
              Refused(
                reason: refused,
                child: FilledButton.icon(
                  key: const ValueKey('tour-inventory-stock-transfer'),
                  onPressed: refused == null ? () => _transfer() : null,
                  icon: const Icon(Icons.swap_horiz, size: 18),
                  label: const Text('Transfer'),
                ),
              ),
          ],
        ),
      ),
      list: join == null ? const SizedBox.shrink() : _list(context, join),
      pane: ({required showBack}) => _pane(showBack: showBack),
    );
  }

  // ── the list ─────────────────────────────────────────────────────

  Widget _list(BuildContext context, StockJoin join) {
    final theme = Theme.of(context);
    final money = _may('inventory.financials');
    final query = _search.text.trim().toLowerCase();

    final items = [
      for (final item in join.items)
        if (_passes(join, item, query)) item,
    ];

    return LayoutBuilder(
      builder: (context, box) {
        // A table needs room; beside an open pane, or on a phone, each item
        // is one line with its total.
        final table = box.maxWidth >= 760 && !_hasPane;

        return ListView(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
          children: [
            if (!_hasPane) ...[
              _tiles(context, join, money, twoByTwo: box.maxWidth < 760),
              const SizedBox(height: 12),
            ],
            _toolbar(context, join, wide: table),
            const SizedBox(height: 8),
            if (join.items.isEmpty)
              const Padding(
                padding: EdgeInsets.all(16),
                child: Text('There is no stock.'),
              )
            else if (items.isEmpty)
              Padding(
                padding: const EdgeInsets.all(16),
                child: Text(
                  'Nothing matches.',
                  style: theme.textTheme.bodyMedium,
                ),
              )
            else if (table) ...[
              _headerRow(context, money),
              for (final item in items) ..._tableRows(context, join, item, money),
            ] else
              for (final item in items) _compactRow(context, join, item),
          ],
        );
      },
    );
  }

  bool _passes(StockJoin join, Map<String, dynamic> item, String query) {
    final id = item['id'] as String;
    if (_place != null && join.onHand(id, at: _place) == 0 &&
        !(join.byItem[id] ?? const []).any(
          (r) => inventoryMap(r['location'])?['id'] == _place,
        )) {
      return false;
    }
    if (query.isNotEmpty &&
        ![item['name'], item['sku'], item['number']].any(
          (v) => '${v ?? ''}'.toLowerCase().contains(query),
        )) {
      return false;
    }
    return switch (_tile) {
      'low' => join.status(item) == 'Below reorder',
      'out' => join.status(item) == 'Out of stock',
      'ordered' => join.onOrder(id) > 0,
      _ => true,
    };
  }

  Widget _tiles(
    BuildContext context,
    StockJoin join,
    bool money, {
    required bool twoByTwo,
  }) {
    final low = [
      for (final i in join.items)
        if (join.status(i) == 'Below reorder') i,
    ];
    final covered = low.where((i) {
      final id = i['id'] as String;
      final at = inventoryQtyNumber(i['reorder_at']) ?? 0;
      return join.onHand(id) + join.onOrder(id) > at;
    }).length;
    final out = [
      for (final i in join.items)
        if (join.status(i) == 'Out of stock') i,
    ];
    final ordered = [
      for (final i in join.items)
        if (join.onOrder(i['id'] as String) > 0) i,
    ];
    final orders = join.openOrders ?? 0;
    var valueMinor = 0;
    var priced = false;
    for (final i in join.items) {
      final cost = join.costMinor(i);
      if (cost == null) continue;
      priced = true;
      valueMinor += (cost * join.onHand(i['id'] as String)).round();
    }

    final tiles = [
      InventoryTile(
        key: null,
        label: 'All items',
        value: '${join.items.length}',
        note: money && priced
            ? '${inventoryMoney(valueMinor)} at last cost'
            : 'in ${_count(join.locations.length, 'place')}',
      ),
      InventoryTile(
        key: 'low',
        label: 'Below reorder',
        value: '${low.length}',
        // Orders were only fetched for a role holding Orders. Without it
        // `covered` is 0 because nobody looked, not because nothing is coming,
        // and "none covered" would tell a clerk to order what may be on its way.
        note: !_may('inventory.orders.view')
            ? ''
            : covered == 0
            ? 'none covered by an order'
            : '$covered covered by an order',
        tone: low.isEmpty ? null : Tone.caution,
      ),
      InventoryTile(
        key: 'out',
        label: 'Out of stock',
        value: '${out.length}',
        note: out.isEmpty
            ? 'nothing'
            : out.length == 1
            ? '${out.single['name']}'
            : '${out.first['name']} and ${out.length - 1} more',
        tone: out.isEmpty ? null : Tone.bad,
      ),
      InventoryTile(
        key: 'ordered',
        label: 'On order',
        // Same reason: a zero here would be a count of orders nobody fetched.
        value: _may('inventory.orders.view') ? '${ordered.length}' : '—',
        note: _may('inventory.orders.view')
            ? '${ordered.length == 1 ? 'item' : 'items'}, on '
                  '${_count(orders, 'order')}'
            : '',
      ),
    ];

    return inventoryTiles(
      context,
      prefix: 'stock',
      tiles: tiles,
      picked: _tile,
      onPick: (key) => setState(() => _tile = key),
      twoByTwo: twoByTwo,
    );
  }

  Widget _toolbar(BuildContext context, StockJoin join, {required bool wide}) {
    final search = TextField(
      key: const Key('stock-search'),
      controller: _search,
      decoration: const InputDecoration(
        hintText: 'Find an item, SKU or number',
        prefixIcon: Icon(Icons.search, size: 20),
        isDense: true,
      ),
      onChanged: (_) => setState(() {}),
    );
    final places = SearchPickerField<String>(
      key: const Key('stock-place'),
      value: _place,
      isDense: true,
      hint: 'All places',
      allowClear: true,
      options: [
        for (final l in join.locations.values)
          SearchOption(
            value: l['id'] as String,
            label: '${l['name']}',
            detail: inventoryKindLabel(l['kind'] as String?),
          ),
      ],
      onChanged: (value) => setState(() => _place = value),
    );

    if (!wide) {
      return Column(
        children: [search, const SizedBox(height: 8), places],
      );
    }
    return Row(
      children: [
        Expanded(flex: 3, child: search),
        const SizedBox(width: 10),
        Expanded(flex: 2, child: places),
      ],
    );
  }

  // ── the table ────────────────────────────────────────────────────

  static const _flex = [5, 2, 2, 2, 3, 2];

  Widget _cells(List<Widget> cells, {bool money = true}) => Row(
    children: [
      for (var i = 0; i < cells.length; i++)
        if (money || i < 5) Expanded(flex: _flex[i], child: cells[i]),
    ],
  );

  Widget _headerRow(BuildContext context, bool money) {
    final style = Theme.of(context).textTheme.labelSmall?.copyWith(
      letterSpacing: 0.8,
      fontWeight: FontWeight.w700,
      color: Theme.of(context).colorScheme.onSurfaceVariant,
    );
    Text h(String t, {TextAlign? align}) =>
        Text(t.toUpperCase(), style: style, textAlign: align);

    return Container(
      padding: const EdgeInsets.fromLTRB(44, 10, 12, 8),
      decoration: BoxDecoration(
        border: Border(bottom: BorderSide(color: EzBiziTheme.edge(context))),
      ),
      child: _cells([
        h('Item'),
        h('Status'),
        h('On hand', align: TextAlign.right),
        h('Reorder at', align: TextAlign.right),
        Padding(padding: const EdgeInsets.only(left: 16), child: h('On order')),
        h('Value', align: TextAlign.right),
      ], money: money),
    );
  }

  List<Widget> _tableRows(
    BuildContext context,
    StockJoin join,
    Map<String, dynamic> item,
    bool money,
  ) {
    final theme = Theme.of(context);
    final id = item['id'] as String;
    final unit = inventoryUnitLabel(item['unit'] as String?);
    final rows = join.byItem[id] ?? const [];
    final open = _unfolded.contains(id);
    final cost = join.costMinor(item);
    final onHand = join.onHand(id, at: _place);
    final status = join.status(item);
    final orders = join.orderNumbers(id);
    final coming = join.onOrder(id);
    final draft = join.inDraft(id);

    final where = rows.isEmpty
        ? 'held nowhere'
        : rows.length == 1
        ? '${inventoryMap(rows.single['location'])?['name'] ?? ''}'
        : '${rows.length} places';

    return [
      Container(
        key: Key('stock-item-$id'),
        decoration: BoxDecoration(
          border: Border(bottom: BorderSide(color: EzBiziTheme.edge(context))),
        ),
        child: Row(
          children: [
            SizedBox(
              width: 40,
              child: rows.isEmpty
                  ? null
                  : IconButton(
                      key: Key('stock-unfold-$id'),
                      tooltip: open ? 'Hide the places' : 'Show the places',
                      icon: Icon(
                        open ? Icons.expand_more : Icons.chevron_right,
                        size: 20,
                      ),
                      onPressed: () => setState(() {
                        if (!_unfolded.remove(id)) _unfolded.add(id);
                      }),
                    ),
            ),
            Expanded(
              child: InkWell(
                onTap: () => _openItem(item),
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(4, 10, 12, 10),
                  child: _cells([
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          item['name'] as String? ?? 'Item',
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.titleSmall,
                        ),
                        Text(
                          [
                            item['sku'],
                            where,
                          ].where((p) => p != null && '$p'.isNotEmpty).join(' · '),
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.bodySmall,
                        ),
                      ],
                    ),
                    Align(
                      alignment: Alignment.centerLeft,
                      child: StatusPill(status, tone: _tone(status)),
                    ),
                    Text(
                      '${inventoryQty(onHand)} $unit',
                      textAlign: TextAlign.right,
                      style: theme.textTheme.titleSmall,
                    ),
                    Text(
                      item['reorder_at'] == null
                          ? '—'
                          : inventoryQty(item['reorder_at']),
                      textAlign: TextAlign.right,
                    ),
                    Padding(
                      padding: const EdgeInsets.only(left: 16),
                      child: Text(
                        coming > 0
                            ? '${inventoryQty(coming)} · ${orders.join(', ')}'
                            : draft > 0
                            ? '${inventoryQty(draft)} in a draft'
                            : '—',
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.bodySmall,
                      ),
                    ),
                    Text(
                      cost == null
                          ? '—'
                          : inventoryMoney((cost * onHand).round()),
                      textAlign: TextAlign.right,
                    ),
                  ], money: money),
                ),
              ),
            ),
          ],
        ),
      ),
      if (open)
        for (final row in rows) _placeRow(context, join, item, row, money),
    ];
  }

  Widget _placeRow(
    BuildContext context,
    StockJoin join,
    Map<String, dynamic> item,
    Map<String, dynamic> row,
    bool money,
  ) {
    final theme = Theme.of(context);
    final location = inventoryMap(row['location']) ?? const {};
    final cost = join.costMinor(item);
    final qty = join.qty(row);
    final canEdit = widget.session.me!.can('inventory.edit');

    return Container(
      key: Key('stock-place-${item['id']}-${location['id']}'),
      color: theme.colorScheme.surfaceContainerLow,
      padding: const EdgeInsets.fromLTRB(56, 6, 12, 6),
      child: _cells([
        Text.rich(
          TextSpan(
            children: [
              TextSpan(
                text: '${location['name'] ?? ''}',
                style: theme.textTheme.bodyMedium,
              ),
              TextSpan(
                text: '  ${inventoryKindLabel(location['kind'] as String?)}',
                style: theme.textTheme.bodySmall,
              ),
            ],
          ),
          overflow: TextOverflow.ellipsis,
        ),
        Wrap(
          spacing: 2,
          children: [
            if (canEdit)
              TextButton(
                onPressed: () => _transfer(
                  from: location['id'] as String?,
                  item: item['id'] as String?,
                ),
                child: const Text('Transfer'),
              ),
            if (canEdit)
              TextButton(
                onPressed: () => _openItem(item),
                child: const Text('Count'),
              ),
          ],
        ),
        Text(inventoryQty(qty), textAlign: TextAlign.right),
        const SizedBox.shrink(),
        const SizedBox.shrink(),
        Text(
          cost == null ? '' : inventoryMoney((cost * qty).round()),
          textAlign: TextAlign.right,
          style: theme.textTheme.bodySmall,
        ),
      ], money: money),
    );
  }

  /// A phone, or the list beside an open pane: one line per item.
  Widget _compactRow(
    BuildContext context,
    StockJoin join,
    Map<String, dynamic> item,
  ) {
    final theme = Theme.of(context);
    final id = item['id'] as String;
    final unit = inventoryUnitLabel(item['unit'] as String?);
    final status = join.status(item);
    final places = (join.byItem[id] ?? const []).length;

    return Card(
      key: Key('stock-item-$id'),
      margin: const EdgeInsets.only(bottom: 8),
      child: ListTile(
        selected: _open?['id'] == id,
        onTap: () => _openItem(item),
        title: Text(
          item['name'] as String? ?? 'Item',
          overflow: TextOverflow.ellipsis,
          style: theme.textTheme.titleSmall,
        ),
        subtitle: Text(
          [
            '${inventoryQty(join.onHand(id, at: _place))} $unit',
            places == 0 ? 'held nowhere' : _count(places, 'place'),
          ].join(' · '),
          overflow: TextOverflow.ellipsis,
        ),
        trailing: _hasPane ? null : StatusPill(status, tone: _tone(status)),
      ),
    );
  }

  Tone _tone(String status) => switch (status) {
    'Out of stock' => Tone.bad,
    'Below reorder' => Tone.caution,
    _ => Tone.ok,
  };

  // ── the pane ─────────────────────────────────────────────────────

  Widget _pane({required bool showBack}) {
    if (_ordering) {
      return AddPurchaseOrderPane(
        api: widget.api,
        session: widget.session,
        showBack: showBack,
        onCancel: _clearPane,
        onSaved: (_) {
          _clearPane();
          _load();
        },
      );
    }

    if (_transferring) {
      return AddStockMovePane.transfer(
        api: widget.api,
        session: widget.session,
        showBack: showBack,
        fromLocationId: _transferFrom,
        itemId: _transferItem,
        onCancel: _clearPane,
        onSaved: (_) {
          _clearPane();
          _load();
        },
      );
    }

    final item = _open;
    final id = item?['id'] as String?;
    if (id == null) return const SizedBox.shrink();

    return ItemPane(
      api: widget.api,
      session: widget.session,
      itemId: id,
      seed: item,
      showBack: showBack,
      onBack: () {
        _clearPane();
        _load();
      },
    );
  }
}

String _count(int n, String noun) => '$n $noun${n == 1 ? '' : 's'}';
