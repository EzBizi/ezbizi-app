import 'package:flutter/material.dart';


import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/tag.dart';
import 'item_pane.dart';

/// Open a sale: lines, and fulfill from a location when it is still open.
class SalePane extends StatefulWidget {
  const SalePane({
    super.key,
    required this.api,
    required this.session,
    required this.sale,
    required this.onBack,
    required this.onChanged,
    this.showBack = false,
  });

  final ApiClient api;
  final Session session;
  final Map<String, dynamic> sale;
  final VoidCallback onBack;
  final ValueChanged<Map<String, dynamic>> onChanged;
  final bool showBack;

  @override
  State<SalePane> createState() => _SalePaneState();
}

class _SalePaneState extends State<SalePane> {
  late Map<String, dynamic> _sale = widget.sale;
  List<Map<String, dynamic>> _locations = [];
  final Map<String, TextEditingController> _qty = {};
  String? _locationId;
  bool _busy = false;
  String? _error;
  bool _loadingLocations = true;

  bool get _financials => widget.session.me!.can('inventory.financials');
  String get _status => _sale['status'] as String? ?? '';
  bool get _hasFulfilled {
    for (final line in inventoryMaps(_sale['lines'])) {
      if ((inventoryQtyNumber(line['qty_fulfilled']) ?? 0) > 0) return true;
    }
    return false;
  }

  bool get _canFulfill =>
      widget.session.me!.can('inventory.edit') &&
      (_status == 'open' || _status == 'partial');
  bool get _canOpen =>
      widget.session.me!.can('inventory.edit') && _status == 'draft';
  bool get _canCancel =>
      widget.session.me!.can('inventory.edit') &&
      (_status == 'draft' || (_status == 'open' && !_hasFulfilled));
  bool get _canPay =>
      _financials &&
      widget.session.me!.can('inventory.edit') &&
      _status != 'cancelled';

  @override
  void initState() {
    super.initState();
    _syncQty();
    _loadLocations();
  }

  @override
  void didUpdateWidget(covariant SalePane oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.sale != widget.sale) {
      _sale = widget.sale;
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
    final lines = inventoryMaps(_sale['lines']);
    final keep = <String>{};
    for (final line in lines) {
      final id = line['id'] as String?;
      if (id == null) continue;
      keep.add(id);
      final ordered = inventoryQtyNumber(line['qty_ordered']) ?? 0;
      final fulfilled = inventoryQtyNumber(line['qty_fulfilled']) ?? 0;
      final left = ordered - fulfilled;
      _qty.putIfAbsent(
        id,
        () => TextEditingController(text: left > 0 ? inventoryQty(left) : ''),
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

    final rows = inventoryRows(response.data)
        .where((row) => row['status'] != 'closed')
        .toList();
    setState(() {
      _loadingLocations = false;
      _locations = rows;
      _locationId ??= rows.length == 1 ? rows.first['id'] as String? : null;
    });
  }

  Future<void> _openSale() => _act((id) => widget.api.post(
    '/inventory/sales/$id/open',
    const {},
  ));

  Future<void> _cancelSale() => _act((id) => widget.api.post(
    '/inventory/sales/$id/cancel',
    const {},
  ));

  Future<void> _markPaid() => _act((id) => widget.api.post(
    '/inventory/sales/$id/pay',
    {'payment_status': 'paid'},
  ));

  Future<void> _markUnpaid() => _act((id) => widget.api.post(
    '/inventory/sales/$id/pay',
    {'payment_status': 'unpaid'},
  ));

  Future<void> _act(
    Future<ApiResponse> Function(String id) send,
  ) async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final id = _sale['id'] as String;
    final response = await send(id);

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
      _sale = Map<String, dynamic>.from(response.data as Map);
      _syncQty();
    });
    widget.onChanged(_sale);
  }

  Future<void> _fulfill() async {
    final locationId = _locationId;
    if (locationId == null) return;

    final lines = <Map<String, dynamic>>[];
    for (final line in inventoryMaps(_sale['lines'])) {
      final id = line['id'] as String?;
      if (id == null) continue;
      final raw = _qty[id]?.text.trim() ?? '';
      if (raw.isEmpty) continue;
      lines.add({'sale_line_id': id, 'qty': raw});
    }
    if (lines.isEmpty) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    final id = _sale['id'];
    final response = await widget.api.post(
      '/inventory/sales/$id/fulfillments',
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

    final fresh = await widget.api.get('/inventory/sales/$id');
    if (!mounted) return;

    setState(() => _busy = false);

    if (fresh.ok) {
      final row = Map<String, dynamic>.from(fresh.data as Map);
      setState(() {
        _sale = row;
        _syncQty();
      });
      widget.onChanged(row);
    } else {
      widget.onChanged(_sale);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final customer = inventoryMap(_sale['customer']);
    final payment = _sale['payment_status'] as String? ?? '';
    final lines = inventoryMaps(_sale['lines']);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        paneHeader(
          context,
          _sale['number'] as String? ?? 'Sale',
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
                    const Tag('Sale'),
                    if (_status.isNotEmpty)
                      Tag(
                        inventorySaleStatusLabel(_status),
                        warn: _status == 'cancelled',
                      ),
                    if (_financials && payment.isNotEmpty)
                      Tag(inventorySalePaymentLabel(payment)),
                  ],
                ),
                const SizedBox(height: 8),
                Text(
                  [
                    customer?['name'] ?? _sale['note'],
                    inventoryOwnerLabel(
                      _sale,
                      widget.session.me!.organization.name,
                    ),
                    inventoryPlaceLabel(_sale),
                    _sale['sold_on'],
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
                    '${inventoryQty(line['qty_fulfilled'])} of ${inventoryQty(line['qty_ordered'])} fulfilled',
                    style: theme.textTheme.bodyMedium,
                  ),
                  if (_financials &&
                      inventoryCents(line['unit_price_minor']) != null) ...[
                    const Text('Unit price'),
                    Text(inventoryCents(line['unit_price_minor'])!),
                  ],
                  if (_canFulfill) ...[
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
                      onPressed: _busy ? null : _openSale,
                      child: const Text('Open sale'),
                    ),
                  ),
                  const SizedBox(height: 12),
                ],
                if (_canFulfill) ...[
                  if (_loadingLocations)
                    const Padding(
                      padding: EdgeInsets.symmetric(vertical: 8),
                      child: LinearProgressIndicator(),
                    )
                  else
                    SearchPickerField<String>(
                      value: _locationId,
                      label: 'Fulfill from',
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
                      onPressed: _busy || _locationId == null ? null : _fulfill,
                      child: const Text('Fulfill'),
                    ),
                  ),
                  const SizedBox(height: 12),
                ],
                if (_canPay) ...[
                  Align(
                    alignment: Alignment.centerLeft,
                    child: payment == 'paid'
                        ? OutlinedButton(
                            onPressed: _busy ? null : _markUnpaid,
                            child: const Text('Mark unpaid'),
                          )
                        : FilledButton(
                            onPressed: _busy ? null : _markPaid,
                            child: const Text('Mark paid'),
                          ),
                  ),
                  const SizedBox(height: 12),
                ],
                if (_canCancel)
                  Align(
                    alignment: Alignment.centerLeft,
                    child: OutlinedButton(
                      onPressed: _busy ? null : _cancelSale,
                      child: const Text('Cancel sale'),
                    ),
                  ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}
