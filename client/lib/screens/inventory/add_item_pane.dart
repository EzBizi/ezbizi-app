import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
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

  bool get _showCompanyField => _me.offersCompanyChoice;

  bool get _showFranchiseField =>
      _me.offersFranchiseChoice && _companyId != null;

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

  List<FranchiseSummary> get _places => _me.franchisesOf(_companyId);

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
    final theme = Theme.of(context);
    final canSave =
        !_busy &&
        _name.text.trim().isNotEmpty &&
        (_companyId != null || _atOrganization);

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
                child: Text('New item', style: theme.textTheme.titleMedium),
              ),
            ],
          ),
        ),
        Expanded(
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (_showCompanyField) ...[
                  SearchPickerField<String>(
                    value: _companyId ?? inventoryOrgValue,
                    label: 'Company',
                    helperText: _companyId == null && _atOrganization
                        ? '$inventoryGeneralLabel — the whole group can see it.'
                        : null,
                    helperMaxLines: 3,
                    options: [
                      if (_atOrganization)
                        SearchOption(
                          value: inventoryOrgValue,
                          label: _me.organization.name,
                        ),
                      for (final c in _me.companiesInView)
                        SearchOption(value: c.id, label: c.name),
                    ],
                    onChanged: _busy
                        ? null
                        : (v) => setState(() {
                            _companyId = v == inventoryOrgValue ? null : v;
                            _franchiseId = null;
                          }),
                  ),
                  const SizedBox(height: 12),
                ],
                if (_showFranchiseField)
                  SearchPickerField<String>(
                    key: ValueKey(_companyId),
                    value: _franchiseId ?? inventoryGeneralValue,
                    label: _me.organization.franchiseLabel,
                    helperText:
                        '$inventoryGeneralLabel is visible to everyone who can see this company.',
                    helperMaxLines: 3,
                    options: [
                      const SearchOption(
                        value: inventoryGeneralValue,
                        label: inventoryGeneralLabel,
                      ),
                      for (final f in _places)
                        SearchOption(value: f.id, label: f.name),
                    ],
                    onChanged: _busy
                        ? null
                        : (v) => setState(
                            () => _franchiseId = v == inventoryGeneralValue
                                ? null
                                : v,
                          ),
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
                child: const Text('Save'),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
