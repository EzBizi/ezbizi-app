import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/money.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import 'add_vendor_pane.dart';
import 'inventory_place_fields.dart';
import 'item_pane.dart';

/// Draft a purchase order in the 75% pane, not a modal.
class AddPurchaseOrderPane extends StatefulWidget {
  const AddPurchaseOrderPane({
    super.key,
    required this.api,
    required this.session,
    required this.onCancel,
    required this.onSaved,
    this.showBack = false,
  });

  final ApiClient api;
  final Session session;
  final VoidCallback onCancel;
  final ValueChanged<Map<String, dynamic>> onSaved;
  final bool showBack;

  @override
  State<AddPurchaseOrderPane> createState() => _AddPurchaseOrderPaneState();
}

class _AddPurchaseOrderPaneState extends State<AddPurchaseOrderPane> {
  String? _companyId;
  String? _franchiseId;
  String? _vendorId;
  List<Map<String, dynamic>> _vendors = [];
  List<Map<String, dynamic>> _items = [];
  final List<_PoLine> _lines = [_PoLine()];
  bool _loading = true;
  bool _busy = false;
  bool _addingVendor = false;
  String? _error;

  Me get _me => widget.session.me!;
  bool get _atOrganization => _me.scope.type == 'organization';
  bool get _financials => _me.can('inventory.financials');

  @override
  void initState() {
    super.initState();
    final place = inventoryPlaceFromScope(_me);
    _companyId = place.companyId;
    _franchiseId = place.franchiseId;
    _load();
  }

