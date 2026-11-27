import 'package:flutter/material.dart';


import '../../core/api_client.dart';
import '../../core/money.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../inventory/inventory_place_fields.dart';
import '../inventory/item_pane.dart';

/// Draft a service in the 75% pane, not a modal.
class AddServicePane extends StatefulWidget {
  const AddServicePane({
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
  State<AddServicePane> createState() => _AddServicePaneState();
}

class _AddServicePaneState extends State<AddServicePane> {
  final _name = TextEditingController();
  final _description = TextEditingController();
  final _price = TextEditingController();
  String? _companyId;
  String? _franchiseId;
  String? _groupId;
  String? _labelId;
  List<Map<String, dynamic>> _groups = [];
  List<Map<String, dynamic>> _labels = [];
  bool _loading = true;
  bool _busy = false;
  String? _error;

  Me get _me => widget.session.me!;
  bool get _atOrganization => _me.scope.type == 'organization';

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
    _name.dispose();
    _description.dispose();
    _price.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final groups = await widget.api.get('/services/groups');
    final labels = await widget.api.get('/services/labels');
    if (!mounted) return;

    if (!groups.ok) {
      setState(() {
        _loading = false;
        _error = groups.message;
      });
      return;
    }
    if (!labels.ok) {
      setState(() {
        _loading = false;
        _error = labels.message;
      });
      return;
    }

    setState(() {
      _loading = false;
      _groups = inventoryRows(groups.data);
      _labels = inventoryRows(labels.data);
    });
  }

  Future<void> _save() async {
    if (_name.text.trim().isEmpty) return;
    if (_companyId == null && !_atOrganization) return;

    int? cents;
    try {
      cents = parseAmountMinor(_price.text);
    } on FormatException catch (e) {
      setState(() => _error = e.message);
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/services', {
      'company_id': ?_companyId,
      'franchise_id': ?_franchiseId,
      'name': _name.text.trim(),
      if (_description.text.trim().isNotEmpty)
        'description': _description.text.trim(),
      'price_minor': ?cents,
      'group_id': ?_groupId,
      'label_id': ?_labelId,
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
        _name.text.trim().isNotEmpty &&
        (_companyId != null || _atOrganization);

    return formPane(
      context,
      'New service',
      showBack: widget.showBack,
      busy: _busy,
      onCancel: widget.onCancel,
      onSave: canSave ? _save : null,
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
            _groupId = null;
            _labelId = null;
          }),
          onFranchiseChanged: (v) => setState(() {
            _franchiseId = v;
            _groupId = null;
            _labelId = null;
          }),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: _name,
          enabled: !_busy,
          decoration: const InputDecoration(
            labelText: 'Name of service',
          ),
          onChanged: (_) => setState(() {}),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: _description,
          enabled: !_busy,
          maxLines: 4,
          decoration: const InputDecoration(
            labelText: 'Description',
          ),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: _price,
          enabled: !_busy,
          keyboardType: const TextInputType.numberWithOptions(
            decimal: true,
          ),
          decoration: const InputDecoration(
            labelText: 'Price',
          ),
        ),
        const SizedBox(height: 12),
        SearchPickerField<String?>(
          value: _groupId,
          label: 'Group',
          options: [
            const SearchOption(value: null, label: 'No group'),
            for (final row in _groups)
              SearchOption(value: row['id'] as String?, label: row['name'] as String? ?? ''),
          ],
          onChanged: _busy
              ? null
              : (v) => setState(() => _groupId = v),
        ),
        const SizedBox(height: 12),
        SearchPickerField<String?>(
          value: _labelId,
          label: 'Label',
          options: [
            const SearchOption(value: null, label: 'No label'),
            for (final row in _labels)
              SearchOption(value: row['id'] as String?, label: row['name'] as String? ?? ''),
          ],
          onChanged: _busy
              ? null
              : (v) => setState(() => _labelId = v),
        ),
        if (_error != null) ...[
          const SizedBox(height: 8),
          ErrorNote(_error!),
        ],
      ],
    );
  }
}
