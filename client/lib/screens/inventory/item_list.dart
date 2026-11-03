import 'package:flutter/material.dart';

import '../../widgets/empty_state.dart';
import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/load_more.dart';
import '../../widgets/refused.dart';
import '../../widgets/tag.dart';
import '../inventory_screen.dart';
import 'add_item_pane.dart';
import 'item_pane.dart';
import 'stock_join.dart';

/// Items chip: the catalogue for this view.
class ItemList extends StatefulWidget {
  const ItemList({
    super.key,
    required this.api,
    required this.session,
    required this.visible,
  });

  final ApiClient api;
  final Session session;
  final bool visible;

  @override
  State<ItemList> createState() => _ItemListState();
}

class _ItemListState extends State<ItemList> {
  final _search = TextEditingController();

  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _items = [];
  int _total = 0;
  int _page = 1;
  int _lastPage = 1;
  String? _pageError;
  bool _fetching = false;
  int _request = 0;
  /// The counts on the tiles, the whole catalogue's (`meta.tiles`), and the
  /// tile narrowing the list: null, `low`, `out` or `discontinued`. A server
  /// without tiles draws none.
  Map<String, dynamic>? _tiles;
  String? _tile;
  bool _adding = false;
  String? _selectedId;
  Map<String, dynamic>? _open;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant ItemList oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.visible && !oldWidget.visible) _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  bool get _hasPane => _adding || _open != null;

  Future<void> _load({int page = 1}) async {
    if (page > 1 && _fetching) return;

    final request = ++_request;

    setState(() {
      _loading = page == 1 && _items.isEmpty;
      _fetching = true;
      _error = null;
      _pageError = null;
    });

    final response = await widget.api.get('/inventory/items', {
      'page': '$page',
      if (_search.text.trim().isNotEmpty) 'search': _search.text.trim(),
      if (_tile == 'low') 'stock': 'below_reorder',
      if (_tile == 'out') 'stock': 'out_of_stock',
      if (_tile == 'discontinued') 'status': 'discontinued',
    });

    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;
      _fetching = false;

      if (!response.ok) {
        if (page == 1) {
          _error = response.message;
        } else {
          _pageError = response.message;
        }
        return;
      }

      final rows = inventoryRows(response.data);
      _items = page == 1 ? rows : [..._items, ...rows];
      _total = inventoryTotal(response.body, _items.length);
      _lastPage = inventoryLastPage(response.body);
      _page = page;
      if (page == 1 && response.body['meta'] is Map) {
        final tiles = (response.body['meta'] as Map)['tiles'];
        _tiles = tiles is Map ? Map<String, dynamic>.from(tiles) : null;
      }
    });
  }

  void _clearPane() => setState(() {
    _adding = false;
    _selectedId = null;
    _open = null;
  });

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;
    final canCreate = me.can('inventory.create');
    final nowhere = (me.companies.isEmpty && me.franchises.isEmpty)
        ? me.nowhereToAct('add an item')
        : null;
    final refused = canCreate ? nowhere : null;

    return InventorySplitScaffold(
      hasPane: _hasPane,
      loading: _loading,
      error: _error,
      onRetry: _load,
      header: PanelHeader(
        note: (_loading || _error != null)
            ? 'The catalogue for this view.'
            : _items.length < _total
            ? 'Showing ${_items.length} of $_total.'
            : _total == 1
            ? '1 item in the catalogue.'
            : '$_total in the catalogue.',
        action: canCreate
            ? Refused(
                reason: refused,
                child: FilledButton.icon(
                  key: const ValueKey('tour-inventory-items-add'),
                  onPressed: refused == null
                      ? () => setState(() {
                          _adding = true;
                          _selectedId = null;
                          _open = null;
                        })
                      : null,
                  icon: const Icon(Icons.add, size: 18),
                  label: const Text('Add item'),
                ),
              )
            : null,
      ),
      list: _list(),
      pane: ({required showBack}) => _pane(showBack: showBack),
    );
  }

  Widget _list() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_tiles case final tiles?)
          Padding(
            key: const ValueKey('tour-inventory-items-tiles'),
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 10),
            child: LayoutBuilder(
              builder: (context, box) => inventoryTiles(
                context,
                prefix: 'items',
                tiles: _tileList(tiles),
                picked: _tile,
                onPick: (key) {
                  setState(() => _tile = key);
                  _load();
                },
                twoByTwo: box.maxWidth < 760,
              ),
            ),
          ),
        Padding(
          key: const ValueKey('tour-inventory-items-search'),
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
          child: TextField(
            controller: _search,
            decoration: InputDecoration(
              prefixIcon: const Icon(Icons.search),
              hintText: 'Name, SKU or number',
              suffixIcon: _search.text.isEmpty
                  ? null
                  : IconButton(
                      icon: const Icon(Icons.clear),
                      tooltip: 'Clear the search',
                      onPressed: () {
                        _search.clear();
                        _load();
                      },
                    ),
            ),
            onSubmitted: (_) => _load(),
            onChanged: (_) => setState(() {}),
          ),
        ),
        // A server without tiles keeps the old way to see discontinued ones.
        if (_tiles == null)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
            child: Align(
              alignment: Alignment.centerLeft,
              child: FilterChip(
                label: const Text('Discontinued'),
                selected: _tile == 'discontinued',
                onSelected: (on) {
                  setState(() => _tile = on ? 'discontinued' : null);
                  _load();
                },
              ),
            ),
          ),
        Expanded(
          child: _items.isEmpty
              ? _search.text.trim().isEmpty && _tile == null
                    ? const EmptyState(
                        icon: Icons.inventory_2_outlined,
                        title: 'No items yet',
                        message: 'There are no items.',
                      )
                    : const EmptyState(
                        icon: Icons.search_off,
                        title: 'No matches',
                        message: 'Nothing matches those filters.',
                      )
              : LayoutBuilder(
                  builder: (context, box) => box.maxWidth >= 760 && !_hasPane
                      ? _table(context)
                      : ListView.separated(
                          padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
                          itemCount:
                              _items.length + (_page < _lastPage ? 1 : 0),
                          separatorBuilder: (_, _) => const SizedBox(height: 8),
                          itemBuilder: (context, i) {
                            if (i == _items.length) return _more();
                            return _row(context, _items[i]);
                          },
                        ),
                ),
        ),
      ],
    );
  }

  /// Stock's four tiles, for the catalogue (Analorena Ventoso, suggestion
  /// 0075: "the inventory layout update needs to be for all the tabs").
  List<InventoryTile> _tileList(Map<String, dynamic> tiles) {
    int count(String key) => (tiles[key] as num?)?.toInt() ?? 0;
    // Null without Stock: how much is left is a stock fact (Inventory,
    // 83999f2c), so the tile says nothing rather than 0.
    final seen = tiles['below_reorder'] != null;
    final low = count('below_reorder');
    final out = count('out_of_stock');
    return [
      InventoryTile(
        key: null,
        label: 'All items',
        value: '${count('items')}',
        note: 'in the catalogue',
      ),
      InventoryTile(
        key: 'low',
        label: 'Below reorder',
        value: seen ? '$low' : '—',
        note: seen ? 'at or under the point to reorder' : '',
        tone: !seen || low == 0 ? null : Tone.caution,
        enabled: seen,
      ),
      InventoryTile(
        key: 'out',
        label: 'Out of stock',
        value: seen ? '$out' : '—',
        note: seen ? 'none left anywhere' : '',
        tone: !seen || out == 0 ? null : Tone.bad,
        enabled: seen,
      ),
      InventoryTile(
        key: 'discontinued',
        label: 'Discontinued',
        value: '${count('discontinued')}',
        note: 'kept for their history',
      ),
    ];
  }

  Widget _more() => LoadMore(
    shown: _items.length,
    total: _total,
    busy: _fetching,
    error: _pageError,
    onMore: () => _load(page: _page + 1),
  );

  /// One line per item with its columns (UI queue 43, option 1).
  Widget _table(BuildContext context) {
    final theme = Theme.of(context);
    final money = widget.session.me!.can('inventory.financials');
    Widget right(String text, {TextStyle? style}) =>
        Text(text, textAlign: TextAlign.right, style: style);

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
      children: [
        inventoryTableRow(context, [
          (5, inventoryHeading(context, 'Item')),
          (2, inventoryHeading(context, 'SKU')),
          if (money)
            (2, inventoryHeading(context, 'Last cost', align: TextAlign.right)),
          (2, inventoryHeading(context, 'Reorder at', align: TextAlign.right)),
          (2, inventoryHeading(context, 'On hand', align: TextAlign.right)),
          (2, const SizedBox.shrink()),
        ]),
        for (final item in _items)
          inventoryTableRow(
            context,
            key: Key('item-row-${item['id']}'),
            selected: item['id'] == _selectedId,
            onTap: () => setState(() {
              _adding = false;
              _selectedId = item['id'] as String?;
              _open = item;
            }),
            [
              (
                5,
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
                        item['number'],
                        inventoryUnitLabel(item['unit'] as String?),
                      ].where((p) => p != null && '$p'.isNotEmpty).join(' · '),
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodySmall,
                    ),
                  ],
                ),
              ),
              (2, Text('${item['sku'] ?? '—'}', overflow: TextOverflow.ellipsis)),
              if (money)
                (
                  2,
                  right(
                    item['last_cost_minor'] is num
                        ? inventoryMoney((item['last_cost_minor'] as num).toInt())
                        : '—',
                  ),
                ),
              (
                2,
                right(
                  item['reorder_at'] == null
                      ? '—'
                      : inventoryQty(item['reorder_at']),
                ),
              ),
              (
                2,
                right(
                  // Null without Stock: the role was never shown quantities,
                  // and a refusal is not "holds nothing" (Inventory 538dbabc).
                  item['on_hand'] == null
                      ? '—'
                      : '${inventoryQty(item['on_hand'])} '
                            '${inventoryUnitLabel(item['unit'] as String?)}',
                  style: theme.textTheme.titleSmall,
                ),
              ),
              (
                2,
                Align(
                  alignment: Alignment.centerRight,
                  child: item['status'] == 'discontinued'
                      ? const Tag('Discontinued', warn: true)
                      : const SizedBox.shrink(),
                ),
              ),
            ],
          ),
        if (_page < _lastPage) _more(),
      ],
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> item) {
    final id = item['id'] as String?;
    final status = item['status'] as String? ?? '';

    return Card(
      child: ListTile(
        selected: id != null && id == _selectedId,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        onTap: () => setState(() {
          _adding = false;
          _selectedId = id;
          _open = item;
        }),
        title: Text(
          item['name'] as String? ?? 'Item',
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
                      inventoryItemStatusLabel(status),
                      warn: status == 'discontinued',
                    ),
                  if ((item['unit'] as String?)?.isNotEmpty == true)
                    Tag(inventoryUnitLabel(item['unit'] as String?)),
                ],
              ),
              Text(
                [
                  item['number'],
                  item['sku'],
                  inventoryOwnerLabel(
                    item,
                    widget.session.me!.organization.name,
                  ),
                  inventoryPlaceLabel(item),
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

  Widget _pane({required bool showBack}) {
    if (_adding) {
      return AddItemPane(
        api: widget.api,
        session: widget.session,
        showBack: showBack,
        onCancel: _clearPane,
        onSaved: (created) {
          setState(() {
            _adding = false;
            _open = created;
            _selectedId = created['id'] as String?;
          });
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
      onBack: _clearPane,
    );
  }
}
