import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import 'inventory_place_fields.dart';
import 'item_pane.dart';

/// Add is the same 75% pane as an item, not a modal.
class AddItemPane extends StatefulWidget {
  const AddItemPane({
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
  State<AddItemPane> createState() => _AddItemPaneState();
}

class _AddItemPaneState extends State<AddItemPane> {
  final _name = TextEditingController();
  final _sku = TextEditingController();
  final _reorder = TextEditingController();
  String _unit = 'each';
  String? _companyId;
  String? _franchiseId;
  bool _busy = false;
  String? _error;

  Me get _me => widget.session.me!;

  bool get _atOrganization => _me.scope.type == 'organization';

  @override
  void initState() {
    super.initState();
    _companyId = _me.filingCompanyId;
    _franchiseId = _me.filingFranchiseId;
  }

  @override
  void dispose() {
    _name.dispose();
    _sku.dispose();
    _reorder.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_name.text.trim().isEmpty) return;
    if (_companyId == null && !_atOrganization) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    final reorder = _reorder.text.trim();

    final response = await widget.api.post('/inventory/items', {
      'company_id': ?_companyId,
      'franchise_id': ?_franchiseId,
      'name': _name.text.trim(),
      if (_sku.text.trim().isNotEmpty) 'sku': _sku.text.trim(),
      'unit': _unit,
      if (reorder.isNotEmpty) 'reorder_at': reorder,
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
        _name.text.trim().isNotEmpty &&
        (_companyId != null || _atOrganization);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        paneHeader(
          context,
          'New item',
          showBack: widget.showBack,
          onBack: _busy ? null : widget.onCancel,
        ),
        Expanded(
          child: SingleChildScrollView(
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
                  onFranchiseChanged: (v) => setState(() => _franchiseId = v),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: _name,
                  enabled: !_busy,
                  decoration: const InputDecoration(labelText: 'Name'),
                  onChanged: (_) => setState(() {}),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: _sku,
                  enabled: !_busy,
                  decoration: const InputDecoration(labelText: 'SKU'),
                ),
                const SizedBox(height: 12),
                SearchPickerField<String>(
                  value: _unit,
                  label: 'Unit',
                  options: [
                    for (final u in inventoryUnits)
                      SearchOption(value: u.$1, label: u.$2),
                  ],
                  onChanged: _busy
                      ? null
                      : (v) => setState(() => _unit = v ?? _unit),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: _reorder,
                  enabled: !_busy,
                  keyboardType: const TextInputType.numberWithOptions(
                    decimal: true,
                  ),
                  decoration: const InputDecoration(
                    labelText: 'Reorder at',
                    helperText: 'On-hand at or below this is Low.',
                    helperMaxLines: 2,
                  ),
                ),
                if (_error != null) ...[
                  const SizedBox(height: 14),
                  ErrorNote(_error!),
                ],
              ],
            ),
          ),
        ),
        paneFooter(
          onCancel: _busy ? null : widget.onCancel,
          onSave: canSave ? _save : null,
        ),
      ],
    );
  }
}