  @override
  void dispose() {
    for (final line in _lines) {
      line.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    final vendors = await widget.api.get('/inventory/vendors');
    final items = await widget.api.get('/inventory/items', {'per_page': '200'});
    if (!mounted) return;

    if (!vendors.ok) {
      setState(() {
        _loading = false;
        _error = vendors.message;
      });
      return;
    }
    if (!items.ok) {
      setState(() {
        _loading = false;
        _error = items.message;
      });
      return;
    }

    final vendorRows = inventoryRows(vendors.data);
    final itemRows = inventoryRows(items.data)
        .where((row) => row['status'] != 'discontinued')
        .toList();

    setState(() {
      _loading = false;
      _vendors = vendorRows;
      _items = itemRows;
      _vendorId ??= vendorRows.length == 1
          ? vendorRows.first['id'] as String?
          : null;
      if (itemRows.length == 1) {
        _lines.first.itemId ??= itemRows.first['id'] as String?;
      }
    });
  }

  List<_PoLine> get _readyLines => [
    for (final line in _lines)
      if (line.itemId != null && line.qty.text.trim().isNotEmpty) line,
  ];

  Future<void> _save() async {
    if (_vendorId == null) return;
    if (_companyId == null && !_atOrganization) return;
    final ready = _readyLines;
    if (ready.isEmpty) return;

    final lines = <Map<String, dynamic>>[];
    for (final line in ready) {
      final row = <String, dynamic>{
        'item_id': line.itemId,
        'qty': line.qty.text.trim(),
      };
      if (_financials) {
        try {
          final cents = parseAmountMinor(line.cost.text);
          if (cents != null) row['unit_cost_minor'] = cents;
        } on FormatException catch (e) {
          setState(() => _error = e.message);
          return;
        }
      }
      lines.add(row);
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/inventory/purchase-orders', {
      'company_id': ?_companyId,
      'franchise_id': ?_franchiseId,
      'vendor_id': _vendorId,
      'lines': lines,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    widget.onSaved(Map<String, dynamic>.from(response.data as Map));
  }

  @override
  Widget build(BuildContext context) {
    if (_addingVendor) {
      return AddVendorPane(
        api: widget.api,
        session: widget.session,
        showBack: true,
        onCancel: () => setState(() => _addingVendor = false),
        onSaved: (vendor) {
          setState(() {
            _addingVendor = false;
            _vendors = [..._vendors, vendor];
            _vendorId = vendor['id'] as String?;
          });
        },
      );
    }

    final theme = Theme.of(context);
    final canSave =
        !_busy &&
        !_loading &&
        _vendorId != null &&
        (_companyId != null || _atOrganization) &&
        _readyLines.isNotEmpty;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: EdgeInsets.fromLTRB(widget.showBack ? 8 : 20, 12, 20, 8),
          child: Row(
            children: [
              if (widget.showBack)
                IconButton(
                  icon: const Icon(Icons.arrow_back),
                  tooltip: 'Back to the list',
                  onPressed: _busy ? null : widget.onCancel,
                ),
              Expanded(
                child: Text(
                  'New purchase order',
                  style: theme.textTheme.titleMedium,
                ),
              ),
            ],
          ),
        ),
        Expanded(
          child: _loading
              ? const Center(child: CircularProgressIndicator())
              : SingleChildScrollView(
                  clipBehavior: Clip.none,
                  padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      InventoryPlaceFields(
                        me: _me,
                        companyId: _companyId,
                        franchiseId: _franchiseId,
                        enabled: !_busy,
                        onCompanyChanged: (v) => setState(() {
                          _companyId = v;
                          _franchiseId = null;
                        }),
                        onFranchiseChanged: (v) =>
                            setState(() => _franchiseId = v),
                      ),
                      const SizedBox(height: 12),
                      DropdownButtonFormField<String>(
                        initialValue: _vendorId,
                        isExpanded: true,
                        decoration: const InputDecoration(labelText: 'Vendor'),
                        items: [
                          for (final v in _vendors)
                            DropdownMenuItem(
                              value: v['id'] as String?,
                              child: Text(
                                v['name'] as String? ?? '',
                                overflow: TextOverflow.ellipsis,
                              ),
                            ),
                        ],
                        onChanged: _busy
                            ? null
                            : (v) => setState(() => _vendorId = v),
                      ),
                      Align(
                        alignment: Alignment.centerLeft,
                        child: TextButton(
                          onPressed: _busy
                              ? null
                              : () => setState(() => _addingVendor = true),
                          child: const Text('New vendor'),
                        ),
                      ),
                      for (var i = 0; i < _lines.length; i++) ...[
                        const SizedBox(height: 12),
                        DropdownButtonFormField<String>(
                          key: ValueKey('po-item-$i-${_lines[i].itemId}'),
                          initialValue: _lines[i].itemId,
                          isExpanded: true,
                          decoration: const InputDecoration(labelText: 'Item'),
                          items: [
                            for (final item in _items)
                              DropdownMenuItem(
                                value: item['id'] as String?,
                                child: Text(
                                  inventoryItemChoiceLabel(item),
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ),
                          ],
                          onChanged: _busy
                              ? null
                              : (v) => setState(() => _lines[i].itemId = v),
                        ),
                        const SizedBox(height: 12),
                        TextField(
                          controller: _lines[i].qty,
                          enabled: !_busy,
                          keyboardType: const TextInputType.numberWithOptions(
                            decimal: true,
                          ),
                          decoration: const InputDecoration(
                            labelText: 'Qty ordered',
                          ),
                          onChanged: (_) => setState(() {}),
                        ),
                        if (_financials) ...[
                          const SizedBox(height: 12),
                          TextField(
                            controller: _lines[i].cost,
                            enabled: !_busy,
                            keyboardType: const TextInputType.numberWithOptions(
                              decimal: true,
                            ),
                            decoration: const InputDecoration(
                              labelText: 'Unit cost',
                            ),
                          ),
                        ],
                      ],
                      Align(
                        alignment: Alignment.centerLeft,
                        child: TextButton(
                          onPressed: _busy
                              ? null
                              : () => setState(() => _lines.add(_PoLine())),
                          child: const Text('Add line'),
                        ),
                      ),
                      if (_error != null) ...[
                        const SizedBox(height: 8),
                        ErrorNote(_error!),
                      ],
                    ],
                  ),
                ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 16),
          child: Wrap(
            spacing: 8,
            runSpacing: 8,
            alignment: WrapAlignment.end,
            children: [
              TextButton(
                onPressed: _busy ? null : widget.onCancel,
                child: const Text('Cancel'),
              ),
              FilledButton(
                onPressed: canSave ? _save : null,
                child: const Text('Save draft'),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _PoLine {
  String? itemId;
  final qty = TextEditingController();
  final cost = TextEditingController();

  void dispose() {
    qty.dispose();
    cost.dispose();
  }
}
