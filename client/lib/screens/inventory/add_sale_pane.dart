import 'package:flutter/material.dart';


import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import 'inventory_place_fields.dart';
import 'item_pane.dart';
import 'order_lines.dart';

/// Draft a sale in the 75% pane, not a modal.
///
/// The buyer is a Customer, or a note that this was a counter sale. There is
/// no second buyer list.
class AddSalePane extends StatefulWidget {
  const AddSalePane({
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
  State<AddSalePane> createState() => _AddSalePaneState();
}

class _AddSalePaneState extends State<AddSalePane> {
  static const _counter = '__counter__';

  String? _companyId;
  String? _franchiseId;
  String? _customerChoice;
  final _note = TextEditingController();
  List<Map<String, dynamic>> _customers = [];
  List<Map<String, dynamic>> _items = [];
  final List<OrderLine> _lines = [OrderLine()];
  bool _loading = true;
  bool _busy = false;
  bool _customersHidden = false;
  String? _error;

  Me get _me => widget.session.me!;
  bool get _atOrganization => _me.scope.type == 'organization';
  bool get _financials => _me.can('inventory.financials');
  bool get _isCounter =>
      _customerChoice == null || _customerChoice == _counter;
  String? get _customerId => _isCounter ? null : _customerChoice;

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
    _note.dispose();
    for (final line in _lines) {
      line.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    final customers = await widget.api.get('/customers', {
      'per_page': '200',
      'active_only': '1',
    });
    final items = await widget.api.get('/inventory/items', {'per_page': '200'});
    if (!mounted) return;

    if (!items.ok) {
      setState(() {
        _loading = false;
        _error = items.message;
      });
      return;
    }

    final customerRows = customers.ok
        ? inventoryRows(customers.data)
        : const <Map<String, dynamic>>[];
    final itemRows = inventoryRows(items.data)
        .where((row) => row['status'] != 'discontinued')
        .toList();

    setState(() {
      _loading = false;
      _customers = customerRows;
      _items = itemRows;
      _customersHidden = !customers.ok;
      _customerChoice ??= customerRows.length == 1
          ? customerRows.first['id'] as String?
          : _counter;
      if (itemRows.length == 1) {
        _lines.first.itemId ??= itemRows.first['id'] as String?;
      }
      if (!customers.ok) {
        _error =
            'Customers are hidden for your role — this will be a counter sale.';
      }
    });
  }

  List<OrderLine> get _readyLines => readyOrderLines(_lines);

  Future<void> _save() async {
    if (_companyId == null && !_atOrganization) return;
    final ready = _readyLines;
    if (ready.isEmpty) return;
    final note = _note.text.trim();
    if (_customerId == null && note.isEmpty) return;

    final List<Map<String, dynamic>> lines;
    try {
      lines = orderLinesPayload(
        ready,
        financials: _financials,
        amountKey: 'unit_price_minor',
      );
    } on FormatException catch (e) {
      setState(() => _error = e.message);
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/inventory/sales', {
      'company_id': ?_companyId,
      'franchise_id': ?_franchiseId,
      'customer_id': ?_customerId,
      if (note.isNotEmpty) 'note': note,
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
    final canSave =
        !_busy &&
        !_loading &&
        (_companyId != null || _atOrganization) &&
        _readyLines.isNotEmpty &&
        (_customerId != null || _note.text.trim().isNotEmpty);

    return formPane(
      context,
      'New sale',
      showBack: widget.showBack,
      busy: _busy,
      onCancel: widget.onCancel,
      onSave: canSave ? _save : null,
      saveLabel: 'Save draft',
      loading: _loading,
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
        SearchPickerField<String>(
          value: _customerChoice,
          label: 'Customer',
          helperText: 'A named customer, or a counter sale with a note.',
          helperMaxLines: 2,
          options: [
            const SearchOption(value: _counter, label: 'Counter sale'),
            for (final customer in _customers)
              SearchOption(value: customer['id'] as String?, label: customer['name'] as String? ?? ''),
          ],
          onChanged: _busy || _customersHidden
              ? null
              : (v) => setState(() => _customerChoice = v),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: _note,
          enabled: !_busy,
          decoration: InputDecoration(
            labelText: 'Note',
            helperText: _isCounter
                ? 'Required for a counter sale.'
                : 'Optional.',
          ),
          onChanged: (_) => setState(() {}),
        ),
        ...orderLineFields(
          lines: _lines,
          items: _items,
          busy: _busy,
          financials: _financials,
          keyPrefix: 'sale',
          amountLabel: 'Unit price',
          changed: () => setState(() {}),
        ),
        if (_error != null) ...[
          const SizedBox(height: 8),
          ErrorNote(_error!),
        ],
      ],
    );
  }
}
