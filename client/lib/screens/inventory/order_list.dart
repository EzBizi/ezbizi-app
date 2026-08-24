import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/async_body.dart';
import '../../widgets/tag.dart';
import '../inventory_screen.dart';
import 'issue_pane.dart';
import 'item_pane.dart';
import 'purchase_order_pane.dart';

/// Orders chip: purchase orders and recent issues.
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
  bool _toReceive = false;
  bool _openPos = false;
  bool _issuesOnly = false;
  bool _isIssue = false;
  String? _selectedId;
  Map<String, dynamic>? _open;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant OrderList oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.visible && !oldWidget.visible) _load();
  }

  bool get _hasPane => _open != null;

  Future<void> _load() async {
    setState(() {
      _loading = _orders.isEmpty && _issues.isEmpty;
      _error = null;
    });

    final po = await widget.api.get('/inventory/purchase-orders');
    final issues = await widget.api.get('/inventory/issues');

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

    setState(() {
      _loading = false;
      _orders = inventoryRows(po.data);
      _issues = inventoryRows(issues.data);
      if (_open != null && _selectedId != null && !_isIssue) {
        final match = _orders.where((row) => row['id'] == _selectedId);
        if (match.isNotEmpty) _open = match.first;
      }
    });
  }

  void _clearPane() => setState(() {
    _selectedId = null;
    _open = null;
    _isIssue = false;
  });

  bool _hasRemaining(Map<String, dynamic> order) {
    for (final line in inventoryMaps(order['lines'])) {
      final ordered = inventoryQtyNumber(line['qty_ordered']) ?? 0;
      final received = inventoryQtyNumber(line['qty_received']) ?? 0;
      if (received < ordered) return true;
    }
    return false;
  }

  List<({bool issue, Map<String, dynamic> row})> _visible() {
    final rows = <({bool issue, Map<String, dynamic> row})>[];

    final wantPo = !_issuesOnly || _openPos || _toReceive;
    final wantIssues = !_toReceive && !_openPos || _issuesOnly;

    if (wantPo) {
      for (final order in _orders) {
        final status = order['status'] as String?;
        if (_toReceive && (status != 'open' || !_hasRemaining(order))) {
          continue;
        }
        if (_openPos && status != 'open') continue;
        if (_issuesOnly && !_openPos && !_toReceive) continue;
        rows.add((issue: false, row: order));
      }
    }

    if (wantIssues && (_issuesOnly || (!_toReceive && !_openPos))) {
      for (final issue in _issues) {
        rows.add((issue: true, row: issue));
      }
    }

    return rows;
  }

  @override
  Widget build(BuildContext context) {
    return InventorySplitScaffold(
      hasPane: _hasPane,
      loading: _loading,
      error: _error,
      onRetry: _load,
      header: PanelHeader(
        title: 'Orders',
        note: (_loading || _error != null)
            ? 'Purchase orders and issues.'
            : 'Open orders to receive, and what has already gone out.',
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
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
          child: Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              FilterChip(
                label: const Text('To receive'),
                selected: _toReceive,
                onSelected: (_) => setState(() => _toReceive = !_toReceive),
              ),
              FilterChip(
                label: const Text('Open POs'),
                selected: _openPos,
                onSelected: (_) => setState(() => _openPos = !_openPos),
              ),
              FilterChip(
                label: const Text('Issues'),
                selected: _issuesOnly,
                onSelected: (_) => setState(() => _issuesOnly = !_issuesOnly),
              ),
            ],
          ),
        ),
        Expanded(
          child: rows.isEmpty
              ? const Center(child: Text('There are no orders.'))
              : ListView.separated(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
                  itemCount: rows.length,
                  separatorBuilder: (_, _) => const SizedBox(height: 8),
                  itemBuilder: (context, i) => _row(context, rows[i]),
                ),
        ),
      ],
    );
  }

  Widget _row(
    BuildContext context,
    ({bool issue, Map<String, dynamic> row}) entry,
  ) {
    final id = entry.row['id'] as String?;
    final selected = id != null && id == _selectedId && _isIssue == entry.issue;

    if (entry.issue) {
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
            _isIssue = true;
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

    final vendor = inventoryMap(entry.row['vendor']);
    final status = entry.row['status'] as String? ?? '';

    return Card(
      child: ListTile(
        selected: selected,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        onTap: () => setState(() {
          _isIssue = false;
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
    final row = _open;
    if (row == null) return const SizedBox.shrink();

    if (_isIssue) {
      return IssuePane(
        issue: row,
        showBack: showBack,
        onBack: _clearPane,
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
          _isIssue = false;
        });
        _load();
      },
    );
  }
}
