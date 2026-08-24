import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
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

  bool get _showCompanyField =>
      _atOrganization || _me.companies.length > 1;

  @override
  void initState() {
    super.initState();
    final scope = _me.scope;
    if (scope.type == 'organization') {
      _companyId = null;
    } else if (scope.type == 'company') {
      _companyId = scope.id;
    } else if (scope.type == 'franchise') {
      final place = _me.franchises.where((f) => f.id == scope.id).firstOrNull;
      _companyId = place?.companyId;
      _franchiseId = place?.id;
    } else if (_me.companies.length == 1) {
      _companyId = _me.companies.first.id;
    }
  }

  @override
  void dispose() {
    _name.dispose();
    _sku.dispose();
    _reorder.dispose();
    super.dispose();
  }

  List<FranchiseSummary> get _places {
    final companyId = _companyId;
    if (companyId == null) return const [];
    return _me.franchises.where((f) => f.companyId == companyId).toList();
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
            clipBehavior: Clip.none,
            padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (_showCompanyField) ...[
                  DropdownButtonFormField<String>(
                    initialValue: _companyId ?? inventoryOrgValue,
                    isExpanded: true,
                    decoration: InputDecoration(
                      labelText: 'Company',
                      helperText: _companyId == null && _atOrganization
                          ? '$inventoryGeneralLabel — the whole group can see it.'
                          : null,
                      helperMaxLines: 3,
                    ),
                    items: [
                      if (_atOrganization)
                        DropdownMenuItem(
                          value: inventoryOrgValue,
                          child: Text(_me.organization.name),
                        ),
                      for (final c in _me.companies)
                        DropdownMenuItem(value: c.id, child: Text(c.name)),
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
                if (_companyId != null)
                  DropdownButtonFormField<String>(
                    key: ValueKey(_companyId),
                    initialValue: _franchiseId ?? inventoryGeneralValue,
                    isExpanded: true,
                    decoration: InputDecoration(
                      labelText: _me.organization.franchiseLabel,
                      helperText:
                          '$inventoryGeneralLabel is visible to everyone who can see this company.',
                      helperMaxLines: 3,
                    ),
                    items: [
                      const DropdownMenuItem(
                        value: inventoryGeneralValue,
                        child: Text(inventoryGeneralLabel),
                      ),
                      for (final f in _places)
                        DropdownMenuItem(value: f.id, child: Text(f.name)),
                    ],
                    onChanged: _busy
                        ? null
                        : (v) => setState(
                            () => _franchiseId =
                                v == inventoryGeneralValue ? null : v,
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
                DropdownButtonFormField<String>(
                  initialValue: _unit,
                  isExpanded: true,
                  decoration: const InputDecoration(labelText: 'Unit'),
                  items: [
                    for (final u in inventoryUnits)
                      DropdownMenuItem(value: u.$1, child: Text(u.$2)),
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
