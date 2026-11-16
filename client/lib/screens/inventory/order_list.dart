import '../../widgets/module_page_header.dart';
import 'dart:async';

import 'package:flutter/material.dart';

import '../../widgets/list_search.dart';

import '../../widgets/empty_state.dart';
import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/refused.dart';
import '../../widgets/status_pill.dart';
import '../../widgets/tag.dart';
import '../inventory_screen.dart';
import 'add_purchase_order_pane.dart';
import 'add_sale_pane.dart';
import 'add_stock_move_pane.dart';
import 'issue_pane.dart';
import 'item_pane.dart';
import 'stock_join.dart';
import 'purchase_order_pane.dart';
import 'sale_pane.dart';

/// Orders chip: purchase orders, sales, and issues.
class OrderList extends StatefulWidget {
  const OrderList({
    super.key,
    required this.api,
    required this.session,
    required this.visible,
  });

  final ApiClient api;
  final Session session;
  final bool visible;

  @override
  State<OrderList> createState() => _OrderListState();
}

class _OrderListState extends State<OrderList> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _orders = [];
  List<Map<String, dynamic>> _issues = [];
  List<Map<String, dynamic>> _sales = [];

  /// The tile narrowing the list: null, `receive`, `open`, `sales` or
  /// `issues`; and the counts on the tiles, across every page (Inventory,
  /// 83999f2c). A server without them draws the old chips.
  String? _tile;
  Map<String, dynamic>? _poTiles;
  Map<String, dynamic>? _saleTiles;
  int? _issueTotal;
  String? _kind;
  bool _addingPo = false;
  bool _addingIssue = false;
  bool _addingSale = false;
  String? _selectedId;
  Map<String, dynamic>? _open;

  /// The search box, sent to all three endpoints as `q`, debounced.
  final _search = TextEditingController();
  Timer? _debounce;
  String _q = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    _debounce?.cancel();
    super.dispose();
  }

  void _searched(String text) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 400), () {
      if (!mounted) return;
      setState(() => _q = text.trim());
      _load();
    });
  }

  @override
  void didUpdateWidget(covariant OrderList oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.visible && !oldWidget.visible) _load();
  }

  bool get _hasPane =>
      _addingPo || _addingIssue || _addingSale || _open != null;

  Future<void> _load() async {
    setState(() {
      _loading = _orders.isEmpty && _issues.isEmpty && _sales.isEmpty;
      _error = null;
    });

    final po = await widget.api.get('/inventory/purchase-orders', {
      if (_tile == 'receive') 'filter': 'to_receive',
      if (_tile == 'open') 'filter': 'open',
      if (_q.isNotEmpty) 'q': _q,
    });
    final issues = await widget.api.get('/inventory/issues', {
      if (_q.isNotEmpty) 'q': _q,
    });
    final sales = await widget.api.get('/inventory/sales', {
      if (_tile == 'sales') 'filter': 'open',
      if (_q.isNotEmpty) 'q': _q,
    });

    if (!mounted) return;

    if (!po.ok) {
      setState(() {
        _loading = false;
        _error = po.message;
      });
      return;
    }

    if (!issues.ok) {
      setState(() {
        _loading = false;
        _error = issues.message;
      });
      return;
    }

    if (!sales.ok) {
      setState(() {
        _loading = false;
        _error = sales.message;
      });
      return;
    }

    setState(() {
      _loading = false;
      _orders = inventoryRows(po.data);
      _issues = inventoryRows(issues.data);
      _sales = inventoryRows(sales.data);
      Map<String, dynamic>? tiles(ApiResponse r) => switch (r.body['meta']) {
        {'tiles': final Map<dynamic, dynamic> t} => Map<String, dynamic>.from(
          t,
        ),
        _ => null,
      };
      _poTiles = tiles(po);
      _saleTiles = tiles(sales);
      _issueTotal = switch (issues.body['meta']) {
        {'total': final num total} => total.toInt(),
        _ => null,
      };
      if (_open != null && _selectedId != null && _kind == 'po') {
        final match = _orders.where((row) => row['id'] == _selectedId);
        if (match.isNotEmpty) _open = match.first;
      }
      if (_open != null && _selectedId != null && _kind == 'sale') {
        final match = _sales.where((row) => row['id'] == _selectedId);
        if (match.isNotEmpty) _open = match.first;
      }
    });
  }

  void _clearPane() => setState(() {
    _addingPo = false;
    _addingIssue = false;
    _addingSale = false;
    _selectedId = null;
    _open = null;
    _kind = null;
  });

  bool _hasRemaining(Map<String, dynamic> order) {
    for (final line in inventoryMaps(order['lines'])) {
      final ordered = inventoryQtyNumber(line['qty_ordered']) ?? 0;
      final received = inventoryQtyNumber(line['qty_received']) ?? 0;
      if (received < ordered) return true;
    }
    return false;
  }

  List<({String kind, Map<String, dynamic> row})> _visible() {
    final rows = <({String kind, Map<String, dynamic> row})>[];
    final tile = _tile;

    // The server already narrowed what it could; these checks are for one
    // that did not.
    if (tile == null || tile == 'receive' || tile == 'open') {
      for (final order in _orders) {
        final status = order['status'] as String?;
        if (tile == 'receive' && (status != 'open' || !_hasRemaining(order))) {
          continue;
        }
        if (tile == 'open' && status != 'open') continue;
        rows.add((kind: 'po', row: order));
      }
    }

    if (tile == null || tile == 'issues') {
      for (final issue in _issues) {
        rows.add((kind: 'issue', row: issue));
      }
    }

    if (tile == null || tile == 'sales') {
      for (final sale in _sales) {
        rows.add((kind: 'sale', row: sale));
      }
    }

    // InventoryPurchaseOrdersController@index, InventorySalesController@index
    // and InventoryIssuesController@index do not read `q` yet, so the loaded
    // rows are also narrowed here. Once they do, this is a no-op.
    final q = _q.toLowerCase();
    if (q.isEmpty) return rows;
    return [
      for (final entry in rows)
        if (_matches(entry.row, q)) entry,
    ];
  }

  /// Number, vendor, customer, job, note and line item names.
  bool _matches(Map<String, dynamic> row, String q) {
    final text = [
      row['number'],
      row['reference'],
      row['note'],
      inventoryMap(row['vendor'])?['name'],
      inventoryMap(row['customer'])?['name'],
      inventoryMap(row['job'])?['number'],
      inventoryMap(row['location'])?['name'],
      for (final line in inventoryMaps(row['lines']))
        inventoryMap(line['item'])?['name'],
    ].whereType<Object>().join(' ').toLowerCase();
    return text.contains(q);
  }

  void _pick(String? tile) {
    setState(() => _tile = tile);
    _load();
  }

  /// Stock's tiles, for what is bought, sold and issued (Analorena Ventoso,
  /// suggestion 0075: "the inventory layout update needs to be for all the
  /// tabs, not just the front"). They replace the four chips one for one.
  List<InventoryTile> _tileList() {
    int count(Map<String, dynamic>? tiles, String key) =>
        (tiles?[key] as num?)?.toInt() ?? 0;
    final unpaid = count(_saleTiles, 'unpaid');
    return [
      InventoryTile(
        key: 'receive',
        label: 'To receive',
        value: '${count(_poTiles, 'to_receive')}',
        note: 'open orders still arriving',
      ),
      InventoryTile(
        key: 'open',
        label: 'Open POs',
        value: '${count(_poTiles, 'open')}',
        note: 'sent to a vendor',
      ),
      InventoryTile(
        key: 'sales',
        label: 'Sales',
        value: '${count(_saleTiles, 'open')}',
        note: unpaid == 0 ? 'open, all paid' : 'open, $unpaid unpaid',
        tone: unpaid == 0 ? null : Tone.caution,
      ),
      InventoryTile(
        key: 'issues',
        label: 'Issues',
        value: _issueTotal == null ? '—' : '$_issueTotal',
        note: 'stock issued to jobs and people',
      ),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;
    final canCreate = me.can('inventory.create');
    final canEdit = me.can('inventory.edit');
    final nowhere = (me.companies.isEmpty && me.franchises.isEmpty)
        ? me.nowhereToAct('add a purchase order')
        : null;
    final createRefused = canCreate ? nowhere : null;
    final issueNowhere = (me.companies.isEmpty && me.franchises.isEmpty)
        ? me.nowhereToAct('issue stock')
        : null;
    final issueRefused = canEdit ? issueNowhere : null;

    return InventorySplitScaffold(
      hasPane: _hasPane,
      loading: _loading,
      error: _error,
      onRetry: _load,
      header: PanelHeader(
        note: (_loading || _error != null)
            ? 'Purchase orders, sales, and issues.'
            : 'Buy in, sell out, and what has already gone to a job.',
        action: (canCreate || canEdit)
            ? Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  if (canCreate)
                    ModuleAction(
                      child: Refused(
                        reason: createRefused,
                        child: FilledButton.icon(
                          key: const ValueKey('tour-inventory-orders-add'),
                          onPressed: createRefused == null
                              ? () => setState(() {
                                  _addingPo = true;
                                  _addingIssue = false;
                                  _addingSale = false;
                                  _selectedId = null;
                                  _open = null;
                                  _kind = null;
                                })
                              : null,
                          icon: const Icon(Icons.add, size: 18),
                          label: const Text('Add purchase order'),
                        ),
                      ),
                    ),
                  if (canEdit)
                    Refused(
                      reason: issueRefused,
                      child: OutlinedButton.icon(
                        key: const ValueKey('tour-inventory-orders-issue'),
                        onPressed: issueRefused == null
                            ? () => setState(() {
                                _addingIssue = true;
                                _addingPo = false;
                                _addingSale = false;
                                _selectedId = null;
                                _open = null;
                                _kind = null;
                              })
                            : null,
                        icon: const Icon(Icons.output, size: 18),
                        label: const Text('Issue'),
                      ),
                    ),
                  if (canCreate)
                    Refused(
                      reason: createRefused,
                      child: OutlinedButton.icon(
                        key: const ValueKey('tour-inventory-orders-sale'),
                        onPressed: createRefused == null
                            ? () => setState(() {
                                _addingSale = true;
                                _addingPo = false;
                                _addingIssue = false;
                                _selectedId = null;
                                _open = null;
                                _kind = null;
                              })
                            : null,
                        icon: const Icon(Icons.point_of_sale, size: 18),
                        label: const Text('Add sale'),
                      ),
                    ),
                ],
              )
            : null,
      ),
      list: _list(),
      pane: ({required showBack}) => _pane(showBack: showBack),
    );
  }

  Widget _list() {
    final rows = _visible();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          key: const ValueKey('tour-inventory-orders-tiles'),
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 10),
          child: _poTiles == null || _saleTiles == null
              ? Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    for (final (key, label) in const [
                      ('receive', 'To receive'),
                      ('open', 'Open POs'),
                      ('issues', 'Issues'),
                      ('sales', 'Sales'),
                    ])
                      FilterChip(
                        label: Text(label),
                        selected: _tile == key,
                        onSelected: (on) => _pick(on ? key : null),
                      ),
                  ],
                )
              : LayoutBuilder(
                  builder: (context, box) => inventoryTiles(
                    context,
                    prefix: 'orders',
                    tiles: _tileList(),
                    picked: _tile,
                    onPick: _pick,
                    twoByTwo: box.maxWidth < 760,
                  ),
                ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
          child: Align(
            alignment: Alignment.centerLeft,
            child: ListSearch(
              key: const Key('inventory-orders-search'),
              controller: _search,
              onChanged: _searched,
              hint: 'Number, vendor, customer or item',
            ),
          ),
        ),
        Expanded(
          child: rows.isEmpty
              ? _q.isEmpty
                    ? const EmptyState(
                        icon: Icons.receipt_long_outlined,
                        title: 'No orders yet',
                        message: 'There are no orders or sales.',
                      )
                    : EmptyState(
                        icon: Icons.search_off,
                        title: 'No matches',
                        message: 'No order, sale or issue matches "$_q".',
                      )
              : LayoutBuilder(
                  builder: (context, box) => box.maxWidth >= 760 && !_hasPane
                      ? _table(context, rows)
                      : ListView.separated(
                          padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
                          itemCount: rows.length,
                          separatorBuilder: (_, _) => const SizedBox(height: 8),
                          itemBuilder: (context, i) => _row(context, rows[i]),
                        ),
                ),
        ),
      ],
    );
  }

  void _tap(({String kind, Map<String, dynamic> row}) entry) => setState(() {
    _kind = entry.kind;
    _addingPo = false;
    _addingIssue = false;
    _addingSale = false;
    _selectedId = entry.row['id'] as String?;
    _open = entry.row;
  });

  /// Orders, sales and issues as one table (UI queue 43, option 1): number,
  /// who it is for, status, how far along, and its total.
  Widget _table(
    BuildContext context,
    List<({String kind, Map<String, dynamic> row})> rows,
  ) {
    final money = widget.session.me!.can('inventory.financials');

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
      children: [
        inventoryTableRow(context, [
          (2, inventoryHeading(context, 'Number')),
          (4, inventoryHeading(context, 'For')),
          (3, inventoryHeading(context, 'Status')),
          (2, inventoryHeading(context, 'Progress')),
          if (money)
            (2, inventoryHeading(context, 'Total', align: TextAlign.right)),
        ]),
        for (final entry in rows) _tableRow(context, entry, money),
      ],
    );
  }

  double _sum(Map<String, dynamic> row, String key) => inventoryMaps(
    row['lines'],
  ).fold(0.0, (t, l) => t + (inventoryQtyNumber(l[key]) ?? 0));

  /// Cents for the whole order, or null when a line has no price.
  int? _total(Map<String, dynamic> row, String price) {
    var cents = 0.0;
    for (final line in inventoryMaps(row['lines'])) {
      final each = line[price];
      if (each is! num) return null;
      cents += each * (inventoryQtyNumber(line['qty_ordered']) ?? 0);
    }
    return cents.round();
  }

  Widget _tableRow(
    BuildContext context,
    ({String kind, Map<String, dynamic> row}) entry,
    bool money,
  ) {
    final theme = Theme.of(context);
    final row = entry.row;
    final status = row['status'] as String? ?? '';

    final String forWho;
    final Widget pill;
    final String progress;
    final int? cents;

    switch (entry.kind) {
      case 'issue':
        final job = inventoryMap(row['job'])?['number'];
        final from = inventoryMap(row['location'])?['name'];
        forWho = [
          if (job != null) 'Job $job',
          if (from != null) 'from $from',
        ].join(' · ');
        pill = const StatusPill('Issue');
        progress = '${row['issued_on'] ?? '—'}';
        cents = null;
      case 'sale':
        final who =
            inventoryMap(row['customer'])?['name'] ?? row['note'] ?? 'Counter';
        forWho = '$who · sale';
        pill = Wrap(
          spacing: 4,
          children: [
            StatusPill(
              inventorySaleStatusLabel(status),
              tone: status == 'cancelled' ? Tone.bad : Tone.info,
            ),
            if (row['payment_status'] == 'unpaid')
              const StatusPill('Unpaid', tone: Tone.caution),
          ],
        );
        progress =
            '${inventoryQty(_sum(row, 'qty_fulfilled'))}/'
            '${inventoryQty(_sum(row, 'qty_ordered'))} fulfilled';
        cents = _total(row, 'unit_price_minor');
      default:
        forWho = '${inventoryMap(row['vendor'])?['name'] ?? ''}';
        final part =
            status == 'open' &&
            _sum(row, 'qty_received') > 0 &&
            _hasRemaining(row);
        pill = StatusPill(
          part ? 'Part received' : inventoryPoStatusLabel(status),
          tone: switch (status) {
            'open' => Tone.info,
            'received' => Tone.ok,
            'cancelled' => Tone.bad,
            _ => Tone.neutral,
          },
        );
        progress = status == 'draft'
            ? 'not sent yet'
            : '${inventoryQty(_sum(row, 'qty_received'))}/'
                  '${inventoryQty(_sum(row, 'qty_ordered'))}';
        cents = _total(row, 'unit_cost_minor');
    }

    return inventoryTableRow(
      context,
      key: Key('order-row-${entry.kind}-${row['id']}'),
      selected: row['id'] == _selectedId && _kind == entry.kind,
      onTap: () => _tap(entry),
      [
        (2, Text('${row['number'] ?? ''}', style: theme.textTheme.titleSmall)),
        (4, Text(forWho, overflow: TextOverflow.ellipsis)),
        (3, Align(alignment: Alignment.centerLeft, child: pill)),
        (2, Text(progress, style: theme.textTheme.bodySmall)),
        if (money)
          (
            2,
            Text(
              cents == null ? '—' : inventoryMoney(cents),
              textAlign: TextAlign.right,
            ),
          ),
      ],
    );
  }

  Widget _row(
    BuildContext context,
    ({String kind, Map<String, dynamic> row}) entry,
  ) {
    final id = entry.row['id'] as String?;
    final selected = id != null && id == _selectedId && _kind == entry.kind;

    if (entry.kind == 'issue') {
      final location = inventoryMap(entry.row['location']);
      final job = inventoryMap(entry.row['job']);
      return Card(
        child: ListTile(
          selected: selected,
          contentPadding: const EdgeInsets.symmetric(
            horizontal: 16,
            vertical: 8,
          ),
          onTap: () => setState(() {
            _kind = 'issue';
            _addingPo = false;
            _addingIssue = false;
            _addingSale = false;
            _selectedId = id;
            _open = entry.row;
          }),
          title: Text(
            entry.row['number'] as String? ?? 'Issue',
            overflow: TextOverflow.ellipsis,
            style: Theme.of(context).textTheme.titleMedium,
          ),
          subtitle: Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Tag('Issue'),
                Text(
                  [
                    location?['name'],
                    job?['number'] ?? entry.row['note'],
                  ].where((p) => p != null && '$p'.isNotEmpty).join(' · '),
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
              ],
            ),
          ),
        ),
      );
    }

    if (entry.kind == 'sale') {
      final customer = inventoryMap(entry.row['customer']);
      final status = entry.row['status'] as String? ?? '';
      return Card(
        child: ListTile(
          selected: selected,
          contentPadding: const EdgeInsets.symmetric(
            horizontal: 16,
            vertical: 8,
          ),
          onTap: () => setState(() {
            _kind = 'sale';
            _addingPo = false;
            _addingIssue = false;
            _addingSale = false;
            _selectedId = id;
            _open = entry.row;
          }),
          title: Text(
            entry.row['number'] as String? ?? 'Sale',
            overflow: TextOverflow.ellipsis,
            style: Theme.of(context).textTheme.titleMedium,
          ),
          subtitle: Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Wrap(
                  children: [
                    const Tag('Sale'),
                    if (status.isNotEmpty)
                      Tag(
                        inventorySaleStatusLabel(status),
                        warn: status == 'cancelled',
                      ),
                  ],
                ),
                Text(
                  customer?['name'] as String? ??
                      entry.row['note'] as String? ??
                      '',
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
              ],
            ),
          ),
        ),
      );
    }

    final vendor = inventoryMap(entry.row['vendor']);
    final status = entry.row['status'] as String? ?? '';

    return Card(
      child: ListTile(
        selected: selected,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        onTap: () => setState(() {
          _kind = 'po';
          _addingPo = false;
          _addingIssue = false;
          _addingSale = false;
          _selectedId = id;
          _open = entry.row;
        }),
        title: Text(
          entry.row['number'] as String? ?? 'Purchase order',
          overflow: TextOverflow.ellipsis,
          style: Theme.of(context).textTheme.titleMedium,
        ),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Wrap(
                children: [
                  if (status.isNotEmpty)
                    Tag(
                      inventoryPoStatusLabel(status),
                      warn: status == 'cancelled',
                    ),
                ],
              ),
              Text(
                vendor?['name'] as String? ?? '',
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.bodyMedium,
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _pane({required bool showBack}) {
    if (_addingPo) {
      return AddPurchaseOrderPane(
        api: widget.api,
        session: widget.session,
        showBack: showBack,
        onCancel: _clearPane,
        onSaved: (created) {
          setState(() {
            _addingPo = false;
            _kind = 'po';
            _open = created;
            _selectedId = created['id'] as String?;
          });
          _load();
        },
      );
    }

    if (_addingIssue) {
      return AddStockMovePane.issue(
        api: widget.api,
        session: widget.session,
        showBack: showBack,
        onCancel: _clearPane,
        onSaved: (created) {
          setState(() {
            _addingIssue = false;
            _kind = 'issue';
            _open = created;
            _selectedId = created['id'] as String?;
          });
          _load();
        },
      );
    }

    if (_addingSale) {
      return AddSalePane(
        api: widget.api,
        session: widget.session,
        showBack: showBack,
        onCancel: _clearPane,
        onSaved: (created) {
          setState(() {
            _addingSale = false;
            _kind = 'sale';
            _open = created;
            _selectedId = created['id'] as String?;
          });
          _load();
        },
      );
    }

    final row = _open;
    if (row == null) return const SizedBox.shrink();

    if (_kind == 'issue') {
      return IssuePane(issue: row, showBack: showBack, onBack: _clearPane);
    }

    if (_kind == 'sale') {
      return SalePane(
        api: widget.api,
        session: widget.session,
        sale: row,
        showBack: showBack,
        onBack: _clearPane,
        onChanged: (updated) {
          setState(() {
            _open = updated;
            _selectedId = updated['id'] as String?;
            _kind = 'sale';
          });
          _load();
        },
      );
    }

    return PurchaseOrderPane(
      api: widget.api,
      session: widget.session,
      order: row,
      showBack: showBack,
      onBack: _clearPane,
      onChanged: (updated) {
        setState(() {
          _open = updated;
          _selectedId = updated['id'] as String?;
          _kind = 'po';
        });
        _load();
      },
    );
  }
}
