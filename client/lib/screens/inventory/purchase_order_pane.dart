import 'package:flutter/material.dart';


import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/tag.dart';
import 'item_pane.dart';

/// Open a PO: lines, and receive into a location when it is still open.
class PurchaseOrderPane extends StatefulWidget {
  const PurchaseOrderPane({
    super.key,
    required this.api,
    required this.session,
    required this.order,
    required this.onBack,
    required this.onChanged,
    this.showBack = false,
  });

  final ApiClient api;
  final Session session;
  final Map<String, dynamic> order;
  final VoidCallback onBack;
  final ValueChanged<Map<String, dynamic>> onChanged;
  final bool showBack;

  @override
  State<PurchaseOrderPane> createState() => _PurchaseOrderPaneState();
}

class _PurchaseOrderPaneState extends State<PurchaseOrderPane> {
  late Map<String, dynamic> _order = widget.order;
  List<Map<String, dynamic>> _locations = [];
  final Map<String, TextEditingController> _qty = {};
  String? _locationId;
  bool _busy = false;
  String? _error;
  bool _loadingLocations = true;

  bool get _financials => widget.session.me!.can('inventory.financials');
  bool get _canReceive =>
      widget.session.me!.can('inventory.edit') &&
      _order['status'] == 'open';
  bool get _canOpen =>
      widget.session.me!.can('inventory.edit') &&
      _order['status'] == 'draft';

  @override
  void initState() {
    super.initState();
    _syncQty();
    _loadLocations();
  }

  @override
  void didUpdateWidget(covariant PurchaseOrderPane oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.order != widget.order) {
      _order = widget.order;
      _syncQty();
    }
  }

  @override
  void dispose() {
    for (final c in _qty.values) {
      c.dispose();
    }
    super.dispose();
  }

  void _syncQty() {
    final lines = inventoryMaps(_order['lines']);
    final keep = <String>{};
    for (final line in lines) {
      final id = line['id'] as String?;
      if (id == null) continue;
      keep.add(id);
      final ordered = inventoryQtyNumber(line['qty_ordered']) ?? 0;
      final received = inventoryQtyNumber(line['qty_received']) ?? 0;
      final left = ordered - received;
      _qty.putIfAbsent(
        id,
        () => TextEditingController(
          text: left > 0 ? inventoryQty(left) : '',
        ),
      );
    }
    for (final id in _qty.keys.toList()) {
      if (!keep.contains(id)) {
        _qty.remove(id)?.dispose();
      }
    }
  }

  Future<void> _loadLocations() async {
    final response = await widget.api.get('/inventory/locations');
    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _loadingLocations = false;
        _error = response.message;
      });
      return;
    }

    final rows = inventoryRows(response.data);
    setState(() {
      _loadingLocations = false;
      _locations = rows;
      _locationId ??= rows.length == 1 ? rows.first['id'] as String? : null;
    });
  }

  Future<void> _openOrder() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final id = _order['id'];
    final response = await widget.api.post(
      '/inventory/purchase-orders/$id/open',
      const {},
    );

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    setState(() {
      _busy = false;
      _order = Map<String, dynamic>.from(response.data as Map);
      _syncQty();
    });
    widget.onChanged(_order);
  }

  Future<void> _receive() async {
    final locationId = _locationId;
    if (locationId == null) return;

    final lines = <Map<String, dynamic>>[];
    for (final line in inventoryMaps(_order['lines'])) {
      final id = line['id'] as String?;
      if (id == null) continue;
      final raw = _qty[id]?.text.trim() ?? '';
      if (raw.isEmpty) continue;
      lines.add({'purchase_order_line_id': id, 'qty': raw});
    }
    if (lines.isEmpty) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    final id = _order['id'];
    final response = await widget.api.post(
      '/inventory/purchase-orders/$id/receives',
      {
        'location_id': locationId,
        'lines': lines,
      },
    );

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    final fresh = await widget.api.get('/inventory/purchase-orders/$id');
    if (!mounted) return;

    setState(() => _busy = false);

    if (fresh.ok) {
      final row = Map<String, dynamic>.from(fresh.data as Map);
      setState(() {
        _order = row;
        _syncQty();
      });
      widget.onChanged(row);
    } else {
      widget.onChanged(_order);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final vendor = inventoryMap(_order['vendor']);
    final status = _order['status'] as String? ?? '';
    final lines = inventoryMaps(_order['lines']);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        paneHeader(
          context,
          _order['number'] as String? ?? 'Purchase order',
          showBack: widget.showBack,
          onBack: _busy ? null : widget.onBack,
          ellipsis: true,
        ),
        Expanded(
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
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
                const SizedBox(height: 8),
                Text(
                  [
                    vendor?['name'],
                    inventoryOwnerLabel(
                      _order,
                      widget.session.me!.organization.name,
                    ),
                    inventoryPlaceLabel(_order),
                  ].where((p) => p != null && '$p'.isNotEmpty).join(' · '),
                  style: theme.textTheme.bodyMedium,
                ),
                if (_error != null) ...[
                  const SizedBox(height: 14),
                  ErrorNote(_error!),
                ],
                const SizedBox(height: 16),
                for (final line in lines) ...[
                  Text(
                    inventoryMap(line['item'])?['name'] as String? ?? 'Item',
                    style: theme.textTheme.titleSmall,
                  ),
                  Text(
                    '${inventoryQty(line['qty_received'])} of ${inventoryQty(line['qty_ordered'])} received',
                    style: theme.textTheme.bodyMedium,
                  ),
                  if (_financials &&
                      inventoryCents(line['unit_cost_minor']) != null) ...[
                    const Text('Unit cost'),
                    Text(inventoryCents(line['unit_cost_minor'])!),
                  ],
                  if (_canReceive) ...[
                    const SizedBox(height: 8),
                    TextField(
                      controller: _qty[line['id'] as String?],
                      enabled: !_busy,
                      keyboardType: const TextInputType.numberWithOptions(
                        decimal: true,
                      ),
                      decoration: const InputDecoration(
                        labelText: 'Qty this time',
                      ),
                    ),
                  ],
                  const SizedBox(height: 16),
                ],
                if (_canOpen) ...[
                  Align(
                    alignment: Alignment.centerLeft,
                    child: FilledButton(
                      onPressed: _busy ? null : _openOrder,
                      child: const Text('Open order'),
                    ),
                  ),
                ],
                if (_canReceive) ...[
                  if (_loadingLocations)
                    const Padding(
                      padding: EdgeInsets.symmetric(vertical: 8),
                      child: LinearProgressIndicator(),
                    )
                  else
                    SearchPickerField<String>(
                      value: _locationId,
                      label: 'Receive into',
                      options: [
                        for (final loc in _locations)
                          SearchOption(value: loc['id'] as String?, label: loc['name'] as String? ?? ''),
                      ],
                      onChanged: _busy
                          ? null
                          : (v) => setState(() => _locationId = v),
                    ),
                  const SizedBox(height: 16),
                  Align(
                    alignment: Alignment.centerLeft,
                    child: FilledButton(
                      onPressed: _busy || _locationId == null
                          ? null
                          : _receive,
                      child: const Text('Receive'),
                    ),
                  ),
                ],
              ],
            ),
          ),
        ),
      ],
    );
  }
}
