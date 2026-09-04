import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/async_body.dart';
import '../../widgets/load_more.dart';
import '../../widgets/refused.dart';
import '../../widgets/tag.dart';
import '../inventory_screen.dart';
import 'add_transfer_pane.dart';
import 'item_pane.dart';

/// Stock chip: balance rows (item at a location).
class StockList extends StatefulWidget {
  const StockList({
    super.key,
    required this.api,
    required this.session,
    required this.visible,
  });

  final ApiClient api;
  final Session session;
  final bool visible;

  @override
  State<StockList> createState() => _StockListState();
}

class _StockListState extends State<StockList> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _rows = [];
  int _total = 0;
  int _page = 1;
  int _lastPage = 1;
  String? _pageError;
  bool _fetching = false;
  int _request = 0;
  bool _low = false;
  bool _transferring = false;
  String? _selectedId;
  Map<String, dynamic>? _open;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant StockList oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.visible && !oldWidget.visible) _load();
  }

  bool get _hasPane => _transferring || _open != null;

  Future<void> _load({int page = 1}) async {
    if (page > 1 && _fetching) return;

    final request = ++_request;

    setState(() {
      _loading = page == 1 && _rows.isEmpty;
      _fetching = true;
      _error = null;
      _pageError = null;
    });

    final response = await widget.api.get('/inventory/stock', {
      'page': '$page',
      if (_low) 'low': '1',
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
      _rows = page == 1 ? rows : [..._rows, ...rows];
      _total = inventoryTotal(response.body, _rows.length);
      _lastPage = inventoryLastPage(response.body);
      _page = page;
    });
  }

  void _clearPane() => setState(() {
    _transferring = false;
    _selectedId = null;
    _open = null;
  });

  void _select(Map<String, dynamic> row) {
    final item = inventoryMap(row['item']);
    setState(() {
      _transferring = false;
      _selectedId = item?['id'] as String?;
      _open = row;
    });
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;
    final canEdit = me.can('inventory.edit');
    final nowhere = (me.companies.isEmpty && me.franchises.isEmpty)
        ? me.nowhereToAct('transfer stock')
        : null;
    final refused = canEdit ? nowhere : null;

    return InventorySplitScaffold(
      hasPane: _hasPane,
      loading: _loading,
      error: _error,
      onRetry: _load,
      header: PanelHeader(
        note: (_loading || _error != null)
            ? 'On-hand at each place.'
            : _rows.length < _total
            ? 'Showing ${_rows.length} of $_total.'
            : _total == 1
            ? '1 balance in this view.'
            : '$_total in this view.',
        action: canEdit
            ? Refused(
                reason: refused,
                child: FilledButton.icon(
                  onPressed: refused == null
                      ? () => setState(() {
                          _transferring = true;
                          _selectedId = null;
                          _open = null;
                        })
                      : null,
                  icon: const Icon(Icons.swap_horiz, size: 18),
                  label: const Text('Transfer'),
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
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
          child: Align(
            alignment: Alignment.centerLeft,
            child: FilterChip(
              label: const Text('Low'),
              selected: _low,
              onSelected: (_) {
                setState(() => _low = !_low);
                _load();
              },
            ),
          ),
        ),
        Expanded(
          child: _rows.isEmpty
              ? const Center(child: Text('There is no stock.'))
              : ListView.separated(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
                  itemCount: _rows.length + (_page < _lastPage ? 1 : 0),
                  separatorBuilder: (_, _) => const SizedBox(height: 8),
                  itemBuilder: (context, i) {
                    if (i == _rows.length) {
                      return LoadMore(
                        shown: _rows.length,
                        total: _total,
                        busy: _fetching,
                        error: _pageError,
                        onMore: () => _load(page: _page + 1),
                      );
                    }
                    return _row(context, _rows[i]);
                  },
                ),
        ),
      ],
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> row) {
    final item = inventoryMap(row['item']);
    final location = inventoryMap(row['location']);
    final id = item?['id'] as String?;
    final unit = inventoryUnitLabel(item?['unit'] as String?);
    final qty = inventoryQty(row['qty_on_hand']);
    final low = inventoryIsLow(item, row['qty_on_hand']);

    return Card(
      child: ListTile(
        selected: id != null && id == _selectedId,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        onTap: () => _select(row),
        title: Text(
          item?['name'] as String? ?? 'Item',
          overflow: TextOverflow.ellipsis,
          style: Theme.of(context).textTheme.titleMedium,
        ),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (low) const Tag('Low', warn: true),
              Text(
                [
                  '$qty $unit',
                  location?['name'],
                  item?['sku'],
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
    if (_transferring) {
      final seed = inventoryMap(_open?['location']);
      final item = inventoryMap(_open?['item']);
      return AddTransferPane(
        api: widget.api,
        session: widget.session,
        showBack: showBack,
        fromLocationId: seed?['id'] as String?,
        itemId: item?['id'] as String?,
        onCancel: _clearPane,
        onSaved: (_) {
          _clearPane();
          _load();
        },
      );
    }

    final row = _open;
    final item = inventoryMap(row?['item']);
    final id = item?['id'] as String?;
    if (id == null) return const SizedBox.shrink();

    return ItemPane(
      api: widget.api,
      session: widget.session,
      itemId: id,
      seed: item,
      seedBalance: row,
      showBack: showBack,
      onBack: _clearPane,
    );
  }
}
